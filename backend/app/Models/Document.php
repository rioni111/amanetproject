<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\DocumentOrigin;
use App\Enums\DocumentStatus;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A logical document inside a matter. Its files are immutable DocumentVersion rows; metadata
 * (title, description, category) is editable and optimistic-locked.
 */
class Document extends Model
{
    use HasLockVersion;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'status' => DocumentStatus::class,
            'origin' => DocumentOrigin::class,
            'archived_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'latest_version_number' => 'integer',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isArchived(): bool
    {
        return $this->status === DocumentStatus::Archived;
    }

    public function assertNotArchived(): void
    {
        if ($this->isArchived()) {
            throw new ConflictHttpException('This document is archived. Restore it to make changes.');
        }
    }
}
