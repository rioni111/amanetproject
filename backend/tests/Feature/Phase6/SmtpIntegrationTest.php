<?php

namespace Tests\Feature\Phase6;

use App\Models\EmailDelivery;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CommunicationWorkflow;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/**
 * REAL SMTP: Laravel builds MIME messages and submits them over SMTP to the isolated E2E Mailpit
 * (docker compose service mailpit-e2e; nothing leaves the machine). The test reads the message back
 * through Mailpit's API, checks headers, both bodies and the link, and then uses the link from the
 * delivered message on the public API. Requires mailpit-e2e to be running (verify-windows.bat starts it);
 * if it is not, the test FAILS with an explanation instead of being skipped.
 */
class SmtpIntegrationTest extends TestCase
{
    use CommunicationWorkflow, RefreshDatabase, RequestWorkflow, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        try {
            Http::timeout(3)->get($this->e2eMailpitApi().'/info')->throw();
        } catch (\Throwable) {
            $this->fail('The E2E Mailpit is not reachable at '.$this->e2eMailpitApi().'. Start it: docker compose up -d --pull never mailpit-e2e');
        }
        $this->useE2eMailpit();
        config(['mail.from.address' => 'workspace@lexora.test', 'mail.from.name' => 'Lexora Workspace',
            'lexora.communications.reply_to' => 'client-care@lexora.test', 'lexora.communications.reply_to_name' => 'Lexora Client Care']);
    }

    /** The Mailpit message (summary + full) carrying our X-Lexora-Delivery header. */
    private function mailpitMessage(EmailDelivery $delivery): array
    {
        $search = Http::get($this->e2eMailpitApi().'/search', ['query' => 'subject:"'.$delivery->subject.'"', 'limit' => 50])->throw()->json('messages') ?? [];
        foreach ($search as $summary) {
            $headers = Http::get($this->e2eMailpitApi().'/message/'.$summary['ID'].'/headers')->throw()->json();
            if (($headers['X-Lexora-Delivery'][0] ?? null) === $delivery->uuid) {
                return Http::get($this->e2eMailpitApi().'/message/'.$summary['ID'])->throw()->json() + ['Headers' => $headers];
            }
        }
        $this->fail('The accepted message was not found in Mailpit.');
    }

    #[Test]
    public function a_document_request_email_is_delivered_over_smtp_and_its_link_accepts_the_upload(): void
    {
        $matter = $this->matterFor('Harbor Point Logistics');
        [$request] = $this->requestWithLink($matter, ['title' => 'Proof of address & ID <scan>']);
        $response = $this->adminPost($request, 'send-email')->assertCreated();
        $delivery = EmailDelivery::query()->findOrFail($response->json('data.id'));
        $this->assertNotNull($delivery->provider_message_id, 'the transport returned a Message-ID');

        $message = $this->mailpitMessage($delivery);
        $this->assertSame('workspace@lexora.test', $message['From']['Address']);
        $this->assertSame($matter->client->email, $message['To'][0]['Address']);
        $this->assertSame('client-care@lexora.test', $message['ReplyTo'][0]['Address']);
        $this->assertSame('Document request — Lexora Legal', $message['Subject']);
        // provider_message_id is the id the SMTP server returned when it accepted the message
        // ("250 … queued as <id>"); for Mailpit that is the stored message's own id.
        $this->assertSame($delivery->provider_message_id, $message['ID']);

        // Both parts exist; user text is escaped in HTML and readable in plain text.
        $this->assertStringContainsString('Proof of address &amp; ID &lt;scan&gt;', $message['HTML']);
        $this->assertStringNotContainsString('<scan>', $message['HTML']);
        $this->assertStringContainsString('Proof of address & ID <scan>', $message['Text']);
        $this->assertStringNotContainsString('&amp;', $message['Text']);

        // The link survives MIME encoding with its fragment, in both parts, and is the registered one.
        preg_match('#http://[^\s"<>]+/client-upload\#([A-Za-z0-9_-]{43})#', $message['HTML'], $html);
        preg_match('#http://\S+/client-upload\#([A-Za-z0-9_-]{43})#', $message['Text'], $text);
        $this->assertNotEmpty($html[1] ?? null);
        $this->assertSame($html[1], $text[1] ?? null);
        $token = $html[1];
        $this->assertSame(SecureAccessLinkService::hashToken($token), SecureAccessLink::query()->findOrFail($delivery->secure_access_link_id)->token_hash);

        $this->asGuest()->publicSubmit($token, PdfFactory::upload('proof.pdf', ['Proof of address']))->assertCreated();
        $this->assertSame('submitted', $request->fresh()->status->value);
    }

    #[Test]
    public function a_signing_invitation_is_delivered_over_smtp_and_its_link_reaches_the_signing_page(): void
    {
        $matter = $this->signingMatter();
        $document = $this->uploadDocument($matter);
        $request = SigningRequest::query()->findOrFail($this->createSigning($matter, $document, ['issue_link' => false])->json('data.id'));
        $response = $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$matter->id}/signing-requests/{$request->id}/send-email")->assertCreated();
        $message = $this->mailpitMessage(EmailDelivery::query()->findOrFail($response->json('data.id')));

        $this->assertSame('ana.krasniqi@northwind.example', $message['To'][0]['Address']);
        $this->assertSame('Signature requested — Lexora Legal', $message['Subject']);
        preg_match('#/client-sign\#([A-Za-z0-9_-]{43})#', $message['Text'], $m);
        $this->forgetUser()->publicSigning('GET', '', $m[1] ?? 'missing')->assertOk()->assertJsonPath('data.document_title', 'Client Service Agreement');
        $this->assertSame('sent', $request->fresh()->status->value);
    }
}
