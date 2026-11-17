<?php

namespace App\Enums;

/**
 * Delivery outcome of the invitation — kept separate from signing progress. Phase 5 records only what the
 * administrator confirmed ("shared_manually"); it never claims an email was sent. Phase 6 adds the email outcomes.
 */
enum DeliveryStatus: string
{
    use HasValues;

    case NotShared = 'not_shared';
    case SharedManually = 'shared_manually';

    public function label(): string
    {
        return $this === self::NotShared ? 'Not shared yet' : 'Shared by the administrator (outside the app)';
    }
}
