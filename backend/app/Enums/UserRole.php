<?php

namespace App\Enums;

enum UserRole: string
{
    use HasValues;

    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
        };
    }
}
