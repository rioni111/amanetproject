<?php

namespace Tests\Feature\Phase5;

use App\Enums\ActivityEventType;
use App\Models\ActivityEvent;
use App\Models\DocumentVersion;
use App\Models\SignatureRecord;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\SigningConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\Support\SignedPdf;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Scenario D (backend): the administrator signs as the authenticated account. */
class AdminSigningTest extends TestCase
{
    use RefreshDatabase, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function adminPayload(array $overrides = []): array
    {
        $payload = $this->signaturePayload($overrides);
        if (! array_key_exists('confirmed_name', $overrides)) {
            unset($payload['confirmed_name']); // administrators never send it
        }

        return $payload;
    }

    #[Test]
    public function an_administrator_signs_a_document_directly_with_a_drawn_signature(): void
    {
        $matter = $this->signingMatter();
        $bytes = PdfFactory::bytes(['Engagement letter', 'Signature page']);
        $document = $this->uploadDocument($matter, $bytes);
        $source = $document->currentVersion;

        $response = $this->actingAsAdmin($this->signingAdmin())->withHeader('User-Agent', 'AdminBrowser/2.0')
            ->postJson("/api/matters/{$matter->id}/documents/{$document->id}/sign", [...$this->adminPayload(['page' => 2]), 'document_version_id' => $source->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.signer_type', 'admin')
            ->assertJsonPath('data.signer_name', 'Elena Marsh')
            ->assertJsonPath('data.signed_version.label', 'v2')
            ->assertJsonPath('data.signed_version.kind', 'signed')
            ->assertJsonPath('data.source_version.label', 'v1');

        $request = SigningRequest::query()->sole();
        $this->assertSame($this->signingAdmin()->id, $request->signer_user_id);
        $record = SignatureRecord::query()->sole();
        $this->assertSame('admin', $record->signer_type->value);
        $this->assertSame($this->signingAdmin()->id, $record->signer_user_id);
        $this->assertSame('Elena Marsh', $record->confirmed_name);
        $this->assertSame('AdminBrowser/2.0', $record->user_agent);
        $this->assertSame(SigningConsent::VERSION, $record->consent_version);

        $signed = DocumentVersion::query()->findOrFail($request->signed_document_version_id);
        $this->assertSame('admin', $signed->origin->value);
        $this->assertSame('Elena Marsh (signature)', $signed->uploaded_by_name);
        $out = Storage::disk('documents')->get($signed->storage_path);
        $this->assertTrue(SignedPdf::hasCaption($out, 'Elena Marsh'));
        $this->assertSame(SignedPdf::pageSizes($bytes), SignedPdf::pageSizes($out));
        $this->assertSame($bytes, Storage::disk('documents')->get($source->storage_path), 'the source is untouched');

        // Admin signing events are attributed to the authenticated administrator.
        foreach ([ActivityEventType::SigningRequested, ActivityEventType::SigningCompleted, ActivityEventType::DocumentSigned] as $type) {
            $event = ActivityEvent::query()->where('event_type', $type->value)->sole();
            $this->assertSame('admin', $event->actor_type->value);
            $this->assertSame($this->signingAdmin()->id, $event->actor_id);
        }

        // The signed version can be previewed and downloaded like any version; it cannot be signed again.
        $this->get("/api/matters/{$matter->id}/documents/{$document->id}/versions/{$signed->id}/download", ['Accept' => 'application/json'])
            ->assertOk()->assertHeader('X-Document-Sha256', $signed->sha256_checksum);
        $this->postJson("/api/matters/{$matter->id}/documents/{$document->id}/sign", [...$this->adminPayload(), 'document_version_id' => $signed->id])
            ->assertUnprocessable()->assertJsonPath('errors.document_version_id.0', 'This version is already a signed PDF. Signed documents cannot be signed again (one signer per PDF).');
        $this->assertSame($response->json('data.id'), $request->id);
    }

    #[Test]
    public function an_administrator_signs_a_prepared_request_with_a_typed_signature_and_identity_cannot_be_spoofed(): void
    {
        $matter = $this->signingMatter();
        $document = $this->uploadDocument($matter);
        $id = $this->createSigning($matter, $document, ['signer_type' => 'admin', 'signer_name' => null, 'signer_email' => null])->assertCreated()->json('data.id');
        $request = SigningRequest::query()->findOrFail($id);
        $url = "/api/matters/{$matter->id}/signing-requests/{$id}/admin-sign";

        // Another administrator cannot sign a request designated to Elena.
        $other = User::factory()->create(['name' => 'Victor Hale']);
        $this->forgetUser()->actingAsAdmin($other)->postJson($url, $this->adminPayload(['method' => 'typed', 'strokes' => null, 'typed_name' => 'Victor Hale']))->assertStatus(409);
        // Identity fields in the payload are refused, and the typed name must be the signer's.
        $this->forgetUser()->actingAsAdmin($this->signingAdmin())->postJson($url, $this->adminPayload(['signer_name' => 'Victor Hale', 'confirmed_name' => 'Victor Hale']))
            ->assertUnprocessable()->assertJsonValidationErrors(['signer_name', 'confirmed_name']);
        $this->postJson($url, $this->adminPayload(['method' => 'typed', 'strokes' => null, 'typed_name' => 'Victor Hale']))->assertUnprocessable()->assertJsonValidationErrors(['typed_name']);
        $this->postJson($url, $this->adminPayload(['consent' => false]))->assertUnprocessable()->assertJsonValidationErrors(['consent']);
        $this->assertSame('ready', $request->fresh()->status->value);

        $this->postJson($url, $this->adminPayload(['method' => 'typed', 'strokes' => null, 'typed_name' => 'Elena Marsh', 'lock_version' => $request->fresh()->lock_version]))
            ->assertOk()->assertJsonPath('data.status', 'signed');
        $signed = DocumentVersion::query()->findOrFail($request->fresh()->signed_document_version_id);
        $this->assertTrue(SignedPdf::hasTypedSignature(Storage::disk('documents')->get($signed->storage_path), 'Elena Marsh'));
        // Retry after success: consistent refusal, still one signed version.
        $this->postJson($url, $this->adminPayload())->assertStatus(409);
        $this->assertSame(1, SignatureRecord::query()->count());
        $this->assertSame(2, DocumentVersion::query()->count());

        // Evidence endpoint.
        $this->getJson("/api/matters/{$matter->id}/signing-requests/{$id}/evidence")->assertOk()
            ->assertJsonPath('data.signature_method', 'typed')
            ->assertJsonPath('data.signer_name', 'Elena Marsh')
            ->assertJsonPath('data.identity_basis', 'Authenticated administrator account (Lexora session).')
            ->assertJsonPath('data.signed_sha256', $signed->sha256_checksum);
    }

    #[Test]
    public function a_client_request_cannot_be_signed_by_an_administrator(): void
    {
        [$request] = $this->clientSigning();
        $this->signingAction($request, 'admin-sign', $this->adminPayload())->assertStatus(409);
        $this->forgetUser()->postJson("/api/matters/{$request->matter_id}/signing-requests/{$request->id}/admin-sign", $this->adminPayload())->assertUnauthorized();
        $this->assertSame(0, SignatureRecord::query()->count());
    }
}
