<?php

namespace App\Http\Resources;

use App\Enums\DocumentRequestStatus;
use App\Enums\SubmissionReviewStatus;
use App\Models\DocumentRequest;
use App\Support\LegalDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Client document request for administrators. `allowed_actions` tells the UI which controls to offer;
 * the server re-validates every action against the current state.
 *
 * @mixin DocumentRequest
 */
class DocumentRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $matterArchived = $this->relationLoaded('matter') && $this->matter->isArchived();
        $latest = $this->relationLoaded('latestSubmission') ? $this->latestSubmission : null;
        $usable = $this->relationLoaded('usableLink') ? $this->usableLink : null;
        $writable = ! $matterArchived && $this->status->isOpen();

        return [
            'id' => $this->id,
            'matter_id' => $this->matter_id,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'requested_category' => $this->requested_category->value,
            'requested_category_label' => $this->requested_category->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->status->isOpen(),
            'is_overdue' => $this->isOverdue(),
            'due_at' => $this->due_at?->toIso8601String(),
            'due_date' => LegalDate::toDate($this->due_at),
            'document_id' => $this->document_id,
            'submissions_count' => $this->submissions_count,
            'last_submitted_at' => $this->last_submitted_at?->toIso8601String(),
            'review_started_at' => $this->review_started_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_version' => $this->whenLoaded('approvedVersion', fn () => $this->approvedVersion
                ? ['id' => $this->approvedVersion->id, 'version_number' => $this->approvedVersion->version_number, 'label' => $this->approvedVersion->label()]
                : null),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'client' => $this->whenLoaded('client', fn () => array_filter(['id' => $this->client->id, 'name' => $this->client->name, 'email' => $this->client->email ?? null], fn ($v) => $v !== null)),
            'matter' => $this->whenLoaded('matter', fn () => [
                'id' => $this->matter->id,
                'matter_number' => $this->matter->matter_number,
                'title' => $this->matter->title,
                'status' => $this->matter->status->value,
            ]),
            'document' => $this->whenLoaded('document', fn () => $this->document ? [
                'id' => $this->document->id,
                'title' => $this->document->title,
                'status' => $this->document->status->value,
                'versions_count' => $this->document->latest_version_number,
            ] : null),
            'latest_submission' => $this->when($this->relationLoaded('latestSubmission'), fn () => $latest ? new DocumentRequestSubmissionResource($latest) : null),
            'active_link' => $this->when($this->relationLoaded('usableLink'), fn () => $usable ? new SecureAccessLinkResource($usable) : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'allowed_actions' => [
                'edit' => $writable,
                'issue_link' => $writable && $this->status->acceptsUploads() && ($usable === null || ! $usable->isUsable()),
                'rotate_link' => $writable && $this->status->acceptsUploads(),
                'revoke_link' => $usable !== null && $usable->revoked_at === null && $usable->consumed_at === null,
                'start_review' => $writable && $this->status === DocumentRequestStatus::Submitted && $latest?->review_status === SubmissionReviewStatus::Pending,
                'approve' => $writable && $this->status === DocumentRequestStatus::UnderReview && $latest?->review_status === SubmissionReviewStatus::InReview,
                'request_correction' => $writable && $this->status === DocumentRequestStatus::UnderReview && $latest?->review_status === SubmissionReviewStatus::InReview,
                'cancel' => $writable,
                // Phase 6: e-mail the request / correction to the client (server picks the client's address).
                'send_email' => $writable && $this->status->acceptsUploads(),
            ],
        ];
    }
}
