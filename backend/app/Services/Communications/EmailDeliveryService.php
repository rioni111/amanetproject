<?php

namespace App\Services\Communications;

use App\Enums\ActivityEventType;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailFailureCategory;
use App\Enums\NotificationKind;
use App\Mail\ClientActionMail;
use App\Models\EmailDelivery;
use App\Models\Matter;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Delivery mechanics shared by every e-mail (Phase 6). Architecture: SYNCHRONOUS SEND AFTER COMMIT.
 *
 *   1. claim   — short transaction, request row locked: eligibility, recipient, cooldown, "one sending per
 *                request" and idempotency are checked, and an email_deliveries row is inserted as 'sending'.
 *   2. send    — no transaction open: the action token is generated in memory, the MIME message is built and
 *                submitted to the configured SMTP server (bounded timeout, MAIL_TIMEOUT).
 *   3. finish  — second transaction: on acceptance the link (hash only) is registered and the request updated
 *                (DocumentRequestEmailService / SigningEmailService); on failure only the delivery row changes.
 *
 * No queue is involved, so no token ever lands in a job payload; nothing secret is persisted. A process that
 * dies between 2 and 3 leaves a 'sending' row, which recoverStale() later marks failed / "interrupted"
 * (outcome unknown — the SMTP server may or may not have accepted the message; see the Phase 6 report §16).
 */
