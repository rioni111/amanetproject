<?php

namespace App\Http\Resources;

use App\Models\Matter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Matter */
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
            'due_at' => $this->due_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'client' => new ClientSummaryResource($this->whenLoaded('client')),
            'open_tasks_count' => $this->whenHas('open_tasks_count', fn () => (int) $this->open_tasks_count),
        ];
    }
}
