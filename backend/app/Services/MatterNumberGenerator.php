<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issues matter numbers in the existing format LEX-{year}-{0000}.
 *
 * Concurrency: one row per year in matter_number_counters is incremented with a single atomic
 * INSERT … ON CONFLICT DO UPDATE … RETURNING. The row lock taken by that statement is held until the
 * surrounding transaction ends, so concurrent creators are serialised on that one row (not on the
 * matters table) and can never receive the same value. Because the increment is part of the
 * creating transaction, a failed creation rolls the counter back too.
 *
 * GREATEST(…, highest existing number) keeps the counter ahead of numbers inserted without it
 * (e.g. the idempotent demo seeder), so a generated number never collides with an existing one.
 * The unique index on matters.matter_number remains the final guarantee.
 */
class MatterNumberGenerator
{
    public const PREFIX = 'LEX';

    public function next(?int $year = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Matter numbers must be generated inside the transaction that creates the matter.');
        }

        $year ??= (int) Carbon::now()->format('Y');
        $pattern = sprintf('^%s-%04d-(\d+)$', self::PREFIX, $year);

        $row = DB::selectOne(<<<'SQL'
            INSERT INTO matter_number_counters AS c (year, last_value, updated_at)
            VALUES (
                :year,
                COALESCE((SELECT MAX(CAST(substring(matter_number FROM CAST(:pattern AS text)) AS integer))
                          FROM matters WHERE matter_number ~ CAST(:pattern2 AS text)), 0) + 1,
                now()
            )
            ON CONFLICT (year) DO UPDATE
                SET last_value = GREATEST(c.last_value,
                        COALESCE((SELECT MAX(CAST(substring(matter_number FROM CAST(:pattern3 AS text)) AS integer))
                                  FROM matters WHERE matter_number ~ CAST(:pattern4 AS text)), 0)) + 1,
                    updated_at = now()
            RETURNING c.last_value
        SQL, ['year' => $year, 'pattern' => $pattern, 'pattern2' => $pattern, 'pattern3' => $pattern, 'pattern4' => $pattern]);

        return sprintf('%s-%04d-%04d', self::PREFIX, $year, (int) $row->last_value);
    }
}
