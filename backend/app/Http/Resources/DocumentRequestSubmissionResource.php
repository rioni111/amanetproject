<?php

namespace App\Http\Resources;

use App\Models\DocumentRequestSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One client submission (administrators only). The client message and correction reason are
 * confidential matter content: they appear here and nowhere in the activity history.
 *
 * @mixin DocumentRequestSubmission
 */
class DocumentRequestSubmissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_request_id' => $this->document_request_id,
            'submission_number' => $this->submission_number,
            'document_id' => $this->document_id,
            'version' => new DocumentVersionResource($this->whenLoaded('version')),
            'client_message' => $this->client_message,
            'submitted_by' => $this->submitted_by_label,
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'review_status' => $this->review_status->value,
            'review_status_label' => $this->review_status->label(),
            'review_started_at' => $this->review_started_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by_name !== null ? ['id' => $this->reviewed_by, 'name' => $this->reviewed_by_name] : null,
            'correction_reason' => $this->correction_reason,
        ];
    }
}
