<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Optimistic concurrency. Every update increments `lock_version`; a client that edits from a stale
 * copy sends the version it loaded and receives 409 Conflict instead of silently overwriting a newer
 * change (see the services, which compare after locking the row with SELECT … FOR UPDATE).
 *
 * @mixin Model
 */
trait HasLockVersion
{
    protected static function bootHasLockVersion(): void
    {
        static::updating(function (Model $model) {
            $model->setAttribute('lock_version', ((int) $model->getOriginal('lock_version')) + 1);
        });
    }

    /** Throws 409 when the caller supplied a version and it is not the current one. */
    public function assertLockVersion(?int $expected, string $message): void
    {
        if ($expected !== null && $expected !== (int) $this->getAttribute('lock_version')) {
            throw new ConflictHttpException($message);
        }
    }
}
