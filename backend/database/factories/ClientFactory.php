<?php

namespace Database\Factories;

use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(ClientType::cases());

        return [
            'name' => $type === ClientType::Organization ? fake()->company() : fake()->name(),
            'client_type' => $type,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional(0.8)->numerify('+1 555 01## ####'),
            'address' => fake()->optional(0.6)->address(),
            'reference_number' => fake()->unique()->bothify('CL-####'),
        ];
    }

    public function individual(): static
    {
        return $this->state(fn () => ['client_type' => ClientType::Individual, 'name' => fake()->name()]);
    }

    public function organization(): static
    {
        return $this->state(fn () => ['client_type' => ClientType::Organization, 'name' => fake()->company()]);
    }
}
