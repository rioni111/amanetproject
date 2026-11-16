<?php

namespace App\Models;

use App\Enums\ActivityEventType;
use App\Enums\ActorType;
use Database\Factories\ActivityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Append-only matter history. Updates and deletes are rejected both here and
 * by a PostgreSQL trigger (see the create_activity_events_table migration).
 */
class ActivityEvent extends Model
{
    /** @use HasFactory<ActivityEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'matter_id',
        'actor_type',
        'actor_id',
        'actor_label',
        'event_type',
        'subject_type',
        'subject_id',
        'description',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'event_type' => ActivityEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity events are append-only and cannot be modified.'));
        static::deleting(fn () => throw new LogicException('Activity events are append-only and cannot be deleted.'));
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
