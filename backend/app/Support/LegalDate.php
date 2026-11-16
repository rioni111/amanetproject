<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Date-only legal values (deadlines, opening dates) on top of timestamptz columns.
 *
 * The API exchanges these as calendar dates ("2026-11-30"). They are interpreted in the firm's
 * time zone (config lexora.timezone) and stored in UTC:
 *   - a deadline is the END of that day (23:59:59.999999), so a matter becomes overdue only once the
 *     whole day has passed;
 *   - an opening date is the START of that day.
 * Reading them back always converts to the firm's time zone first, so a value never shifts by a day
 * regardless of the server's or the browser's time zone.
 */
final class LegalDate
{
    public static function timezone(): string
    {
        return (string) config('lexora.timezone', 'UTC');
    }

    public static function endOfDay(?string $date): ?CarbonImmutable
    {
        return $date === null ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone())->endOfDay()->utc();
    }

    public static function startOfDay(?string $date): ?CarbonImmutable
    {
        return $date === null ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone())->startOfDay()->utc();
    }

    /** Calendar date of a stored timestamp in the firm's time zone. */
    public static function toDate(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::instance($value)->setTimezone(self::timezone())->format('Y-m-d');
    }

    /** Today's date in the firm's time zone. */
    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->format('Y-m-d');
    }
}
