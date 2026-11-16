<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal, administrator-only notes. Never exposed through client links.
     */
    public function up(): void
    {
        Schema::create('matter_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestampsTz();

            $table->index(['matter_id', 'created_at']);
        });

        DB::statement('ALTER TABLE matter_notes ADD CONSTRAINT matter_notes_body_not_blank CHECK (length(btrim(body)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_notes');
    }
};
