<?php

namespace Tests\Feature\Phase4;

use App\Enums\ActivityEventType;
use App\Enums\MatterStatus;
use App\Models\ActivityEvent;
use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\TestCase;

/** Scenarios B and C (backend): secure link generation, expiry, revocation, rotation and scope. */
class SecureLinkTest extends TestCase
{
    use RefreshDatabase, RequestWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    #[Test]
    public function tokens_are_256_bit_random_and_only_their_hash_is_stored(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $response = $this->createRequest($this->matterFor())->assertCreated()
            ->assertJsonPath('link.shown_once', true)
            ->assertJsonPath('link.is_local_url', true)
            ->assertJsonPath('link.expires_at', '2026-11-24T10:00:00+00:00'); // default: 7 days
        $token = $this->tokenFrom($response);
        $this->assertStringStartsWith('http://localhost:3000/client-upload#', $response->json('link.url'));

        $raw = base64_decode(strtr($token, '-_', '+/').'=', true);
        $this->assertSame(32, strlen($raw), '32 random bytes = 256 bits');

        $link = SecureAccessLink::query()->sole();
        $this->assertSame('document_upload', $link->purpose->value);
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertSame(1, $link->max_uses);
        $row = json_encode(DB::table('secure_access_links')->first());
        $this->assertStringNotContainsString($token, $row, 'the raw token is never stored');

        // Uniqueness and unpredictability across many tokens.
        $tokens = array_map(fn () => SecureAccessLinkService::generateToken(), range(1, 500));
        $this->assertCount(500, array_unique($tokens));
        foreach ($tokens as $t) {
            $this->assertMatchesRegularExpression(SecureAccessLinkService::TOKEN_PATTERN, $t);
        }

        // The URL is returned once: later reads expose only status, never the token, URL or hash.
        $request = DocumentRequest::query()->sole();
        $detail = $this->showRequest($request)->assertOk()
            ->assertJsonPath('data.active_link.state', 'active')
            ->assertJsonPath('data.active_link.purpose', 'document_upload');
        foreach ([$detail->getContent(), $this->getJson('/api/document-requests')->getContent(), $this->getJson("/api/matters/{$request->matter_id}/activity")->getContent()] as $json) {
            $this->assertStringNotContainsString($token, $json);
            $this->assertStringNotContainsString($link->token_hash, $json);
            $this->assertStringNotContainsString('token_hash', $json);
            $this->assertStringNotContainsString('client-upload#', $json);
        }
        $this->assertSame(0, ActivityEvent::query()->where('metadata', 'like', '%'.$token.'%')->orWhere('description', 'like', '%'.$token.'%')->count());
    }

    #[Test]
    public function custom_expiry_is_bounded_and_links_expire_server_side(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        [$request, $token] = $this->requestWithLink(overrides: ['link_expires_in_days' => 2]);
        $this->assertSame('2026-11-19T10:00:00+00:00', SecureAccessLink::query()->sole()->expires_at->toIso8601String());

        $this->publicView($token)->assertOk()->assertJsonPath('data.title', 'Signed Service Agreement');

        Carbon::setTestNow('2026-11-19 10:00:01');
        $this->publicView($token)->assertStatus(410)->assertJsonPath('code', 'link_expired');
        $this->publicSubmit($token, PdfFactory::upload())->assertStatus(410)->assertJsonPath('code', 'link_expired');
        $this->assertSame(0, $request->submissions()->count());
        $this->assertSame([], Storage::disk('documents')->allFiles());

        // The administrator sees the expiry and can issue a new link (the expired one is retired).
        $this->showRequest($request)->assertJsonPath('data.active_link.state', 'expired')->assertJsonPath('data.allowed_actions.issue_link', true);
        $fresh = $this->tokenFrom($this->adminPost($request, 'links')->assertCreated());
        $this->publicView($fresh)->assertOk();
        $this->assertSame('replaced', SecureAccessLink::query()->where('token_hash', hash('sha256', $token))->toBase()->value('revocation_reason'));
    }

