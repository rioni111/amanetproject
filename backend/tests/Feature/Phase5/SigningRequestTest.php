<?php

namespace Tests\Feature\Phase5;

use App\Enums\ActivityEventType;
use App\Enums\MatterStatus;
use App\Models\ActivityEvent;
use App\Models\DocumentRequest;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SignatureRecord;
use App\Models\SigningRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Scenarios A–C (backend): creation and eligibility, secure signing links, progress tracking. */
class SigningRequestTest extends TestCase
{
    use RefreshDatabase, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    #[Test]
    public function client_and_admin_requests_have_exactly_one_server_determined_signer(): void
    {
        $matter = $this->signingMatter();
        $document = $this->uploadDocument($matter);

        $client = $this->createSigning($matter, $document, ['issue_link' => false])->assertCreated()
            ->assertJsonPath('data.signer_type', 'client')
            ->assertJsonPath('data.signer_name', 'Ana Krasniqi')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.status_label', 'Ready to send')
            ->assertJsonPath('data.delivery_status', 'not_shared')
            ->assertJsonPath('data.delivery_channel', null)
            ->assertJsonPath('data.sent_at', null)
            ->assertJsonPath('data.source_version.label', 'v1')
            ->assertJsonPath('data.allowed_actions.mark_shared', false)
            ->assertJsonPath('link', null);
        $request = SigningRequest::query()->findOrFail($client->json('data.id'));
        $this->assertSame($matter->client_id, $request->client_id);
        $this->assertSame($document->current_version_id, $request->document_version_id);
        $event = ActivityEvent::query()->where('event_type', ActivityEventType::SigningRequested->value)->sole();
        $this->assertSame($request->id, $event->metadata['signing_request_id']);

        // One active request per document.
        $this->createSigning($matter, $document)->assertStatus(409);
        $this->signingAction($request, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Administrator signer: always the authenticated user; identity fields are refused.
        $this->createSigning($matter, $document, ['signer_type' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors(['signer_name', 'signer_email']);
        $admin = $this->createSigning($matter, $document, ['signer_type' => 'admin', 'signer_name' => null, 'signer_email' => null, 'issue_link' => true])
            ->assertCreated()->assertJsonPath('data.signer_name', 'Elena Marsh')->assertJsonPath('data.signer_email', 'elena.marsh@lexora.test')
            ->assertJsonPath('link', null)->assertJsonPath('data.allowed_actions.admin_sign', true);
        $this->assertSame($this->signingAdmin()->id, SigningRequest::query()->findOrFail($admin->json('data.id'))->signer_user_id);
    }

    #[Test]
    public function invalid_signers_and_ineligible_sources_are_refused(): void
    {
        $matter = $this->signingMatter();
        $document = $this->uploadDocument($matter);

        $this->createSigning($matter, $document, ['signer_name' => '', 'signer_email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors(['signer_name', 'signer_email']);
        $this->createSigning($matter, $document, ['signers' => [['name' => 'A'], ['name' => 'B']]])->assertUnprocessable()->assertJsonValidationErrors(['signers']);
        $this->createSigning($matter, $document, ['status' => 'signed', 'client_id' => 99, 'signer_user_id' => 1, 'signed_at' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'client_id', 'signer_user_id', 'signed_at']);
        $this->createSigning($matter, $document, ['signer_type' => 'witness'])->assertUnprocessable();

        // Another matter's document / version, a non-current version.
        $other = $this->uploadDocument($this->signingMatter('Other Client'));
        $this->createSigning($matter, $other)->assertUnprocessable()->assertJsonValidationErrors(['document_id']);
        $this->createSigning($matter, $document, ['document_version_id' => $other->current_version_id])->assertUnprocessable()->assertJsonValidationErrors(['document_version_id']);
        $old = $document->current_version_id;
        $this->actingAsAdmin($this->signingAdmin())->post("/api/matters/{$matter->id}/documents/{$document->id}/versions",
            ['file' => PdfFactory::upload('v2.pdf', ['Revised']), 'lock_version' => $document->fresh()->lock_version], ['Accept' => 'application/json'])->assertCreated();
        $this->createSigning($matter, $document, ['document_version_id' => $old])->assertUnprocessable()
            ->assertJsonPath('errors.document_version_id.0', 'Only the current version of a document can be signed. Open the latest version.');

        // PDFs the engine must not process (would silently lose annotations / forms).
        $annotated = $this->uploadDocument($matter, PdfFactory::bytes(['Linked'], '/Annots [] '), 'Annotated');
        $this->createSigning($matter, $annotated)->assertUnprocessable()->assertJsonValidationErrors(['document']);
        $this->actingAsAdmin($this->signingAdmin())->getJson("/api/matters/{$matter->id}/documents/{$annotated->id}/versions/{$annotated->current_version_id}/signing-eligibility")
            ->assertUnprocessable();
        $this->actingAsAdmin($this->signingAdmin())->getJson("/api/matters/{$matter->id}/documents/{$document->id}/versions/{$document->fresh()->current_version_id}/signing-eligibility")
            ->assertOk()->assertJsonPath('data.eligible', true)->assertJsonPath('data.page_count', 1);

        // Archived document / matter; guests.
        $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$matter->id}/documents/{$annotated->id}/archive", ['lock_version' => $annotated->fresh()->lock_version])->assertOk();
        $this->createSigning($matter, $annotated->fresh())->assertStatus(409);
        $archived = Matter::factory()->status(MatterStatus::Archived)->create();
        $this->createSigning($archived, $document)->assertStatus(409);
        $this->forgetUser()->postJson("/api/matters/{$matter->id}/signing-requests", [])->assertUnauthorized();
        $this->assertSame(0, SigningRequest::query()->count());
    }

    /**
     * PDF compatibility limits (report §5): files the engine cannot process faithfully are accepted as documents
     * (Phase 3 rules) but refused for signing — on eligibility, request creation and direct admin signing — with
     * an explanation, and nothing is created or changed.
     */
    #[Test]
    public function pdfs_the_engine_cannot_sign_faithfully_are_refused_with_an_explanation(): void
    {
        $matter = $this->signingMatter();
        $cases = [
            'form' => [PdfFactory::withFeatures('/AcroForm 10 0 R', [10 => '<< /Fields [] >>']), 'This PDF contains form fields.'],
            'annotations' => [PdfFactory::bytes(['Linked'], '/Annots [] '), 'This PDF contains annotations'],
            'digital signature' => [PdfFactory::withFeatures('', [10 => '<< /Type /Sig /Filter /Adobe.PPKLite /ByteRange [0 10 20 10] /Contents <00> >>']),
                'This PDF already contains a digital signature.'],
            'xref stream' => [PdfFactory::xrefStream(), 'This PDF uses a compressed format (PDF 1.5+ cross-reference streams)'],
            'broken xref' => [PdfFactory::brokenCrossReference(), 'This PDF could not be read by the signing engine.'],
        ];
        foreach ($cases as $label => [$bytes, $message]) {
            $document = $this->uploadDocument($matter, $bytes, "Unsupported {$label}");
            $version = $document->currentVersion;
            $base = "/api/matters/{$matter->id}/documents/{$document->id}";

            $eligibility = $this->actingAsAdmin($this->signingAdmin())->getJson("{$base}/versions/{$version->id}/signing-eligibility")->assertUnprocessable();
            $this->assertStringStartsWith($message, $eligibility->json('errors.document.0'), $label);
            $this->createSigning($matter, $document)->assertUnprocessable()->assertJsonValidationErrors(['document']);
            $payload = $this->signaturePayload(['document_version_id' => $version->id]);
            unset($payload['confirmed_name']);
            $this->actingAsAdmin($this->signingAdmin())->postJson("{$base}/sign", $payload)->assertUnprocessable();

            $this->assertSame(1, DocumentVersion::query()->where('document_id', $document->id)->count(), "{$label}: no signed version");
            $this->assertSame($version->sha256_checksum, hash('sha256', Storage::disk('documents')->get($version->storage_path)), "{$label}: original unchanged");
        }
        $this->assertSame(0, SigningRequest::query()->count());
        $this->assertSame(0, SignatureRecord::query()->count());
    }

    #[Test]
    public function client_submissions_must_be_reviewed_before_signing(): void
    {
        $matter = $this->signingMatter();
        $create = fn () => $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$matter->id}/document-requests", [
            'title' => 'Signed lease', 'instructions' => 'Upload the lease.', 'requested_category' => 'agreements', 'issue_link' => true,
        ])->assertCreated();
        $created = $create();
        $url = $created->json('link.url');
        $this->forgetUser()->post('/api/public/document-upload/submit', ['file' => PdfFactory::upload('lease.pdf', ['Lease'])],
            ['Accept' => 'application/json', 'X-Upload-Token' => substr($url, strrpos($url, '#') + 1)])->assertCreated();
        $documentRequest = DocumentRequest::query()->findOrFail($created->json('data.id'));
        $document = $documentRequest->document()->with('currentVersion')->first();
        $clientVersion = $document->currentVersion;

        $this->createSigning($matter, $document, ['signer_type' => 'admin', 'signer_name' => null, 'signer_email' => null])->assertStatus(409)
            ->assertJsonPath('message', 'This document belongs to a client request that is still being reviewed. Approve the submission before signing it.');

        $base = "/api/matters/{$matter->id}/document-requests/{$documentRequest->id}";
        $submission = $this->actingAsAdmin($this->signingAdmin())->getJson($base)->json('data.latest_submission.id');
        $this->postJson("{$base}/start-review", ['submission_id' => $submission, 'lock_version' => $documentRequest->fresh()->lock_version])->assertOk();
        $this->postJson("{$base}/approve", ['submission_id' => $submission, 'lock_version' => $documentRequest->fresh()->lock_version])->assertOk();

        $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$matter->id}/documents/{$document->id}/sign", [
            ...$this->signaturePayload(['confirmed_name' => null]), 'document_version_id' => $clientVersion->id,
        ])->assertCreated()->assertJsonPath('data.status', 'signed')->assertJsonPath('data.signed_version.kind', 'signed');
        $this->assertSame($clientVersion->sha256_checksum, $clientVersion->fresh()->sha256_checksum);
        $this->assertTrue(Storage::disk('documents')->exists($clientVersion->storage_path));
        $this->assertSame('approved', $documentRequest->fresh()->status->value, 'signing never changes the review outcome');
    }

    #[Test]
    public function signing_links_are_hashed_scoped_expiring_revocable_and_rotatable(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        [$request, $token] = $this->clientSigning();
        $link = SecureAccessLink::query()->sole();
        $this->assertSame('document_signing', $link->purpose->value);
        $this->assertSame($request->id, $link->signing_request_id);
        $this->assertNull($link->document_request_id);
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertSame('2026-11-24T10:00:00+00:00', $link->expires_at->toIso8601String(), 'default: 7 days');
        $this->assertSame(32, strlen((string) base64_decode(strtr($token, '-_', '+/').'=', true)));
        foreach ([$this->signingDetail($request)->getContent(), $this->getJson('/api/signing-requests')->getContent(), json_encode(DB::table('secure_access_links')->get()), json_encode(DB::table('activity_events')->get())] as $json) {
            $this->assertStringNotContainsString($token, $json);
            $this->assertStringNotContainsString('client-sign#', $json);
        }

        // Purpose isolation both ways.
        $this->forgetUser()->getJson('/api/public/document-upload', ['X-Upload-Token' => $token])->assertNotFound();
        $this->publicSigning('GET', '', 'A'.str_repeat('x', 42))->assertNotFound()->assertJsonPath('code', 'link_invalid');
        $this->publicSigning('GET', '', null)->assertNotFound();

        // Rotation: old link dies immediately, new one works.
        $this->signingAction($request, 'links')->assertStatus(409);
        $rotated = $this->signingAction($request, 'links/rotate')->assertCreated();
        $second = $this->signingTokenFrom($rotated);
        $this->publicSigning('GET', '', $token)->assertNotFound();
        $this->publicSigning('GET', '', $second)->assertOk();
        $this->assertSame(1, ActivityEvent::query()->where('event_type', ActivityEventType::SigningLinkRotated->value)->count());

        // Expiry.
        Carbon::setTestNow('2026-11-24T10:00:01Z');
        $this->publicSigning('GET', '', $second)->assertStatus(410)->assertJsonPath('code', 'link_expired');
        Carbon::setTestNow('2026-11-17 11:00:00');

        // Revocation.
        $active = SecureAccessLink::query()->where('token_hash', hash('sha256', $second))->sole();
        $this->signingAction($request, "links/{$active->id}/revoke")->assertOk()->assertJsonPath('revoked_link.state', 'revoked');
        $this->publicSigning('GET', '', $second)->assertNotFound()->assertJsonPath('code', 'link_invalid');
        $this->publicSigning('POST', '/view', $second)->assertNotFound();

        // Admin requests never have links.
        $matter = $this->signingMatter('Admin Only');
        $doc = $this->uploadDocument($matter);
        $admin = SigningRequest::query()->findOrFail($this->createSigning($matter, $doc, ['signer_type' => 'admin', 'signer_name' => null, 'signer_email' => null])->json('data.id'));
        $this->signingAction($admin, 'links')->assertStatus(409);
        $this->signingAction($admin, 'mark-shared')->assertStatus(409);
    }

    #[Test]
    public function progress_moves_forward_only_with_server_timestamps_and_no_email_claims(): void
    {
        Carbon::setTestNow('2026-11-17 09:00:00');
        [$request, $token] = $this->clientSigning(overrides: ['issue_link' => false]);
        // Sent requires a usable link and an explicit administrator confirmation.
        $this->signingAction($request, 'mark-shared')->assertStatus(409);
        $this->signingAction($request, 'links')->assertCreated();
        $detail = $this->signingDetail($request)->assertJsonPath('data.status', 'ready')->assertJsonPath('data.allowed_actions.mark_shared', true);
        $token = $this->signingTokenFrom($this->signingAction($request, 'links/rotate')->assertCreated());

        Carbon::setTestNow('2026-11-17 09:05:00');
        $this->signingAction($request, 'mark-shared', ['lock_version' => 1])->assertStatus(409); // stale
        $this->signingAction($request, 'mark-shared')->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.delivery_channel', 'manual')
            ->assertJsonPath('data.delivery_status', 'shared_manually')
            ->assertJsonPath('data.delivery_status_label', 'Shared by the administrator (outside the app)')
            ->assertJsonPath('data.sent_at', '2026-11-17T09:05:00+00:00');
        $this->signingAction($request, 'mark-shared')->assertStatus(409);

        Carbon::setTestNow('2026-11-17 09:10:00');
        $this->publicSigning('POST', '/view', $token, ['viewed_at' => '2020-01-01'])->assertOk()->assertJsonPath('data.status', 'viewed');
        Carbon::setTestNow('2026-11-17 09:11:00');
        $this->publicSigning('POST', '/view', $token)->assertOk(); // duplicate: no change
        $this->assertSame('2026-11-17T09:10:00+00:00', $request->fresh()->viewed_at->toIso8601String());
        $this->publicSigning('POST', '/start', $token)->assertOk()->assertJsonPath('data.status', 'started');
        $this->publicSigning('POST', '/view', $token)->assertOk()->assertJsonPath('data.status', 'started', 'a late view never moves progress back');
        $this->publicSigning('POST', '/start', $token)->assertOk(); // idempotent

        Carbon::setTestNow('2026-11-17 09:15:00');
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertCreated();
        $request->refresh();
        $this->assertSame(['sent', 'viewed', 'started', 'signed'], [
            $request->sent_at ? 'sent' : null, $request->viewed_at ? 'viewed' : null, $request->started_at ? 'started' : null, $request->status->value,
        ]);
        $this->assertTrue($request->sent_at->lessThanOrEqualTo($request->viewed_at) && $request->viewed_at->lessThanOrEqualTo($request->started_at) && $request->started_at->lessThanOrEqualTo($request->signed_at));
        $this->assertSame(1, ActivityEvent::query()->where('event_type', ActivityEventType::SigningViewed->value)->count());
        $this->assertSame(1, ActivityEvent::query()->where('event_type', ActivityEventType::SigningInvitationShared->value)->count());
        $this->assertStringNotContainsString('email_sent', json_encode(ActivityEvent::all()->toArray()));

        // A finished request is final in the database too.
        $this->expectException(QueryException::class);
        DB::table('signing_requests')->where('id', $request->id)->update(['status' => 'viewed']);
    }

    #[Test]
    public function a_client_may_open_the_link_before_it_is_marked_shared(): void
    {
        [$request, $token] = $this->clientSigning();
        $this->publicSigning('POST', '/view', $token)->assertOk()->assertJsonPath('data.status', 'viewed');
        $request->refresh();
        $this->assertNull($request->sent_at, 'no sharing was confirmed, so no "sent" milestone is invented');
        $this->assertSame('not_shared', $request->delivery_status->value);
        $this->signingAction($request, 'mark-shared')->assertStatus(409);
    }

    #[Test]
    public function the_database_guards_signer_and_source_assignments(): void
    {
        [$request] = $this->clientSigning();
        foreach ([['signer_name' => 'Mallory'], ['document_version_id' => $request->document_version_id + 100], ['signer_type' => 'admin', 'signer_user_id' => User::factory()->create()->id, 'client_id' => null]] as $change) {
            try {
                DB::transaction(fn () => DB::table('signing_requests')->where('id', $request->id)->update($change));
                $this->fail('reassignment should be refused: '.json_encode($change));
            } catch (QueryException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('Ana Krasniqi', $request->fresh()->signer_name);
    }
}
