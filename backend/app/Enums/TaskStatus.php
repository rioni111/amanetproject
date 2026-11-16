<?php

namespace App\Enums;

enum TaskStatus: string
{
    use HasValues;

    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
        };
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Todo->value, self::InProgress->value];
    }
}
