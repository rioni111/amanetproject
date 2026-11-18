<?php

namespace App\Enums;

/**
 * Safe failure categories. The raw transport error is never stored or shown (it can contain server banners,
 * addresses or configuration details); only one of these categories is.
 */
enum EmailFailureCategory: string
{
    use HasValues;

    case Connection = 'connection';                 // refused / unreachable / DNS
    case Timeout = 'timeout';
    case Authentication = 'authentication';         // the server rejected the SMTP credentials
    case RecipientRejected = 'recipient_rejected';  // the server refused the recipient address
    case SenderRejected = 'sender_rejected';        // the server refused the configured sender
    case Configuration = 'configuration';           // e-mail is not configured / misconfigured
    case InvalidRecipient = 'invalid_recipient';    // no usable address on file
    case Interrupted = 'interrupted';               // the process stopped during sending: outcome unknown
    case Unknown = 'unknown';
    // Reasons carried by cancelled / skipped records (not transport failures):
    case Ineligible = 'ineligible';                 // the request no longer needs this action (signed, cancelled, …)
    case ReminderLimit = 'reminder_limit';          // the automatic-reminder cap for the request was reached

    /**
     * The server explicitly refused the message (or it was never handed over), so its link can never have
     * reached anyone. Connection drops, timeouts, interruptions and unknown errors are NOT certain: the server
     * may have accepted the message before the answer was lost.
     */
    public function definitelyNotDelivered(): bool
    {
        return in_array($this, [self::Authentication, self::RecipientRejected, self::SenderRejected, self::Configuration, self::InvalidRecipient], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Connection => 'The mail server could not be reached.',
            self::Timeout => 'The mail server did not respond in time.',
            self::Authentication => 'The mail server rejected the configured login.',
            self::RecipientRejected => 'The mail server refused the recipient address.',
            self::SenderRejected => 'The mail server refused the configured sender address.',
            self::Configuration => 'E-mail sending is not configured correctly.',
            self::InvalidRecipient => 'There is no valid e-mail address for the recipient.',
            self::Interrupted => 'Sending was interrupted; it is unknown whether the mail server accepted the message.',
            self::Unknown => 'The e-mail could not be sent.',
            self::Ineligible => 'Not sent: the request no longer needs this action.',
            self::ReminderLimit => 'Not sent: the maximum number of automatic reminders was reached.',
        };
    }
}
