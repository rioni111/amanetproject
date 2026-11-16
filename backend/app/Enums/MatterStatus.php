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

    /**
     * Status transition policy (docs/data-model.md, "Matter lifecycle"):
     *  - the three active states may move freely between each other;
     *  - any active state may be closed;
     *  - a closed matter may be reopened (back to open or in progress);
     *  - archiving and restoring are separate actions (see canBeArchived / MatterService::restore):
     *    any non-archived matter may be archived, and restore returns it to the status it had.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::OnHold, self::Closed],
            self::InProgress => [self::Open, self::OnHold, self::Closed],
            self::OnHold => [self::Open, self::InProgress, self::Closed],
            self::Closed => [self::Open, self::InProgress],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function canBeArchived(): bool
    {
        return $this !== self::Archived;
    }

    /** Statuses a new matter may start in. */
    public static function initialValues(): array
    {
        return self::activeValues();
    }
}
