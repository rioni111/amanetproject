<?php

namespace Tests\Feature\Api;

use App\Enums\MatterStatus;
use App\Enums\Priority;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_accurate_aggregates_from_the_database(): void
    {
        $clients = Client::factory()->count(3)->create();
        $c = $clients->first();

        // Active matters with deadlines
        $dueIn2 = Matter::factory()->for($c)->create(['status' => MatterStatus::Open, 'due_at' => '2026-03-12 09:00:00', 'matter_number' => 'T-1']);
        $dueIn14 = Matter::factory()->for($c)->create(['status' => MatterStatus::InProgress, 'due_at' => '2026-03-24 18:00:00', 'matter_number' => 'T-2']);
        Matter::factory()->for($c)->create(['status' => MatterStatus::OnHold, 'due_at' => '2026-03-25 09:00:00', 'matter_number' => 'T-3']); // outside window
        Matter::factory()->for($c)->create(['status' => MatterStatus::Open, 'due_at' => '2026-03-09 09:00:00', 'matter_number' => 'T-4']); // overdue
        Matter::factory()->for($c)->create(['status' => MatterStatus::Open, 'due_at' => null, 'matter_number' => 'T-5']);
        // Inactive matters with a deadline inside the window must not count.
        Matter::factory()->for($c)->status(MatterStatus::Closed)->create(['due_at' => '2026-03-11 09:00:00', 'matter_number' => 'T-6']);
        Matter::factory()->for($c)->status(MatterStatus::Archived)->create(['due_at' => '2026-03-11 09:00:00', 'matter_number' => 'T-7']);

        Task::factory()->for($dueIn2)->count(2)->create(['due_at' => '2026-03-20 00:00:00']);
        Task::factory()->for($dueIn2)->inProgress()->create(['due_at' => '2026-03-01 00:00:00']); // overdue open task
        Task::factory()->for($dueIn14)->completed()->count(3)->create();

        $response = $this->actingAsAdmin()->getJson('/api/dashboard/summary')->assertOk();

        $response->assertJsonPath('data.stats', [
            'total_clients' => 3,
            'active_matters' => 5,
            'total_matters' => 7,
            'upcoming_deadlines' => 2,
            'overdue_matters' => 1,
            'open_tasks' => 3,
            'overdue_tasks' => 1,
            // Phase 4 counters (this fixture has no client document requests; see Phase4 DocumentRequestCountersTest).
            'requests_outstanding' => 0,
            'requests_pending' => 0,
            'requests_awaiting_review' => 0,
            'requests_correction' => 0,
            'requests_overdue' => 0,
            'requests_approved' => 0,
        ]);
        $response->assertJsonPath('data.upcoming_window_days', 14);
        $response->assertJsonPath('data.upcoming_deadlines.*.matter_number', ['T-1', 'T-2']);
        $response->assertJsonPath('data.status_breakdown.0', ['status' => 'open', 'label' => 'Open', 'count' => 3]);
        $response->assertJsonPath('data.status_breakdown.4', ['status' => 'archived', 'label' => 'Archived', 'count' => 1]);
    }

    #[Test]
    public function recent_matters_are_ordered_by_latest_activity_and_include_the_client(): void
    {
        $client = Client::factory()->create(['name' => 'Fictional Holdings']);
        Matter::factory()->for($client)->create(['matter_number' => 'OLD', 'updated_at' => '2026-02-01 10:00:00']);
        Matter::factory()->for($client)->create(['matter_number' => 'NEW', 'updated_at' => '2026-03-09 10:00:00', 'priority' => Priority::Urgent]);

        $this->actingAsAdmin()->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.recent_matters.0.matter_number', 'NEW')
            ->assertJsonPath('data.recent_matters.0.priority', 'urgent')
            ->assertJsonPath('data.recent_matters.0.client.name', 'Fictional Holdings')
            ->assertJsonPath('data.recent_matters.1.matter_number', 'OLD');
    }

    #[Test]
    public function the_upcoming_window_is_configurable(): void
    {
        config(['lexora.dashboard.upcoming_window_days' => 30]);
        Matter::factory()->create(['status' => MatterStatus::Open, 'due_at' => '2026-04-05 09:00:00']);

        $this->actingAsAdmin()->getJson('/api/dashboard/summary')
            ->assertJsonPath('data.stats.upcoming_deadlines', 1)
            ->assertJsonPath('data.upcoming_window_days', 30);
    }

    #[Test]
    public function an_empty_workspace_returns_zeroes_not_errors(): void
    {
        $this->actingAsAdmin()->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.stats.total_clients', 0)
            ->assertJsonPath('data.stats.active_matters', 0)
            ->assertJsonPath('data.recent_matters', [])
            ->assertJsonPath('data.upcoming_deadlines', []);
    }

    #[Test]
    public function the_seeded_demo_workspace_produces_the_documented_figures(): void
    {
        Carbon::setTestNow();
        $this->seed();

        $this->actingAsAdmin()->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.stats', [
                'total_clients' => 8,
                'active_matters' => 7,
                'total_matters' => 10,
                'upcoming_deadlines' => 4,
                'overdue_matters' => 1,
                'open_tasks' => 11,
                'overdue_tasks' => 1,
                // Phase 4: the demo seed creates no client document requests.
                'requests_outstanding' => 0,
                'requests_pending' => 0,
                'requests_awaiting_review' => 0,
                'requests_correction' => 0,
                'requests_overdue' => 0,
                'requests_approved' => 0,
            ]);
    }
}
