<?php

namespace App\Models;

use App\Enums\DocumentOrigin;
use App\Enums\DocumentVersionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One stored PDF. Immutable: updates and deletes are rejected here and by a PostgreSQL trigger.
 * storage_disk / storage_path are internal and never serialised (see DocumentVersionResource).
 */
class DocumentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $hidden = ['storage_disk', 'storage_path'];

    protected function casts(): array
    {
        return [
            'kind' => DocumentVersionKind::class,
            'origin' => DocumentOrigin::class,
            'version_number' => 'integer',
            'file_size' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Document versions are immutable.'));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function label(): string
    {
        return 'v'.$this->version_number;
    }
}
