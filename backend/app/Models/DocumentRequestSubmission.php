<?php

namespace App\Models;

use App\Enums\SubmissionReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One successful client upload for a request. The submitted facts (version, number, message, time)
 * are immutable and rows are never deleted (model guard + PostgreSQL trigger); only the review
 * fields change, once, from pending → in review → a final decision.
 */
class DocumentRequestSubmission extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'review_status' => SubmissionReviewStatus::class,
            'submission_number' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'review_started_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Document request submissions cannot be deleted.'));
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(SecureAccessLink::class, 'secure_access_link_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->review_status, [
            SubmissionReviewStatus::Approved, SubmissionReviewStatus::CorrectionRequested, SubmissionReviewStatus::Cancelled,
        ], true);
    }
}
