<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Concerns\HasLockVersion;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasLockVersion;

    protected $fillable = [
        'matter_id',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => Priority::class,
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status !== TaskStatus::Completed;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Task> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', TaskStatus::openValues());
    }
}