    #[Test]
    public function rotation_and_revocation_take_effect_immediately(): void
    {
        [$request, $first] = $this->requestWithLink();

        // A second "create" is refused while a usable link exists.
        $this->adminPost($request, 'links')->assertStatus(409);

        $second = $this->tokenFrom($this->adminPost($request, 'links/rotate')->assertCreated());
        $this->assertNotSame($first, $second);
        $this->publicView($first)->assertNotFound()->assertJsonPath('code', 'link_invalid');
        $this->publicView($second)->assertOk();
        $this->assertSame(1, ActivityEvent::query()->where('event_type', ActivityEventType::LinkRotated->value)->count());

        $active = SecureAccessLink::query()->where('token_hash', hash('sha256', $second))->sole();
        $this->adminPost($request, "links/{$active->id}/revoke")->assertOk()
            ->assertJsonPath('revoked_link.state', 'revoked')
            ->assertJsonPath('revoked_link.revocation_reason', 'revoked')
            ->assertJsonPath('data.active_link', null);
        $this->publicView($second)->assertNotFound()->assertJsonPath('code', 'link_invalid');
        $this->publicSubmit($second, PdfFactory::upload())->assertNotFound();
        $this->adminPost($request, "links/{$active->id}/revoke")->assertStatus(409); // already revoked

        // Revoked and unknown tokens are indistinguishable.
        $unknown = $this->publicView(SecureAccessLinkService::generateToken())->assertNotFound();
        $this->assertSame($unknown->json(), $this->publicView($second)->json());
        $this->publicView('not-a-token')->assertNotFound()->assertJsonPath('code', 'link_invalid');
        $this->publicView(null)->assertNotFound()->assertJsonPath('code', 'link_invalid');

        $this->assertSame(0, $request->submissions()->count());
        $this->assertSame(1, ActivityEvent::query()->where('event_type', ActivityEventType::LinkRevoked->value)->count());
    }

    #[Test]
    public function a_used_link_cannot_upload_again(): void
    {
        [$request, $token] = $this->requestWithLink();
        $this->publicSubmit($token, PdfFactory::upload('a.pdf', ['First']))->assertCreated();

        $this->publicView($token)->assertStatus(410)->assertJsonPath('code', 'link_used');
        $this->publicSubmit($token, PdfFactory::upload('b.pdf', ['Second']))->assertStatus(410)->assertJsonPath('code', 'link_used');
        $this->assertSame(1, $request->submissions()->count());
        $this->showRequest($request)->assertJsonPath('data.active_link', null)->assertJsonPath('data.allowed_actions.issue_link', false);
        $this->adminPost($request, 'links')->assertStatus(409); // already submitted: review first
    }

