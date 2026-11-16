<?php

namespace App\Enums;

/**
 * Shared helpers for string-backed enums used in validation rules,
 * database check constraints and API option lists.
 */
trait HasValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** SQL fragment for CHECK constraints, e.g. "'low', 'medium'". */
    public static function sqlList(): string
    {
        return implode(', ', array_map(fn (string $v) => "'".$v."'", self::values()));
    }
}
