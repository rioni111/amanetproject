<?php

use App\Enums\DeliveryStatus;
use App\Enums\EmailCommunicationType;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailFailureCategory;
use App\Enums\EmailTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — e-mail communication and administrator notifications. Additive only.
 *
 * email_deliveries   one row per e-mail (attempt history: attempt_count, timestamps, safe failure category).
 *                    Never stores the action link, its token or token hash, credentials or the message body.
 * notifications      Laravel's database notification table (administrator in-app notifications).
 * + document_requests.overdue_notified_at, secure_access_links.expiry_notified_at (one notification each)
 * + signing_requests.delivery_status gains 'email_accepted'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->unsignedBigInteger('document_request_id')->nullable();
            $table->unsignedBigInteger('signing_request_id')->nullable();
            // The link this e-mail carried, registered only once the SMTP server accepted the message.
            $table->foreignId('secure_access_link_id')->nullable()->unique()->constrained('secure_access_links')->restrictOnDelete();
            $table->string('communication_type', 40);
            $table->string('initiated_by', 20);
            $table->string('reminder_slot', 80)->nullable();
            $table->string('recipient_email', 255);
            $table->string('recipient_name', 200);
            $table->string('subject', 255);
            $table->string('channel', 20)->default('email');
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_category', 40)->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['document_request_id', 'created_at']);
            $table->index(['signing_request_id', 'created_at']);
            $table->index(['matter_id', 'created_at']);
            $table->index(['status', 'last_attempt_at']);
        });

        $c = fn (string $sql) => DB::statement('ALTER TABLE email_deliveries ADD CONSTRAINT '.$sql);
        $c('email_deliveries_document_request_fk FOREIGN KEY (document_request_id, matter_id) REFERENCES document_requests (id, matter_id) ON DELETE RESTRICT');
        $c('email_deliveries_signing_request_fk FOREIGN KEY (signing_request_id, matter_id) REFERENCES signing_requests (id, matter_id) ON DELETE RESTRICT');
        $c('email_deliveries_type_check CHECK (communication_type IN ('.EmailCommunicationType::sqlList().'))');
        $c('email_deliveries_initiated_by_check CHECK (initiated_by IN ('.EmailTrigger::sqlList().'))');
        $c('email_deliveries_status_check CHECK (status IN ('.EmailDeliveryStatus::sqlList().'))');
        $c('email_deliveries_failure_check CHECK (failure_category IS NULL OR failure_category IN ('.EmailFailureCategory::sqlList().'))');
        $c("email_deliveries_channel_check CHECK (channel = 'email')");
        $c("email_deliveries_recipient_check CHECK (length(btrim(recipient_email)) > 3 AND position('@' in recipient_email) > 1 AND recipient_email !~ '[\\r\\n]')");
        // Exactly the right target for the type: a document request, a signing request, or (test) nothing.
        $c("email_deliveries_target_check CHECK (
            (communication_type IN ('document_request', 'document_correction', 'document_request_reminder')
                AND document_request_id IS NOT NULL AND signing_request_id IS NULL AND matter_id IS NOT NULL)
            OR (communication_type IN ('signing_invitation', 'signing_reminder')
                AND signing_request_id IS NOT NULL AND document_request_id IS NULL AND matter_id IS NOT NULL)
            OR (communication_type = 'test' AND matter_id IS NULL AND document_request_id IS NULL AND signing_request_id IS NULL))");
        $c("email_deliveries_reminder_check CHECK ((initiated_by = 'automatic') = (reminder_slot IS NOT NULL)
            AND (initiated_by = 'manual' OR communication_type IN ('document_request_reminder', 'signing_reminder')))");
        $c("email_deliveries_accepted_check CHECK ((status = 'accepted') = (accepted_at IS NOT NULL))");
        $c("email_deliveries_failed_check CHECK ((status = 'failed') = (failed_at IS NOT NULL AND failure_category IS NOT NULL))");
        $c("email_deliveries_link_check CHECK (secure_access_link_id IS NULL OR status = 'accepted')");
        $c("email_deliveries_attempts_check CHECK (status IN ('pending', 'skipped', 'cancelled') OR attempt_count >= 1)");
        // At most one SMTP submission in progress per request: concurrent clicks, two administrators or two
        // scheduler processes can never send the same request twice at the same time.
        DB::statement("CREATE UNIQUE INDEX email_deliveries_one_sending_per_document_request ON email_deliveries (document_request_id) WHERE status = 'sending' AND document_request_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX email_deliveries_one_sending_per_signing_request ON email_deliveries (signing_request_id) WHERE status = 'sending' AND signing_request_id IS NOT NULL");

        DB::unprepared(<<<'SQL'
            -- Delivery records are audit records: never deleted; the target, recipient and type never change;
            -- a finished outcome (accepted / failed / cancelled / skipped) is final.
            CREATE OR REPLACE FUNCTION email_deliveries_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'email delivery records cannot be deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.matter_id IS DISTINCT FROM OLD.matter_id OR NEW.client_id IS DISTINCT FROM OLD.client_id
                    OR NEW.document_request_id IS DISTINCT FROM OLD.document_request_id
                    OR NEW.signing_request_id IS DISTINCT FROM OLD.signing_request_id
                    OR NEW.communication_type <> OLD.communication_type OR NEW.recipient_email <> OLD.recipient_email
                    OR NEW.idempotency_key <> OLD.idempotency_key OR NEW.uuid <> OLD.uuid THEN
                    RAISE EXCEPTION 'the target and recipient of an e-mail delivery cannot be changed' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status IN ('accepted', 'failed', 'cancelled', 'skipped') THEN
                    RAISE EXCEPTION 'a % e-mail delivery is final', OLD.status USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER email_deliveries_guard BEFORE UPDATE OR DELETE ON email_deliveries
                FOR EACH ROW EXECUTE FUNCTION email_deliveries_guard();
        SQL);

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at', 6)->nullable();
            // Microseconds: notifications created in the same second still list newest first.
            $table->timestampsTz(6);

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index('created_at');
        });

        Schema::table('document_requests', function (Blueprint $table) {
            $table->timestampTz('overdue_notified_at')->nullable();
        });
        Schema::table('secure_access_links', function (Blueprint $table) {
            $table->timestampTz('expiry_notified_at')->nullable();
        });

        DB::statement('ALTER TABLE signing_requests DROP CONSTRAINT signing_requests_delivery_status_check');
        DB::statement('ALTER TABLE signing_requests ADD CONSTRAINT signing_requests_delivery_status_check CHECK (delivery_status IN ('.DeliveryStatus::sqlList().'))');
        // Channel and status must agree (manual sharing vs accepted invitation e-mail).
        DB::statement("ALTER TABLE signing_requests ADD CONSTRAINT signing_requests_delivery_channel_status CHECK (
            delivery_status = 'not_shared'
            OR (delivery_status = 'shared_manually' AND delivery_channel = 'manual')
            OR (delivery_status = 'email_accepted' AND delivery_channel = 'email'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE signing_requests DROP CONSTRAINT IF EXISTS signing_requests_delivery_channel_status');
        DB::statement('ALTER TABLE signing_requests DROP CONSTRAINT IF EXISTS signing_requests_delivery_status_check');
        DB::statement("ALTER TABLE signing_requests ADD CONSTRAINT signing_requests_delivery_status_check CHECK (delivery_status IN ('not_shared', 'shared_manually'))");
        Schema::table('secure_access_links', fn (Blueprint $table) => $table->dropColumn('expiry_notified_at'));
        Schema::table('document_requests', fn (Blueprint $table) => $table->dropColumn('overdue_notified_at'));
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('email_deliveries');
        DB::unprepared('DROP FUNCTION IF EXISTS email_deliveries_guard() CASCADE');
    }
};
