<?php

namespace App\Services;

use App\Enums\ActivityEventType;
use App\Enums\MatterStatus;
use App\Enums\Priority;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use App\Support\LegalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matter lifecycle: creation with numbering, detail updates, status transitions, archive and restore.
 *
 * Every public method runs in ONE database transaction that contains both the change and its
 * activity event; if the history entry cannot be written, the change is rolled back.
 * Existing matters are re-read with SELECT … FOR UPDATE, so concurrent requests are serialised per
 * matter and transitions are always validated against the current state.
 */
class MatterService
{
    public const STALE_MESSAGE = 'This matter was changed by someone else after you opened it. Reload to see the latest version.';

    /** Fields whose before/after values are safe to keep in history metadata. */
    private const TRACKED_FIELDS = ['title', 'matter_type', 'opened_date', 'due_date'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly MatterNumberGenerator $numbers,
    ) {}

    /** @param array<string, mixed> $data validated input */
    public function create(array $data, User $actor): Matter
    {
        return DB::transaction(function () use ($data, $actor) {
            $client = Client::query()->findOrFail($data['client_id']);
            $status = MatterStatus::from($data['status'] ?? MatterStatus::Open->value);

            $matter = new Matter;
            $matter->forceFill([
                'matter_number' => $this->numbers->next(),
                'client_id' => $client->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'matter_type' => $data['matter_type'] ?? null,
                'status' => $status,
                'priority' => Priority::from($data['priority'] ?? Priority::Medium->value),
                'opened_at' => isset($data['opened_date']) ? LegalDate::startOfDay($data['opened_date']) : Carbon::now(),
                'due_at' => LegalDate::endOfDay($data['due_date'] ?? null),
            ])->save();

            $this->activity->record($matter, ActivityEventType::MatterCreated,
                "Matter {$matter->matter_number} opened for ".rtrim($client->name, '.').'.', $actor, $matter,
                ['status' => $status->value, 'priority' => $matter->priority->value]);

            return $matter;
        });
    }

