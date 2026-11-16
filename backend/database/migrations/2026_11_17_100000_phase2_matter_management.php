<?php

use App\Enums\MatterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — additive changes only (no data is dropped or rewritten except safe back-fills):
 *
 *  - clients.email becomes optional (a client record must be creatable before an address is known).
 *  - matters.archived_from_status remembers the status a matter had when it was archived, so
 *    "restore" returns it to exactly that state.
 *  - Lifecycle CHECKs in the other direction: archived_at only on archived matters, closed_at only on
 *    closed/archived matters (Phase 1 only enforced "terminal state ⇒ timestamp").
 *  - lock_version on matters, tasks and notes for optimistic concurrency (stale edits → 409).
 *  - matter_number_counters: one row per year, incremented atomically inside the creating transaction
 *    (INSERT … ON CONFLICT DO UPDATE … RETURNING), which serialises concurrent matter creation without
 *    a table lock and never reuses a number. The unique index on matters.matter_number stays as backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE clients ALTER COLUMN email DROP NOT NULL');

        Schema::table('matters', function (Blueprint $table) {
            $table->string('archived_from_status', 20)->nullable()->after('archived_at');
            $table->unsignedInteger('lock_version')->default(1);
            $table->index('created_at');
            $table->index('opened_at');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1);
        });

        Schema::table('matter_notes', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1);
        });

        // Back-fill: archived matters that were closed before archiving restore to "closed".
        DB::statement("UPDATE matters SET archived_from_status = CASE WHEN closed_at IS NOT NULL THEN 'closed' ELSE 'open' END WHERE status = 'archived' AND archived_from_status IS NULL");

        $restorable = implode(', ', array_map(fn (string $v) => "'{$v}'", array_values(array_diff(MatterStatus::values(), [MatterStatus::Archived->value]))));
        DB::statement("ALTER TABLE matters ADD CONSTRAINT matters_archived_from_status_check CHECK (archived_from_status IS NULL OR (status = 'archived' AND archived_from_status IN ({$restorable})))");
        DB::statement("ALTER TABLE matters ADD CONSTRAINT matters_archived_at_only_when_archived CHECK (status = 'archived' OR archived_at IS NULL)");
        DB::statement("ALTER TABLE matters ADD CONSTRAINT matters_closed_at_only_when_closed CHECK (status IN ('closed', 'archived') OR closed_at IS NULL)");
        DB::statement('ALTER TABLE matters ADD CONSTRAINT matters_lock_version_positive CHECK (lock_version >= 1)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_lock_version_positive CHECK (lock_version >= 1)');
        DB::statement('ALTER TABLE matter_notes ADD CONSTRAINT matter_notes_lock_version_positive CHECK (lock_version >= 1)');

        Schema::create('matter_number_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value');
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement('ALTER TABLE matter_number_counters ADD CONSTRAINT matter_number_counters_year_range CHECK (year BETWEEN 2000 AND 2999)');
        DB::statement('ALTER TABLE matter_number_counters ADD CONSTRAINT matter_number_counters_last_value_positive CHECK (last_value >= 1)');

        // Seed the counters from numbers that already exist (e.g. the demo data LEX-2026-0001…0010).
        DB::statement(<<<'SQL'
            INSERT INTO matter_number_counters (year, last_value)
            SELECT CAST(substring(matter_number FROM '^LEX-(\d{4})-\d+$') AS integer),
                   MAX(CAST(substring(matter_number FROM '^LEX-\d{4}-(\d+)$') AS integer))
            FROM matters
            WHERE matter_number ~ '^LEX-\d{4}-\d+$'
            GROUP BY 1
            ON CONFLICT (year) DO UPDATE SET last_value = GREATEST(matter_number_counters.last_value, EXCLUDED.last_value)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_number_counters');

        DB::statement('ALTER TABLE matter_notes DROP CONSTRAINT IF EXISTS matter_notes_lock_version_positive');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_lock_version_positive');
        DB::statement('ALTER TABLE matters DROP CONSTRAINT IF EXISTS matters_lock_version_positive');
        DB::statement('ALTER TABLE matters DROP CONSTRAINT IF EXISTS matters_closed_at_only_when_closed');
        DB::statement('ALTER TABLE matters DROP CONSTRAINT IF EXISTS matters_archived_at_only_when_archived');
        DB::statement('ALTER TABLE matters DROP CONSTRAINT IF EXISTS matters_archived_from_status_check');

        Schema::table('matter_notes', fn (Blueprint $table) => $table->dropColumn('lock_version'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('lock_version'));
        Schema::table('matters', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['opened_at']);
            $table->dropColumn(['archived_from_status', 'lock_version']);
        });

        // Only reversible when every client has an email again.
        DB::statement('ALTER TABLE clients ALTER COLUMN email SET NOT NULL');
    }
};
