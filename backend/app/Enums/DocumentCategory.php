<?php

namespace App\Enums;

/** How documents are organised in the library (flat categories, no folder tree). */
enum DocumentCategory: string
{
    use HasValues;

    case Contracts = 'contracts';
    case Agreements = 'agreements';
    case Correspondence = 'correspondence';
    case Evidence = 'evidence';
    case CourtDocuments = 'court_documents';
    case Identification = 'identification';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contracts => 'Contracts',
            self::Agreements => 'Agreements',
            self::Correspondence => 'Correspondence',
            self::Evidence => 'Evidence',
            self::CourtDocuments => 'Court documents',
            self::Identification => 'Identification',
            self::Other => 'Other',
        };
    }
}
