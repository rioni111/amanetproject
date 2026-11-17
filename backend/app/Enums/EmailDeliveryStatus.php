<?php

namespace App\Enums;

/**
 * Honest delivery states. E-mails are sent synchronously after the request state has committed (no queue,
 * so no token ever sits in a job payload) — there is therefore no "queued" state.
 *
 * pending   prepared, no attempt yet (only transient inside a claim)
 * sending   an SMTP submission is in progress (claimed; at most one per request)
 * accepted  the SMTP server accepted the message ("250 OK"). Not proof of arrival in the recipient's inbox.
 * failed    the attempt failed or the outcome is unknown (worker interrupted) — a safe category is recorded
 * cancelled the communication became ineligible before it was sent
 * skipped   an automatic communication was intentionally not sent (recorded only when a slot was claimed)
 *
 * "delivered" / "bounced" are deliberately absent: they would need provider webhooks, which are not configured.
 */
enum EmailDeliveryStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Prepared',
            self::Sending => 'Sending',
            self::Accepted => 'Accepted by the mail server',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Skipped => 'Skipped',
        };
    }
}
