<?php

use App\Enums\EmailLinkReservationStatus;
use App\Enums\SecureLinkPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — closes the Phase 6 "accepted but never registered" window (issue P7-001).
 *
 * The SHA-256 hash of an e-mailed token is reserved BEFORE the message is handed to the SMTP server (never the
 * token itself). A reservation is NOT a link: no query, index or resolver treats it as usable. It only matters
 * if the process stopped (or the outcome was ambiguous) after the server may have accepted the message: the
 * recipient presenting that token proves the e-mail reached them, and the link is then activated on first use
 * (EmailLinkActivationService) under the same locks and rules as a normal acceptance.
 *
 *   pending    reserved; the outcome of the send is not known yet (or the process stopped / timed out)
 *   registered the normal path: the server accepted it and the link was registered by the sending process
 *   activated  the recipient used the token of a send whose outcome was unknown; the link was registered then
 *   discarded  the server definitely refused the message: the token never left the process
 *   superseded a later e-mail or a newly generated link replaced it before it was used
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_link_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_delivery_id')->unique()->constrained('email_deliveries')->restrictOnDelete();
            $table->string('purpose', 30);
            $table->foreignId('matter_id')->constrained('matters')->restrictOnDelete();
            $table->unsignedBigInteger('document_request_id')->nullable();
            $table->unsignedBigInteger('signing_request_id')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->string('status', 20);
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('secure_access_link_id')->nullable()->unique()->constrained('secure_access_links')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['document_request_id', 'status']);
            $table->index(['signing_request_id', 'status']);
        });

        $c = fn (string $sql) => DB::statement('ALTER TABLE email_link_reservations ADD CONSTRAINT '.$sql);
        $c('email_link_reservations_document_request_fk FOREIGN KEY (document_request_id, matter_id) REFERENCES document_requests (id, matter_id) ON DELETE RESTRICT');
        $c('email_link_reservations_signing_request_fk FOREIGN KEY (signing_request_id, matter_id) REFERENCES signing_requests (id, matter_id) ON DELETE RESTRICT');
        $c('email_link_reservations_purpose_check CHECK (purpose IN ('.SecureLinkPurpose::sqlList().'))');
        $c('email_link_reservations_status_check CHECK (status IN ('.EmailLinkReservationStatus::sqlList().'))');
        $c("email_link_reservations_hash_check CHECK (token_hash ~ '^[0-9a-f]{64}$')");
        $c("email_link_reservations_target_check CHECK (
            (purpose = 'document_upload' AND document_request_id IS NOT NULL AND signing_request_id IS NULL)
            OR (purpose = 'document_signing' AND signing_request_id IS NOT NULL AND document_request_id IS NULL))");
        // A registered/activated reservation points at the link it became; the others never do.
        $c("email_link_reservations_link_check CHECK ((status IN ('registered', 'activated')) = (secure_access_link_id IS NOT NULL))");
        $c("email_link_reservations_resolved_check CHECK ((status = 'pending') = (resolved_at IS NULL))");

        DB::unprepared(<<<'SQL'
            -- Reservations are audit records: never deleted, their target and hash never change, and only a
            -- pending reservation can be resolved (once).
            CREATE OR REPLACE FUNCTION email_link_reservations_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'e-mail link reservations cannot be deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.token_hash <> OLD.token_hash OR NEW.email_delivery_id <> OLD.email_delivery_id
                    OR NEW.purpose <> OLD.purpose OR NEW.matter_id <> OLD.matter_id
                    OR NEW.document_request_id IS DISTINCT FROM OLD.document_request_id
                    OR NEW.signing_request_id IS DISTINCT FROM OLD.signing_request_id
                    OR NEW.expires_at <> OLD.expires_at THEN
                    RAISE EXCEPTION 'an e-mail link reservation cannot be changed' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'a % e-mail link reservation is final', OLD.status USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER email_link_reservations_guard BEFORE UPDATE OR DELETE ON email_link_reservations
                FOR EACH ROW EXECUTE FUNCTION email_link_reservations_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_link_reservations');
        DB::unprepared('DROP FUNCTION IF EXISTS email_link_reservations_guard() CASCADE');
    }
};
