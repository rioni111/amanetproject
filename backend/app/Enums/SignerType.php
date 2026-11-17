<?php

namespace App\Enums;

/** Who signs: the authenticated administrator, or the matter's client through a secure link. */
enum SignerType: string
{
    use HasValues;

    case Admin = 'admin';
    case Client = 'client';

    public function label(): string
    {
        return $this === self::Admin ? 'Administrator' : 'Client';
    }
}
