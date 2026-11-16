<?php

use App\Enums\MatterStatus;
use App\Enums\Priority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matters', function (Blueprint $table) {
            $table->id();
            $table->string('matter_number', 32)->unique();
            // A client with matters cannot be deleted: legal records must not be orphaned.
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('matter_type', 100)->nullable();
            $table->string('status', 20)->default(MatterStatus::Open->value);
            $table->string('priority', 20)->default(Priority::Medium->value);
            $table->timestampTz('opened_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('client_id');
            $table->index(['status', 'due_at']);
            $table->index('priority');
            $table->index('matter_type');
            $table->index('updated_at');
        });

        DB::statement('ALTER TABLE matters ADD CONSTRAINT matters_status_check CHECK (status IN ('.MatterStatus::sqlList().'))');
        DB::statement('ALTER TABLE matters ADD CONSTRAINT matters_priority_check CHECK (priority IN ('.Priority::sqlList().'))');
        DB::statement('ALTER TABLE matters ADD CONSTRAINT matters_title_not_blank CHECK (length(btrim(title)) > 0)');
        // Lifecycle integrity: terminal states must carry their timestamp.
        DB::statement("ALTER TABLE matters ADD CONSTRAINT matters_closed_has_timestamp CHECK (status <> 'closed' OR closed_at IS NOT NULL)");
        DB::statement("ALTER TABLE matters ADD CONSTRAINT matters_archived_has_timestamp CHECK (status <> 'archived' OR archived_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('matters');
    }
};
