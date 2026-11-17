<?php

namespace App\Models;

use App\Enums\SignatureMethod;
use App\Enums\SignerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Evidence of one completed signing action. Immutable (model guard + PostgreSQL trigger). Contains no
 * signature image/strokes, tokens or file paths — only what the evidence summary needs.
 */
class SignatureRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'signer_type' => SignerType::class,
            'signature_method' => SignatureMethod::class,
            'placement' => 'array',
            'page_number' => 'integer',
            'consent_accepted_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Signature records are immutable.'));
        static::deleting(fn () => throw new LogicException('Signature records are immutable.'));
    }

    public function signingRequest(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class);
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'source_document_version_id');
    }

    public function signedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'signed_document_version_id');
    }
}
