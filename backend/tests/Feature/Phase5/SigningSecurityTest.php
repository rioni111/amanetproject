<?php

namespace Tests\Feature\Phase5;

use App\Models\DocumentVersion;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Scenario I (backend): authorisation, scoping, isolation, rate limits and leakage. */
class SigningSecurityTest extends TestCase
{
    use RefreshDatabase, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    #[Test]
    public function every_administrator_signing_endpoint_requires_a_session(): void
    {
        [$request] = $this->clientSigning();
        $link = SecureAccessLink::query()->sole();
        $base = "/api/matters/{$request->matter_id}/signing-requests";
        $doc = $request->document_id;
        $this->forgetUser();
        foreach ([
            ['get', '/api/signing-requests'], ['get', $base], ['post', $base], ['get', "{$base}/{$request->id}"], ['get', "{$base}/{$request->id}/evidence"],
            ['post', "{$base}/{$request->id}/links"], ['post', "{$base}/{$request->id}/links/rotate"], ['post', "{$base}/{$request->id}/links/{$link->id}/revoke"],
            ['post', "{$base}/{$request->id}/mark-shared"], ['post', "{$base}/{$request->id}/cancel"], ['post', "{$base}/{$request->id}/admin-sign"],
            ['post', "/api/matters/{$request->matter_id}/documents/{$doc}/sign"],
            ['get', "/api/matters/{$request->matter_id}/documents/{$doc}/versions/{$request->document_version_id}/signing-eligibility"],
        ] as [$method, $uri]) {
            $this->json($method, $uri, [], ['Accept' => 'application/json'])->assertUnauthorized();
        }
    }

    #[Test]
    public function nested_resources_and_tokens_cannot_cross_matters(): void
    {
        [$a, $tokenA] = $this->clientSigning($this->signingMatter('Client A'));
        [$b] = $this->clientSigning($this->signingMatter('Client B'));
        $linkA = SecureAccessLink::query()->where('signing_request_id', $a->id)->sole();
        $this->actingAsAdmin($this->signingAdmin());

        $this->getJson("/api/matters/{$b->matter_id}/signing-requests/{$a->id}")->assertNotFound();
        $this->postJson("/api/matters/{$b->matter_id}/signing-requests/{$a->id}/cancel")->assertNotFound();
        $this->postJson("/api/matters/{$b->matter_id}/signing-requests/{$b->id}/links/{$linkA->id}/revoke")->assertNotFound();
        $this->postJson("/api/matters/{$b->matter_id}/documents/{$a->document_id}/sign", [])->assertNotFound();
        $this->getJson("/api/matters/{$b->matter_id}/documents/{$a->document_id}/versions/{$a->document_version_id}/signing-eligibility")->assertNotFound();

        // Token A only ever serves request A's document.
        $this->publicSigning('GET', '', $tokenA)->assertOk()->assertJsonPath('data.document_title', 'Client Service Agreement');
        $bytes = $this->publicSigning('GET', '/document', $tokenA)->assertOk()->streamedContent();
        $this->assertSame(DocumentVersion::query()->findOrFail($a->document_version_id)->sha256_checksum, hash('sha256', $bytes));
        // The token is not an administrator credential.
        $this->forgetUser()->getJson("/api/matters/{$a->matter_id}/documents", ['X-Signing-Token' => $tokenA])->assertUnauthorized();
        $this->forgetUser()->getJson("/api/matters/{$a->matter_id}/notes", ['X-Signing-Token' => $tokenA])->assertUnauthorized();
        $this->forgetUser()->get('/storage/'.DocumentVersion::query()->findOrFail($a->document_version_id)->storage_path)->assertNotFound();
    }

    #[Test]
    public function public_endpoints_are_isolated_minimal_and_rate_limited(): void
    {
        [$request, $token] = $this->clientSigning();
        $info = $this->publicSigning('GET', '', $token)->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertSame(['firm_name', 'document_title', 'version_label', 'file_name', 'signer_name', 'signer_email', 'message', 'status', 'viewed', 'started', 'expires_at', 'consent'], array_keys($info->json('data')));
        $this->publicSigning('GET', '/document', $token)->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")->assertHeader('Referrer-Policy', 'no-referrer');

        // Foreign origins are refused; the SPA origin works without cookies or CSRF.
        $this->publicSigning('GET', '', $token, [], ['Origin' => 'https://evil.example'])->assertForbidden();
        $this->publicSigning('POST', '/view', $token, [], ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->assertOk()
            ->assertCookieMissing(config('session.cookie'));

        // Five completion attempts per token per minute.
        $this->publicSigning('POST', '/start', $token)->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->publicSigning('POST', '/complete', $token, $this->signaturePayload(['consent' => false]))->assertUnprocessable();
        }
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertStatus(429);
        $this->assertSame('started', $request->fresh()->status->value);

        // Repeated unknown tokens lock the IP out.
        config(['lexora.public_links.invalid_attempts' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $this->publicSigning('GET', '', 'guess'.$i)->assertNotFound();
        }
        $this->publicSigning('GET', '', $token)->assertStatus(429);
    }

    #[Test]
    public function tokens_never_reach_logs_responses_or_the_database(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        [$request, $token] = $this->clientSigning();
        $this->viewAndStart($token);
        $this->app['cache']->store()->flush();
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload(['x' => 0.99]))->assertUnprocessable();
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertCreated();
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertStatus(410);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($token, $line);
        }
        foreach (['signing_requests', 'signature_records', 'secure_access_links', 'activity_events', 'document_versions'] as $table) {
            $this->assertStringNotContainsString($token, json_encode(DB::table($table)->get()), $table);
        }
        $detail = $this->signingDetail($request)->assertOk()->assertJsonPath('evidence.signature_method', 'drawn')->getContent();
        $this->assertStringNotContainsString($token, $detail);
        $this->assertStringNotContainsString('storage_path', $detail);
        $this->assertStringNotContainsString('token_hash', $detail);
        $this->assertSame(1, SigningRequest::query()->where('status', 'signed')->count());
    }
}
