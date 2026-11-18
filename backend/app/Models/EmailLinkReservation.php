<?php

namespace App\Models;

use App\Enums\EmailLinkReservationStatus;
use App\Enums\SecureLinkPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The SHA-256 hash of an e-mailed token, reserved before the SMTP submission. Never a usable link by itself
 * (see EmailLinkActivationService). Holds no token, URL or message content.
 */
class EmailLinkReservation extends Model
{
    protected $fillable = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => SecureLinkPurpose::class,
            'status' => EmailLinkReservationStatus::class,
            'expires_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(EmailDelivery::class, 'email_delivery_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(SecureAccessLink::class, 'secure_access_link_id');
    }
}
