<?php

namespace Database\Factories;

use App\Enums\MatterStatus;
use App\Enums\Priority;
use App\Models\Client;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Matter>
 */
class MatterFactory extends Factory
{
    public function definition(): array
    {
        $openedAt = Carbon::now()->subDays(fake()->numberBetween(5, 120));

        return [
            'matter_number' => fake()->unique()->bothify('LEX-'.Carbon::now()->year.'-####'),
            'client_id' => Client::factory(),
            'title' => fake()->randomElement(['Supply agreement review', 'Lease renewal', 'Shareholder dispute', 'Trademark filing', 'Employment contract']).' — '.fake()->lastName(),
            'description' => fake()->optional()->paragraph(),
            'matter_type' => fake()->randomElement(['Contract Review', 'Employment Dispute', 'Corporate Registration', 'Property Agreement', 'Commercial Litigation']),
            'status' => MatterStatus::Open,
            'priority' => fake()->randomElement(Priority::cases()),
            'opened_at' => $openedAt,
            'due_at' => fake()->optional(0.8)->dateTimeBetween('+1 day', '+60 days'),
        ];
    }

    public function status(MatterStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'closed_at' => in_array($status, [MatterStatus::Closed, MatterStatus::Archived], true) ? Carbon::now()->subDays(3) : null,
            'archived_at' => $status === MatterStatus::Archived ? Carbon::now()->subDay() : null,
            'archived_from_status' => $status === MatterStatus::Archived ? MatterStatus::Closed : null,
        ]);
    }

    public function dueInDays(int $days): static
    {
        return $this->state(fn () => ['due_at' => Carbon::now()->addDays($days)]);
    }
}
