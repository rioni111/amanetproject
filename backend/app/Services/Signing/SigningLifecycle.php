<?php

namespace App\Services\Signing;

use App\Enums\ActivityEventType;
use App\Enums\LinkRevocationReason;
use App\Enums\SigningCancellationReason;
use App\Enums\SigningStatus;
use App\Models\Document;
use App\Models\Matter;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Support\Carbon;

/**
 * Closing unfinished signing requests. Called inside the caller's transaction, after the caller has
 * locked the matter (and document): document archived, newer version added, matter archived, or an
 * administrator's cancellation. Cancellation revokes the request's usable links at the same moment;
 * nothing ever re-activates a cancelled request or a revoked link.
 */
class SigningLifecycle
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly SecureAccessLinkService $links,
    ) {}

    public function cancel(Matter $matter, SigningRequest $request, SigningCancellationReason $reason, ?User $actor): void
    {
        $from = $request->status;
        $request->forceFill([
            'status' => SigningStatus::Cancelled,
            'cancelled_at' => Carbon::now(),
            'cancellation_reason' => $reason,
            'cancelled_by' => $actor?->id,
        ])->save();
        $revoked = $this->links->revokeUsableForSigningRequest($request,
            $reason === SigningCancellationReason::MatterArchived ? LinkRevocationReason::MatterArchived : LinkRevocationReason::RequestCancelled,
            $actor);

        $this->activity->record($matter, ActivityEventType::SigningCancelled,
            "Signing request cancelled ({$reason->label()}): {$request->signer_name}.", $actor, $request, [
                'signing_request_id' => $request->id,
                'document_id' => $request->document_id,
                'source_version_id' => $request->document_version_id,
                'from' => $from->value,
                'to' => SigningStatus::Cancelled->value,
                'reason' => $reason->value,
                'links_revoked' => $revoked,
            ]);
    }

    /** @return int number of requests cancelled */
    public function cancelActiveForDocument(Matter $matter, Document $document, SigningCancellationReason $reason, ?User $actor): int
    {
        $requests = SigningRequest::query()->where('document_id', $document->id)
            ->whereIn('status', SigningStatus::activeValues())->lockForUpdate()->get();
        foreach ($requests as $request) {
            $this->cancel($matter, $request, $reason, $actor);
        }

        return $requests->count();
    }

    /** @return int number of requests cancelled */
    public function cancelActiveForMatter(Matter $matter, SigningCancellationReason $reason, ?User $actor): int
    {
        $requests = SigningRequest::query()->where('matter_id', $matter->id)
            ->whereIn('status', SigningStatus::activeValues())->lockForUpdate()->get();
        foreach ($requests as $request) {
            $this->cancel($matter, $request, $reason, $actor);
        }

        return $requests->count();
    }
}
