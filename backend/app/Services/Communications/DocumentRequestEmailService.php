<?php

namespace App\Services\Communications;

use App\Enums\DocumentRequestStatus;
use App\Enums\EmailCommunicationType;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailFailureCategory;
use App\Enums\EmailTrigger;
use App\Enums\LinkRevocationReason;
use App\Enums\SecureLinkPurpose;
use App\Mail\ClientActionMail;
use App\Models\DocumentRequest;
use App\Models\EmailDelivery;
use App\Models\Matter;
use App\Models\User;
use App\Services\Requests\DocumentRequestService;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * E-mails for client document requests (Phase 4 workflow): the request itself, correction requests and the
 * scheduler's reminders. The recipient is ALWAYS the request's client (the matter's client) — never an
 * address from the API payload. See EmailDeliveryService for the claim → send → finish architecture.
 */
class DocumentRequestEmailService
{
    public function __construct(
        private readonly EmailDeliveryService $deliveries,
        private readonly SecureAccessLinkService $links,
        private readonly DocumentRequestService $requests,
        private readonly MailComposer $composer,
    ) {}

    /**
     * Sends the request / correction e-mail (manual) or a reminder (automatic, $reminderSlot set).
     * Returns the delivery (accepted or failed), the earlier delivery for a replayed idempotency key, or
     * null when an automatic reminder was not due after all (nothing sent, nothing recorded).
     */
    public function send(Matter $matter, DocumentRequest $request, ?User $actor, ?string $idempotencyKey = null,
        ?int $expiresInDays = null, ?string $reminderSlot = null): ?EmailDelivery
    {
        $automatic = $reminderSlot !== null;
        $this->deliveries->assertAvailable();
        $this->deliveries->recoverStale('document_request_id', $request->id);
        $key = $automatic ? (string) $idempotencyKey : 'manual:document_request:'.$request->id.':'.($idempotencyKey ?? Str::uuid());
        // The token exists only in memory (and, once sent, in the message); its hash is reserved with the claim.
        $token = SecureAccessLinkService::generateToken();
        $expiresAt = Carbon::now()->addDays($this->links->expiryDays($expiresInDays))->startOfMinute();

        /** @var array{0: EmailDelivery, 1: bool}|null $claim [delivery, replayed] */
        $claim = DB::transaction(function () use ($matter, $request, $actor, $automatic, $key, $reminderSlot, $token, $expiresAt) {
            $matter = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
            $request = DocumentRequest::query()->whereBelongsTo($matter)->with('client:id,name,email')->lockForUpdate()->findOrFail($request->getKey());

            $existing = EmailDelivery::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $automatic ? null : [$existing, true]; // a repeated click / retried request: no second e-mail
            }
            $problem = $this->ineligibility($matter, $request);
            if ($problem !== null) {
                if ($automatic) {
                    return null;
                }
                throw new ConflictHttpException($problem);
            }
            $type = $automatic ? EmailCommunicationType::DocumentRequestReminder
                : ($request->status === DocumentRequestStatus::CorrectionRequested ? EmailCommunicationType::DocumentCorrection : EmailCommunicationType::DocumentRequest);
            $attributes = [
                'matter_id' => $matter->id,
                'client_id' => $request->client_id,
                'document_request_id' => $request->id,
                'communication_type' => $type,
                'initiated_by' => $automatic ? EmailTrigger::Automatic : EmailTrigger::Manual,
                'reminder_slot' => $reminderSlot,
                'recipient_email' => Str::limit((string) $request->client->email, 255, ''),
                'recipient_name' => EmailDeliveryService::cleanName($request->client->name),
                'subject' => $this->composer->subject($type),
                'idempotency_key' => $key,
                'created_by' => $actor?->id,
            ];
            if (! EmailDeliveryService::isValidAddress($request->client->email)) {
                if ($automatic) {
                    return [$this->deliveries->recordSkipped($attributes, EmailFailureCategory::InvalidRecipient), true];
                }
                throw ValidationException::withMessages(['recipient' => ['The client has no valid e-mail address. Update the client\'s e-mail address, or copy the link and share it yourself.']]);
            }
            if (EmailDelivery::query()->where('document_request_id', $request->id)->where('status', EmailDeliveryStatus::Sending->value)->exists()) {
                if ($automatic) {
                    return null;
                }
                throw new ConflictHttpException('An e-mail for this request is being sent right now. Wait a moment and reload.');
            }
            if (! $automatic) {
                $this->assertCooldown($request, $type);
            }

            $delivery = $this->deliveries->claim($attributes);
            $this->deliveries->reserve($delivery, SecureLinkPurpose::DocumentUpload, $token, $expiresAt);

            return [$delivery, false];
        });

        if ($claim === null) {
            return null;
        }
        [$delivery, $replayed] = $claim;
        if ($replayed) {
            return $delivery;
        }

        // ---- send: token only in memory; no transaction open while talking to the SMTP server ----
        $request->loadMissing('client:id,name,email');
        $content = $this->composer->documentRequest($delivery->communication_type, $request, $delivery->recipient_name,
            $this->links->uploadUrl($token), $expiresAt, $reminderSlot);
        $outcome = $this->deliveries->send($delivery, new ClientActionMail($content, $delivery->uuid, $automatic));
        unset($content);

        if (! $outcome->accepted) {
            unset($token);

            return $this->deliveries->markFailed($delivery, $outcome->failure, $matter, $actor, $automatic, $delivery->communication_type->label());
        }

        // ---- finish: register the delivered link (hash only) and record the outcome, atomically ----
        return $this->deliveries->finishAccepted($delivery, function () use ($matter, $request, $actor, $delivery, $outcome, &$token, $expiresAt) {
            $matter = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
            $request = DocumentRequest::query()->whereBelongsTo($matter)->with('client:id,name')->lockForUpdate()->findOrFail($request->getKey());
            if (($done = $this->deliveries->finishedOnFirstUse($delivery)) !== null) {
                return $done; // the client already used the link (activated on first use)
            }
            // The request may have closed while the message was being sent: the link is then stored already
            // revoked (the client sees why), never usable.
            $closed = match (true) {
                $matter->isArchived() => LinkRevocationReason::MatterArchived,
                $request->status === DocumentRequestStatus::Approved => LinkRevocationReason::RequestApproved,
                $request->status === DocumentRequestStatus::Cancelled => LinkRevocationReason::RequestCancelled,
                default => null,
            };
            [$link, $replaced] = $this->links->registerDeliveredLink(SecureLinkPurpose::DocumentUpload, $request, $token, $actor, $expiresAt, $closed);
            $token = null;
            $this->requests->touch($request);

            $what = match ($delivery->communication_type) {
                EmailCommunicationType::DocumentCorrection => 'Correction request e-mail',
                EmailCommunicationType::DocumentRequestReminder => 'Reminder e-mail',
                default => 'Document request e-mail',
            };

            return $this->deliveries->markAccepted($delivery, $outcome->messageId, $link->id, $matter, $actor,
                "{$what} accepted by the mail server for {$delivery->recipient_name}: {$request->title}.", array_filter([
                    'link_id' => $link->id,
                    'link_expires_at' => $link->expires_at->toIso8601String(),
                    'links_replaced' => $replaced,
                    'link_closed' => $closed?->value,
                ], fn ($v) => $v !== null));
        });
    }

    /** Why this request cannot be e-mailed now (null = it can). */
    public function ineligibility(Matter $matter, DocumentRequest $request): ?string
    {
        return match (true) {
            $matter->isArchived() => 'This matter is archived and read-only. Restore it to contact the client.',
            $request->status === DocumentRequestStatus::Submitted, $request->status === DocumentRequestStatus::UnderReview => 'The client has already submitted a file. Review it, or request a correction to e-mail a new link.',
            ! $request->status->acceptsUploads() => "This request is {$request->status->label()}; no e-mail can be sent.",
            default => null,
        };
    }

    /**
     * Double-send brake: the SAME kind of e-mail for the same request at most once per cooldown. A different,
     * state-changing message (a correction right after the request e-mail) is not blocked.
     */
    private function assertCooldown(DocumentRequest $request, EmailCommunicationType $type): void
    {
        $seconds = max(0, (int) config('lexora.communications.manual_cooldown_seconds', 60));
        $recent = EmailDelivery::query()->where('document_request_id', $request->id)
            ->where('communication_type', $type->value)
            ->where('status', EmailDeliveryStatus::Accepted->value)
            ->where('accepted_at', '>', Carbon::now()->subSeconds($seconds))
            ->exists();
        if ($recent) {
            throw new ConflictHttpException('An e-mail for this request was accepted by the mail server moments ago. Wait a minute before sending another one.');
        }
    }
}
