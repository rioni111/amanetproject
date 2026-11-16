<?php

namespace App\Services;

use App\Enums\ActivityEventType;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Support\LegalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Task changes for one matter. The matter row is locked first (consistent lock order: matter, then
 * task), archived matters are read-only, and each change is written together with its history event.
 * completed_at is managed here so it always satisfies tasks_completion_consistency.
 */
class TaskService
{
    public const STALE_MESSAGE = 'This task was changed by someone else after you opened it. Reload to see the latest version.';

    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param array<string, mixed> $data validated input */
    public function create(Matter $matter, array $data, User $actor): Task
    {
        return DB::transaction(function () use ($matter, $data, $actor) {
            $matter = $this->lockMatter($matter);
            $status = TaskStatus::from($data['status'] ?? TaskStatus::Todo->value);

            $task = new Task;
            $task->forceFill([
                'matter_id' => $matter->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $status,
                'priority' => Priority::from($data['priority'] ?? Priority::Medium->value),
                'due_at' => LegalDate::endOfDay($data['due_date'] ?? null),
                'completed_at' => $status === TaskStatus::Completed ? Carbon::now() : null,
                'created_by' => $actor->id, // from the session, never from the request
            ])->save();

            $this->activity->record($matter, ActivityEventType::TaskCreated, "Task added: {$task->title}.", $actor, $task,
                ['status' => $status->value, 'priority' => $task->priority->value, 'due_date' => LegalDate::toDate($task->due_at)]);
            if ($status === TaskStatus::Completed) {
                $this->activity->record($matter, ActivityEventType::TaskCompleted, "Task completed: {$task->title}.", $actor, $task);
            }
            $this->touchMatter($matter);

            return $task;
        });
    }

    /** @param array<string, mixed> $data validated input */
    public function update(Matter $matter, Task $task, array $data, User $actor): Task
    {
        return DB::transaction(function () use ($matter, $task, $data, $actor) {
            $matter = $this->lockMatter($matter);
            $task = Task::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($task->getKey());
            $task->assertLockVersion($data['lock_version'] ?? null, self::STALE_MESSAGE);

            $before = $this->snapshot($task);
            $oldStatus = $task->status;

            foreach (['title', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $task->{$field} = $data[$field];
                }
            }
            if (array_key_exists('priority', $data)) {
                $task->priority = Priority::from($data['priority']);
            }
            if (array_key_exists('due_date', $data) && $data['due_date'] !== LegalDate::toDate($task->due_at)) {
                $task->due_at = LegalDate::endOfDay($data['due_date']);
            }
            if (array_key_exists('status', $data)) {
                $task->status = TaskStatus::from($data['status']);
                if ($task->status === TaskStatus::Completed && $oldStatus !== TaskStatus::Completed) {
                    $task->completed_at = Carbon::now();
                } elseif ($task->status !== TaskStatus::Completed) {
                    $task->completed_at = null; // reopened (or never completed)
                }
            }

            if (! $task->isDirty()) {
                return $task;
            }
            $descriptionChanged = $task->isDirty('description');
            $task->save();

            $after = $this->snapshot($task);
            $changes = [];
            foreach (['title', 'priority', 'due_date'] as $field) {
                if ($before[$field] !== $after[$field]) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $after[$field]];
                }
            }
            if ($descriptionChanged) {
                $changes['description'] = ['changed' => true];
            }
            if ($oldStatus !== $task->status && $oldStatus !== TaskStatus::Completed && $task->status !== TaskStatus::Completed) {
                $changes['status'] = ['from' => $oldStatus->value, 'to' => $task->status->value];
            }

            if ($changes !== []) {
                $labels = array_map(fn (string $f) => str_replace(['_date', '_'], [' date', ' '], $f), array_keys($changes));
                $this->activity->record($matter, ActivityEventType::TaskUpdated,
                    "Task updated: {$task->title} (".implode(', ', $labels).').', $actor, $task, ['changes' => $changes]);
            }
            if ($oldStatus !== TaskStatus::Completed && $task->status === TaskStatus::Completed) {
                $this->activity->record($matter, ActivityEventType::TaskCompleted, "Task completed: {$task->title}.", $actor, $task);
            } elseif ($oldStatus === TaskStatus::Completed && $task->status !== TaskStatus::Completed) {
                $this->activity->record($matter, ActivityEventType::TaskReopened, "Task reopened: {$task->title}.", $actor, $task,
                    ['to' => $task->status->value]);
            }
            $this->touchMatter($matter);

            return $task;
        });
    }

    public function delete(Matter $matter, Task $task, User $actor): void
    {
        DB::transaction(function () use ($matter, $task, $actor) {
            $matter = $this->lockMatter($matter);
            $task = Task::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($task->getKey());
            $title = $task->title;
            $id = $task->id;
            $task->delete();

            // The event keeps the task's id (subject) and title so history explains what disappeared.
            $this->activity->record($matter, ActivityEventType::TaskDeleted, "Task deleted: {$title}.", $actor, null,
                ['task_id' => $id, 'title' => $title]);
            $this->touchMatter($matter);
        });
    }

    private function lockMatter(Matter $matter): Matter
    {
        $locked = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
        $locked->assertNotArchived();

        return $locked;
    }

    /** Work on a matter counts as matter activity for "recently updated" ordering. */
    private function touchMatter(Matter $matter): void
    {
        Matter::query()->whereKey($matter->getKey())->update(['updated_at' => Carbon::now()]);
    }

    /** @return array<string, string|null> */
    private function snapshot(Task $task): array
    {
        return [
            'title' => $task->title,
            'priority' => $task->priority->value,
            'due_date' => LegalDate::toDate($task->due_at),
        ];
    }
}
