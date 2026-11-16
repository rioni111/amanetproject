<?php

namespace Tests\Feature\Phase2;

use App\Enums\ActivityEventType;
use App\Enums\MatterStatus;
use App\Models\ActivityEvent;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\MatterNoteService;
use App\Services\MatterNumberGenerator;
use App\Services\MatterService;
use App\Services\TaskService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class NoteAndActivityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'Client admitted the invoices were backdated.';

    #[Test]
    public function an_admin_can_add_edit_and_delete_an_internal_note(): void
    {
        $matter = Matter::factory()->create();
        $admin = User::factory()->create(['name' => 'Elena Marsh']);

        $id = $this->actingAsAdmin($admin)->postJson("/api/matters/{$matter->id}/notes", ['body' => self::SECRET])
            ->assertCreated()
            ->assertJsonPath('data.body', self::SECRET)
            ->assertJsonPath('data.author.name', 'Elena Marsh')
            ->assertJsonPath('data.is_edited', false)
            ->json('data.id');
        $this->assertDatabaseHas('matter_notes', ['id' => $id, 'matter_id' => $matter->id, 'author_id' => $admin->id]);

        $this->patchJson("/api/matters/{$matter->id}/notes/{$id}", ['body' => 'Revised wording.', 'lock_version' => 1])
            ->assertOk()->assertJsonPath('data.body', 'Revised wording.')->assertJsonPath('data.is_edited', true);
        $this->patchJson("/api/matters/{$matter->id}/notes/{$id}", ['body' => 'Stale edit', 'lock_version' => 1])->assertConflict();

        $this->getJson("/api/matters/{$matter->id}/notes")->assertOk()->assertJsonPath('data.0.body', 'Revised wording.');

        $this->deleteJson("/api/matters/{$matter->id}/notes/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('matter_notes', ['id' => $id]);

        $types = $matter->activityEvents()->orderBy('id')->pluck('event_type')->map->value->all();
        $this->assertSame(['note.added', 'note.updated', 'note.deleted'], $types);
    }

    #[Test]
    public function note_bodies_never_reach_activity_history(): void
    {
        $matter = Matter::factory()->create();
        $admin = $this->actingAsAdmin();
        $id = $admin->postJson("/api/matters/{$matter->id}/notes", ['body' => self::SECRET])->json('data.id');
        $admin->patchJson("/api/matters/{$matter->id}/notes/{$id}", ['body' => self::SECRET.' Confirmed.'])->assertOk();

        $history = json_encode($admin->getJson("/api/matters/{$matter->id}/activity")->json());
        $this->assertStringNotContainsString('backdated', $history);
        $this->assertStringNotContainsString('backdated', (string) ActivityEvent::query()->pluck('metadata')->toJson());
        $this->assertStringNotContainsString('backdated', (string) ActivityEvent::query()->pluck('description')->toJson());
    }

    #[Test]
    public function notes_are_validated_scoped_and_private(): void
    {
        $matter = Matter::factory()->create();
        $other = Matter::factory()->create();
        $foreign = MatterNote::factory()->for($other)->create(['body' => 'Other matter note']);
        $intruder = User::factory()->create();

        $admin = $this->actingAsAdmin();
        $admin->postJson("/api/matters/{$matter->id}/notes", ['body' => '   '])->assertUnprocessable()->assertJsonValidationErrors(['body']);
        $admin->postJson("/api/matters/{$matter->id}/notes", ['body' => 'x', 'author_id' => $intruder->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['author_id']);
        $admin->postJson("/api/matters/{$matter->id}/notes", ['body' => str_repeat('n', 10001)])->assertUnprocessable();
        $admin->patchJson("/api/matters/{$matter->id}/notes/{$foreign->id}", ['body' => 'Hijack'])->assertNotFound();
        $admin->deleteJson("/api/matters/{$matter->id}/notes/{$foreign->id}")->assertNotFound();
        $this->assertSame('Other matter note', $foreign->fresh()->body);
    }

    #[Test]
    public function notes_are_not_accessible_without_an_admin_session(): void
    {
        $note = MatterNote::factory()->create(['body' => self::SECRET]);

        $this->fromFrontend()->getJson("/api/matters/{$note->matter_id}/notes")->assertUnauthorized()->assertDontSee('backdated');
        $this->getJson("/api/matters/{$note->matter_id}/notes")->assertUnauthorized();
        $this->fromFrontend()->postJson("/api/matters/{$note->matter_id}/notes", ['body' => 'x'])->assertUnauthorized();
        $this->fromFrontend()->patchJson("/api/matters/{$note->matter_id}/notes/{$note->id}", ['body' => 'x'])->assertUnauthorized();
        $this->fromFrontend()->deleteJson("/api/matters/{$note->matter_id}/notes/{$note->id}")->assertUnauthorized();
        // Not exposed by any other payload either.
        $this->actingAsAdmin()->getJson("/api/matters/{$note->matter_id}")->assertOk()->assertDontSee('backdated');
        $this->getJson('/api/matters')->assertDontSee('backdated');
    }

    #[Test]
    public function the_timeline_is_newest_first_paginated_and_records_every_action(): void
    {
        $matter = Matter::factory()->create(['status' => 'open']);
        $admin = $this->actingAsAdmin();

        Carbon::setTestNow('2026-11-16 09:00:00');
        $taskId = $admin->postJson("/api/matters/{$matter->id}/tasks", ['title' => 'Step'])->json('data.id');
        Carbon::setTestNow('2026-11-16 09:05:00');
        $admin->patchJson("/api/matters/{$matter->id}/tasks/{$taskId}", ['status' => 'completed'])->assertOk();
        Carbon::setTestNow('2026-11-16 09:10:00');
        $admin->postJson("/api/matters/{$matter->id}/status", ['status' => 'in_progress'])->assertOk();
        Carbon::setTestNow('2026-11-16 09:15:00');
        $admin->postJson("/api/matters/{$matter->id}/notes", ['body' => 'Note'])->assertCreated();

        $admin->getJson("/api/matters/{$matter->id}/activity?per_page=3")
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.*.event_type', ['note.added', 'matter.status_changed', 'task.completed'])
            ->assertJsonPath('data.0.actor.type', 'admin')
            ->assertJsonPath('data.0.occurred_at', '2026-11-16T09:15:00+00:00')
            ->assertJsonStructure(['data' => [['id', 'event_type', 'description', 'actor' => ['type', 'label'], 'metadata', 'occurred_at']]]);
        $admin->getJson("/api/matters/{$matter->id}/activity?per_page=3&page=2")->assertJsonPath('data.0.event_type', 'task.created');
    }

    #[Test]
    public function a_change_is_rolled_back_when_its_history_entry_cannot_be_written(): void
    {
        $matter = Matter::factory()->create(['status' => 'open', 'title' => 'Unchanged']);
        $task = Task::factory()->for($matter)->create(['status' => 'todo']);
        $admin = User::factory()->create();
        $failing = Mockery::mock(ActivityLogger::class);
        $failing->shouldReceive('record')->andThrow(new RuntimeException('history unavailable'));

        $attempts = [
            fn () => (new MatterService($failing, app(MatterNumberGenerator::class)))->transition($matter, MatterStatus::Closed, $admin),
            fn () => (new MatterService($failing, app(MatterNumberGenerator::class)))->update($matter, ['title' => 'Changed'], $admin),
            fn () => (new MatterService($failing, app(MatterNumberGenerator::class)))->archive($matter, $admin),
            fn () => (new TaskService($failing))->update($matter, $task, ['status' => 'completed'], $admin),
            fn () => (new TaskService($failing))->create($matter, ['title' => 'Never stored'], $admin),
            fn () => (new MatterNoteService($failing))->create($matter, 'Never stored', $admin),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected the change to fail with its history entry.');
            } catch (RuntimeException $e) {
                $this->assertSame('history unavailable', $e->getMessage());
            }
        }

        $matter->refresh();
        $this->assertSame('open', $matter->status->value);
        $this->assertSame('Unchanged', $matter->title);
        $this->assertNull($matter->closed_at);
        $this->assertSame('todo', $task->fresh()->status->value);
        $this->assertNull($task->fresh()->completed_at);
        $this->assertSame(1, Task::count());
        $this->assertSame(0, MatterNote::count());
        $this->assertSame(0, ActivityEvent::count());
    }

    #[Test]
    public function recorded_history_stays_append_only(): void
    {
        $matter = Matter::factory()->create();
        $this->actingAsAdmin()->postJson("/api/matters/{$matter->id}/status", ['status' => 'closed'])->assertOk();
        $event = ActivityEvent::query()->sole();

        try {
            // Savepoint, so the failed statement does not abort the surrounding test transaction.
            DB::transaction(fn () => DB::table('activity_events')->where('id', $event->id)->update(['description' => 'Rewritten']));
            $this->fail('UPDATE should be blocked by the trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertSame(ActivityEventType::MatterStatusChanged, $event->fresh()->event_type);
        $this->assertStringNotContainsString('Rewritten', $event->fresh()->description);
    }
}
