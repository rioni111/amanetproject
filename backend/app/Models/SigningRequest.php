<?php

namespace App\Models;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Enums\SecureLinkPurpose;
use App\Enums\SignerType;
use App\Enums\SigningCancellationReason;
use App\Enums\SigningStatus;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A request for ONE signer to sign ONE immutable PDF version. Signer, source and lifecycle columns are
 * written only by the signing services; the database refuses reassignment and changes after completion.
 */
class SigningRequest extends Model
{
    use HasLockVersion;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'signer_type' => SignerType::class,
            'status' => SigningStatus::class,
            'delivery_channel' => DeliveryChannel::class,
            'delivery_status' => DeliveryStatus::class,
            'cancellation_reason' => SigningCancellationReason::class,
            'sent_at' => 'immutable_datetime',
            'viewed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function signedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'signed_document_version_id');
    }

    public function signerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function signatureRecord(): HasOne
    {
        return $this->hasOne(SignatureRecord::class);
    }

    /** Route-binding relation for /signing-requests/{signingRequest}/links/{link}. */
    public function links(): HasMany
    {
        return $this->hasMany(SecureAccessLink::class)->where('purpose', SecureLinkPurpose::DocumentSigning->value);
    }

    /** The one signing link that may still be used (partial unique index). */
    public function usableLink(): HasOne
    {
        return $this->hasOne(SecureAccessLink::class)
            ->where('purpose', SecureLinkPurpose::DocumentSigning->value)
            ->whereNull('revoked_at')
            ->whereNull('consumed_at');
    }

    public function isClientSigner(): bool
    {
        return $this->signer_type === SignerType::Client;
    }
}
