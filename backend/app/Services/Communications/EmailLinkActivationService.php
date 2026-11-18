<?php

namespace App\Services\Communications;

use App\Enums\ActivityEventType;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentRequestStatus;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailLinkReservationStatus;
use App\Enums\LinkRevocationReason;
use App\Enums\SecureLinkPurpose;
use App\Enums\SigningStatus;
use App\Models\DocumentRequest;
use App\Models\EmailDelivery;
use App\Models\EmailLinkReservation;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use App\Services\ActivityLogger;
use App\Services\Requests\DocumentRequestService;
use App\Services\Requests\PublicLinkException;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 (issue P7-001): activation on first use of a link whose e-mail was sent but never confirmed.
 *
 * Phase 6 registers an e-mailed link only after the SMTP server accepted the message. If the process stopped
 * between that acceptance and the database commit — or the server's answer was lost (timeout) — the client
 * could hold a link that was never registered. The token's hash is now reserved before the submission, and
 * when a public endpoint receives a token that matches no link but a still-PENDING reservation, possession of
 * the token proves the e-mail reached its recipient. The link is then registered exactly as a normal
 * acceptance would have done (request locked; previous link replaced; a closed request gets an already-closed
 * link; signing Ready → Sent), and the event is recorded honestly (email.link_activated).
 *
 * Never activated: discarded reservations (the server refused the message, so the token never left the
 * process), superseded ones (a later e-mail or a newly generated link replaced them) and expired ones.
 * The token itself is never stored.
 */
class EmailLinkActivationService
{
    public function __construct(
        private readonly SecureAccessLinkService $links,
        private readonly DocumentRequestService $requests,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Returns the registered link for this token, or null when no activatable reservation exists (the caller
     * then answers "link invalid", exactly as for an unknown token).
     *
     * @throws PublicLinkException link_expired for an expired, still pending reservation
     */
    public function activate(#[\SensitiveParameter] string $token, SecureLinkPurpose $purpose): ?SecureAccessLink
    {
        $hash = SecureAccessLinkService::hashToken($token);
        $reservation = EmailLinkReservation::query()->where('token_hash', $hash)->where('purpose', $purpose->value)->first();
        if ($reservation === null) {
            return null;
        }
        if ($reservation->status !== EmailLinkReservationStatus::Pending) {
            return $this->registeredLink($reservation);
        }
        if ($reservation->expires_at->isPast()) {
            throw $purpose === SecureLinkPurpose::DocumentUpload
                ? PublicLinkException::because('link_expired')
                : PublicLinkException::signing('link_expired');
        }

        return DB::transaction(function () use ($reservation, $purpose, &$token) {
            // Same lock order as every other writer: matter → request → delivery → reservation.
            $matter = Matter::query()->lockForUpdate()->findOrFail($reservation->matter_id);
            $request = $purpose === SecureLinkPurpose::DocumentUpload
                ? DocumentRequest::query()->whereBelongsTo($matter)->with('client:id,name')->lockForUpdate()->findOrFail($reservation->document_request_id)
                : SigningRequest::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($reservation->signing_request_id);
            $delivery = EmailDelivery::query()->lockForUpdate()->findOrFail($reservation->email_delivery_id);
            $locked = EmailLinkReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== EmailLinkReservationStatus::Pending) {
                return $this->registeredLink($locked); // the sending process finished meanwhile
            }

            $closed = $this->closedReason($matter, $request);
            [$link, $replaced] = $this->links->registerDeliveredLink($purpose, $request, $token, null, $locked->expires_at, $closed);
            $token = null;

            $locked->forceFill([
                'status' => EmailLinkReservationStatus::Activated,
                'resolved_at' => Carbon::now(),
                'secure_access_link_id' => $link->id,
            ])->save();

            $transition = [];
            if ($request instanceof SigningRequest) {
                if ($closed === null && $request->status === SigningStatus::Ready) {
                    $request->forceFill([
                        'status' => SigningStatus::Sent,
                        'sent_at' => Carbon::now(),
                        'delivery_channel' => DeliveryChannel::Email,
                        'delivery_status' => DeliveryStatus::EmailAccepted,
                    ])->save();
                    $transition = ['from' => SigningStatus::Ready->value, 'to' => SigningStatus::Sent->value];
                }
                if ($request->status->isActive()) {
                    SigningRequest::query()->whereKey($request->id)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => Carbon::now()]);
                }
            } else {
                $this->requests->touch($request);
            }

            // Still 'sending' (the sending process stopped before recording anything): the recipient holding the
            // token proves the message was delivered, which is more than an SMTP acceptance. A delivery already
            // closed as failed (interrupted / timeout) stays as recorded — its outcome is final — and this event
            // documents what happened afterwards.
            $outcome = $delivery->status->value;
            if ($delivery->status === EmailDeliveryStatus::Sending) {
                $delivery->forceFill([
                    'status' => EmailDeliveryStatus::Accepted,
                    'accepted_at' => Carbon::now(),
                    'secure_access_link_id' => $link->id,
                ])->save();
                $outcome = 'accepted_on_first_use';
            }

            $this->activity->record($matter, ActivityEventType::EmailLinkActivated,
                "The recipient opened the link from an e-mail whose sending had not been confirmed ({$delivery->communication_type->label()} to {$delivery->recipient_name}). The link was activated on first use.",
                null, $delivery, array_filter([
                    'delivery_id' => $delivery->id,
                    'communication_type' => $delivery->communication_type->value,
                    'request_id' => $delivery->document_request_id,
                    'signing_request_id' => $delivery->signing_request_id,
                    'recipient_email' => $delivery->recipient_email,
                    'delivery_outcome' => $outcome,
                    'failure_category' => $delivery->failure_category?->value,
                    'link_id' => $link->id,
                    'links_replaced' => $replaced,
                    'link_closed' => $closed?->value,
                    ...$transition,
                ], fn ($v) => $v !== null));

            return $link->fresh();
        });
    }

    private function registeredLink(EmailLinkReservation $reservation): ?SecureAccessLink
    {
        return in_array($reservation->status, [EmailLinkReservationStatus::Registered, EmailLinkReservationStatus::Activated], true)
            ? SecureAccessLink::query()->find($reservation->secure_access_link_id)
            : null;
    }

    private function closedReason(Matter $matter, DocumentRequest|SigningRequest $request): ?LinkRevocationReason
    {
        if ($matter->isArchived()) {
            return LinkRevocationReason::MatterArchived;
        }
        if ($request instanceof DocumentRequest) {
            return match ($request->status) {
                DocumentRequestStatus::Approved => LinkRevocationReason::RequestApproved,
                DocumentRequestStatus::Cancelled => LinkRevocationReason::RequestCancelled,
                default => null,
            };
        }

        return $request->status->isActive() ? null : LinkRevocationReason::RequestCancelled;
    }
}