    #[Test]
    public function a_signing_purpose_token_is_unknown_to_the_upload_endpoints(): void
    {
        [$request] = $this->requestWithLink();
        // Phase 5 made signing links real (they must target a signing request), so this uses a genuine,
        // active signing link of the same matter instead of an inserted row.
        $document = $this->actingAsAdmin($this->admin())->post("/api/matters/{$request->matter_id}/documents",
            ['file' => PdfFactory::upload('nda.pdf', ['NDA']), 'title' => 'NDA', 'category' => 'contracts'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $url = (string) $this->postJson("/api/matters/{$request->matter_id}/signing-requests", [
            'document_id' => $document['id'], 'document_version_id' => $document['current_version']['id'],
            'signer_type' => 'client', 'signer_name' => 'Northwind Signer', 'issue_link' => true,
        ])->assertCreated()->json('link.url');
        $this->assertStringContainsString('/client-sign#', $url);
        $token = substr($url, strrpos($url, '#') + 1);
        $this->assertSame('document_signing', SecureAccessLink::query()->where('token_hash', hash('sha256', $token))->value('purpose')?->value);

        $this->publicView($token)->assertNotFound()->assertJsonPath('code', 'link_invalid');
        $this->publicSubmit($token, PdfFactory::upload())->assertNotFound();
        $this->assertSame(0, $request->submissions()->count());
    }

    #[Test]
    public function closed_requests_and_archived_matters_refuse_their_links(): void
    {
        // Cancelled request.
        [$cancelled, $cancelledToken] = $this->requestWithLink();
        $this->adminPost($cancelled, 'cancel', ['lock_version' => $this->lockVersion($cancelled)])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->publicView($cancelledToken)->assertStatus(410)->assertJsonPath('code', 'request_cancelled');
        $this->publicSubmit($cancelledToken, PdfFactory::upload())->assertStatus(410);
        $this->assertSame('request_cancelled', SecureAccessLink::query()->where('document_request_id', $cancelled->id)->toBase()->value('revocation_reason'));
        $this->adminPost($cancelled, 'links')->assertStatus(409);

        // Approved request: the consumed link and any later link stop working.
        [$approved, $token] = $this->requestWithLink();
        $submissionId = $this->submitAndStartReview($approved, $token, 'Approved file');
        $this->adminPost($approved, 'approve', ['submission_id' => $submissionId, 'lock_version' => $this->lockVersion($approved)])->assertOk();
        $this->publicView($token)->assertStatus(410)->assertJsonPath('code', 'request_approved');

        // Archived matter: links are revoked in the archiving transaction and stay revoked after restore.
        $matter = $this->matterFor('Archive Test Client');
        [$request, $archiveToken] = $this->requestWithLink($matter);
        $this->actingAsAdmin($this->admin())->postJson("/api/matters/{$matter->id}/archive", ['lock_version' => $matter->fresh()->lock_version])->assertOk();
        $this->assertSame('matter_archived', SecureAccessLink::query()->where('document_request_id', $request->id)->toBase()->value('revocation_reason'));
        $this->publicView($archiveToken)->assertStatus(410)->assertJsonPath('code', 'request_unavailable');
        $this->publicSubmit($archiveToken, PdfFactory::upload())->assertStatus(410);
        $this->assertSame(1, ActivityEvent::query()->where('matter_id', $matter->id)->where('event_type', ActivityEventType::LinkRevoked->value)->count());
        $this->createRequest($matter)->assertStatus(409); // read-only
        $this->adminPost($request, 'links/rotate')->assertStatus(409);

        $this->actingAsAdmin($this->admin())->postJson("/api/matters/{$matter->id}/restore", ['lock_version' => $matter->fresh()->lock_version])->assertOk();
        $this->assertNotSame(MatterStatus::Archived, $matter->fresh()->status);
        $this->publicView($archiveToken)->assertStatus(410); // not reactivated
        $this->assertNotNull(SecureAccessLink::query()->where('document_request_id', $request->id)->value('revoked_at'));
        // A deliberate new link works again.
        $this->publicView($this->tokenFrom($this->adminPost($request, 'links')->assertCreated()))->assertOk();
    }

    #[Test]
    public function the_public_api_rejects_an_upload_into_an_archived_matter_even_if_a_link_is_still_active(): void
    {
        [$request, $token] = $this->requestWithLink();
        // Simulate an inconsistent state (e.g. a direct database change): the link was NOT revoked.
        Matter::query()->whereKey($request->matter_id)->update(['status' => 'archived', 'archived_at' => now(), 'archived_from_status' => 'open']);

        $this->publicView($token)->assertStatus(410)->assertJsonPath('code', 'request_unavailable');
        $this->publicSubmit($token, PdfFactory::upload())->assertStatus(410);
        $this->assertSame(0, $request->submissions()->count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    #[Test]
    public function the_database_allows_only_one_usable_upload_link_per_request(): void
    {
        [$request] = $this->requestWithLink();
        $this->expectException(QueryException::class);
        DB::table('secure_access_links')->insert([
            'uuid' => (string) Str::uuid(), 'purpose' => 'document_upload', 'matter_id' => $request->matter_id,
            'document_request_id' => $request->id, 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDay(),
            'max_uses' => 1, 'successful_uses' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
