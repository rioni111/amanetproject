<?php

namespace App\Enums;

enum ClientType: string
{
    use HasValues;

    case Individual = 'individual';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Organization => 'Organization',
        };
    }
}