class EmailDeliveryService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly AdminNotifier $notifier,
    ) {}

    /**
     * Whether e-mail can be sent, and what is configured — never a password, username or other secret.
     *
     * @return array<string, mixed>
     */
    public function transportStatus(): array
    {
        $mailer = (string) config('mail.default');
        $config = (array) config("mail.mailers.{$mailer}", []);
        $transport = (string) ($config['transport'] ?? '');
        $from = (string) config('mail.from.address');
        $publicUrl = (string) config('lexora.public_app_url');
        $https = str_starts_with(strtolower($publicUrl), 'https://');
        $local = SecureAccessLinkService::isLocalUrl($publicUrl);

        $reason = match (true) {
            ! config('lexora.communications.email_enabled') => 'E-mail sending is switched off (LEXORA_EMAIL_ENABLED=false).',
            in_array($transport, ['log', 'array'], true) && ! app()->environment('testing') => 'The configured mailer does not send real e-mail (MAIL_MAILER='.$mailer.').',
            $transport === '' => 'No mail transport is configured (MAIL_MAILER).',
            $transport === 'smtp' && blank($config['host'] ?? null) && blank($config['url'] ?? null) => 'The SMTP host is not configured (MAIL_HOST).',
            ! filter_var($from, FILTER_VALIDATE_EMAIL) => 'The sender address is not configured (MAIL_FROM_ADDRESS).',
            app()->isProduction() && (! $https || $local) => 'Client links need a public HTTPS address (PUBLIC_APP_URL) before e-mail can be sent.',
            default => null,
        };

        return [
            'available' => $reason === null,
            'reason' => $reason,
            'mailer' => $mailer,
            'transport' => $transport,
            'host' => $transport === 'smtp' ? ($config['host'] ?? null) : null,
            'port' => $transport === 'smtp' ? (int) ($config['port'] ?? 0) : null,
            'encryption' => $transport === 'smtp' ? ($config['scheme'] ?? null) : null,
            'authenticated' => $transport === 'smtp' && filled($config['username'] ?? null),
            'from_address' => $from,
            'from_name' => (string) config('mail.from.name'),
            'reply_to' => config('lexora.communications.reply_to'),
            'public_app_url' => $publicUrl,
            'public_url_https' => $https,
            'public_url_local' => $local,
            'mail_ui_url' => config('lexora.communications.mail_ui_url') ?: null,
        ];
    }

    /** Throws 503 with a plain explanation when e-mail cannot be sent (the UI keeps Copy link available). */
    public function assertAvailable(): void
    {
        $status = $this->transportStatus();
        if (! $status['available']) {
            throw new HttpException(503, (string) $status['reason']);
        }
    }

    /** A usable single address: RFC-valid, no header-injection characters, no display-name tricks. */
    public static function isValidAddress(?string $address): bool
    {
        return is_string($address) && $address !== '' && strlen($address) <= 255
            && preg_match('/[\r\n<>,;"]/', $address) !== 1
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Display names are reduced to one plain line (no CR/LF, no quotes or brackets). */
    public static function cleanName(string $name): string
    {
        return Str::limit(trim((string) preg_replace('/[\r\n<>"]+/', ' ', $name)), 200, '');
    }

    /**
     * Inserts the 'sending' claim. MUST run inside the caller's transaction with the request row locked: the
     * callers check idempotency, the in-progress index and the cooldown under that lock first; the unique
     * indexes are the backstop.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function claim(array $attributes): EmailDelivery
    {
        $delivery = new EmailDelivery;
        $delivery->forceFill([
            'uuid' => (string) Str::uuid(),
            'channel' => 'email',
            'status' => EmailDeliveryStatus::Sending,
            'attempt_count' => 1,
            'last_attempt_at' => Carbon::now(),
            ...$attributes,
        ])->save();

        return $delivery;
    }

    /** Records a slot that was intentionally not sent (inside the caller's transaction). */
    public function recordSkipped(array $attributes, EmailFailureCategory $reason): EmailDelivery
    {
        $delivery = new EmailDelivery;
        $delivery->forceFill([
            'uuid' => (string) Str::uuid(),
            'channel' => 'email',
            'status' => EmailDeliveryStatus::Skipped,
            'attempt_count' => 0,
            'failure_category' => $reason,
            ...$attributes,
        ])->save();

        return $delivery;
    }

    /** Submits the message to the configured transport. Never throws; never logs the message or the error text. */
    public function send(EmailDelivery $delivery, ClientActionMail $mail): SendOutcome
    {
        if (! self::isValidAddress($delivery->recipient_email)) {
            return SendOutcome::failed(EmailFailureCategory::InvalidRecipient);
        }
        try {
            $sent = Mail::mailer()->to($delivery->recipient_email, $delivery->recipient_name)->send($mail);

            return SendOutcome::accepted($sent?->getMessageId());
        } catch (Throwable $e) {
            return SendOutcome::failed(self::categorize($e));
        }
    }

    /** Maps a transport error to a safe category (the error text itself is discarded). */
    public static function categorize(Throwable $e): EmailFailureCategory
    {
        if ($e instanceof RfcComplianceException) {
            return EmailFailureCategory::InvalidRecipient;
        }
        if ($e instanceof UnexpectedResponseException || ($e instanceof TransportExceptionInterface && $e->getCode() >= 400)) {
            $code = (int) $e->getCode();
            $text = strtolower($e->getMessage());

            return match (true) {
                in_array($code, [530, 534, 535, 538], true) || str_contains($text, 'authenticat') => EmailFailureCategory::Authentication,
                in_array($code, [550, 551, 552, 553, 554, 501], true) && (str_contains($text, 'sender') || str_contains($text, 'mail from')) => EmailFailureCategory::SenderRejected,
                in_array($code, [550, 551, 552, 553, 554], true) => EmailFailureCategory::RecipientRejected,
                $code >= 400 && $code < 500 => EmailFailureCategory::Connection, // temporary server refusal
                default => EmailFailureCategory::Unknown,
            };
        }
        if ($e instanceof TransportExceptionInterface) {
            $text = strtolower($e->getMessage());

            return match (true) {
                str_contains($text, 'timed out') || str_contains($text, 'timeout') => EmailFailureCategory::Timeout,
                str_contains($text, 'authenticat') => EmailFailureCategory::Authentication,
                default => EmailFailureCategory::Connection, // refused, unreachable, DNS, TLS handshake
            };
        }
        if ($e instanceof \InvalidArgumentException || $e instanceof \LogicException) {
            return EmailFailureCategory::Configuration;
        }

        return EmailFailureCategory::Unknown;
    }

    /**
     * Marks a claimed delivery failed (own transaction). Nothing else changes: the request, its status and any
     * previously delivered link stay exactly as they were.
     */
    public function markFailed(EmailDelivery $delivery, EmailFailureCategory $category, ?Matter $matter, ?User $actor, bool $notify, string $what): EmailDelivery
    {
        return DB::transaction(function () use ($delivery, $category, $matter, $actor, $notify, $what) {
            $locked = EmailDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($locked->status !== EmailDeliveryStatus::Sending) {
                return $locked; // already finished (e.g. recovered meanwhile)
            }
            $locked->forceFill([
                'status' => EmailDeliveryStatus::Failed,
                'failed_at' => Carbon::now(),
                'failure_category' => $category,
            ])->save();

            if ($matter !== null) {
                $this->activity->record($matter, ActivityEventType::EmailFailed,
                    "{$what} could not be sent: {$category->label()} No successful delivery was recorded.",
                    $actor, $locked, $this->metadata($locked));
                if ($notify) {
                    $this->notifier->notify(NotificationKind::EmailFailed, $matter,
                        "{$what} to {$locked->recipient_name} failed: {$category->label()}", $this->href($locked), $this->ids($locked));
                }
            }

            return $locked;
        });
    }

    /**
     * Marks a claimed delivery accepted INSIDE the caller's finish transaction (after the link was registered).
     */
    public function markAccepted(EmailDelivery $delivery, ?string $messageId, ?int $linkId, Matter $matter, ?User $actor, string $description, array $extra = []): EmailDelivery
    {
        $locked = EmailDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
        $locked->forceFill([
            'status' => EmailDeliveryStatus::Accepted,
            'accepted_at' => Carbon::now(),
            'provider_message_id' => $messageId !== null ? Str::limit($messageId, 250, '') : null,
            'secure_access_link_id' => $linkId,
        ])->save();
        $this->activity->record($matter, ActivityEventType::EmailAccepted, $description, $actor, $locked, [...$this->metadata($locked), ...$extra]);

        return $locked;
    }

    /**
     * Claims left in 'sending' longer than the stale window belong to a process that stopped mid-send. Their
     * outcome is unknown, so they are closed as failed / interrupted (never as accepted) and the
     * administrators are told to check and resend. Scope: one request, or everything (scheduler).
     */
    public function recoverStale(?string $column = null, ?int $id = null): int
    {
        $cutoff = Carbon::now()->subMinutes(max(1, (int) config('lexora.communications.stale_sending_minutes', 10)));
        $stale = EmailDelivery::query()
            ->where('status', EmailDeliveryStatus::Sending->value)
            ->where('last_attempt_at', '<', $cutoff)
            ->when($column !== null, fn ($q) => $q->where($column, $id))
            ->with('matter')
            ->limit(100)
            ->get();
        foreach ($stale as $delivery) {
            $this->markFailed($delivery, EmailFailureCategory::Interrupted, $delivery->matter, null, true, $delivery->communication_type->label());
        }

        return $stale->count();
    }

    /** @return array<string, mixed> audit metadata: ids, type, recipient snapshot, outcome — never links or bodies */
    public function metadata(EmailDelivery $d): array
    {
        return array_filter([
            'delivery_id' => $d->id,
            'communication_type' => $d->communication_type->value,
            'initiated_by' => $d->initiated_by->value,
            'reminder_slot' => $d->reminder_slot,
            'request_id' => $d->document_request_id,
            'signing_request_id' => $d->signing_request_id,
            'recipient_email' => $d->recipient_email,
            'recipient_name' => $d->recipient_name,
            'status' => $d->status->value,
            'failure_category' => $d->failure_category?->value,
            'attempt' => $d->attempt_count,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, int|null> */
    public function ids(EmailDelivery $d): array
    {
        return ['delivery_id' => $d->id, 'request_id' => $d->document_request_id, 'signing_request_id' => $d->signing_request_id];
    }

    /** Internal page of the request this delivery belongs to. */
    public function href(EmailDelivery $d): string
    {
        return match (true) {
            $d->document_request_id !== null => "/matters/{$d->matter_id}/requests/{$d->document_request_id}",
            $d->signing_request_id !== null => "/matters/{$d->matter_id}/signatures/{$d->signing_request_id}",
            default => '/dashboard',
        };
    }
}
