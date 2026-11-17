<?php

namespace App\Models;

use App\Enums\EmailCommunicationType;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailFailureCategory;
use App\Enums\EmailTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One e-mail (and its attempt history). An audit record: never deleted, target and recipient never change,
 * a finished outcome is final (database trigger). It never holds the action link, token or message body.
 */
class EmailDelivery extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'communication_type' => EmailCommunicationType::class,
            'initiated_by' => EmailTrigger::class,
            'status' => EmailDeliveryStatus::class,
            'failure_category' => EmailFailureCategory::class,
            'attempt_count' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function signingRequest(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(SecureAccessLink::class, 'secure_access_link_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
