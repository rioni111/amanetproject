<?php

namespace App\Models;

use App\Enums\LinkRevocationReason;
use App\Enums\SecureLinkPurpose;
use App\Enums\SecureLinkState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A purpose-scoped bearer link. Only the SHA-256 hash of its token is stored; the token itself is
 * shown to the administrator once, in the response that created it, and can never be recovered.
 */
class SecureAccessLink extends Model
{
    protected $fillable = [];

    /** The hash never leaves the server, not even to administrators. */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => SecureLinkPurpose::class,
            'revocation_reason' => LinkRevocationReason::class,
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'first_opened_at' => 'immutable_datetime',
            'max_uses' => 'integer',
            'successful_uses' => 'integer',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function signingRequest(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function state(): SecureLinkState
    {
        return match (true) {
            $this->revoked_at !== null => SecureLinkState::Revoked,
            $this->consumed_at !== null => SecureLinkState::Used,
            $this->expires_at->isPast() => SecureLinkState::Expired,
            default => SecureLinkState::Active,
        };
    }

    public function isUsable(): bool
    {
        return $this->state() === SecureLinkState::Active;
    }
}
