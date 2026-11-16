<?php

namespace App\Services;

use App\Enums\ActivityEventType;
use App\Enums\ActorType;
use App\Models\ActivityEvent;
use App\Models\Matter;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Single entry point for appending matter history. Later phases call this from
 * controllers/services inside the same DB transaction as the change they record.
 */
class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?Matter $matter,
        ActivityEventType $type,
        string $description,
        ?User $actor = null,
        ?Model $subject = null,
        array $metadata = [],
        ?DateTimeInterface $occurredAt = null,
    ): ActivityEvent {
        return ActivityEvent::create([
            'matter_id' => $matter?->getKey(),
            'actor_type' => $actor ? ActorType::Admin : ActorType::System,
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor?->name ?? 'System',
            'event_type' => $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'metadata' => $metadata ?: null,
            'occurred_at' => $occurredAt ? Carbon::instance($occurredAt) : Carbon::now(),
        ]);
    }
}
