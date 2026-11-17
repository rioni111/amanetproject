<?php

namespace App\Services\Communications;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Enums\EmailCommunicationType;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailFailureCategory;
use App\Enums\EmailTrigger;
use App\Enums\LinkRevocationReason;
use App\Enums\SecureLinkPurpose;
use App\Enums\SigningStatus;
use App\Mail\ClientActionMail;
use App\Models\EmailDelivery;
use App\Models\Matter;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Signing invitation and signing reminder e-mails (Phase 5 workflow).
 *
 * Recipient: the signing request's signer e-mail SNAPSHOT (immutable by database trigger) — never an address
 * from the payload, never a later edit of the client record. A different recipient requires cancelling the
 * request and creating a new one.
 *
 * Progress: "Sent" is reached when the invitation e-mail is ACCEPTED by the SMTP server (not when it is
 * prepared or attempted); delivery_channel = email, delivery_status = email_accepted. Progress never moves
 * backwards: if the signer already viewed or started (through an earlier link), the status is left alone.
 */
class SigningEmailService
{
    public function __construct(
        private readonly EmailDeliveryService $deliveries,
        private readonly SecureAccessLinkService $links,
        private readonly MailComposer $composer,
    ) {}

    public function send(Matter $matter, SigningRequest $request, ?User $actor, ?string $idempotencyKey = null,
        ?int $expiresInDays = null, ?string $reminderSlot = null): ?EmailDelivery
    {
        $automatic = $reminderSlot !== null;
        $this->deliveries->assertAvailable();
        $this->deliveries->recoverStale('signing_request_id', $request->id);
        $key = $automatic ? (string) $idempotencyKey : 'manual:signing_request:'.$request->id.':'.($idempotencyKey ?? Str::uuid());

        $claim = DB::transaction(function () use ($matter, $request, $actor, $automatic, $key, $reminderSlot) {
            $matter = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
            $request = SigningRequest::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($request->getKey());

            $existing = EmailDelivery::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $automatic ? null : [$existing, true];
            }
            $problem = $this->ineligibility($matter, $request);
            if ($problem !== null) {
                if ($automatic) {
                    return null;
                }
                throw new ConflictHttpException($problem);
            }
            $type = $automatic ? EmailCommunicationType::SigningReminder : EmailCommunicationType::SigningInvitation;
            $attributes = [
                'matter_id' => $matter->id,
                'client_id' => $request->client_id,
                'signing_request_id' => $request->id,
                'communication_type' => $type,
                'initiated_by' => $automatic ? EmailTrigger::Automatic : EmailTrigger::Manual,
                'reminder_slot' => $reminderSlot,
                'recipient_email' => Str::limit((string) $request->signer_email, 255, ''),
                'recipient_name' => EmailDeliveryService::cleanName($request->signer_name),
                'subject' => $this->composer->subject($type),
                'idempotency_key' => $key,
                'created_by' => $actor?->id,
            ];
            if (! EmailDeliveryService::isValidAddress($request->signer_email)) {
                if ($automatic) {
                    return [$this->deliveries->recordSkipped($attributes, EmailFailureCategory::InvalidRecipient), true];
                }
                throw ValidationException::withMessages(['recipient' => ['No valid e-mail address is recorded for this signer. The signer of a request cannot be changed: cancel it and create a new request with the signer\'s e-mail address, or copy the link and share it yourself.']]);
            }
            if (EmailDelivery::query()->where('signing_request_id', $request->id)->where('status', EmailDeliveryStatus::Sending->value)->exists()) {
                if ($automatic) {
                    return null;
                }
                throw new ConflictHttpException('An invitation for this request is being sent right now. Wait a moment and reload.');
            }
            if (! $automatic) {
                $seconds = max(0, (int) config('lexora.communications.manual_cooldown_seconds', 60));
                $recent = EmailDelivery::query()->where('signing_request_id', $request->id)
                    ->where('status', EmailDeliveryStatus::Accepted->value)
                    ->where('accepted_at', '>', Carbon::now()->subSeconds($seconds))->exists();
                if ($recent) {
                    throw new ConflictHttpException('An invitation for this request was accepted by the mail server moments ago. Wait a minute before sending another one.');
                }
            }

            return [$this->deliveries->claim($attributes), false];
        });

        if ($claim === null) {
            return null;
        }
        [$delivery, $replayed] = $claim;
        if ($replayed) {
            return $delivery;
        }

        $token = SecureAccessLinkService::generateToken();
        $days = $this->links->expiryDays($expiresInDays ?? (int) config('lexora.signing.link_expiry_days', 7));
        $expiresAt = Carbon::now()->addDays($days)->startOfMinute();
        $documentTitle = (string) $request->document()->value('title');
        $content = $this->composer->signing($delivery->communication_type, $request, $documentTitle, $this->links->signingUrl($token), $expiresAt);
        $outcome = $this->deliveries->send($delivery, new ClientActionMail($content, $delivery->uuid, $automatic));
        unset($content);

        if (! $outcome->accepted) {
            unset($token);

            return $this->deliveries->markFailed($delivery, $outcome->failure, $matter, $actor, $automatic, $delivery->communication_type->label());
        }

        return DB::transaction(function () use ($matter, $request, $actor, $delivery, $outcome, &$token, $expiresAt, $documentTitle) {
            $matter = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
            $request = SigningRequest::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($request->getKey());
            $closed = match (true) {
                $matter->isArchived() => LinkRevocationReason::MatterArchived,
                ! $request->status->isActive() => LinkRevocationReason::RequestCancelled, // signed or cancelled meanwhile
                default => null,
            };
            [$link, $replaced] = $this->links->registerDeliveredLink(SecureLinkPurpose::DocumentSigning, $request, $token, $actor, $expiresAt, $closed);
            $token = null;

            $from = $request->status;
            $transition = [];
            if ($closed === null && $from === SigningStatus::Ready) {
                $request->forceFill([
                    'status' => SigningStatus::Sent,
                    'sent_at' => Carbon::now(),
                    'delivery_channel' => DeliveryChannel::Email,
                    'delivery_status' => DeliveryStatus::EmailAccepted,
                ])->save();
                $transition = ['from' => SigningStatus::Ready->value, 'to' => SigningStatus::Sent->value];
            }
            if ($request->status->isActive()) { // signed / cancelled rows are final (database trigger)
                SigningRequest::query()->whereKey($request->id)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => Carbon::now()]);
            }

            $what = $delivery->communication_type === EmailCommunicationType::SigningReminder ? 'Signing reminder' : 'Signing invitation e-mail';

            return $this->deliveries->markAccepted($delivery, $outcome->messageId, $link->id, $matter, $actor,
                "{$what} accepted by the mail server for {$delivery->recipient_name}: {$documentTitle}.", array_filter([
                    'link_id' => $link->id,
                    'link_expires_at' => $link->expires_at->toIso8601String(),
                    'links_replaced' => $replaced,
                    'link_closed' => $closed?->value,
                    ...$transition,
                ], fn ($v) => $v !== null));
        });
    }

    public function ineligibility(Matter $matter, SigningRequest $request): ?string
    {
        return match (true) {
            $matter->isArchived() => 'This matter is archived and read-only. Restore it to contact the signer.',
            ! $request->isClientSigner() => 'Administrator signing requests are signed inside the workspace; there is nothing to e-mail.',
            $request->status === SigningStatus::Signed => 'This document has already been signed; no invitation is needed.',
            ! $request->status->isActive() => "This signing request is {$request->status->label()}; no invitation can be sent.",
            default => null,
        };
    }
}
