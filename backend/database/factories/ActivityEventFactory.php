<?php

namespace Database\Factories;

use App\Enums\ActivityEventType;
use App\Enums\ActorType;
use App\Models\ActivityEvent;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityEvent>
 */
class ActivityEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'actor_type' => ActorType::System,
            'actor_id' => null,
            'actor_label' => 'System',
            'event_type' => ActivityEventType::MatterCreated,
            'description' => 'Matter opened.',
            'occurred_at' => now(),
        ];
    }
}
