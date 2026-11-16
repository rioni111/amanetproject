<?php

namespace App\Models;

use App\Enums\MatterStatus;
use App\Enums\Priority;
use Database\Factories\MatterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Matter extends Model
{
    /** @use HasFactory<MatterFactory> */
    use HasFactory;

    protected $fillable = [
        'matter_number',
        'client_id',
        'title',
        'description',
        'matter_type',
        'status',
        'priority',
        'opened_at',
        'due_at',
        'closed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MatterStatus::class,
            'priority' => Priority::class,
            'opened_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(MatterNote::class);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    /** @param Builder<Matter> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', MatterStatus::activeValues());
    }

    /**
     * Active matters whose deadline falls within [now, now + days].
     *
     * @param  Builder<Matter>  $query
     */
    public function scopeDueWithin(Builder $query, int $days, ?Carbon $now = null): void
    {
        $now ??= Carbon::now();
        $query->active()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$now, $now->copy()->addDays($days)->endOfDay()]);
    }

    /** @param Builder<Matter> $query */
    public function scopeOverdue(Builder $query, ?Carbon $now = null): void
    {
        $query->active()->whereNotNull('due_at')->where('due_at', '<', $now ?? Carbon::now());
    }

    public function isOverdue(): bool
    {
        return $this->status->isActive() && $this->due_at !== null && $this->due_at->isPast();
    }
}
