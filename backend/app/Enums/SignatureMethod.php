<?php

namespace App\Enums;

/** How the signature appearance was produced (both are rendered as vector content into the PDF). */
enum SignatureMethod: string
{
    use HasValues;

    case Drawn = 'drawn';
    case Typed = 'typed';

    public function label(): string
    {
        return $this === self::Drawn ? 'Drawn signature' : 'Typed signature';
    }
}
