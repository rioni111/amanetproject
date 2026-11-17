<?php

namespace App\Enums;

/** Why an unsigned signing request was closed. */
enum SigningCancellationReason: string
{
    use HasValues;

    case Manual = 'manual';                     // an administrator cancelled it
    case MatterArchived = 'matter_archived';
    case DocumentArchived = 'document_archived';
    case SourceSuperseded = 'source_superseded'; // a newer version was added: the pinned source is no longer current

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Cancelled by an administrator',
            self::MatterArchived => 'Matter archived',
            self::DocumentArchived => 'Document archived',
            self::SourceSuperseded => 'A newer version of the document was added',
        };
    }
}
