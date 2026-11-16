<?php

namespace Tests\Feature\Database;

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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function assertRejectedByDatabase(callable $callback): void
    {
        try {
            DB::transaction(fn () => $callback());
            $this->fail('Expected the database to reject the write.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    #[Test]
    public function relationships_resolve_in_both_directions(): void
    {
        $matter = Matter::factory()->create();
        $task = Task::factory()->for($matter)->create();
        $note = MatterNote::factory()->for($matter)->create();

        $this->assertTrue($matter->client->matters->contains($matter));
        $this->assertTrue($matter->tasks->contains($task));
        $this->assertTrue($matter->notes->contains($note));
        $this->assertTrue($note->author->is(User::first()));
    }

    #[Test]
    public function enum_columns_reject_unknown_values(): void
    {
        $matter = Matter::factory()->create();

        $this->assertRejectedByDatabase(fn () => DB::table('matters')->where('id', $matter->id)->update(['status' => 'deleted']));
        $this->assertRejectedByDatabase(fn () => DB::table('matters')->where('id', $matter->id)->update(['priority' => 'critical']));
        $this->assertRejectedByDatabase(fn () => DB::table('clients')->insert(['name' => 'X', 'client_type' => 'company', 'email' => 'x@example.com']));
        $this->assertRejectedByDatabase(fn () => DB::table('users')->insert(['name' => 'X', 'email' => 'x@example.com', 'password' => 'x', 'role' => 'client']));
    }

    #[Test]
    public function matter_lifecycle_timestamps_are_enforced(): void
    {
        $this->assertRejectedByDatabase(fn () => Matter::factory()->create(['status' => MatterStatus::Closed, 'closed_at' => null]));
        $this->assertRejectedByDatabase(fn () => Matter::factory()->create(['status' => MatterStatus::Archived, 'archived_at' => null, 'closed_at' => now()]));
    }

    #[Test]
    public function task_completion_state_must_be_consistent(): void
    {
        $this->assertRejectedByDatabase(fn () => Task::factory()->create(['status' => TaskStatus::Completed, 'completed_at' => null]));
        $this->assertRejectedByDatabase(fn () => Task::factory()->create(['status' => TaskStatus::Todo, 'completed_at' => now()]));
    }

    #[Test]
    public function matter_numbers_are_unique(): void
    {
        Matter::factory()->create(['matter_number' => 'LEX-2026-0001']);

        $this->assertRejectedByDatabase(fn () => Matter::factory()->create(['matter_number' => 'LEX-2026-0001']));
    }

    #[Test]
    public function a_client_with_matters_cannot_be_deleted(): void
    {
        $client = Client::factory()->has(Matter::factory())->create();

        $this->assertRejectedByDatabase(fn () => $client->delete());
        $this->assertModelExists($client);
    }

    #[Test]
    public function tasks_and_notes_cannot_be_orphaned(): void
    {
        $this->assertRejectedByDatabase(fn () => Task::factory()->create(['matter_id' => 999999]));
        $this->assertRejectedByDatabase(fn () => MatterNote::factory()->create(['matter_id' => 999999]));
    }

    #[Test]
    public function deleting_a_matter_without_history_cascades_to_its_tasks_and_notes(): void
    {
        $matter = Matter::factory()->create();
        Task::factory()->for($matter)->count(2)->create();
        MatterNote::factory()->for($matter)->create();

        $matter->delete();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('matter_notes', 0);
    }

    #[Test]
    public function a_matter_with_recorded_activity_cannot_be_hard_deleted(): void
    {
        $matter = Matter::factory()->create();
        app(ActivityLogger::class)->record($matter, ActivityEventType::MatterCreated, 'Matter opened.');

        $this->assertRejectedByDatabase(fn () => $matter->delete());
    }

    #[Test]
    public function removing_an_admin_keeps_their_tasks_and_notes(): void
    {
        $admin = User::factory()->create();
        $task = Task::factory()->create(['created_by' => $admin->id]);
        $note = MatterNote::factory()->create(['author_id' => $admin->id]);

        $admin->delete();

        $this->assertNull($task->fresh()->created_by);
        $this->assertNull($note->fresh()->author_id);
    }

    #[Test]
    public function activity_events_are_append_only_in_the_model_and_the_database(): void
    {
        $admin = User::factory()->create(['name' => 'Ada Counsel']);
        $matter = Matter::factory()->create();
        $event = app(ActivityLogger::class)->record($matter, ActivityEventType::NoteAdded, 'Internal note added.', $admin, $matter, ['source' => 'test']);

        $this->assertSame('admin', $event->actor_type->value);
        $this->assertSame('Ada Counsel', $event->actor_label);
        $this->assertSame('matter', $event->subject_type);
        $this->assertSame(['source' => 'test'], $event->fresh()->metadata);

        try {
            $event->update(['description' => 'tampered']);
            $this->fail('Model allowed an update.');
        } catch (LogicException) {
        }

        try {
            $event->delete();
            $this->fail('Model allowed a delete.');
        } catch (LogicException) {
        }

        $this->assertRejectedByDatabase(fn () => DB::table('activity_events')->where('id', $event->id)->update(['description' => 'tampered']));
        $this->assertRejectedByDatabase(fn () => DB::table('activity_events')->where('id', $event->id)->delete());
        $this->assertSame('Internal note added.', ActivityEvent::find($event->id)->description);
    }

    #[Test]
    public function user_emails_are_normalised_and_unique(): void
    {
        User::factory()->create(['email' => 'Partner@Lexora.TEST']);

        $this->assertDatabaseHas('users', ['email' => 'partner@lexora.test']);
        $this->assertRejectedByDatabase(fn () => User::factory()->create(['email' => 'partner@lexora.test']));
    }
}
