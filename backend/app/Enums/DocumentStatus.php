<?php

namespace App\Enums;

/**
 * Document LIFECYCLE only (is the document in active use?). Review states of client submissions
 * (Phase 4) and signing states (Phase 5) are separate concepts with their own tables — a document is
 * never "signed" because of what its PDF looks like.
 */
enum DocumentStatus: string
{
    use HasValues;

    case Active = 'active';
    case Archived = 'archived';
}
