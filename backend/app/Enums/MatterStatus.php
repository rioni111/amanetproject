<?php

namespace App\Enums;

enum MatterStatus: string
{
    use HasValues;

    case Open = 'open';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::OnHold => 'On hold',
            self::Closed => 'Closed',
            self::Archived => 'Archived',
        };
    }

    /**
     * "Active" matters are those the firm is still working on. This single
     * definition is used by the dashboard, filters and future reporting.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Open, self::InProgress, self::OnHold];
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::active());
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }
}
