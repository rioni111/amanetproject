<?php

namespace Tests\Feature\Phase7;

use App\Enums\EmailFailureCategory;
use App\Models\ActivityEvent;
use App\Models\EmailDelivery;
use App\Models\EmailLinkReservation;
use App\Models\SecureAccessLink;
use App\Services\Communications\EmailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CommunicationWorkflow;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/**
 * Phase 7 issue P7-001 — "the SMTP server accepted the e-mail, but the link was never registered".
 *
 * The failure is reproduced for real: a database trigger makes the finish transaction fail AFTER the message
 * was handed to the mailer (exactly what a crash or a database outage at that moment does). Before Phase 7
 * the client's link in that e-mail answered "link not valid" forever; now the hash reserved before sending
 * lets the link be activated when the client first opens it — without ever storing the token.
 */
class EmailLinkRecoveryTest extends TestCase
{
    use CommunicationWorkflow, RefreshDatabase, RequestWorkflow, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Mail::fake();
    }

    /** Every link insert fails: the finish transaction of an accepted e-mail rolls back. */
    private function breakLinkRegistration(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION p7_fail_link_insert() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'simulated database failure after SMTP acceptance'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER p7_fail_link_insert BEFORE INSERT ON secure_access_links FOR EACH ROW EXECUTE FUNCTION p7_fail_link_insert();
        SQL);
    }

    private function repairLinkRegistration(): void
    {
        DB::unprepared('DROP TRIGGER p7_fail_link_insert ON secure_access_links; DROP FUNCTION p7_fail_link_insert();');
    }

    #[Test]
    public function an_accepted_email_whose_recording_failed_still_gives_the_client_a_working_link(): void
    {
        [$request, $oldToken] = $this->requestWithLink();
        $this->breakLinkRegistration();

        $this->adminPost($request, 'send-email')->assertStatus(500)
            ->assertJsonPath('code', 'email_unrecorded')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'The link in the e-mail will work when the client opens it'));
        $token = $this->tokenFromMail($this->lastMail());
        $this->repairLinkRegistration();

        $delivery = EmailDelivery::query()->sole();
        $this->assertSame('sending', $delivery->status->value, 'nothing about the outcome could be recorded');
        $this->assertSame('pending', $delivery->reservation->status->value);
        $this->assertSame(1, SecureAccessLink::query()->count(), 'no link was registered and the old one was not revoked');
        $this->publicView($oldToken)->assertOk();

        // The client opens the e-mailed link: possession of the token proves the message arrived.
        $this->publicView($token)->assertOk()->assertJsonPath('data.title', 'Signed Service Agreement');

        $delivery->refresh();
        $this->assertSame('accepted', $delivery->status->value);
        $this->assertNotNull($delivery->secure_access_link_id);
        $this->assertSame('activated', $delivery->reservation->fresh()->status->value);
        $this->publicView($oldToken)->assertNotFound(); // replaced, exactly as a normal acceptance does
        $this->assertSame(1, SecureAccessLink::query()->whereNull('revoked_at')->count());
        $event = ActivityEvent::query()->where('event_type', 'email.link_activated')->sole();
        $this->assertSame('accepted_on_first_use', $event->metadata['delivery_outcome']);
        $this->assertStringNotContainsString($token, json_encode($event->metadata));

        $this->publicSubmit($token, PdfFactory::upload('agreement.pdf', ['Signed agreement']))->assertCreated();
        $this->assertSame('submitted', $request->fresh()->status->value);
    }

    #[Test]
    public function an_interrupted_signing_invitation_is_reported_honestly_and_still_signable(): void
    {
        [$signing] = $this->clientSigning(null, null, ['issue_link' => false]);
        $this->breakLinkRegistration();
        $this->signingAction($signing, 'send-email')->assertStatus(500)->assertJsonPath('code', 'email_unrecorded');
        $token = $this->tokenFromMail($this->lastMail(), 'client-sign');
        $this->repairLinkRegistration();
        $this->assertSame('ready', $signing->fresh()->status->value, 'never Sent without a recorded outcome');

        // The stale-send recovery closes it as "interrupted" (outcome unknown) and tells the firm.
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->assertSame(0, Artisan::call('lexora:communications'));
        $delivery = EmailDelivery::query()->sole();
        $this->assertSame(['failed', 'interrupted'], [$delivery->status->value, $delivery->failure_category->value]);
        $this->assertSame(1, DB::table('notifications')->where('type', 'email_failed')->count());

        // The signer opens the invitation anyway: the link works, Sent is recorded, the outcome stays as recorded.
        $this->viewAndStart($token);
        $signing->refresh();
        $this->assertSame('started', $signing->status->value);
        $this->assertSame(['email', 'email_accepted'], [$signing->delivery_channel->value, $signing->delivery_status->value]);
        $this->assertNotNull($signing->sent_at);
        $this->assertSame(['failed', 'interrupted'], [$delivery->fresh()->status->value, $delivery->fresh()->failure_category->value], 'a final outcome is never rewritten');

        $history = $this->actingAsAdmin($this->signingAdmin())->getJson("/api/matters/{$signing->matter_id}/signing-requests/{$signing->id}/communications")->assertOk();
        $this->assertNotNull($history->json('data.0.recipient_used_link_at'));
        $this->assertSame('active', $history->json('data.0.link.state'));

        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertCreated();
        $this->assertSame('signed', $signing->fresh()->status->value);
        $this->publicSigning('GET', '', $token)->assertStatus(410)->assertJsonPath('code', 'already_signed');
    }

    #[Test]
    public function a_newer_link_or_email_supersedes_an_unconfirmed_one(): void
    {
        [$request] = $this->requestWithLink();
        $this->breakLinkRegistration();
        $this->adminPost($request, 'send-email')->assertStatus(500);
        $unconfirmed = $this->tokenFromMail($this->lastMail());
        $this->repairLinkRegistration();
        Carbon::setTestNow(Carbon::now()->addMinutes(11)); // recovered as interrupted, so a new e-mail is allowed
        Artisan::call('lexora:communications');

        $this->adminPost($request, 'send-email')->assertCreated();
        $latest = $this->tokenFromMail($this->lastMail());

        $this->publicView($unconfirmed)->assertNotFound()->assertJsonPath('code', 'link_invalid');
        $this->publicView($latest)->assertOk();
        $this->assertSame(['superseded', 'registered'], EmailLinkReservation::query()->orderBy('id')->pluck('status')->map->value->all());
    }

    #[Test]
    public function a_generated_link_also_supersedes_an_unconfirmed_email(): void
    {
        [$request] = $this->requestWithLink();
        $this->breakLinkRegistration();
        $this->adminPost($request, 'send-email')->assertStatus(500);
        $unconfirmed = $this->tokenFromMail($this->lastMail());
        $this->repairLinkRegistration();

        $this->adminPost($request, 'links/rotate', ['lock_version' => $this->lockVersion($request)])->assertCreated();

        $this->publicView($unconfirmed)->assertNotFound();
        $this->assertSame('superseded', EmailLinkReservation::query()->sole()->status->value);
    }

    #[Test]
    public function definite_refusals_expiry_and_closed_requests_never_activate_a_link(): void
    {
        // A definite refusal by the server: the token never left the process, the reservation is discarded.
        [$refused, $refusedOld] = $this->requestWithLink();
        $this->breakLinkRegistration();
        $this->adminPost($refused, 'send-email')->assertStatus(500);
        $refusedToken = $this->tokenFromMail($this->lastMail());
        $this->repairLinkRegistration();
        $delivery = EmailDelivery::query()->where('document_request_id', $refused->id)->sole();
        app(EmailDeliveryService::class)->markFailed($delivery, EmailFailureCategory::RecipientRejected, null, null, false, 'Document request e-mail');
        $this->publicView($refusedToken)->assertNotFound();
        $this->assertSame('discarded', $delivery->reservation->fresh()->status->value);
        $this->publicView($refusedOld)->assertOk(); // the client's existing link is untouched

        // Expired before it was ever used.
        [$expired] = $this->requestWithLink($this->matterFor('Harbour Freight Ltd'));
        $this->breakLinkRegistration();
        $this->adminPost($expired, 'send-email', ['link_expires_in_days' => 1])->assertStatus(500);
        $expiredToken = $this->tokenFromMail($this->lastMail());
        $this->repairLinkRegistration();
        Carbon::setTestNow(Carbon::now()->addDays(2));
        $this->publicView($expiredToken)->assertStatus(410)->assertJsonPath('code', 'link_expired');
        Carbon::setTestNow();

        // The request was cancelled meanwhile: the link is registered already closed, never usable.
        [$cancelled] = $this->requestWithLink($this->matterFor('Meridian Trust'));
        $this->breakLinkRegistration();
        $this->adminPost($cancelled, 'send-email')->assertStatus(500);
        $cancelledToken = $this->tokenFromMail($this->lastMail());
        $this->repairLinkRegistration();
        $this->adminPost($cancelled, 'cancel', ['lock_version' => $this->lockVersion($cancelled)])->assertOk();
        $this->publicView($cancelledToken)->assertStatus(410)->assertJsonPath('code', 'request_cancelled');
        $this->publicSubmit($cancelledToken, PdfFactory::upload('late.pdf', ['Late']))->assertStatus(410);
        $this->assertSame(0, SecureAccessLink::query()->where('document_request_id', $cancelled->id)->whereNull('revoked_at')->count());
    }

    #[Test]
    public function the_reservation_holds_only_a_hash_and_cannot_be_rewritten(): void
    {
        [$request] = $this->requestWithLink();
        $this->adminPost($request, 'send-email')->assertCreated();
        $token = $this->tokenFromMail($this->lastMail());
        $reservation = EmailLinkReservation::query()->sole();

        $this->assertSame('registered', $reservation->status->value);
        $this->assertSame(hash('sha256', $token), $reservation->token_hash);
        $this->assertStringNotContainsString($token, DB::table('email_link_reservations')->get()->toJson());
        $this->assertArrayNotHasKey('token_hash', $reservation->toArray(), 'never serialised');

        $this->expectExceptionMessage('is final');
        DB::table('email_link_reservations')->where('id', $reservation->id)->update(['status' => 'pending', 'resolved_at' => null]);
    }
}
