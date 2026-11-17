<?php

namespace Tests\Feature\Phase6;

use App\Mail\ClientActionMail;
use App\Models\ActivityEvent;
use App\Models\EmailDelivery;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CommunicationWorkflow;
use Tests\Support\RequestWorkflow;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Phase 6 §15 / §12: signing invitation e-mails and their integration with Sent → Viewed → Started → Signed. */
class SigningInvitationEmailTest extends TestCase
{
    use CommunicationWorkflow, RefreshDatabase, RequestWorkflow, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Mail::fake();
    }

    /** A client signing request without any link yet. */
    private function readyRequest(array $overrides = []): SigningRequest
    {
        $matter = $this->signingMatter();
        $document = $this->uploadDocument($matter);
        $id = $this->createSigning($matter, $document, ['issue_link' => false, ...$overrides])->assertCreated()->json('data.id');

        return SigningRequest::query()->findOrFail($id);
    }

    private function sendInvitation(SigningRequest $request, array $body = [])
    {
        return $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$request->matter_id}/signing-requests/{$request->id}/send-email", $body);
    }

    #[Test]
    public function an_accepted_invitation_marks_sent_and_its_link_completes_the_signing(): void
    {
        $request = $this->readyRequest();
        $this->assertSame('ready', $request->status->value);

        $this->sendInvitation($request, ['idempotency_key' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.communication_type', 'signing_invitation')
            ->assertJsonPath('data.recipient_email', 'ana.krasniqi@northwind.example')
            ->assertJsonPath('data.recipient_name', 'Ana Krasniqi');

        $mail = $this->lastMail();
        $this->assertTrue($mail->hasTo('ana.krasniqi@northwind.example'));
        $this->assertSame('Signature requested — Lexora Legal', $mail->mailContent->subject);
        $this->assertStringContainsString('No account or password is needed', $mail->render());
        $token = $this->tokenFromMail($mail, 'client-sign');

        // "Sent" because the SMTP transport accepted the message — channel e-mail, own delivery status.
        $request->refresh();
        $this->assertSame('sent', $request->status->value);
        $this->assertNotNull($request->sent_at);
        $this->assertSame('email', $request->delivery_channel->value);
        $this->assertSame('email_accepted', $request->delivery_status->value);
        $this->assertSame(SecureAccessLinkService::hashToken($token), SecureAccessLink::query()->sole()->token_hash);
        $event = ActivityEvent::query()->where('event_type', 'email.accepted')->sole();
        $this->assertSame(['ready', 'sent'], [$event->metadata['from'], $event->metadata['to']]);

        // The e-mailed link drives the real signing flow; viewed / started / signed come from the signer only.
        $this->viewAndStart($token);
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertCreated();
        $request->refresh();
        $this->assertSame('signed', $request->status->value);
        $this->assertTrue($request->sent_at->lte($request->viewed_at) && $request->viewed_at->lte($request->started_at));

        // Purpose isolation: the signing token is unknown to the upload endpoints.
        $this->asGuest()->publicView($token)->assertNotFound();
        // Completed: no further invitation.
        $this->travel(2)->minutes();
        $this->sendInvitation($request)->assertConflict()->assertJsonPath('message', 'This document has already been signed; no invitation is needed.');
        Mail::assertSent(ClientActionMail::class, 1);
    }

    #[Test]
    public function preparing_or_failing_an_invitation_never_marks_sent(): void
    {
        $request = $this->readyRequest();
        $this->useUnreachableSmtp();
        $this->sendInvitation($request)->assertStatus(502)->assertJsonPath('data.status', 'failed');

        $request->refresh();
        $this->assertSame('ready', $request->status->value);
        $this->assertNull($request->sent_at);
        $this->assertSame('not_shared', $request->delivery_status->value);
        $this->assertSame(0, SecureAccessLink::query()->count(), 'no link exists for a message that was not accepted');
        $this->assertSame('failed', EmailDelivery::query()->sole()->status->value);
    }

    #[Test]
    public function progress_never_moves_backwards_and_the_recipient_is_the_signer_snapshot(): void
    {
        // The signer opened the manually shared link before the e-mail went out.
        [$request, $token] = $this->clientSigning();
        $this->viewAndStart($token);
        DB::table('clients')->where('id', $request->client_id)->update(['email' => 'changed@northwind.example']); // later client edit
        $this->travel(2)->minutes();

        $this->sendInvitation($request)->assertCreated()->assertJsonPath('data.recipient_email', 'ana.krasniqi@northwind.example');
        $request->refresh();
        $this->assertSame('started', $request->status->value, 'no regression to sent');
        $this->assertNull($request->sent_at);
        $this->assertSame('not_shared', $request->delivery_status->value);
        // The resend replaced the earlier link: the old token stops, the e-mailed one works.
        $this->publicSigning('GET', '', $token)->assertNotFound();
        $this->publicSigning('GET', '', $this->tokenFromMail($this->lastMail(), 'client-sign'))->assertOk();
    }

    #[Test]
    public function administrator_cancelled_archived_and_addressless_requests_cannot_be_emailed(): void
    {
        $matter = $this->signingMatter();
        $admin = SigningRequest::query()->findOrFail($this->createSigning($matter, $this->uploadDocument($matter, null, 'Admin doc'), ['signer_type' => 'admin', 'signer_name' => null, 'signer_email' => null])->assertCreated()->json('data.id'));
        $this->sendInvitation($admin)->assertConflict();

        $noEmail = $this->readyRequest(['signer_email' => null]);
        $this->sendInvitation($noEmail)->assertUnprocessable()->assertJsonValidationErrors(['recipient']);

        $cancelled = $this->readyRequest();
        $this->signingAction($cancelled, 'cancel', [])->assertOk();
        $this->sendInvitation($cancelled)->assertConflict();

        $this->sendInvitation($this->readyRequest(), ['recipient_email' => 'other@evil.example'])->assertUnprocessable();
        Mail::assertNothingSent();
    }
}
