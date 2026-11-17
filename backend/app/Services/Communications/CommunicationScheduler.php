<?php

namespace App\Services\Communications;

use App\Enums\DocumentRequestStatus;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailFailureCategory;
use App\Enums\EmailTrigger;
use App\Enums\LinkRevocationReason;
use App\Enums\MatterStatus;
use App\Enums\NotificationKind;
use App\Enums\SecureLinkPurpose;
use App\Enums\SignerType;
use App\Enums\SigningStatus;
use App\Models\DocumentRequest;
use App\Models\EmailDelivery;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use App\Support\LegalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One run of the communication scheduler (`php artisan lexora:communications`, every 5 minutes):
 *
 *  1. recover interrupted sends (claims stuck in 'sending') → failed / interrupted + notification
 *  2. overdue document requests → one notification each (document_requests.overdue_notified_at)
 *  3. expired signing links of unsigned requests → one notification each (secure_access_links.expiry_notified_at)
 *  4. document request reminders, 5. signing reminders.
 *
 * Reminder safety — every rule is re-checked under the request lock by the mail services before sending:
 *  - only requests still waiting for the CLIENT (pending / correction requested; sent / viewed / started);
 *  - never for archived matters, never after the administrator revoked the latest link on purpose;
 *  - one slot = one stable idempotency key (unique index): a second scheduler process, a re-run or a retry
 *    can never send the same reminder twice;
 *  - at most max_per_request automatic reminders per request, at least min_interval_hours after the last
 *    e-mail of any kind, and only the LATEST due slot (a long-stopped scheduler does not send a burst);
 *  - signing: not while the signer was active within min_interval_hours (a reminder replaces the link and
 *    would interrupt a signer who is mid-way), and only while the current link is still valid.
 * Dates are evaluated in the firm's time zone (LegalDate), timestamps are stored in UTC.
 */
class CommunicationScheduler
{
    public function __construct(
        private readonly EmailDeliveryService $deliveries,
        private readonly DocumentRequestEmailService $documentEmails,
        private readonly SigningEmailService $signingEmails,
        private readonly AdminNotifier $notifier,
    ) {}

    /** @return array<string, int> counts only (no recipients, no links) */
    public function run(bool $dryRun = false): array
    {
        $stats = ['recovered' => 0, 'overdue_notified' => 0, 'expiry_notified' => 0, 'document_reminders' => 0, 'signing_reminders' => 0,
            'reminders_failed' => 0, 'reminders_skipped' => 0, 'reminders_due' => 0];
        if (! $dryRun) {
            $stats['recovered'] = $this->deliveries->recoverStale();
            $stats['overdue_notified'] = $this->notifyOverdue();
            $stats['expiry_notified'] = $this->notifyExpiredSigningLinks();
        }
        if (! config('lexora.communications.reminders.enabled') || ! $this->deliveries->transportStatus()['available']) {
            return $stats;
        }
        foreach ($this->documentCandidates() as [$request, $slot]) {
            $stats['reminders_due']++;
            if (! $dryRun) {
                $this->count($stats, 'document_reminders', fn () => $this->sendDocumentReminder($request, $slot));
            }
        }
        foreach ($this->signingCandidates() as [$request, $slot]) {
            $stats['reminders_due']++;
            if (! $dryRun) {
                $this->count($stats, 'signing_reminders', fn () => $this->sendSigningReminder($request, $slot));
            }
        }

        return $stats;
    }

    /** @param \Closure(): ?EmailDelivery $send */
    private function count(array &$stats, string $key, \Closure $send): void
    {
        try {
            $delivery = $send();
        } catch (Throwable) {
            $stats['reminders_failed']++; // e.g. the request changed under the lock; the next run re-evaluates

            return;
        }
        match ($delivery?->status) {
            EmailDeliveryStatus::Accepted => $stats[$key]++,
            EmailDeliveryStatus::Failed => $stats['reminders_failed']++,
            EmailDeliveryStatus::Skipped => $stats['reminders_skipped']++,
            default => null,
        };
    }

