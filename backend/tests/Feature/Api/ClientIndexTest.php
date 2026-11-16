<?php

namespace Tests\Feature\Api;

use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_clients_alphabetically_with_matter_counts(): void
    {
        $zed = Client::factory()->organization()->create(['name' => 'Zephyr Labs']);
        Client::factory()->individual()->create(['name' => 'Anna Bell']);
        Matter::factory()->for($zed)->create(['status' => MatterStatus::Open]);
        Matter::factory()->for($zed)->status(MatterStatus::Closed)->create();

        $this->actingAsAdmin()->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Anna Bell', 'Zephyr Labs'])
            ->assertJsonPath('data.1.matters_count', 2)
            ->assertJsonPath('data.1.active_matters_count', 1)
            ->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function it_filters_by_type_and_searches_name_email_and_reference(): void
    {
        Client::factory()->organization()->create(['name' => 'Orchard Ventures', 'email' => 'hello@orchard.example.com', 'reference_number' => 'CL-7001']);
        Client::factory()->individual()->create(['name' => 'Mila Torres', 'email' => 'mila@example.org', 'reference_number' => 'CL-7002']);

        $admin = $this->actingAsAdmin();
        $admin->getJson('/api/clients?type=individual')->assertJsonPath('data.*.name', ['Mila Torres']);
        $admin->getJson('/api/clients?search=orchard.example')->assertJsonPath('data.*.name', ['Orchard Ventures']);
        $admin->getJson('/api/clients?search=cl-7002')->assertJsonPath('data.*.name', ['Mila Torres']);
    }

    #[Test]
    public function invalid_filters_are_rejected(): void
    {
        $this->actingAsAdmin()->getJson('/api/clients?type=company&direction=sideways')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'direction']);
    }
}
