<?php

namespace App\Enums;

/** Review state of one client submission. Decisions (approved, correction_requested) are final. */
enum SubmissionReviewStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case InReview = 'in_review';
    case Approved = 'approved';
    case CorrectionRequested = 'correction_requested';
    case Cancelled = 'cancelled'; // the request was cancelled before a decision

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting review',
            self::InReview => 'In review',
            self::Approved => 'Approved',
            self::CorrectionRequested => 'Correction requested',
            self::Cancelled => 'Not reviewed (request cancelled)',
        };
    }
}
