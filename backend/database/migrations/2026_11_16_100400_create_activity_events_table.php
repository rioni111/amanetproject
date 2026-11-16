<?php

use App\Enums\ActorType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only, dated history of what happened on a matter.
     */
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            // History must survive: a matter with recorded activity cannot be hard-deleted
            // (matters are closed/archived instead).
            $table->foreignId('matter_id')->nullable()->constrained('matters')->restrictOnDelete();
            $table->string('actor_type', 20);
            // Polymorphic by actor_type (users.id for admins, later a signer/request id for clients),
            // so no FK. actor_label snapshots the display name at the time of the event.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 200)->nullable();
            $table->string('event_type', 60);
            $table->nullableMorphs('subject');
            $table->string('description', 500);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['matter_id', 'occurred_at']);
            $table->index('event_type');
            $table->index('occurred_at');
        });

        DB::statement('ALTER TABLE activity_events ADD CONSTRAINT activity_events_actor_type_check CHECK (actor_type IN ('.ActorType::sqlList().'))');

        // Enforce append-only semantics at the database level.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'activity_events is append-only (% not allowed)', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER activity_events_no_update_delete
                BEFORE UPDATE OR DELETE ON activity_events
                FOR EACH ROW EXECUTE FUNCTION activity_events_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
        DB::unprepared('DROP FUNCTION IF EXISTS activity_events_append_only()');
    }
};