    // ------------------------------------------------------------------ document requests

    /** @return list<array{0: DocumentRequest, 1: string}> */
    public function documentCandidates(): array
    {
        $requests = DocumentRequest::query()
            ->whereIn('status', [DocumentRequestStatus::Pending->value, DocumentRequestStatus::CorrectionRequested->value])
            ->whereHas('matter', fn ($q) => $q->where('status', '<>', MatterStatus::Archived->value))
            ->whereHas('links') // the client has been given (or e-mailed) a link at least once
            ->orderBy('id')
            ->limit(max(1, (int) config('lexora.communications.reminders.batch_size', 50)) * 4)
            ->get();

        $out = [];
        foreach ($requests as $request) {
            $slot = $this->documentSlot($request);
            if ($slot !== null && $this->reminderAllowed('document_request_id', $request->id, $slot['key'])
                && ! $this->latestLinkRevokedManually('document_request_id', $request->id)) {
                $out[] = [$request, $slot['key']];
            }
            if (count($out) >= (int) config('lexora.communications.reminders.batch_size', 50)) {
                break;
            }
        }

        return $out;
    }

    /**
     * The latest reminder slot that is due today (firm time zone), e.g. "due-3:2026-11-20" or "nodue+4".
     *
     * @return array{key: string}|null
     */
    public function documentSlot(DocumentRequest $request): ?array
    {
        $tz = LegalDate::timezone();
        $today = Carbon::now($tz)->startOfDay();
        $cycle = $request->status->value; // a correction starts a new cycle of reminders
        if ($request->due_at !== null) {
            $due = Carbon::instance($request->due_at)->setTimezone($tz)->startOfDay();
            $latest = null;
            foreach ((array) config('lexora.communications.reminders.document_due_offsets', [-3, 0, 2]) as $offset) {
                if ($due->copy()->addDays((int) $offset)->lte($today)) {
                    $latest = (int) $offset;
                }
            }

            return $latest === null ? null : ['key' => sprintf('%s:due%+d:%s', $cycle, $latest, $due->toDateString())];
        }
        $firstContact = SecureAccessLink::query()->where('document_request_id', $request->id)->min('created_at');
        if ($firstContact === null) {
            return null;
        }
        $anchor = Carbon::parse($firstContact)->setTimezone($tz)->startOfDay();
        $latest = null;
        foreach ((array) config('lexora.communications.reminders.document_no_due_days', [4, 10]) as $days) {
            if ($anchor->copy()->addDays((int) $days)->lte($today)) {
                $latest = (int) $days;
            }
        }

        return $latest === null ? null : ['key' => sprintf('%s:nodue+%d', $cycle, $latest)];
    }

    private function sendDocumentReminder(DocumentRequest $request, string $slot): ?EmailDelivery
    {
        $key = "reminder:document_request:{$request->id}:{$slot}";
        if ($this->limitReached('document_request_id', $request->id)) {
            return $this->skipOnce($key, $request->matter_id, ['document_request_id' => $request->id, 'client_id' => $request->client_id,
                'communication_type' => 'document_request_reminder'], $request->client()->value('email'), $request->client()->value('name'), $slot);
        }
        $matter = Matter::query()->findOrFail($request->matter_id);

        return $this->documentEmails->send($matter, $request, null, $key, null, $slot);
    }

    // ------------------------------------------------------------------ signing requests

