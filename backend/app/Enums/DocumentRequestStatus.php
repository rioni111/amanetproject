<?php

namespace App\Enums;

/**
 * Lifecycle of a client document request. Transitions are enforced by DocumentRequestService and
 * DocumentReviewService; the API never accepts a status from the client.
 *
 *   pending ──upload──▶ submitted ──start review──▶ under_review ──approve──▶ approved
 *      │                    │                           │
 *      │                    │                           └─request correction─▶ correction_requested ──upload──▶ submitted …
 *      └──────────── cancel (from every open state) ──────────────────────────────▶ cancelled
 */
enum DocumentRequestStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case CorrectionRequested = 'correction_requested';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting client',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::CorrectionRequested => 'Correction requested',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Submitted, self::Cancelled],
            self::Submitted => [self::UnderReview, self::Cancelled],
            self::UnderReview => [self::Approved, self::CorrectionRequested, self::Cancelled],
            self::CorrectionRequested => [self::Submitted, self::Cancelled],
            self::Approved, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Open = the requirement is still outstanding (on the client's or the firm's side). */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Approved, self::Cancelled], true);
    }

    /** States in which the client may upload (through a valid link). */
    public function acceptsUploads(): bool
    {
        return $this === self::Pending || $this === self::CorrectionRequested;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Submitted->value, self::UnderReview->value, self::CorrectionRequested->value];
    }

    /** Waiting for the client: these are the states that can become overdue. @return list<string> */
    public static function awaitingClientValues(): array
    {
        return [self::Pending->value, self::CorrectionRequested->value];
    }

    /** Waiting for the firm. @return list<string> */
    public static function awaitingReviewValues(): array
    {
        return [self::Submitted->value, self::UnderReview->value];
    }
}
