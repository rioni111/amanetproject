<?php

namespace Tests\Feature\Phase2;

use App\Enums\ActivityEventType;
use App\Enums\MatterStatus;
use App\Enums\TaskStatus;
use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\MatterNumberGenerator;
use App\Services\MatterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MatterManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lexora.timezone' => 'UTC']);
    }

    private function payload(Client $client, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Distribution Agreement Review',
            'description' => 'Review exclusivity and termination clauses.',
            'client_id' => $client->id,
            'matter_type' => 'Contract Review',
            'priority' => 'high',
            'opened_date' => '2026-11-10',
            'due_date' => '2026-12-01',
        ], $overrides);
    }

    #[Test]
    public function an_admin_can_create_a_matter_with_a_generated_number(): void
    {
        Carbon::setTestNow('2026-11-16 10:00:00');
        $client = Client::factory()->create(['name' => 'Northwind']);
        $admin = User::factory()->create(['name' => 'Elena Marsh']);

        $response = $this->actingAsAdmin($admin)->postJson('/api/matters', $this->payload($client))
            ->assertCreated()
            ->assertJsonPath('data.matter_number', 'LEX-2026-0001')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.opened_date', '2026-11-10')
            ->assertJsonPath('data.due_date', '2026-12-01')
            ->assertJsonPath('data.due_at', '2026-12-01T23:59:59+00:00') // end of the deadline day
            ->assertJsonPath('data.client.name', 'Northwind')
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.allowed_transitions', ['in_progress', 'on_hold', 'closed']);

        $matter = Matter::findOrFail($response->json('data.id'));
        $event = $matter->activityEvents()->sole();
        $this->assertSame(ActivityEventType::MatterCreated, $event->event_type);
        $this->assertSame('Elena Marsh', $event->actor_label);
        $this->assertSame('Matter LEX-2026-0001 opened for Northwind.', $event->description);

        $this->postJson('/api/matters', $this->payload($client))->assertJsonPath('data.matter_number', 'LEX-2026-0002');
    }

    #[Test]
    public function numbering_continues_after_existing_numbers_and_restarts_each_year(): void
    {
        Carbon::setTestNow('2026-06-01 09:00:00');
        $client = Client::factory()->create();
        Matter::factory()->for($client)->create(['matter_number' => 'LEX-2026-0041']); // e.g. seeded or imported

        $admin = $this->actingAsAdmin();
        $admin->postJson('/api/matters', $this->payload($client))->assertJsonPath('data.matter_number', 'LEX-2026-0042');

        Carbon::setTestNow('2027-01-02 09:00:00');
        $admin->postJson('/api/matters', $this->payload($client, ['opened_date' => null, 'due_date' => null]))
            ->assertJsonPath('data.matter_number', 'LEX-2027-0001');
    }

    #[Test]
    public function a_failed_creation_does_not_consume_a_number(): void
    {
        Carbon::setTestNow('2026-11-16 10:00:00');
        $client = Client::factory()->create();
        $admin = User::factory()->create();
        $input = ['title' => 'T', 'client_id' => $client->id, 'priority' => 'low'];
        $working = app(MatterService::class);
        $this->assertSame('LEX-2026-0001', $working->create($input, $admin)->matter_number);

        // Activity logging fails inside the transaction → matter AND counter increment roll back.
        $failingLogger = \Mockery::mock(ActivityLogger::class);
        $failingLogger->shouldReceive('record')->andThrow(new \RuntimeException('history unavailable'));
        $failing = new MatterService($failingLogger, app(MatterNumberGenerator::class));
        try {
            $failing->create($input, $admin);
            $this->fail('Expected the creation to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('history unavailable', $e->getMessage());
        }
        $this->assertSame(1, Matter::count());

        $this->assertSame('LEX-2026-0002', $working->create($input, $admin)->matter_number);
    }

    #[Test]
    public function creation_rejects_invalid_and_server_managed_input(): void
    {
        $client = Client::factory()->create();
        $admin = $this->actingAsAdmin();

        $admin->postJson('/api/matters', ['title' => '', 'client_id' => 999999, 'priority' => 'critical', 'status' => 'archived', 'due_date' => '31/12/2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'client_id', 'priority', 'status', 'due_date']);

        $admin->postJson('/api/matters', $this->payload($client, ['status' => 'closed']))
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $admin->postJson('/api/matters', $this->payload($client, ['opened_date' => '2026-12-10', 'due_date' => '2026-12-01']))
            ->assertUnprocessable()->assertJsonPath('errors.due_date.0', 'The deadline cannot be before the opening date.');

        $admin->postJson('/api/matters', $this->payload($client, ['matter_number' => 'LEX-1999-0001', 'closed_at' => now()->toIso8601String()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['matter_number', 'closed_at']);

        $admin->postJson('/api/matters', $this->payload($client, ['description' => str_repeat('d', 10001)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['description']);

        $this->assertSame(0, Matter::count());
    }

    #[Test]
    public function matter_details_include_client_reference_and_task_statistics(): void
    {
        $client = Client::factory()->create(['reference_number' => 'CL-555']);
        $matter = Matter::factory()->for($client)->create();
        Task::factory()->for($matter)->create(['status' => TaskStatus::Todo, 'due_at' => now()->subDay()]);
        Task::factory()->for($matter)->inProgress()->create(['due_at' => now()->addDay()]);
        Task::factory()->for($matter)->completed()->create();
        MatterNote::factory()->for($matter)->create();

        $this->actingAsAdmin()->getJson("/api/matters/{$matter->id}")
            ->assertOk()
            ->assertJsonPath('data.client.reference_number', 'CL-555')
            ->assertJsonPath('data.tasks_count', 3)
            ->assertJsonPath('data.open_tasks_count', 2)
            ->assertJsonPath('data.overdue_tasks_count', 1)
            ->assertJsonPath('data.completed_tasks_count', 1)
            ->assertJsonPath('data.notes_count', 1);

        $this->getJson('/api/matters/999999')->assertNotFound();
    }

    #[Test]
    public function an_admin_can_edit_a_matter_and_history_records_safe_changes(): void
    {
        $from = Client::factory()->create(['name' => 'Old Client']);
        $to = Client::factory()->create(['name' => 'New Client']);
        $matter = Matter::factory()->for($from)->create(['title' => 'Old title', 'priority' => 'medium', 'matter_number' => 'LEX-2026-0100']);

        $this->actingAsAdmin()->patchJson("/api/matters/{$matter->id}", [
            'title' => 'New title',
            'priority' => 'urgent',
            'due_date' => '2026-12-24',
            'client_id' => $to->id,
            'description' => 'Confidential strategy text',
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.title', 'New title')
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.due_date', '2026-12-24')
            ->assertJsonPath('data.client.name', 'New Client')
            ->assertJsonPath('data.matter_number', 'LEX-2026-0100') // number is preserved
            ->assertJsonPath('data.lock_version', 2);

        $updated = ActivityEvent::query()->where('event_type', ActivityEventType::MatterUpdated->value)->sole();
        $this->assertEquals(['from' => 'Old title', 'to' => 'New title'], $updated->metadata['changes']['title']);
        $this->assertEquals(['from' => 'Old Client', 'to' => 'New Client'], $updated->metadata['changes']['client']);
        $this->assertSame(['changed' => true], $updated->metadata['changes']['description']);
        $this->assertStringNotContainsString('Confidential', json_encode($updated->metadata));

        $priority = ActivityEvent::query()->where('event_type', ActivityEventType::MatterPriorityChanged->value)->sole();
        $this->assertEquals(['from' => 'medium', 'to' => 'urgent'], $priority->metadata);
    }

    #[Test]
    public function editing_rejects_status_number_and_invalid_values(): void
    {
        $matter = Matter::factory()->create();
        $admin = $this->actingAsAdmin();

        $admin->patchJson("/api/matters/{$matter->id}", ['status' => 'closed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $admin->patchJson("/api/matters/{$matter->id}", ['matter_number' => 'LEX-2026-9999'])
            ->assertUnprocessable()->assertJsonValidationErrors(['matter_number']);
        $admin->patchJson("/api/matters/{$matter->id}", ['priority' => 'whenever', 'title' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['priority', 'title']);
    }

    #[Test]
    public function a_stale_edit_is_rejected_with_409_and_changes_nothing(): void
    {
        $matter = Matter::factory()->create(['title' => 'Original']);
        $admin = $this->actingAsAdmin();

        $admin->patchJson("/api/matters/{$matter->id}", ['title' => 'First editor', 'lock_version' => 1])->assertOk();
        $admin->patchJson("/api/matters/{$matter->id}", ['title' => 'Second editor', 'lock_version' => 1])
            ->assertConflict()
            ->assertJsonPath('message', 'This matter was changed by someone else after you opened it. Reload to see the latest version.');

        $this->assertSame('First editor', $matter->fresh()->title);
    }

    public static function allowedTransitions(): array
    {
        return [
            'open → in progress' => ['open', 'in_progress'],
            'in progress → on hold' => ['in_progress', 'on_hold'],
            'on hold → in progress' => ['on_hold', 'in_progress'],
            'in progress → closed' => ['in_progress', 'closed'],
            'open → closed' => ['open', 'closed'],
        ];
    }

    #[Test]
    #[DataProvider('allowedTransitions')]
    public function allowed_status_transitions_are_applied_and_recorded(string $from, string $to): void
    {
        $matter = Matter::factory()->create(['status' => $from]);

        $this->actingAsAdmin()->postJson("/api/matters/{$matter->id}/status", ['status' => $to])
            ->assertOk()->assertJsonPath('data.status', $to);

        $matter->refresh();
        $this->assertSame($to === 'closed', $matter->closed_at !== null);
        $event = $matter->activityEvents()->where('event_type', ActivityEventType::MatterStatusChanged->value)->sole();
        $this->assertEquals(['from' => $from, 'to' => $to], $event->metadata);
    }

    #[Test]
    public function a_closed_matter_can_be_reopened_and_closed_at_is_cleared(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Closed)->create();

        $this->actingAsAdmin()->postJson("/api/matters/{$matter->id}/status", ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.closed_at', null);

        $this->assertStringStartsWith('Matter reopened', $matter->activityEvents()->latest('id')->first()->description);
    }

    #[Test]
    public function invalid_transitions_are_rejected(): void
    {
        $closed = Matter::factory()->status(MatterStatus::Closed)->create();
        $admin = $this->actingAsAdmin();

        $admin->postJson("/api/matters/{$closed->id}/status", ['status' => 'on_hold'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'A matter cannot move from Closed to On hold.');
        $admin->postJson("/api/matters/{$closed->id}/status", ['status' => 'archived'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $admin->postJson("/api/matters/{$closed->id}/status", ['status' => 'pending'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $archived = Matter::factory()->status(MatterStatus::Archived)->create();
        $admin->postJson("/api/matters/{$archived->id}/status", ['status' => 'open'])->assertConflict();

        $this->assertSame(MatterStatus::Closed, $closed->fresh()->status);
        $this->assertSame(0, ActivityEvent::query()->count());
    }

    #[Test]
    public function archiving_preserves_data_and_restore_returns_the_previous_status(): void
    {
        $matter = Matter::factory()->create(['status' => MatterStatus::InProgress]);
        $task = Task::factory()->for($matter)->create();
        $note = MatterNote::factory()->for($matter)->create();
        $admin = $this->actingAsAdmin();

        $admin->postJson("/api/matters/{$matter->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.archived_from_status', 'in_progress')
            ->assertJsonPath('data.allowed_transitions', []);
        $this->assertNotNull($matter->fresh()->archived_at);
        $this->assertModelExists($task);
        $this->assertModelExists($note);

        // Archived matters are read-only.
        $admin->patchJson("/api/matters/{$matter->id}", ['title' => 'Changed'])->assertConflict();
        $admin->postJson("/api/matters/{$matter->id}/tasks", ['title' => 'New task'])->assertConflict();
        $admin->postJson("/api/matters/{$matter->id}/notes", ['body' => 'New note'])->assertConflict();
        $admin->postJson("/api/matters/{$matter->id}/archive")->assertUnprocessable();
        // ...but stay visible through the archive filter.
        $admin->getJson('/api/matters?status=archived')->assertJsonPath('data.0.id', $matter->id);

        $admin->postJson("/api/matters/{$matter->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.archived_at', null)
            ->assertJsonPath('data.archived_from_status', null);
        $admin->postJson("/api/matters/{$matter->id}/restore")->assertUnprocessable();

        $types = $matter->activityEvents()->orderBy('id')->pluck('event_type')->map->value->all();
        $this->assertSame(['matter.archived', 'matter.restored'], $types);
    }

    #[Test]
    public function a_matter_archived_after_closing_is_restored_as_closed(): void
    {
        $matter = Matter::factory()->status(MatterStatus::Closed)->create();
        $closedAt = $matter->closed_at;
        $admin = $this->actingAsAdmin();

        $admin->postJson("/api/matters/{$matter->id}/archive")->assertOk();
        $admin->postJson("/api/matters/{$matter->id}/restore")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertEquals($closedAt->toIso8601String(), $matter->fresh()->closed_at->toIso8601String());
    }

    #[Test]
    public function dashboard_counts_follow_matter_changes(): void
    {
        $client = Client::factory()->create();
        $admin = $this->actingAsAdmin();
        $admin->getJson('/api/dashboard/summary')->assertJsonPath('data.stats.active_matters', 0);

        $id = $admin->postJson('/api/matters', $this->payload($client, ['opened_date' => null, 'due_date' => now()->addDays(3)->toDateString()]))->json('data.id');
        $admin->getJson('/api/dashboard/summary')
            ->assertJsonPath('data.stats.active_matters', 1)
            ->assertJsonPath('data.stats.upcoming_deadlines', 1);
        $admin->getJson("/api/clients/{$client->id}")->assertJsonPath('data.active_matters_count', 1);

        $admin->postJson("/api/matters/{$id}/archive")->assertOk();
        $admin->getJson('/api/dashboard/summary')
            ->assertJsonPath('data.stats.active_matters', 0)
            ->assertJsonPath('data.stats.total_matters', 1)
            ->assertJsonPath('data.stats.upcoming_deadlines', 0);
    }

    #[Test]
    public function deadlines_are_calendar_dates_in_the_firm_time_zone(): void
    {
        config(['lexora.timezone' => 'America/New_York']);
        $client = Client::factory()->create();

        $response = $this->actingAsAdmin()->postJson('/api/matters', $this->payload($client, ['opened_date' => '2026-03-01', 'due_date' => '2026-03-31']))
            ->assertCreated()
            ->assertJsonPath('data.due_date', '2026-03-31')
            ->assertJsonPath('data.opened_date', '2026-03-01');

        // End of 31 March in New York (EDT, UTC−4) is 1 April 03:59:59 UTC — stored in UTC, read back unchanged.
        $this->assertSame('2026-04-01 03:59:59', Matter::findOrFail($response->json('data.id'))->due_at->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function matter_list_supports_new_filters_and_sorts(): void
    {
        $client = Client::factory()->create(['reference_number' => 'CL-4242']);
        Matter::factory()->for($client)->create(['matter_type' => 'Contract Review', 'due_at' => now()->subDay(), 'status' => MatterStatus::Open]);
        Matter::factory()->create(['matter_type' => 'Employment Dispute', 'due_at' => now()->addDays(2), 'status' => MatterStatus::Open]);
        Matter::factory()->create(['matter_type' => 'Employment Dispute', 'due_at' => null, 'status' => MatterStatus::Open]);

        $admin = $this->actingAsAdmin();
        $admin->getJson('/api/matters?matter_type=Employment%20Dispute')->assertJsonPath('meta.total', 2);
        $admin->getJson('/api/matters?deadline=overdue')->assertJsonPath('meta.total', 1);
        $admin->getJson('/api/matters?deadline=upcoming')->assertJsonPath('meta.total', 1);
        $admin->getJson('/api/matters?deadline=none')->assertJsonPath('meta.total', 1);
        $admin->getJson('/api/matters?search=CL-4242')->assertJsonPath('meta.total', 1);
        $admin->getJson('/api/matters?sort=created_at&direction=asc')->assertOk();
        $admin->getJson('/api/matters?deadline=soon')->assertUnprocessable()->assertJsonValidationErrors(['deadline']);
        $admin->getJson('/api/matters?sort=client_id')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
    }

    #[Test]
    public function matter_endpoints_require_authentication(): void
    {
        $matter = Matter::factory()->create();
        $client = Client::factory()->create();

        $this->fromFrontend()->postJson('/api/matters', $this->payload($client))->assertUnauthorized();
        $this->fromFrontend()->getJson("/api/matters/{$matter->id}")->assertUnauthorized();
        $this->fromFrontend()->patchJson("/api/matters/{$matter->id}", ['title' => 'x'])->assertUnauthorized();
        $this->fromFrontend()->postJson("/api/matters/{$matter->id}/status", ['status' => 'closed'])->assertUnauthorized();
        $this->fromFrontend()->postJson("/api/matters/{$matter->id}/archive")->assertUnauthorized();
        $this->fromFrontend()->postJson("/api/matters/{$matter->id}/restore")->assertUnauthorized();
        $this->fromFrontend()->getJson("/api/matters/{$matter->id}/activity")->assertUnauthorized();
        $this->assertSame(1, Matter::count());
    }
}
