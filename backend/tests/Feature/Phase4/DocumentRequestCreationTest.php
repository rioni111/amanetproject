<?php

namespace Tests\Feature\Phase4;

use App\Enums\ActivityEventType;
use App\Enums\MatterStatus;
use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Services\MatterService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RequestWorkflow;
use Tests\TestCase;

/** Scenario A (backend): creating client document requests. */
class DocumentRequestCreationTest extends TestCase
{
    use RefreshDatabase, RequestWorkflow;

    #[Test]
    public function an_admin_creates_a_request_for_the_matters_client(): void
    {
        $matter = $this->matterFor('Northwind Holdings Ltd');
        $today = Carbon::now('UTC')->addDays(10)->format('Y-m-d');

        $response = $this->createRequest($matter, ['due_date' => $today], issueLink: false)
            ->assertCreated()
            ->assertJsonPath('data.matter_id', $matter->id)
            ->assertJsonPath('data.client.id', $matter->client_id)
            ->assertJsonPath('data.client.name', 'Northwind Holdings Ltd')
            ->assertJsonPath('data.title', 'Signed Service Agreement')
            ->assertJsonPath('data.requested_category', 'agreements')
            ->assertJsonPath('data.requested_category_label', 'Agreements')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Awaiting client')
            ->assertJsonPath('data.due_date', $today)
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.submissions_count', 0)
            ->assertJsonPath('data.active_link', null)
            ->assertJsonPath('data.created_by.name', 'Elena Marsh')
            ->assertJsonPath('data.allowed_actions.issue_link', true)
            ->assertJsonPath('link', null);

        $request = DocumentRequest::query()->sole();
        $this->assertSame($matter->client_id, $request->client_id);
        $this->assertSame(1, $request->lock_version);

        $event = ActivityEvent::query()->where('event_type', ActivityEventType::DocumentRequested->value)->sole();
        $this->assertSame($matter->id, $event->matter_id);
        $this->assertSame('admin', $event->actor_type->value);
        $this->assertSame('Document requested from the client: Signed Service Agreement.', $event->description);
        $this->assertEquals(['request_id' => $request->id, 'category' => 'agreements', 'due_date' => $today], $event->metadata);

        // Persisted: visible again in the matter list and the workspace list.
        $this->actingAsAdmin($this->admin())->getJson("/api/matters/{$matter->id}/document-requests")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $request->id);
        $this->getJson('/api/document-requests')->assertOk()->assertJsonPath('data.0.matter.matter_number', $matter->matter_number);
        $this->assertNotNull($response->json('data.lock_version'));
    }

    #[Test]
    public function invalid_input_and_server_managed_fields_are_rejected(): void
    {
        $matter = $this->matterFor();
        $other = Client::factory()->create();
        $yesterday = Carbon::now('UTC')->subDay()->format('Y-m-d');

        $this->createRequest($matter, ['title' => '   ', 'instructions' => '', 'requested_category' => 'tax_returns', 'due_date' => $yesterday])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'instructions', 'requested_category', 'due_date']);
        $this->createRequest($matter, ['due_date' => '31/12/2030'])->assertUnprocessable()->assertJsonValidationErrors(['due_date']);
        $this->createRequest($matter, ['instructions' => str_repeat('a', 5001)])->assertUnprocessable()->assertJsonValidationErrors(['instructions']);
        $this->createRequest($matter, ['link_expires_in_days' => 31])->assertUnprocessable()->assertJsonValidationErrors(['link_expires_in_days']);
        $this->createRequest($matter, ['link_expires_in_days' => 0])->assertUnprocessable()->assertJsonValidationErrors(['link_expires_in_days']);

        // The client, matter, status and link fields belong to the server.
        $this->createRequest($matter, [
            'client_id' => $other->id, 'matter_id' => 999, 'status' => 'approved', 'document_id' => 1, 'token_hash' => str_repeat('a', 64),
            'created_by' => 1, 'approved_at' => now()->toIso8601String(), 'successful_uses' => 5, 'consumed_at' => now()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'matter_id', 'status', 'document_id', 'token_hash', 'created_by', 'approved_at', 'successful_uses', 'consumed_at']);

        $this->assertSame(0, DocumentRequest::query()->count());
        $this->assertSame(0, ActivityEvent::query()->count());
    }

    #[Test]
    public function guests_cannot_create_requests_and_archived_matters_are_read_only(): void
    {
        $matter = $this->matterFor();
        $this->postJson("/api/matters/{$matter->id}/document-requests", [
            'title' => 'X', 'instructions' => 'Y', 'requested_category' => 'other',
        ])->assertUnauthorized();

        $archived = Matter::factory()->status(MatterStatus::Archived)->create();
        $this->createRequest($archived)->assertStatus(409)->assertJsonPath('message', 'This matter is archived and read-only. Restore it to make changes.');
        $this->assertSame(0, DocumentRequest::query()->count());
    }

    #[Test]
    public function the_database_refuses_a_request_whose_client_is_not_the_matters_client(): void
    {
        $matter = $this->matterFor();
        $other = Client::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('document request client does not match the matter client');
        DB::table('document_requests')->insert([
            'uuid' => (string) Str::uuid(), 'matter_id' => $matter->id, 'client_id' => $other->id, 'title' => 'Wrong client',
            'instructions' => 'n/a', 'requested_category' => 'other', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_matters_client_cannot_change_while_requests_are_open(): void
    {
        [$request] = $this->requestWithLink();
        $matter = $request->matter;
        $newClient = Client::factory()->create();

        $this->actingAsAdmin($this->admin())->patchJson("/api/matters/{$matter->id}", [
            'client_id' => $newClient->id, 'lock_version' => $matter->lock_version,
        ])->assertUnprocessable()->assertJsonPath('errors.client_id.0', 'This matter has open client document requests. Complete or cancel them before changing the client.');
        $this->assertSame($request->client_id, $matter->fresh()->client_id);

        // Defence in depth: the database refuses the change even when the service is bypassed.
        try {
            DB::transaction(fn () => DB::table('matters')->where('id', $matter->id)->update(['client_id' => $newClient->id])); // savepoint
            $this->fail('The trigger should have refused the client change.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('open document requests cannot be changed', $e->getMessage());
        }

        // Once the request is closed (cancelled), the client may change; the request keeps its history.
        $this->adminPost($request, 'cancel', ['lock_version' => $this->lockVersion($request)])->assertOk();
        app(MatterService::class)->update($matter->fresh(), ['client_id' => $newClient->id], $this->admin());
        $this->assertSame($newClient->id, $matter->fresh()->client_id);
        $this->assertNotSame($newClient->id, $request->fresh()->client_id);
    }

    #[Test]
    public function an_admin_edits_an_open_request_and_history_omits_the_instructions_text(): void
    {
        [$request] = $this->requestWithLink();
        $due = Carbon::now('UTC')->addDays(20)->format('Y-m-d');

        $this->actingAsAdmin($this->admin())->patchJson("/api/matters/{$request->matter_id}/document-requests/{$request->id}", [
            'title' => 'Countersigned Service Agreement', 'instructions' => 'Confidential: include schedule B.', 'due_date' => $due,
            'lock_version' => $this->lockVersion($request),
        ])->assertOk()->assertJsonPath('data.title', 'Countersigned Service Agreement')->assertJsonPath('data.due_date', $due);

        $event = ActivityEvent::query()->where('event_type', ActivityEventType::DocumentRequestUpdated->value)->sole();
        $this->assertSame(['changed' => true], $event->metadata['changes']['instructions']);
        $this->assertStringNotContainsString('Confidential', json_encode($event->metadata));

        // Stale edits are refused.
        $this->actingAsAdmin($this->admin())->patchJson("/api/matters/{$request->matter_id}/document-requests/{$request->id}", [
            'title' => 'Stale', 'lock_version' => 1,
        ])->assertStatus(409);
    }
}
