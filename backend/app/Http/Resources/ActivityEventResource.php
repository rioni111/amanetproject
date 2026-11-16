<?php

namespace App\Http\Resources;

use App\Models\ActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One history entry. Metadata only ever contains non-confidential values (status/priority codes,
 * dates, titles, changed field names) — note bodies and descriptions are never recorded.
 *
 * @mixin ActivityEvent
 */
class ActivityEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type->value,
            'description' => $this->description,
            'actor' => ['type' => $this->actor_type->value, 'label' => $this->actor_label],
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'metadata' => $this->metadata ?? (object) [],
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
