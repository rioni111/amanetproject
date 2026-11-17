<?php

namespace App\Enums;

/**
 * How the invitation that established "Sent" reached the signer — kept separate from signing progress.
 * shared_manually: the administrator confirmed sharing it outside the app (Phase 5).
 * email_accepted:  an invitation e-mail was ACCEPTED by the configured SMTP server (Phase 6). This is the
 *                  strongest fact SMTP submission establishes; it does not prove arrival in the inbox.
 * The full history of every e-mail attempt lives in email_deliveries.
 */
enum DeliveryStatus: string
{
    use HasValues;

    case NotShared = 'not_shared';
    case SharedManually = 'shared_manually';
    case EmailAccepted = 'email_accepted';

    public function label(): string
    {
        return match ($this) {
            self::NotShared => 'Not shared yet',
            self::SharedManually => 'Shared by the administrator (outside the app)',
            self::EmailAccepted => 'Invitation e-mail accepted by the mail server',
        };
    }
}
