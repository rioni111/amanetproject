<?php

namespace App\Http\Resources;

use App\Enums\MatterStatus;
use App\Models\Matter;
use App\Support\LegalDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matter representation. Timestamps are ISO-8601 (UTC); `opened_date` / `due_date` are the
 * calendar dates in the firm's time zone and are what forms edit (see App\Support\LegalDate).
 *
 * @mixin Matter
 */
class MatterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matter_number' => $this->matter_number,
            'title' => $this->title,
            'description' => $this->description,
            'matter_type' => $this->matter_type,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'is_active' => $this->status->isActive(),
            'is_overdue' => $this->isOverdue(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opened_date' => LegalDate::toDate($this->opened_at),
            'due_at' => $this->due_at?->toIso8601String(),
            'due_date' => LegalDate::toDate($this->due_at),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'archived_from_status' => $this->archived_from_status?->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            // What the status control may offer next (the server re-validates every change).
            'allowed_transitions' => array_map(fn (MatterStatus $s) => $s->value, $this->status->allowedTransitions()),
            'client' => new ClientSummaryResource($this->whenLoaded('client')),
            'open_tasks_count' => $this->whenHas('open_tasks_count', fn () => (int) $this->open_tasks_count),
            'overdue_tasks_count' => $this->whenHas('overdue_tasks_count', fn () => (int) $this->overdue_tasks_count),
            'completed_tasks_count' => $this->whenHas('completed_tasks_count', fn () => (int) $this->completed_tasks_count),
            'tasks_count' => $this->whenCounted('tasks'),
            'notes_count' => $this->whenCounted('notes'),
            'documents_count' => $this->whenHas('documents_count', fn () => (int) $this->documents_count),
            'requests' => $this->when($this->hasAttribute('requests_open_count'), fn () => [
                'open' => (int) $this->requests_open_count,
                'pending' => (int) $this->requests_pending_count,
                'awaiting_review' => (int) $this->requests_awaiting_review_count,
                'correction_requested' => (int) $this->requests_correction_count,
                'approved' => (int) $this->requests_approved_count,
                'overdue' => (int) $this->requests_overdue_count,
            ]),
        ];
    }
}
