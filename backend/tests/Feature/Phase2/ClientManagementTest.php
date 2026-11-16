<?php

namespace Tests\Feature\Phase2;

use App\Enums\ActivityEventType;
use App\Enums\MatterStatus;
use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Northwind Trading Ltd.',
            'client_type' => 'organization',
            'email' => 'Legal@Northwind.example.com',
            'phone' => '+1 555 0199 1234',
            'address' => '1 Harbour Street, Example City',
            'reference_number' => 'CL-2001',
        ], $overrides);
    }

    #[Test]
    public function an_admin_can_create_a_client(): void
    {
        $response = $this->actingAsAdmin()->postJson('/api/clients', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Northwind Trading Ltd.')
            ->assertJsonPath('data.client_type', 'organization')
            ->assertJsonPath('data.email', 'legal@northwind.example.com') // normalised
            ->assertJsonPath('data.matters_count', 0)
            ->assertJsonPath('data.active_matters_count', 0);

        $this->assertDatabaseHas('clients', ['id' => $response->json('data.id'), 'reference_number' => 'CL-2001']);
        $event = ActivityEvent::query()->where('event_type', ActivityEventType::ClientCreated->value)->sole();
        $this->assertNull($event->matter_id, 'client events are not attached to an invented matter');
        $this->assertSame('client', $event->subject_type);
    }

    #[Test]
    public function only_name_and_type_are_required(): void
    {
        $this->actingAsAdmin()->postJson('/api/clients', ['name' => 'Ada Example', 'client_type' => 'individual', 'email' => '', 'phone' => ''])
            ->assertCreated()
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.reference_number', null);
    }

    #[Test]
    public function invalid_client_payloads_are_rejected_with_field_errors(): void
    {
        $this->actingAsAdmin()->postJson('/api/clients', [
            'name' => '   ',
            'client_type' => 'partnership',
            'email' => 'not-an-email',
            'phone' => 'call me',
            'reference_number' => 'bad ref!',
            'address' => str_repeat('a', 1001),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'client_type', 'email', 'phone', 'reference_number', 'address']);

        $this->actingAsAdmin()->postJson('/api/clients', $this->validPayload(['phone' => '+1 (2)']))
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);
        $this->actingAsAdmin()->postJson('/api/clients', $this->validPayload(['name' => str_repeat('x', 201)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $this->assertSame(0, Client::count());
    }

    #[Test]
    public function server_managed_attributes_cannot_be_supplied(): void
    {
        $this->actingAsAdmin()->postJson('/api/clients', $this->validPayload(['id' => 999, 'created_at' => '2020-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['id', 'created_at']);
    }

    #[Test]
    public function duplicate_reference_numbers_are_rejected(): void
    {
        Client::factory()->create(['reference_number' => 'CL-2001']);

        $this->actingAsAdmin()->postJson('/api/clients', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference_number.0', 'Another client already uses this reference number.');
    }

    #[Test]
    public function client_details_include_matter_counts(): void
    {
        $client = Client::factory()->create();
        Matter::factory()->for($client)->create(['status' => MatterStatus::Open]);
        Matter::factory()->for($client)->create(['status' => MatterStatus::OnHold]);
        Matter::factory()->for($client)->status(MatterStatus::Closed)->create();
        Matter::factory()->for($client)->status(MatterStatus::Archived)->create();

        $this->actingAsAdmin()->getJson("/api/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.matters_count', 4)
            ->assertJsonPath('data.active_matters_count', 2)
            ->assertJsonPath('data.closed_matters_count', 1)
            ->assertJsonPath('data.archived_matters_count', 1);

        $this->actingAsAdmin()->getJson("/api/matters?client_id={$client->id}")->assertJsonPath('meta.total', 4);
    }

    #[Test]
    public function a_missing_client_is_a_json_404(): void
    {
        $this->actingAsAdmin()->getJson('/api/clients/987654')
            ->assertNotFound()->assertExactJson(['message' => 'The requested resource was not found.']);
    }

    #[Test]
    public function an_admin_can_update_a_client_without_creating_duplicates(): void
    {
        $client = Client::factory()->create(['reference_number' => 'CL-1', 'name' => 'Old Name']);
        Client::factory()->create(['reference_number' => 'CL-2']);

        $this->actingAsAdmin()->patchJson("/api/clients/{$client->id}", ['name' => 'New Name', 'reference_number' => 'CL-1'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.reference_number', 'CL-1'); // keeping its own reference is allowed

        $this->actingAsAdmin()->patchJson("/api/clients/{$client->id}", ['reference_number' => 'CL-2'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reference_number']);

        $this->assertSame(2, Client::count());
        $event = ActivityEvent::query()->where('event_type', ActivityEventType::ClientUpdated->value)->sole();
        $this->assertSame(['name'], $event->metadata['changed_fields']);
    }

    #[Test]
    public function a_client_without_matters_can_be_deleted(): void
    {
        $client = Client::factory()->create();

        $this->actingAsAdmin()->deleteJson("/api/clients/{$client->id}")->assertNoContent();

        $this->assertModelMissing($client);
        $this->assertTrue(ActivityEvent::query()->where('event_type', ActivityEventType::ClientDeleted->value)->exists());
    }

    #[Test]
    public function a_client_with_matters_cannot_be_deleted(): void
    {
        $client = Client::factory()->create();
        Matter::factory()->for($client)->status(MatterStatus::Archived)->create();

        $this->actingAsAdmin()->deleteJson("/api/clients/{$client->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'This client has legal matters and cannot be deleted. Client records with matters are retained.');

        $this->assertModelExists($client);
    }

    #[Test]
    public function client_search_covers_name_email_reference_and_phone(): void
    {
        Client::factory()->create(['name' => 'Alpha', 'email' => 'a@example.com', 'reference_number' => 'CL-7001', 'phone' => '+1 555 0100 0001']);
        Client::factory()->create(['name' => 'Beta', 'email' => 'b@example.com', 'reference_number' => 'CL-7002', 'phone' => '+1 555 0100 0002']);

        $admin = $this->actingAsAdmin();
        $admin->getJson('/api/clients?search=CL-7002')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Beta');
        $admin->getJson('/api/clients?search=a@example')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Alpha');
        $admin->getJson('/api/clients?search=0100 0002')->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function client_mutations_require_authentication(): void
    {
        $client = Client::factory()->create();

        $this->fromFrontend()->postJson('/api/clients', $this->validPayload())->assertUnauthorized();
        $this->fromFrontend()->getJson("/api/clients/{$client->id}")->assertUnauthorized();
        $this->fromFrontend()->patchJson("/api/clients/{$client->id}", ['name' => 'X'])->assertUnauthorized();
        $this->fromFrontend()->deleteJson("/api/clients/{$client->id}")->assertUnauthorized();
        $this->assertModelExists($client);
    }
}