    /** @return list<array{0: SigningRequest, 1: string}> */
    public function signingCandidates(): array
    {
        $now = Carbon::now();
        $quiet = (int) config('lexora.communications.reminders.min_interval_hours', 24);
        $requests = SigningRequest::query()
            ->where('signer_type', SignerType::Client->value)
            ->whereIn('status', [SigningStatus::Sent->value, SigningStatus::Viewed->value, SigningStatus::Started->value])
            ->whereNotNull('sent_at')
            ->whereHas('matter', fn ($q) => $q->where('status', '<>', MatterStatus::Archived->value))
            ->with('usableLink')
            ->orderBy('id')
            ->limit(max(1, (int) config('lexora.communications.reminders.batch_size', 50)) * 4)
            ->get();

        $out = [];
        foreach ($requests as $request) {
            $link = $request->usableLink;
            if ($link === null || ! $link->isUsable()) {
                continue; // expired or revoked: the administrator is notified instead (no automatic re-issue)
            }
            $lastActivity = max($request->viewed_at?->getTimestamp() ?? 0, $request->started_at?->getTimestamp() ?? 0);
            if ($lastActivity > $now->copy()->subHours($quiet)->getTimestamp()) {
                continue; // the signer is (or was just) active
            }
            $slot = null;
            $beforeExpiry = (int) config('lexora.communications.reminders.signing_before_expiry_days', 1);
            if ($link->expires_at->copy()->subDays($beforeExpiry)->lte($now)) {
                $slot = "before_expiry:{$link->id}";
            } elseif ($request->sent_at->copy()->addDays((int) config('lexora.communications.reminders.signing_after_shared_days', 3))->lte($now)) {
                $slot = 'after_shared';
            }
            if ($slot !== null && $this->reminderAllowed('signing_request_id', $request->id, "reminder:signing_request:{$request->id}:{$slot}")) {
                $out[] = [$request, $slot];
            }
            if (count($out) >= (int) config('lexora.communications.reminders.batch_size', 50)) {
                break;
            }
        }

        return $out;
    }

    private function sendSigningReminder(SigningRequest $request, string $slot): ?EmailDelivery
    {
        $key = "reminder:signing_request:{$request->id}:{$slot}";
        if ($this->limitReached('signing_request_id', $request->id)) {
            return $this->skipOnce($key, $request->matter_id, ['signing_request_id' => $request->id, 'client_id' => $request->client_id,
                'communication_type' => 'signing_reminder'], $request->signer_email, $request->signer_name, $slot);
        }
        $matter = Matter::query()->findOrFail($request->matter_id);

        return $this->signingEmails->send($matter, $request, null, $key, null, $slot);
    }

    // ------------------------------------------------------------------ shared rules

    /** Not yet handled (idempotency key), and the cooldown since the last e-mail has passed. */
    private function reminderAllowed(string $column, int $id, string $keyOrSlot): bool
    {
        $key = str_starts_with($keyOrSlot, 'reminder:') ? $keyOrSlot
            : 'reminder:'.($column === 'document_request_id' ? 'document_request' : 'signing_request').":{$id}:{$keyOrSlot}";
        if (EmailDelivery::query()->where('idempotency_key', $key)->exists()) {
            return false;
        }
        $hours = (int) config('lexora.communications.reminders.min_interval_hours', 24);

        return ! EmailDelivery::query()->where($column, $id)
            ->whereIn('status', [EmailDeliveryStatus::Accepted->value, EmailDeliveryStatus::Sending->value])
            ->where('last_attempt_at', '>', Carbon::now()->subHours($hours))
            ->exists();
    }

    private function limitReached(string $column, int $id): bool
    {
        return EmailDelivery::query()->where($column, $id)
            ->where('initiated_by', EmailTrigger::Automatic->value)
            ->whereIn('status', [EmailDeliveryStatus::Accepted->value, EmailDeliveryStatus::Sending->value])
            ->count() >= max(0, (int) config('lexora.communications.reminders.max_per_request', 3));
    }

    /** An administrator who revoked the latest link meant "stop": automatic reminders respect that. */
    private function latestLinkRevokedManually(string $column, int $id): bool
    {
        $latest = SecureAccessLink::query()->where($column, $id)->orderByDesc('id')->first();

        return $latest !== null && $latest->revocation_reason === LinkRevocationReason::Revoked;
    }

