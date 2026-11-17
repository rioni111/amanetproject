<?php

namespace App\Services\Requests;

use App\Enums\ActivityEventType;
use App\Enums\DocumentRequestStatus;
use App\Enums\LinkRevocationReason;
use App\Enums\SubmissionReviewStatus;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestSubmission;
use App\Models\Matter;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Review of client submissions: start review → approve, or → request a correction (which issues a
 * fresh upload link). Decisions always name the submission they are about: if a newer submission has
 * arrived, or another administrator decided first (lock_version), the decision is refused with 409
 * instead of silently applying to a different file.
 *
 * Approval means "the requested document was accepted". It does not sign anything (Phase 5).
 */
class DocumentReviewService
{
    public function __construct(
        private readonly DocumentRequestService $requests,
        private readonly SecureAccessLinkService $links,
        private readonly ActivityLogger $activity,
    ) {}

    public function startReview(Matter $matter, DocumentRequest $request, int $submissionId, User $actor, ?int $lockVersion): DocumentRequest
    {
        return DB::transaction(function () use ($matter, $request, $submissionId, $actor, $lockVersion) {
            [$matter, $request] = $this->requests->lock($matter, $request, $lockVersion);
            $this->assertTransition($request, DocumentRequestStatus::UnderReview);
            $submission = $this->latestSubmission($request, $submissionId, SubmissionReviewStatus::Pending);

            $now = Carbon::now();
            $submission->forceFill(['review_status' => SubmissionReviewStatus::InReview, 'review_started_at' => $now, 'review_started_by' => $actor->id])->save();
            $request->forceFill(['status' => DocumentRequestStatus::UnderReview, 'review_started_at' => $now, 'review_started_by' => $actor->id])->save();

            $this->activity->record($matter, ActivityEventType::DocumentReviewStarted,
                "Review started: {$request->title} (submission {$submission->submission_number}, {$submission->version->label()}).",
                $actor, $request, $this->metadata($request, $submission, DocumentRequestStatus::Submitted));

            return $request;
        });
    }

    public function approve(Matter $matter, DocumentRequest $request, int $submissionId, User $actor, ?int $lockVersion): DocumentRequest
    {
        return DB::transaction(function () use ($matter, $request, $submissionId, $actor, $lockVersion) {
            [$matter, $request] = $this->requests->lock($matter, $request, $lockVersion);
            $this->assertTransition($request, DocumentRequestStatus::Approved);
            $submission = $this->latestSubmission($request, $submissionId, SubmissionReviewStatus::InReview);

            $now = Carbon::now();
            $submission->forceFill([
                'review_status' => SubmissionReviewStatus::Approved,
                'reviewed_at' => $now,
                'reviewed_by' => $actor->id,
                'reviewed_by_name' => $actor->name,
            ])->save();
            $request->forceFill([
                'status' => DocumentRequestStatus::Approved,
                'approved_version_id' => $submission->document_version_id, // the exact PDF accepted
                'approved_at' => $now,
                'reviewed_by' => $actor->id,
            ])->save();
            // Nothing more may be uploaded for this request.
            $revoked = $this->links->revokeUsableForRequest($request, LinkRevocationReason::RequestApproved, $actor);

            $this->activity->record($matter, ActivityEventType::DocumentApproved,
                "Document approved: {$request->title} ({$submission->version->label()}).",
                $actor, $request, [...$this->metadata($request, $submission, DocumentRequestStatus::UnderReview), 'links_revoked' => $revoked]);

            return $request;
        });
    }

    /** Rejects the latest submission for correction and returns the fresh upload link for the client. */
    public function requestCorrection(Matter $matter, DocumentRequest $request, int $submissionId, string $reason, User $actor, ?int $lockVersion, ?int $expiresInDays): IssuedLink
    {
        return DB::transaction(function () use ($matter, $request, $submissionId, $reason, $actor, $lockVersion, $expiresInDays) {
            [$matter, $request] = $this->requests->lock($matter, $request, $lockVersion);
            $this->assertTransition($request, DocumentRequestStatus::CorrectionRequested);
            $submission = $this->latestSubmission($request, $submissionId, SubmissionReviewStatus::InReview);

            $now = Carbon::now();
            $submission->forceFill([
                'review_status' => SubmissionReviewStatus::CorrectionRequested,
                'correction_reason' => $reason, // stored with the submission; never copied into history metadata
                'reviewed_at' => $now,
                'reviewed_by' => $actor->id,
                'reviewed_by_name' => $actor->name,
            ])->save();
            $request->forceFill(['status' => DocumentRequestStatus::CorrectionRequested, 'reviewed_by' => $actor->id])->save();

            $this->activity->record($matter, ActivityEventType::CorrectionRequested,
                "Correction requested: {$request->title} ({$submission->version->label()}).",
                $actor, $request, [...$this->metadata($request, $submission, DocumentRequestStatus::UnderReview), 'reason_recorded' => true]);

            // Fresh, independent upload opportunity (any leftover link is revoked by issue()).
            return $this->requests->issue($matter, $request, $actor, $expiresInDays, false);
        });
    }

    private function assertTransition(DocumentRequest $request, DocumentRequestStatus $target): void
    {
        if (! $request->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => ["A request that is {$request->status->label()} cannot become {$target->label()}."],
            ]);
        }
    }

    /** The decision must concern the newest submission, in the expected review state. */
    private function latestSubmission(DocumentRequest $request, int $submissionId, SubmissionReviewStatus $expected): DocumentRequestSubmission
    {
        $latest = $request->submissions()->with('version')->orderByDesc('submission_number')->lockForUpdate()->first();
        if ($latest === null || $latest->id !== $submissionId) {
            throw new ConflictHttpException('A newer submission has arrived for this request. Reload and review the latest file.');
        }
        if ($latest->review_status !== $expected) {
            throw new ConflictHttpException('This submission is no longer awaiting that decision. Reload to see its current state.');
        }

        return $latest;
    }

    /** @return array<string, mixed> */
    private function metadata(DocumentRequest $request, DocumentRequestSubmission $submission, DocumentRequestStatus $from): array
    {
        return [
            'request_id' => $request->id,
            'submission_id' => $submission->id,
            'submission_number' => $submission->submission_number,
            'document_id' => $submission->document_id,
            'version_id' => $submission->document_version_id,
            'version_number' => $submission->version->version_number,
            'from' => $from->value,
            'to' => $request->status->value,
        ];
    }
}
