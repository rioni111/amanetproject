<?php

namespace App\Enums;

/**
 * What a secure access link authorises. Each purpose has its own endpoints, rules and expiry; a
 * token is only ever accepted by the endpoints of its own purpose.
 *  - document_upload  (Phase 4) submit one PDF for one document request
 *  - document_signing (Phase 5, not implemented) reserved so the shared table needs no migration
 */
enum SecureLinkPurpose: string
{
    use HasValues;

    case DocumentUpload = 'document_upload';
    case DocumentSigning = 'document_signing';
}
