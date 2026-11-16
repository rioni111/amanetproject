<?php

namespace App\Services;

use App\Enums\ActivityEventType;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Internal (confidential) notes. Note bodies are NEVER written to activity metadata or logs:
 * history records only that a note was added, edited or deleted, by whom and when.
 *
 * Deletion policy: a note is hard-deleted (its confidential text is not retained), and an append-only
 * "note.deleted" event keeps the fact, the actor and the time.
 */
class MatterNoteService
{
    public const STALE_MESSAGE = 'This note was changed by someone else after you opened it. Reload to see the latest version.';

    public function __construct(private readonly ActivityLogger $activity) {}

    public function create(Matter $matter, string $body, User $actor): MatterNote
    {
        return DB::transaction(function () use ($matter, $body, $actor) {
            $matter = $this->lockMatter($matter);

            $note = new MatterNote;
            $note->forceFill([
                'matter_id' => $matter->id,
                'author_id' => $actor->id, // from the session, never from the request
                'body' => $body,
            ])->save();

            $this->activity->record($matter, ActivityEventType::NoteAdded, 'Internal note added.', $actor, $note);
            $this->touchMatter($matter);

            return $note;
        });
    }

    public function update(Matter $matter, MatterNote $note, string $body, User $actor, ?int $lockVersion = null): MatterNote
    {
        return DB::transaction(function () use ($matter, $note, $body, $actor, $lockVersion) {
            $matter = $this->lockMatter($matter);
            $note = MatterNote::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($note->getKey());
            $note->assertLockVersion($lockVersion, self::STALE_MESSAGE);

            $note->body = $body;
            if (! $note->isDirty()) {
                return $note;
            }
            $note->save();

            $this->activity->record($matter, ActivityEventType::NoteUpdated, 'Internal note edited.', $actor, $note);
            $this->touchMatter($matter);

            return $note;
        });
    }

    public function delete(Matter $matter, MatterNote $note, User $actor): void
    {
        DB::transaction(function () use ($matter, $note, $actor) {
            $matter = $this->lockMatter($matter);
            $note = MatterNote::query()->whereBelongsTo($matter)->lockForUpdate()->findOrFail($note->getKey());
            $id = $note->id;
            $note->delete();

            $this->activity->record($matter, ActivityEventType::NoteDeleted, 'Internal note deleted.', $actor, null, ['note_id' => $id]);
            $this->touchMatter($matter);
        });
    }

    private function lockMatter(Matter $matter): Matter
    {
        $locked = Matter::query()->lockForUpdate()->findOrFail($matter->getKey());
        $locked->assertNotArchived();

        return $locked;
    }

    private function touchMatter(Matter $matter): void
    {
        Matter::query()->whereKey($matter->getKey())->update(['updated_at' => Carbon::now()]);
    }
}
