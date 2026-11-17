<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Enums\DocumentRequestStatus;
use App\Enums\SecureLinkPurpose;
use App\Models\Concerns\HasLockVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A document the firm asked a matter's client to provide. Lifecycle columns (status, document,
 * review and approval fields) are written only by the services — never mass-assigned.
 */
class DocumentRequest extends Model
{
    use HasLockVersion;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'requested_category' => DocumentCategory::class,
            'status' => DocumentRequestStatus::class,
            'due_at' => 'immutable_datetime',
            'last_submitted_at' => 'immutable_datetime',
            'review_started_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'submissions_count' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function approvedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'approved_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(DocumentRequestSubmission::class);
    }

    public function latestSubmission(): HasOne
    {
        return $this->hasOne(DocumentRequestSubmission::class)->ofMany('submission_number', 'max');
    }

    /** Route-binding relation for /document-requests/{documentRequest}/links/{link}. */
    public function links(): HasMany
    {
        return $this->hasMany(SecureAccessLink::class)->where('purpose', SecureLinkPurpose::DocumentUpload->value);
    }

    /** The one link that may still be used (at most one exists: partial unique index). */
    public function usableLink(): HasOne
    {
        return $this->hasOne(SecureAccessLink::class)
            ->where('purpose', SecureLinkPurpose::DocumentUpload->value)
            ->whereNull('revoked_at')
            ->whereNull('consumed_at');
    }

    /**
     * Overdue = still waiting for the client (pending or correction requested) and the due day has
     * passed in the firm's time zone (due_at is the end of that day).
     *
     * @param  Builder<DocumentRequest>  $query
     */
    public function scopeOverdue(Builder $query, ?Carbon $now = null): void
    {
        $query->whereIn('document_requests.status', DocumentRequestStatus::awaitingClientValues())
            ->whereNotNull('document_requests.due_at')
            ->where('document_requests.due_at', '<', $now ?? Carbon::now());
    }

    public function isOverdue(): bool
    {
        return in_array($this->status->value, DocumentRequestStatus::awaitingClientValues(), true)
            && $this->due_at !== null && $this->due_at->isPast();
    }
}
