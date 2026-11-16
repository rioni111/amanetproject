<?php

namespace Database\Factories;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'title' => fake()->randomElement(['Draft engagement letter', 'Collect signed NDA', 'Review indemnity clause', 'File court submission', 'Prepare board resolution']),
            'description' => fake()->optional()->sentence(),
            'status' => TaskStatus::Todo,
            'priority' => fake()->randomElement(Priority::cases()),
            'due_at' => fake()->optional(0.7)->dateTimeBetween('-5 days', '+30 days'),
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Completed, 'completed_at' => Carbon::now()->subDay()]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::InProgress]);
    }
}
