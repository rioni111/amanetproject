<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A firm administrator. External clients never get user accounts.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /** Emails are stored lower-cased so uniqueness and login are case-insensitive. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(MatterNote::class, 'author_id');
    }

    /**
     * Phase 6: in-app notifications (Laravel's database channel creates them through this relation), newest
     * first with microsecond precision. Scoped to this user — another user's notification is never reachable.
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(WorkspaceNotificationRecord::class, 'notifiable')->latest();
    }
}
