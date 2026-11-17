<?php

namespace App\Enums;

/** What an e-mail is for. Each type has one template (App\Mail\ClientActionMail) and one action page. */
enum EmailCommunicationType: string
{
    use HasValues;

    case DocumentRequest = 'document_request';                   // first request for a document
    case DocumentCorrection = 'document_correction';             // a correction was requested
    case DocumentRequestReminder = 'document_request_reminder';  // automatic follow-up (scheduler)
    case SigningInvitation = 'signing_invitation';
    case SigningReminder = 'signing_reminder';                   // automatic follow-up (scheduler)
    case Test = 'test';                                          // administrator's own transport test

    public function label(): string
    {
        return match ($this) {
            self::DocumentRequest => 'Document request e-mail',
            self::DocumentCorrection => 'Correction request e-mail',
            self::DocumentRequestReminder => 'Document request reminder',
            self::SigningInvitation => 'Signing invitation e-mail',
            self::SigningReminder => 'Signing reminder',
            self::Test => 'Test e-mail',
        };
    }

    public function isReminder(): bool
    {
        return $this === self::DocumentRequestReminder || $this === self::SigningReminder;
    }

    public function isSigning(): bool
    {
        return $this === self::SigningInvitation || $this === self::SigningReminder;
    }
}
