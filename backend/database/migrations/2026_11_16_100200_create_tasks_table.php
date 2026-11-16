<?php

use App\Enums\Priority;
use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 20)->default(TaskStatus::Todo->value);
            $table->string('priority', 20)->default(Priority::Medium->value);
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            // Keep the task if the creating administrator account is removed.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['matter_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('.TaskStatus::sqlList().'))');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('.Priority::sqlList().'))');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_title_not_blank CHECK (length(btrim(title)) > 0)');
        // completed_at is set if and only if the task is completed.
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_completion_consistency CHECK ((status = 'completed') = (completed_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
