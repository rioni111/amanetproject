<?php

use App\Enums\ClientType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('client_type', 20);
            // Not unique: one contact address may legitimately represent several clients
            // (e.g. a group company and its subsidiaries). See docs/data-model.md.
            $table->string('email', 255);
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            // Firm-assigned client reference, unique when present.
            $table->string('reference_number', 50)->nullable()->unique();
            $table->timestampsTz();

            $table->index('name');
            $table->index('email');
            $table->index('client_type');
        });

        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_client_type_check CHECK (client_type IN ('.ClientType::sqlList().'))');
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_name_not_blank CHECK (length(btrim(name)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
