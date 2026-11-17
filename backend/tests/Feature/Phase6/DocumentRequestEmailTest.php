<?php

namespace Tests\Feature\Phase6;

use App\Enums\MatterStatus;
use App\Mail\ClientActionMail;
use App\Models\ActivityEvent;
use App\Models\DocumentRequest;
use App\Models\EmailDelivery;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Services\Communications\EmailDeliveryService;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Tests\Support\CommunicationWorkflow;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\TestCase;

/** Phase 6 §13: document request e-mails (recipient, secure link, idempotency, cooldown, failures, secrets). */
class DocumentRequestEmailTest extends TestCase
{
    use CommunicationWorkflow, RefreshDatabase, RequestWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Mail::fake();
    }

    private function send(DocumentRequest $request, array $body = [])
    {
        return $this->adminPost($request, 'send-email', $body);
    }

    #[Test]
    public function an_administrator_emails_a_request_to_the_matters_client_with_a_working_secure_link(): void
    {
        $matter = $this->matterFor('Northwind Holdings Ltd');
        $request = DocumentRequest::query()->findOrFail($this->createRequest($matter, [], false)->assertCreated()->json('data.id'));
        $client = $matter->client;

        $key = (string) Str::uuid();
        $response = $this->send($request, ['idempotency_key' => $key])->assertCreated()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.communication_type', 'document_request')
            ->assertJsonPath('data.recipient_email', $client->email)
            ->assertJsonPath('message', 'E-mail accepted by the configured mail server.');
        $this->assertArrayNotHasKey('url', $response->json('data'));

        Mail::assertSent(ClientActionMail::class, 1);
        $mail = $this->lastMail();
        $this->assertTrue($mail->hasTo($client->email));
        $this->assertSame('Document request — Lexora Legal', $mail->mailContent->subject, 'no matter or document details in the subject');
        $this->assertStringNotContainsString($request->title, $mail->mailContent->subject);
        $html = $mail->render();
        $this->assertStringContainsString('Signed Service Agreement', $html);
        $this->assertStringContainsString('Please upload the signed service agreement', $html);
        $token = $this->tokenFromMail($mail);
        $this->assertStringContainsString('/client-upload#'.$token, $html, 'the HTML link keeps the fragment');

        // The link exists only now, as a hash; it works on the public page.
        $delivery = EmailDelivery::query()->sole();
        $link = SecureAccessLink::query()->sole();
        $this->assertSame($delivery->secure_access_link_id, $link->id);
        $this->assertSame(SecureAccessLinkService::hashToken($token), $link->token_hash);
        $this->publicView($token)->assertOk()->assertJsonPath('data.title', 'Signed Service Agreement');

        // Nothing secret persisted anywhere.
        foreach ([EmailDelivery::query()->get()->toJson(), ActivityEvent::query()->get()->toJson(), DB::table('notifications')->get()->toJson()] as $stored) {
            $this->assertStringNotContainsString($token, $stored);
            $this->assertStringNotContainsString($link->token_hash, $stored);
        }
        $event = ActivityEvent::query()->where('event_type', 'email.accepted')->sole();
        $this->assertSame($delivery->id, $event->metadata['delivery_id']);
        $this->assertSame($client->email, $event->metadata['recipient_email']);

        // A repeated click (same idempotency key) returns the first result and sends nothing.
        $this->send($request, ['idempotency_key' => $key])->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $delivery->id);
        Mail::assertSent(ClientActionMail::class, 1);
        // A new attempt within the cooldown is refused.
        $this->send($request, ['idempotency_key' => (string) Str::uuid()])->assertConflict();
        Mail::assertSent(ClientActionMail::class, 1);

        // After the cooldown a resend replaces the link: the new one works, the old one stops at once.
        $this->travel(61)->seconds();
        $this->send($request, ['idempotency_key' => (string) Str::uuid()])->assertCreated();
        $newToken = $this->tokenFromMail($this->lastMail());
        $this->assertNotSame($token, $newToken);
        $this->assertSame('replaced', $link->fresh()->revocation_reason->value);
        $this->asGuest()->publicView($token)->assertNotFound();
        $this->publicView($newToken)->assertOk();
        $this->assertSame(1, SecureAccessLink::query()->whereNull('revoked_at')->count(), 'one usable link per request');
    }

    #[Test]
    public function the_recipient_and_delivery_values_are_decided_by_the_server(): void
    {
        [$request] = $this->requestWithLink();
        foreach (['recipient_email' => 'attacker@evil.example', 'to' => 'x@evil.example', 'bcc' => 'x@evil.example', 'subject' => 'Hi',
            'status' => 'accepted', 'client_id' => 99, 'matter_id' => 99, 'url' => 'https://evil.example'] as $field => $value) {
            $this->send($request, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
        $this->send($request, ['idempotency_key' => 'not-a-uuid'])->assertUnprocessable();
        Mail::assertNothingSent();
        $this->assertSame(0, EmailDelivery::query()->count());
    }

    #[Test]
    public function closed_foreign_archived_and_unaddressable_requests_are_refused(): void
    {
        [$request, $token] = $this->requestWithLink();
        // Another matter's request through this matter's URL: 404 (scoped binding).
        $other = $this->matterFor('Other Client');
        $this->actingAsAdmin($this->admin())->postJson("/api/matters/{$other->id}/document-requests/{$request->id}/send-email")->assertNotFound();
        // Guests: 401.
        $this->asGuest()->postJson("/api/matters/{$request->matter_id}/document-requests/{$request->id}/send-email")->assertUnauthorized();

        // A client with no valid address: 422, nothing sent.
        DB::table('clients')->where('id', $request->client_id)->update(['email' => 'not-an-address']);
        $this->send($request)->assertUnprocessable()->assertJsonValidationErrors(['recipient']);
        DB::table('clients')->where('id', $request->client_id)->update(['email' => "client@example.test\r\nBcc: victim@example.test"]);
        $this->send($request)->assertUnprocessable();
        DB::table('clients')->where('id', $request->client_id)->update(['email' => 'legal@northwind.example']);

        // Waiting for review: the client already uploaded — no e-mail asking for it again.
        $this->publicSubmit($token, PdfFactory::upload('agreement.pdf', ['Signed agreement']))->assertCreated();
        $this->send($request)->assertConflict();

        // Cancelled.
        [$cancelled] = $this->requestWithLink();
        $this->adminPost($cancelled, 'cancel', ['lock_version' => $this->lockVersion($cancelled)])->assertOk();
        $this->send($cancelled)->assertConflict();

        // Archived matter.
        [$archived] = $this->requestWithLink();
        Matter::query()->whereKey($archived->matter_id)->update(['status' => MatterStatus::Archived->value, 'archived_at' => now(), 'archived_from_status' => 'open']);
        $this->send($archived)->assertConflict();

        Mail::assertNothingSent();
        $this->assertSame(0, EmailDelivery::query()->count());
    }

    #[Test]
    public function a_failed_send_records_the_failure_and_keeps_the_previously_delivered_link(): void
    {
        [$request, $token] = $this->requestWithLink();
        $this->useUnreachableSmtp();

        $this->send($request, ['idempotency_key' => (string) Str::uuid()])->assertStatus(502)
            ->assertJsonPath('code', 'email_failed')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_category', 'connection');
        $this->assertStringStartsWith('E-mail could not be sent. No successful delivery has been recorded.', $this->send($request)->json('message'));

        $this->assertSame(['failed', 'failed'], EmailDelivery::query()->orderBy('id')->pluck('status')->map->value->all());
        $this->assertNull(EmailDelivery::query()->first()->accepted_at);
        $this->assertSame(1, SecureAccessLink::query()->count(), 'no new link was created');
        $this->publicView($token)->assertOk(); // the link the client already has still works
        $this->assertSame(2, ActivityEvent::query()->where('event_type', 'email.failed')->count());
        $this->assertSame(0, ActivityEvent::query()->where('event_type', 'email.accepted')->count());
        $this->assertSame('pending', $request->fresh()->status->value, 'the request itself never changes because of an e-mail');
    }

    #[Test]
    public function email_that_is_not_configured_is_reported_without_exposing_settings(): void
    {
        [$request] = $this->requestWithLink();
        config(['mail.mailers.smtp.username' => 'smtp-user', 'mail.mailers.smtp.password' => 'Super-Secret-Smtp-Password-1']);

        $status = $this->actingAsAdmin($this->admin())->getJson('/api/communications/status')->assertOk();
        $this->assertTrue($status->json('data.available'));
        $this->assertStringNotContainsString('Super-Secret-Smtp-Password-1', $status->getContent());
        $this->assertStringNotContainsString('smtp-user', $status->getContent());

        config(['lexora.communications.email_enabled' => false]);
        $this->actingAsAdmin($this->admin())->getJson('/api/communications/status')->assertOk()->assertJsonPath('data.available', false);
        $this->send($request)->assertStatus(503)->assertJsonPath('message', 'E-mail sending is switched off (LEXORA_EMAIL_ENABLED=false).');

        config(['lexora.communications.email_enabled' => true, 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.mailers.smtp.url' => null]);
        $this->send($request)->assertStatus(503);
        config(['mail.default' => 'array', 'mail.from.address' => 'not an address']);
        $this->send($request)->assertStatus(503);
        Mail::assertNothingSent();
        $this->assertSame(0, EmailDelivery::query()->count());
    }

    #[Test]
    public function transport_errors_map_to_safe_categories(): void
    {
        $this->assertSame('connection', EmailDeliveryService::categorize(new TransportException('Connection could not be established with host "127.0.0.1:1": stream_socket_client(): Unable to connect'))->value);
        $this->assertSame('timeout', EmailDeliveryService::categorize(new TransportException('Connection to "127.0.0.1:25" timed out.'))->value);
        $this->assertSame('authentication', EmailDeliveryService::categorize(new UnexpectedResponseException('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".', 535))->value);
        $this->assertSame('recipient_rejected', EmailDeliveryService::categorize(new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown".', 550))->value);
        $this->assertSame('sender_rejected', EmailDeliveryService::categorize(new UnexpectedResponseException('Expected response code "250" but got code "553", with message "553 5.7.1 Sender address rejected".', 553))->value);
        $this->assertSame('connection', EmailDeliveryService::categorize(new UnexpectedResponseException('Expected response code "250" but got code "421", with message "421 try later".', 421))->value);
        $this->assertSame('invalid_recipient', EmailDeliveryService::categorize(new RfcComplianceException('Email "x" does not comply with addr-spec of RFC 2822.'))->value);
        $this->assertSame('configuration', EmailDeliveryService::categorize(new \InvalidArgumentException('Mailer [nope] is not defined.'))->value);

        $this->assertTrue(EmailDeliveryService::isValidAddress('ana.krasniqi@northwind.example'));
        foreach (['', 'no-at-sign', "a@b.example\r\nBcc: c@d.example", 'Name <a@b.example>', 'a@b.example, c@d.example', str_repeat('a', 250).'@b.example'] as $bad) {
            $this->assertFalse(EmailDeliveryService::isValidAddress($bad), $bad);
        }
        $this->assertSame('Ana Krasniqi Bcc: x', EmailDeliveryService::cleanName("Ana Krasniqi\r\nBcc: x"));
        Carbon::setTestNow();
    }
}
