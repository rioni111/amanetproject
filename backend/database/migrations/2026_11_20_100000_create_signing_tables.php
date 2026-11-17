<?php

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Enums\SignatureMethod;
use App\Enums\SignerType;
use App\Enums\SigningCancellationReason;
use App\Enums\SigningStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — electronic PDF signing. Additive: two new tables, one new nullable column on
 * secure_access_links (with a stricter "exactly one target" CHECK replacing the Phase 4 upload-only
 * CHECK; every existing row satisfies it) and guard triggers.
 *
 * signing_requests   one designated signer for one immutable source version of one document (ONE SIGNER PER PDF)
 * signature_records  the completed signing action: signer snapshot, consent, placement, source and signed checksums
 *
 * Database-enforced invariants:
 *  - document ∈ matter, source version ∈ document, signed version ∈ document (composite foreign keys);
 *  - the source version is an original upload (kind = upload), never an already-signed version (trigger);
 *  - a client signer is the matter's client at creation (trigger); signer and source can never be reassigned (trigger);
 *  - at most one active signing request per document (partial unique index) — no competing "final" PDFs;
 *  - signed ⇔ signed_at + signed version; cancelled ⇔ cancelled_at + reason; progress timestamps in order;
 *  - one signature record per request and per signed version; records are immutable (trigger);
 *  - a link targets exactly one of (document request, signing request), matching its purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('matter_id')->constrained('matters')->restrictOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id'); // the immutable source PDF that will be signed
            $table->string('signer_type', 20);
            $table->foreignId('signer_user_id')->nullable()->constrained('users')->restrictOnDelete(); // admin signer
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete(); // client signer
            $table->string('signer_name', 200); // snapshot: never re-derived from mutable profiles
            $table->string('signer_email', 255)->nullable();
            $table->text('message')->nullable(); // optional note shown to the signer
            $table->string('status', 20)->default(SigningStatus::Ready->value);
            $table->string('delivery_channel', 20)->nullable(); // manual (Phase 5); email (Phase 6)
            $table->string('delivery_status', 30)->default(DeliveryStatus::NotShared->value);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 30)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('signed_document_version_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'matter_id']); // target of the links' composite FK
            $table->index(['matter_id', 'status']);
            $table->index('status');
            $table->index('document_id');
            $table->index('created_at');
            $table->index('updated_at');
        });

        $s = fn (string $sql) => DB::statement('ALTER TABLE signing_requests ADD CONSTRAINT '.$sql);
        $s('signing_requests_document_fk FOREIGN KEY (document_id, matter_id) REFERENCES documents (id, matter_id) ON DELETE RESTRICT');
        $s('signing_requests_source_fk FOREIGN KEY (document_version_id, document_id) REFERENCES document_versions (id, document_id) ON DELETE RESTRICT');
        $s('signing_requests_signed_fk FOREIGN KEY (signed_document_version_id, document_id) REFERENCES document_versions (id, document_id) ON DELETE RESTRICT');
        $s('signing_requests_signer_type_check CHECK (signer_type IN ('.SignerType::sqlList().'))');
        $s('signing_requests_status_check CHECK (status IN ('.SigningStatus::sqlList().'))');
        $s('signing_requests_delivery_channel_check CHECK (delivery_channel IS NULL OR delivery_channel IN ('.DeliveryChannel::sqlList().'))');
        $s('signing_requests_delivery_status_check CHECK (delivery_status IN ('.DeliveryStatus::sqlList().'))');
        $s('signing_requests_cancellation_reason_check CHECK (cancellation_reason IS NULL OR cancellation_reason IN ('.SigningCancellationReason::sqlList().'))');
        $s('signing_requests_signer_name_not_blank CHECK (length(btrim(signer_name)) > 0)');
        // Exactly one designated signer, of exactly one kind.
        $s("signing_requests_admin_signer CHECK ((signer_type = 'admin') = (signer_user_id IS NOT NULL))");
        $s("signing_requests_client_signer CHECK ((signer_type = 'client') = (client_id IS NOT NULL))");
        $s("signing_requests_signed_consistency CHECK ((status = 'signed') = (signed_at IS NOT NULL AND signed_document_version_id IS NOT NULL))");
        $s("signing_requests_cancelled_consistency CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL))");
        $s("signing_requests_sent_has_time CHECK (status <> 'sent' OR sent_at IS NOT NULL)");
        $s("signing_requests_viewed_has_time CHECK (status <> 'viewed' OR viewed_at IS NOT NULL)");
        $s("signing_requests_started_has_time CHECK (status <> 'started' OR started_at IS NOT NULL)");
        $s("signing_requests_delivery_consistency CHECK ((sent_at IS NULL) = (delivery_status = 'not_shared') AND (delivery_channel IS NULL) = (delivery_status = 'not_shared'))");
        $s('signing_requests_progress_order CHECK ((sent_at IS NULL OR viewed_at IS NULL OR viewed_at >= sent_at) AND (viewed_at IS NULL OR started_at IS NULL OR started_at >= viewed_at) AND (started_at IS NULL OR signed_at IS NULL OR signed_at >= started_at))');
        $s('signing_requests_output_differs CHECK (signed_document_version_id IS NULL OR signed_document_version_id <> document_version_id)');
        $s('signing_requests_lock_version_positive CHECK (lock_version >= 1)');
        // One active signing request per document: two requests can never compete to produce the "final" signed PDF.
        DB::statement("CREATE UNIQUE INDEX signing_requests_one_active_per_document ON signing_requests (document_id) WHERE status IN ('ready', 'sent', 'viewed', 'started')");

        Schema::create('signature_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signing_request_id')->unique()->constrained('signing_requests')->restrictOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->string('signer_type', 20);
            $table->unsignedBigInteger('signer_user_id')->nullable(); // snapshot (no FK: immutable row)
            $table->string('signer_name', 200);   // designated signer
            $table->string('signer_email', 255)->nullable();
            $table->string('confirmed_name', 200); // the name the signer confirmed when signing
            $table->string('signature_method', 20);
            $table->unsignedInteger('page_number');
            $table->jsonb('placement'); // normalised rectangle + page size and rectangle in PDF points
            $table->unsignedBigInteger('source_document_version_id');
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('signed_document_version_id')->unique();
            $table->char('signed_sha256', 64);
            $table->string('consent_version', 40);
            $table->text('consent_text');
            $table->timestampTz('consent_accepted_at');
            $table->timestampTz('signed_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        $r = fn (string $sql) => DB::statement('ALTER TABLE signature_records ADD CONSTRAINT '.$sql);
        $r('signature_records_source_fk FOREIGN KEY (source_document_version_id, document_id) REFERENCES document_versions (id, document_id) ON DELETE RESTRICT');
        $r('signature_records_signed_fk FOREIGN KEY (signed_document_version_id, document_id) REFERENCES document_versions (id, document_id) ON DELETE RESTRICT');
        $r('signature_records_signer_type_check CHECK (signer_type IN ('.SignerType::sqlList().'))');
        $r('signature_records_method_check CHECK (signature_method IN ('.SignatureMethod::sqlList().'))');
        $r('signature_records_page_positive CHECK (page_number >= 1)');
        $r("signature_records_checksums CHECK (source_sha256 ~ '^[0-9a-f]{64}$' AND signed_sha256 ~ '^[0-9a-f]{64}$' AND source_sha256 <> signed_sha256)");
        $r('signature_records_versions_differ CHECK (source_document_version_id <> signed_document_version_id)');
        $r('signature_records_names_not_blank CHECK (length(btrim(signer_name)) > 0 AND length(btrim(confirmed_name)) > 0)');

        Schema::table('secure_access_links', function (Blueprint $table) {
            $table->unsignedBigInteger('signing_request_id')->nullable()->after('document_request_id');
            $table->index('signing_request_id');
        });
        DB::statement('ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_signing_fk FOREIGN KEY (signing_request_id, matter_id) REFERENCES signing_requests (id, matter_id) ON DELETE RESTRICT');
        // Replaces the Phase 4 upload-only CHECK: a link targets exactly one resource, matching its purpose.
        DB::statement('ALTER TABLE secure_access_links DROP CONSTRAINT IF EXISTS secure_access_links_upload_target');
        DB::statement("ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_target CHECK (
            (purpose = 'document_upload' AND document_request_id IS NOT NULL AND signing_request_id IS NULL)
            OR (purpose = 'document_signing' AND signing_request_id IS NOT NULL AND document_request_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX secure_access_links_one_usable_signing_link ON secure_access_links (signing_request_id) WHERE purpose = 'document_signing' AND revoked_at IS NULL AND consumed_at IS NULL");

        DB::unprepared(<<<'SQL'
            -- Source must be an original (upload) version; a client signer must be the matter's client.
            CREATE OR REPLACE FUNCTION signing_requests_insert_guard() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM document_versions WHERE id = NEW.document_version_id AND kind = 'upload') THEN
                    RAISE EXCEPTION 'only original (unsigned) document versions can be signed' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.signer_type = 'client' AND NOT EXISTS (SELECT 1 FROM matters WHERE id = NEW.matter_id AND client_id = NEW.client_id) THEN
                    RAISE EXCEPTION 'the client signer must be the matter client' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER signing_requests_insert_guard BEFORE INSERT ON signing_requests
                FOR EACH ROW EXECUTE FUNCTION signing_requests_insert_guard();

            -- The signer and the source are fixed for life; finished requests are final; nothing is deleted.
            CREATE OR REPLACE FUNCTION signing_requests_update_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'signing requests cannot be deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.matter_id <> OLD.matter_id OR NEW.document_id <> OLD.document_id
                    OR NEW.document_version_id <> OLD.document_version_id OR NEW.signer_type <> OLD.signer_type
                    OR NEW.signer_user_id IS DISTINCT FROM OLD.signer_user_id OR NEW.client_id IS DISTINCT FROM OLD.client_id
                    OR NEW.signer_name <> OLD.signer_name OR NEW.signer_email IS DISTINCT FROM OLD.signer_email THEN
                    RAISE EXCEPTION 'the signer and source document of a signing request cannot be changed' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status IN ('signed', 'cancelled') THEN
                    RAISE EXCEPTION 'a % signing request is final', OLD.status USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER signing_requests_update_guard BEFORE UPDATE OR DELETE ON signing_requests
                FOR EACH ROW EXECUTE FUNCTION signing_requests_update_guard();

            -- Signature evidence is immutable.
            CREATE OR REPLACE FUNCTION signature_records_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'signature_records are immutable (% not allowed)', TG_OP USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER signature_records_no_update_delete BEFORE UPDATE OR DELETE ON signature_records
                FOR EACH ROW EXECUTE FUNCTION signature_records_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS secure_access_links_one_usable_signing_link');
        DB::statement('ALTER TABLE secure_access_links DROP CONSTRAINT IF EXISTS secure_access_links_target');
        DB::statement('ALTER TABLE secure_access_links DROP CONSTRAINT IF EXISTS secure_access_links_signing_fk');
        Schema::table('secure_access_links', fn (Blueprint $table) => $table->dropColumn('signing_request_id'));
        DB::statement("ALTER TABLE secure_access_links ADD CONSTRAINT secure_access_links_upload_target CHECK (purpose <> 'document_upload' OR document_request_id IS NOT NULL)");
        Schema::dropIfExists('signature_records');
        DB::unprepared('DROP FUNCTION IF EXISTS signature_records_immutable() CASCADE');
        Schema::dropIfExists('signing_requests');
        DB::unprepared('DROP FUNCTION IF EXISTS signing_requests_update_guard() CASCADE; DROP FUNCTION IF EXISTS signing_requests_insert_guard() CASCADE;');
    }
};
