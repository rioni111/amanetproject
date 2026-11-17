<?php

use App\Enums\DocumentCategory;
use App\Enums\DocumentRequestStatus;
use App\Enums\LinkRevocationReason;
use App\Enums\SecureLinkPurpose;
use App\Enums\SubmissionReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — client document requests, secure access links and client submissions.
 * Additive only: three new tables, two new UNIQUE constraints on existing tables (targets of
 * composite foreign keys) and two guard triggers. No existing row or column is changed.
 *
 * document_requests             what the firm asked a matter's client to provide, and its review lifecycle
 * secure_access_links           purpose-scoped bearer links (only a SHA-256 hash of the token is stored)
 * document_request_submissions  one row per successful client upload; points at the immutable version created
 *
 * Integrity rules enforced by PostgreSQL (not only by the services):
 *  - a request's client is the matter's client when it is created (trigger), and a matter's client
 *    cannot change while it has open requests (trigger) — so a link can never be redirected to
 *    another client's matter;
 *  - a request's document belongs to the same matter, its approved version to that document, a
 *    submission's version to the request's document (composite foreign keys);
 *  - at most one usable (not revoked, not consumed) upload link per request (partial unique index);
 *  - a link can produce at most one submission (unique), and never more uses than allowed (CHECK);
 *  - submissions are never deleted and their facts never change; review decisions are final.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Targets for composite foreign keys ("this id AND this parent").
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_id_matter_unique UNIQUE (id, matter_id)');

        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('matter_id')->constrained('matters')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('title', 255);
            $table->text('instructions');
            $table->string('requested_category', 30);
            $table->string('status', 30)->default(DocumentRequestStatus::Pending->value);
            $table->timestampTz('due_at')->nullable(); // end of the due day in the firm's time zone
            $table->unsignedBigInteger('document_id')->nullable(); // set by the first accepted upload
            $table->unsignedInteger('submissions_count')->default(0); // submission numbering, under row lock
            $table->timestampTz('last_submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('review_started_at')->nullable();
            $table->foreignId('review_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); // last decision
            $table->unsignedBigInteger('approved_version_id')->nullable(); // the exact PDF version accepted
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'matter_id']); // target of the links' composite FK
            $table->index(['matter_id', 'status']);
            $table->index('status');
            $table->index('due_at');
            $table->index('client_id');
            $table->index('updated_at');
        });

        DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_title_not_blank CHECK (length(btrim(title)) > 0)');
        DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_instructions_not_blank CHECK (length(btrim(instructions)) > 0)');
        DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_category_check CHECK (requested_category IN ('.DocumentCategory::sqlList().'))');
        DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_status_check CHECK (status IN ('.DocumentRequestStatus::sqlList().'))');
        DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_lock_version_positive CHECK (lock_version >= 1)');
        DB::statement("ALTER TABLE document_requests ADD CONSTRAINT document_requests_approved_consistency CHECK ((status = 'approved') = (approved_at IS NOT NULL AND approved_version_id IS NOT NULL))");
        DB::statement("ALTER TABLE document_requests ADD CONSTRAINT document_requests_cancelled_consistency CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL))");
        // Every state after a submission has a document; a pending request has none and no submissions.
        DB::statement("ALTER TABLE document_requests ADD CONSTRAINT document_requests_document_consistency CHECK (status IN ('pending', 'cancelled') OR document_id IS NOT NULL)");
        DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_submissions_consistency CHECK ((document_id IS NULL) = (submissions_count = 0))');
        DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_document_fk FOREIGN KEY (document_id, matter_id) REFERENCES documents (id, matter_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_approved_version_fk FOREIGN KEY (approved_version_id, document_id) REFERENCES document_versions (id, document_id) ON DELETE RESTRICT');

        Schema::create('secure_access_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('purpose', 30);
            $table->foreignId('matter_id')->constrained('matters')->restrictOnDelete();
            // Upload links point at a document request; Phase 5 signing links will add their own target.
            $table->unsignedBigInteger('document_request_id')->nullable();
            $table->char('token_hash', 64)->unique(); // SHA-256 (hex) of the 256-bit token; the token itself is never stored
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason', 30)->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('consumed_at')->nullable();
            $table->unsignedSmallInteger('max_uses')->default(1);
            $table->unsignedSmallInteger('successful_uses')->default(0);
            $table->timestampTz('first_opened_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('document_request_id');
            $table->index(['matter_id', 'revoked_at']);
            $table->index('expires_at');
        });

        DB::statement('ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_purpose_check CHECK (purpose IN ('.SecureLinkPurpose::sqlList().'))');
        DB::statement("ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_upload_target CHECK (purpose <> 'document_upload' OR document_request_id IS NOT NULL)");
        DB::statement("ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_hash_format CHECK (token_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_uses_check CHECK (max_uses >= 1 AND successful_uses >= 0 AND successful_uses <= max_uses)');
        DB::statement('ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_consumed_consistency CHECK ((consumed_at IS NOT NULL) = (successful_uses >= max_uses))');
        DB::statement('ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_revoked_consistency CHECK ((revoked_at IS NULL) = (revocation_reason IS NULL))');
        DB::statement('ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_revocation_reason_check CHECK (revocation_reason IS NULL OR revocation_reason IN ('.LinkRevocationReason::sqlList().'))');
        DB::statement('ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_expiry_after_creation CHECK (expires_at > created_at)');
        DB::statement('ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_request_fk FOREIGN KEY (document_request_id, matter_id) REFERENCES document_requests (id, matter_id) ON DELETE RESTRICT');
        // Issuing a new upload link always retires the previous one in the same transaction.
        DB::statement("CREATE UNIQUE INDEX secure_access_links_one_usable_upload_link ON secure_access_links (document_request_id) WHERE purpose = 'document_upload' AND revoked_at IS NULL AND consumed_at IS NULL");

        Schema::create('document_request_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_id')->constrained('document_requests')->restrictOnDelete();
            $table->unsignedInteger('submission_number');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id')->unique(); // one version ↔ one submission
            // One-use links: a link can never produce a second submission (backstop for the row lock).
            $table->foreignId('secure_access_link_id')->unique()->constrained('secure_access_links')->restrictOnDelete();
            $table->text('client_message')->nullable(); // confidential: never copied into activity metadata
            $table->string('submitted_by_label', 200); // client snapshot shown in the history (no account exists)
            $table->timestampTz('submitted_at');
            $table->string('review_status', 30)->default(SubmissionReviewStatus::Pending->value);
            $table->timestampTz('review_started_at')->nullable();
            $table->foreignId('review_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewed_by_name', 200)->nullable();
            $table->text('correction_reason')->nullable(); // confidential: shown to admins and through the next link only
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['document_request_id', 'submission_number']);
            $table->index('review_status');
        });

        DB::statement('ALTER TABLE document_request_submissions ADD CONSTRAINT submissions_number_positive CHECK (submission_number >= 1)');
        DB::statement('ALTER TABLE document_request_submissions ADD CONSTRAINT submissions_review_status_check CHECK (review_status IN ('.SubmissionReviewStatus::sqlList().'))');
        DB::statement("ALTER TABLE document_request_submissions ADD CONSTRAINT submissions_correction_reason CHECK ((review_status = 'correction_requested') = (correction_reason IS NOT NULL AND length(btrim(correction_reason)) > 0))");
        DB::statement("ALTER TABLE document_request_submissions ADD CONSTRAINT submissions_decision_recorded CHECK (review_status NOT IN ('approved', 'correction_requested') OR reviewed_at IS NOT NULL)");
        DB::statement('ALTER TABLE document_request_submissions ADD CONSTRAINT submissions_version_fk FOREIGN KEY (document_version_id, document_id) REFERENCES document_versions (id, document_id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
            -- A request is created for the matter's CURRENT client only.
            CREATE OR REPLACE FUNCTION document_requests_client_matches_matter() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM matters WHERE id = NEW.matter_id AND client_id = NEW.client_id) THEN
                    RAISE EXCEPTION 'document request client does not match the matter client'
                        USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF TG_OP = 'UPDATE' AND (NEW.matter_id <> OLD.matter_id OR NEW.client_id <> OLD.client_id) THEN
                    RAISE EXCEPTION 'a document request cannot move to another matter or client'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER document_requests_client_guard
                BEFORE INSERT OR UPDATE OF matter_id, client_id ON document_requests
                FOR EACH ROW EXECUTE FUNCTION document_requests_client_matches_matter();

            -- A matter's client cannot be changed while client requests are still open: an issued link
            -- must never start serving a different client.
            CREATE OR REPLACE FUNCTION matters_client_change_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.client_id IS DISTINCT FROM OLD.client_id AND EXISTS (
                    SELECT 1 FROM document_requests
                    WHERE matter_id = NEW.id AND status IN ('pending', 'submitted', 'under_review', 'correction_requested')
                ) THEN
                    RAISE EXCEPTION 'the client of a matter with open document requests cannot be changed'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER matters_client_change_guard
                BEFORE UPDATE OF client_id ON matters
                FOR EACH ROW EXECUTE FUNCTION matters_client_change_guard();

            -- Submissions are permanent: no DELETE, the submitted facts never change, decisions are final.
            CREATE OR REPLACE FUNCTION document_request_submissions_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'document request submissions cannot be deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.document_request_id <> OLD.document_request_id OR NEW.submission_number <> OLD.submission_number
                    OR NEW.document_id <> OLD.document_id OR NEW.document_version_id <> OLD.document_version_id
                    OR NEW.secure_access_link_id <> OLD.secure_access_link_id OR NEW.submitted_at <> OLD.submitted_at
                    OR NEW.client_message IS DISTINCT FROM OLD.client_message OR NEW.submitted_by_label <> OLD.submitted_by_label THEN
                    RAISE EXCEPTION 'submitted facts are immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.review_status IN ('approved', 'correction_requested', 'cancelled') THEN
                    RAISE EXCEPTION 'a final review decision cannot be changed' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER document_request_submissions_guard
                BEFORE UPDATE OR DELETE ON document_request_submissions
                FOR EACH ROW EXECUTE FUNCTION document_request_submissions_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS matters_client_change_guard ON matters;
            DROP FUNCTION IF EXISTS matters_client_change_guard();
        SQL);
        Schema::dropIfExists('document_request_submissions');
        DB::unprepared('DROP FUNCTION IF EXISTS document_request_submissions_guard()');
        Schema::dropIfExists('secure_access_links');
        Schema::dropIfExists('document_requests');
        DB::unprepared('DROP FUNCTION IF EXISTS document_requests_client_matches_matter()');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_id_matter_unique');
    }
};
