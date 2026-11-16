<?php

namespace Database\Factories;

use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterNote>
 */
class MatterNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'author_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
