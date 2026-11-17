<?php

namespace App\Enums;

/** Administrator in-app notifications (database channel). */
enum NotificationKind: string
{
    use HasValues;

    case DocumentSubmitted = 'document_submitted';
    case CorrectionSubmitted = 'correction_submitted';
    case DocumentSigned = 'document_signed';
    case RequestOverdue = 'request_overdue';
    case SigningLinkExpired = 'signing_link_expired';
    case EmailFailed = 'email_failed';

    public function title(): string
    {
        return match ($this) {
            self::DocumentSubmitted => 'Client document received — Review required',
            self::CorrectionSubmitted => 'Corrected document received',
            self::DocumentSigned => 'Document signed successfully',
            self::RequestOverdue => 'Document request overdue',
            self::SigningLinkExpired => 'Signing link expired',
            self::EmailFailed => 'Email delivery failed — Action required',
        };
    }
}
