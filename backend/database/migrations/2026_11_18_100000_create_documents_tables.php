<?php

use App\Enums\DocumentCategory;
use App\Enums\DocumentOrigin;
use App\Enums\DocumentStatus;
use App\Enums\DocumentVersionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — document library with immutable versions (additive: no existing table is changed).
 *
 * documents          one logical document per matter (title, category, lifecycle status, current version)
 * document_versions  one row per stored PDF file; never updated or deleted (trigger), so every committed
 *                    version stays verifiable — later phases add client submissions and signed outputs
 *                    as further versions (kind / origin) and signing requests reference a version id.
 *
 * Circular reference: documents.current_version_id points at a version of THE SAME document, enforced
 * by a composite foreign key (current_version_id, id) → document_versions (id, document_id). A document
 * is inserted with current_version_id NULL, its first version is inserted, then the pointer is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // storage directory name; never derived from user input
            // Documents are part of the legal record: a matter with documents cannot be hard-deleted.
            $table->foreignId('matter_id')->constrained('matters')->restrictOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('category', 30);
            $table->string('status', 20)->default(DocumentStatus::Active->value);
            $table->string('origin', 20)->default(DocumentOrigin::Admin->value);
            $table->unsignedBigInteger('current_version_id')->nullable();
            // Version counter, incremented under a row lock (see DocumentService::addVersion).
            $table->unsignedInteger('latest_version_number')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['matter_id', 'status']);
            $table->index('category');
            $table->index('status');
            $table->index('created_at');
            $table->index('updated_at');
        });

        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_title_not_blank CHECK (length(btrim(title)) > 0)');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_category_check CHECK (category IN ('.DocumentCategory::sqlList().'))');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('.DocumentStatus::sqlList().'))');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_origin_check CHECK (origin IN ('.DocumentOrigin::sqlList().'))');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_archived_consistency CHECK ((status = 'archived') = (archived_at IS NOT NULL))");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_lock_version_positive CHECK (lock_version >= 1)');
        // A committed document always has a current version (only NULL inside the creating transaction).
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_version_counter CHECK ((current_version_id IS NULL) = (latest_version_number = 0))');

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // stored file name
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('kind', 20)->default(DocumentVersionKind::Upload->value);
            $table->string('origin', 20)->default(DocumentOrigin::Admin->value);
            $table->string('original_filename', 255);
            $table->string('storage_disk', 50);
            $table->string('storage_path', 500)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256_checksum', 64);
            // Uploader snapshot without FK: versions are immutable, so ON DELETE SET NULL (an UPDATE)
            // is impossible; the id + name record who uploaded it, like activity_events.actor_*.
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name', 200)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['document_id', 'version_number']);
            $table->unique(['id', 'document_id']); // target of the composite current-version FK
            $table->index('sha256_checksum');
            $table->index('original_filename');
        });

        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_number_positive CHECK (version_number >= 1)');
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_size_positive CHECK (file_size > 0)');
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_checksum_format CHECK (sha256_checksum ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_pdf_only CHECK (mime_type = 'application/pdf')");
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_filename_not_blank CHECK (length(btrim(original_filename)) > 0)');
        // Server-generated layout only: {matter_id}/{document_uuid}/{version_uuid}.pdf — no traversal possible.
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_path_format CHECK (storage_path ~ '^[0-9]+/[0-9a-f-]{36}/[0-9a-f-]{36}\\.pdf$')");
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_kind_check CHECK (kind IN ('.DocumentVersionKind::sqlList().'))');
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_origin_check CHECK (origin IN ('.DocumentOrigin::sqlList().'))');

        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_current_version_fk FOREIGN KEY (current_version_id, id) REFERENCES document_versions (id, document_id) ON DELETE RESTRICT');

        // Committed versions are immutable: no UPDATE, no DELETE — at the database level.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_versions_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'document_versions are immutable (% not allowed)', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER document_versions_no_update_delete
                BEFORE UPDATE OR DELETE ON document_versions
                FOR EACH ROW EXECUTE FUNCTION document_versions_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_current_version_fk');
        Schema::dropIfExists('document_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS document_versions_immutable()');
        Schema::dropIfExists('documents');
    }
};