    /** Records "reminder not sent: limit reached" once per slot (the unique key makes it idempotent). */
    private function skipOnce(string $key, int $matterId, array $target, ?string $email, ?string $name, string $slot): ?EmailDelivery
    {
        return DB::transaction(function () use ($key, $matterId, $target, $email, $name, $slot) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
            if (EmailDelivery::query()->where('idempotency_key', $key)->exists()) {
                return null;
            }

            return $this->deliveries->recordSkipped([
                ...$target,
                'matter_id' => $matterId,
                'initiated_by' => EmailTrigger::Automatic,
                'reminder_slot' => $slot,
                'recipient_email' => EmailDeliveryService::isValidAddress($email) ? $email : 'unknown@invalid.invalid',
                'recipient_name' => EmailDeliveryService::cleanName((string) $name),
                'subject' => '(not sent)',
                'idempotency_key' => $key,
            ], EmailFailureCategory::ReminderLimit);
        });
    }

    // ------------------------------------------------------------------ notifications

    private function notifyOverdue(): int
    {
        $count = 0;
        $requests = DocumentRequest::query()
            ->whereIn('status', [DocumentRequestStatus::Pending->value, DocumentRequestStatus::CorrectionRequested->value])
            ->whereNull('overdue_notified_at')
            ->whereNotNull('due_at')->where('due_at', '<', Carbon::now())
            ->whereHas('matter', fn ($q) => $q->where('status', '<>', MatterStatus::Archived->value))
            ->orderBy('id')->limit(200)->pluck('id');
        foreach ($requests as $id) {
            $count += DB::transaction(function () use ($id) {
                $request = DocumentRequest::query()->with('matter')->lockForUpdate()->findOrFail($id);
                if ($request->overdue_notified_at !== null || ! in_array($request->status, [DocumentRequestStatus::Pending, DocumentRequestStatus::CorrectionRequested], true)) {
                    return 0;
                }
                DocumentRequest::query()->whereKey($id)->update(['overdue_notified_at' => Carbon::now()]);
                $due = Carbon::instance($request->due_at)->setTimezone(LegalDate::timezone())->format('j M Y');
                $this->notifier->notify(NotificationKind::RequestOverdue, $request->matter,
                    "\"{$request->title}\" was due on {$due} and the client has not uploaded it yet.",
                    "/matters/{$request->matter_id}/requests/{$request->id}", ['request_id' => $request->id]);

                return 1;
            });
        }

        return $count;
    }

    private function notifyExpiredSigningLinks(): int
    {
        $count = 0;
        $links = SecureAccessLink::query()
            ->where('purpose', SecureLinkPurpose::DocumentSigning->value)
            ->whereNull('revoked_at')->whereNull('consumed_at')->whereNull('expiry_notified_at')
            ->where('expires_at', '<', Carbon::now())
            ->whereHas('signingRequest', fn ($q) => $q->whereIn('status', SigningStatus::activeValues()))
            ->whereHas('matter', fn ($q) => $q->where('status', '<>', MatterStatus::Archived->value))
            ->orderBy('id')->limit(200)->pluck('id');
        foreach ($links as $id) {
            $count += DB::transaction(function () use ($id) {
                $link = SecureAccessLink::query()->with(['signingRequest.document:id,title', 'matter'])->lockForUpdate()->findOrFail($id);
                if ($link->expiry_notified_at !== null || $link->revoked_at !== null || $link->consumed_at !== null) {
                    return 0;
                }
                SecureAccessLink::query()->whereKey($id)->update(['expiry_notified_at' => Carbon::now()]);
                $request = $link->signingRequest;
                $this->notifier->notify(NotificationKind::SigningLinkExpired, $link->matter,
                    "The signing link for {$request->signer_name} (\"{$request->document?->title}\") expired before it was signed. Send a new invitation or generate a new link.",
                    "/matters/{$link->matter_id}/signatures/{$request->id}", ['signing_request_id' => $request->id]);

                return 1;
            });
        }

        return $count;
    }
}