    /** @param array<string, mixed> $data validated input (status is changed through transition()) */
    public function update(Matter $matter, array $data, User $actor): Matter
    {
        return DB::transaction(function () use ($matter, $data, $actor) {
            $matter = $this->lock($matter);
            $matter->assertLockVersion($data['lock_version'] ?? null, self::STALE_MESSAGE);
            $matter->assertNotArchived();

            $before = $this->snapshot($matter);
            $oldPriority = $matter->priority;
            $oldClient = $matter->client;

            foreach (['title', 'description', 'matter_type'] as $field) {
                if (array_key_exists($field, $data)) {
                    $matter->{$field} = $data[$field];
                }
            }
            if (array_key_exists('priority', $data)) {
                $matter->priority = Priority::from($data['priority']);
            }
            if (array_key_exists('opened_date', $data) && $data['opened_date'] !== null) {
                // Keep the exact original timestamp when the calendar date is unchanged.
                if ($data['opened_date'] !== LegalDate::toDate($matter->opened_at)) {
                    $matter->opened_at = LegalDate::startOfDay($data['opened_date']);
                }
            }
            if (array_key_exists('due_date', $data) && $data['due_date'] !== LegalDate::toDate($matter->due_at)) {
                $matter->due_at = LegalDate::endOfDay($data['due_date']);
            }
            $newClient = null;
            if (array_key_exists('client_id', $data) && (int) $data['client_id'] !== $matter->client_id) {
                $newClient = Client::query()->findOrFail($data['client_id']);
                $matter->client_id = $newClient->id;
            }

            $this->assertDatesConsistent($matter);

            if (! $matter->isDirty()) {
                return $matter;
            }
            $descriptionChanged = $matter->isDirty('description');
            $matter->save();

            $after = $this->snapshot($matter);
            $changes = [];
            foreach (self::TRACKED_FIELDS as $field) {
                if ($before[$field] !== $after[$field]) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $after[$field]];
                }
            }
            if ($newClient) {
                $changes['client'] = ['from' => $oldClient?->name, 'to' => $newClient->name];
            }
            if ($descriptionChanged) {
                // The description may be confidential: record that it changed, not its content.
                $changes['description'] = ['changed' => true];
            }

            if ($changes !== []) {
                $labels = array_map(fn (string $f) => str_replace(['_date', '_'], [' date', ' '], $f), array_keys($changes));
                $this->activity->record($matter, ActivityEventType::MatterUpdated,
                    'Matter details updated: '.implode(', ', $labels).'.', $actor, $matter, ['changes' => $changes]);
            }

            if ($oldPriority !== $matter->priority) {
                $this->activity->record($matter, ActivityEventType::MatterPriorityChanged,
                    "Priority changed from {$oldPriority->label()} to {$matter->priority->label()}.", $actor, $matter,
                    ['from' => $oldPriority->value, 'to' => $matter->priority->value]);
            }

            return $matter;
        });
    }

    public function transition(Matter $matter, MatterStatus $target, User $actor, ?int $lockVersion = null): Matter
    {
        return DB::transaction(function () use ($matter, $target, $actor, $lockVersion) {
            $matter = $this->lock($matter);
            $matter->assertLockVersion($lockVersion, self::STALE_MESSAGE);
            $matter->assertNotArchived();
            $from = $matter->status;

            if ($from === $target) {
                return $matter; // idempotent: nothing to record
            }
            if (! $from->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => ["A matter cannot move from {$from->label()} to {$target->label()}."],
                ]);
            }

            $matter->status = $target;
            if ($target === MatterStatus::Closed) {
                $matter->closed_at = Carbon::now();
            } elseif ($from === MatterStatus::Closed) {
                $matter->closed_at = null; // reopened
            }
            $matter->save();

            $verb = $from === MatterStatus::Closed ? 'Matter reopened' : 'Status changed';
            $this->activity->record($matter, ActivityEventType::MatterStatusChanged,
                "{$verb}: {$from->label()} → {$target->label()}.", $actor, $matter,
                ['from' => $from->value, 'to' => $target->value]);

            return $matter;
        });
    }

    public function archive(Matter $matter, User $actor, ?int $lockVersion = null): Matter
    {
        return DB::transaction(function () use ($matter, $actor, $lockVersion) {
            $matter = $this->lock($matter);
            $matter->assertLockVersion($lockVersion, self::STALE_MESSAGE);
            if (! $matter->status->canBeArchived()) {
                throw ValidationException::withMessages(['status' => ['This matter is already archived.']]);
            }

            $from = $matter->status;
            $matter->forceFill([
                'status' => MatterStatus::Archived,
                'archived_from_status' => $from,
                'archived_at' => Carbon::now(),
            ])->save();

            $this->activity->record($matter, ActivityEventType::MatterArchived,
                "Matter archived (was {$from->label()}).", $actor, $matter,
                ['from' => $from->value, 'to' => MatterStatus::Archived->value]);

            return $matter;
        });
    }

    public function restore(Matter $matter, User $actor, ?int $lockVersion = null): Matter
    {
        return DB::transaction(function () use ($matter, $actor, $lockVersion) {
            $matter = $this->lock($matter);
            $matter->assertLockVersion($lockVersion, self::STALE_MESSAGE);
            if (! $matter->isArchived()) {
                throw ValidationException::withMessages(['status' => ['Only archived matters can be restored.']]);
            }

            // Restore to the status held before archiving; legacy rows fall back to closed/open.
            $target = $matter->archived_from_status
                ?? ($matter->closed_at !== null ? MatterStatus::Closed : MatterStatus::Open);
            $matter->forceFill([
                'status' => $target,
                'archived_from_status' => null,
                'archived_at' => null,
                'closed_at' => $target === MatterStatus::Closed ? ($matter->closed_at ?? Carbon::now()) : null,
            ])->save();

            $this->activity->record($matter, ActivityEventType::MatterRestored,
                "Matter restored from the archive to {$target->label()}.", $actor, $matter,
                ['from' => MatterStatus::Archived->value, 'to' => $target->value]);

            return $matter;
        });
    }

    private function lock(Matter $matter): Matter
    {
        return Matter::query()->with('client:id,name,client_type,reference_number')->lockForUpdate()->findOrFail($matter->getKey());
    }

    /** @return array<string, string|null> */
    private function snapshot(Matter $matter): array
    {
        return [
            'title' => $matter->title,
            'matter_type' => $matter->matter_type,
            'opened_date' => LegalDate::toDate($matter->opened_at),
            'due_date' => LegalDate::toDate($matter->due_at),
        ];
    }

    private function assertDatesConsistent(Matter $matter): void
    {
        if ($matter->due_at !== null && $matter->opened_at !== null
            && LegalDate::toDate($matter->due_at) < LegalDate::toDate($matter->opened_at)) {
            throw ValidationException::withMessages(['due_date' => ['The deadline cannot be before the opening date.']]);
        }
    }
}
