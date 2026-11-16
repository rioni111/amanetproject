<?php

namespace Tests\Feature\Phase2;

use App\Enums\ActivityEventType;
use App\Enums\TaskStatus;
use App\Models\ActivityEvent;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lexora.timezone' => 'UTC']);
    }

    #[Test]
    public function an_admin_can_create_a_task_owned_by_the_session_user(): void
    {
        $matter = Matter::factory()->create();
        $admin = User::factory()->create(['name' => 'Elena Marsh']);

        $response = $this->actingAsAdmin($admin)->postJson("/api/matters/{$matter->id}/tasks", [
            'title' => 'Draft engagement letter',
            'description' => 'Use the new template.',
            'priority' => 'high',
            'due_date' => '2026-12-05',
        ])->assertCreated()
            ->assertJsonPath('data.matter_id', $matter->id)
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.due_date', '2026-12-05')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.created_by.name', 'Elena Marsh');

        $this->assertDatabaseHas('tasks', ['id' => $response->json('data.id'), 'created_by' => $admin->id]);
        $this->assertSame(ActivityEventType::TaskCreated, $matter->activityEvents()->sole()->event_type);
    }

    #[Test]
    public function task_input_is_validated_and_server_fields_are_rejected(): void
    {
        $matter = Matter::factory()->create();
        $other = User::factory()->create();

        $this->actingAsAdmin()->postJson("/api/matters/{$matter->id}/tasks", [
            'title' => '',
            'status' => 'blocked',
            'priority' => 'asap',
            'due_date' => '2026-02-30',
            'created_by' => $other->id,
            'matter_id' => 123,
            'completed_at' => now()->toIso8601String(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'status', 'priority', 'due_date', 'created_by', 'matter_id', 'completed_at']);

        $this->postJson('/api/matters/999999/tasks', ['title' => 'Orphan'])->assertNotFound();
        $this->assertSame(0, Task::count());
    }

    #[Test]
    public function completing_and_reopening_manage_completed_at_and_history(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $matter = Matter::factory()->create();
        $task = Task::factory()->for($matter)->create(['status' => TaskStatus::InProgress, 'due_at' => null]);
        $admin = $this->actingAsAdmin();

        $admin->patchJson("/api/matters/{$matter->id}/tasks/{$task->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.completed_at', '2026-11-16T12:00:00+00:00');

        Carbon::setTestNow('2026-11-17 08:00:00');
        $admin->patchJson("/api/matters/{$matter->id}/tasks/{$task->id}", ['status' => 'todo'])
            ->assertOk()
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.completed_at', null);

        $types = $matter->activityEvents()->orderBy('id')->pluck('event_type')->map->value->all();
        $this->assertSame(['task.completed', 'task.reopened'], $types);
    }

    #[Test]
    public function editing_a_task_records_the_changed_fields(): void
    {
        $matter = Matter::factory()->create();
        $task = Task::factory()->for($matter)->create(['title' => 'Old', 'priority' => 'low', 'due_at' => null]);

        $this->actingAsAdmin()->patchJson("/api/matters/{$matter->id}/tasks/{$task->id}", [
            'title' => 'New', 'priority' => 'urgent', 'due_date' => '2026-12-31', 'lock_version' => 1,
        ])->assertOk()->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.due_date', '2026-12-31');

        $event = ActivityEvent::query()->where('event_type', ActivityEventType::TaskUpdated->value)->sole();
        $this->assertEquals(['from' => 'Old', 'to' => 'New'], $event->metadata['changes']['title']);
        $this->assertEquals(['from' => 'low', 'to' => 'urgent'], $event->metadata['changes']['priority']);
        $this->assertEquals(['from' => null, 'to' => '2026-12-31'], $event->metadata['changes']['due_date']);

        // Stale copy → 409, nothing changes.
        $this->patchJson("/api/matters/{$matter->id}/tasks/{$task->id}", ['title' => 'Stale', 'lock_version' => 1])->assertConflict();
        $this->assertSame('New', $task->fresh()->title);
    }

    #[Test]
    public function tasks_cannot_be_reached_through_another_matter(): void
    {
        $matter = Matter::factory()->create();
        $other = Matter::factory()->create();
        $task = Task::factory()->for($other)->create(['title' => 'Belongs elsewhere']);
        $admin = $this->actingAsAdmin();

        $admin->patchJson("/api/matters/{$matter->id}/tasks/{$task->id}", ['title' => 'Hijacked'])->assertNotFound();
        $admin->deleteJson("/api/matters/{$matter->id}/tasks/{$task->id}")->assertNotFound();
        $admin->getJson("/api/matters/{$matter->id}/tasks")->assertJsonPath('meta.total', 0);

        $this->assertSame('Belongs elsewhere', $task->fresh()->title);
    }

    #[Test]
    public function a_task_can_be_deleted_and_the_deletion_is_recorded(): void
    {
        $matter = Matter::factory()->create();
        $task = Task::factory()->for($matter)->create(['title' => 'Obsolete step']);

        $this->actingAsAdmin()->deleteJson("/api/matters/{$matter->id}/tasks/{$task->id}")->assertNoContent();

        $this->assertModelMissing($task);
        $event = $matter->activityEvents()->sole();
        $this->assertSame(ActivityEventType::TaskDeleted, $event->event_type);
        $this->assertEquals(['task_id' => $task->id, 'title' => 'Obsolete step'], $event->metadata);
    }

    #[Test]
    public function task_list_orders_open_work_first_and_filters_by_status(): void
    {
        $matter = Matter::factory()->create();
        $late = Task::factory()->for($matter)->create(['title' => 'Later', 'due_at' => now()->addDays(9)]);
        $soon = Task::factory()->for($matter)->create(['title' => 'Soon', 'due_at' => now()->addDay()]);
        $done = Task::factory()->for($matter)->completed()->create(['title' => 'Done']);
        $admin = $this->actingAsAdmin();

        $admin->getJson("/api/matters/{$matter->id}/tasks")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$soon->id, $late->id, $done->id]);
        $admin->getJson("/api/matters/{$matter->id}/tasks?status=open")->assertJsonPath('meta.total', 2);
        $admin->getJson("/api/matters/{$matter->id}/tasks?status=completed")->assertJsonPath('data.0.id', $done->id);
        $admin->getJson("/api/matters/{$matter->id}/tasks?status=waiting")->assertUnprocessable();
    }

    #[Test]
    public function outstanding_task_counts_follow_task_changes(): void
    {
        $matter = Matter::factory()->create();
        $admin = $this->actingAsAdmin();
        $before = $admin->getJson('/api/dashboard/summary')->json('data.stats.open_tasks');

        $id = $admin->postJson("/api/matters/{$matter->id}/tasks", ['title' => 'Count me'])->json('data.id');
        $admin->getJson('/api/dashboard/summary')->assertJsonPath('data.stats.open_tasks', $before + 1);
        $admin->getJson("/api/matters/{$matter->id}")->assertJsonPath('data.open_tasks_count', 1);

        $admin->patchJson("/api/matters/{$matter->id}/tasks/{$id}", ['status' => 'completed'])->assertOk();
        $admin->getJson('/api/dashboard/summary')->assertJsonPath('data.stats.open_tasks', $before);
        $admin->getJson("/api/matters/{$matter->id}")
            ->assertJsonPath('data.open_tasks_count', 0)
            ->assertJsonPath('data.completed_tasks_count', 1);
    }

    #[Test]
    public function task_endpoints_require_authentication(): void
    {
        $task = Task::factory()->create();

        $this->fromFrontend()->getJson("/api/matters/{$task->matter_id}/tasks")->assertUnauthorized();
        $this->fromFrontend()->postJson("/api/matters/{$task->matter_id}/tasks", ['title' => 'x'])->assertUnauthorized();
        $this->fromFrontend()->patchJson("/api/matters/{$task->matter_id}/tasks/{$task->id}", ['title' => 'x'])->assertUnauthorized();
        $this->fromFrontend()->deleteJson("/api/matters/{$task->matter_id}/tasks/{$task->id}")->assertUnauthorized();
        $this->assertModelExists($task);
    }
}
