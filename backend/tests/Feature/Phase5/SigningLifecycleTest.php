<?php

namespace Tests\Feature\Phase5;

use App\Models\ActivityEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SecureAccessLink;
use App\Models\SignatureRecord;
use App\Services\ActivityLogger;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\PdfInspector;
use App\Services\Signing\PdfSigningService;
use App\Services\Signing\SigningFailedException;
use App\Services\Signing\SigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\PdfFactory;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Archive / new version interactions (§34) and failure recovery (scenario G). */
class SigningLifecycleTest extends TestCase
{
    use RefreshDatabase, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function assertNothingSigned(): void
    {
        $this->assertSame(0, SignatureRecord::query()->count());
        $this->assertSame(0, DocumentVersion::query()->where('kind', 'signed')->count());
        $expected = DocumentVersion::query()->pluck('storage_path')->sort()->values()->all();
        $this->assertSame($expected, collect(Storage::disk('documents')->allFiles())->sort()->values()->all(), 'no orphaned or staged files');
    }

    #[Test]
    public function archiving_the_matter_cancels_signing_and_restoring_does_not_revive_links(): void
    {
        [$request, $token] = $this->clientSigning();
        $this->viewAndStart($token);
        $matter = $request->matter;
        $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$matter->id}/archive", ['lock_version' => $matter->fresh()->lock_version])->assertOk();

        $request->refresh();
        $this->assertSame('cancelled', $request->status->value);
        $this->assertSame('matter_archived', $request->cancellation_reason->value);
        $this->assertSame('matter_archived', SecureAccessLink::query()->sole()->revocation_reason->value);
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertStatus(410)->assertJsonPath('code', 'request_unavailable');
        $this->createSigning($matter, $request->document)->assertStatus(409);

        $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$matter->id}/restore", ['lock_version' => $matter->fresh()->lock_version])->assertOk();
        $this->publicSigning('GET', '', $token)->assertStatus(410);
        $this->assertSame('cancelled', $request->fresh()->status->value);
        $this->assertNothingSigned();
    }

    #[Test]
    public function archiving_the_document_or_adding_a_version_cancels_the_pinned_request(): void
    {
        [$request, $token] = $this->clientSigning();
        $matter = $request->matter;
        $document = Document::query()->findOrFail($request->document_id);
        $this->actingAsAdmin($this->signingAdmin())->post("/api/matters/{$matter->id}/documents/{$document->id}/versions",
            ['file' => PdfFactory::upload('revised.pdf', ['Revised agreement']), 'lock_version' => $document->lock_version], ['Accept' => 'application/json'])->assertCreated();
        $request->refresh();
        $this->assertSame('cancelled', $request->status->value);
        $this->assertSame('source_superseded', $request->cancellation_reason->value);
        $this->publicSigning('GET', '', $token)->assertStatus(410)->assertJsonPath('code', 'request_cancelled');

        // New request for the new version, then archive the document.
        [$second, $secondToken] = $this->clientSigning($matter, $document->fresh());
        $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$matter->id}/documents/{$document->id}/archive", ['lock_version' => $document->fresh()->lock_version])->assertOk();
        $this->assertSame('document_archived', $second->fresh()->cancellation_reason->value);
        $this->publicSigning('GET', '', $secondToken)->assertStatus(410);
        $this->assertSame(2, ActivityEvent::query()->where('event_type', 'signing.cancelled')->count());
        $this->assertNothingSigned();
    }

    #[Test]
    public function a_source_version_changing_during_signing_is_refused_under_the_lock(): void
    {
        [$request, $token] = $this->clientSigning();
        $this->viewAndStart($token);
        // Simulate a newer version appearing WITHOUT the normal cancellation (a race the service would
        // otherwise prevent): the final transaction must still refuse to sign a file that is no longer current.
        $document = Document::query()->findOrFail($request->document_id);
        $newId = DB::table('document_versions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'document_id' => $document->id, 'version_number' => 2, 'kind' => 'upload',
            'origin' => 'admin', 'original_filename' => 'newer.pdf', 'storage_disk' => 'documents',
            'storage_path' => $document->matter_id.'/'.$document->uuid.'/'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'sha256_checksum' => str_repeat('a', 64), 'created_at' => now(),
        ]);
        DB::table('documents')->where('id', $document->id)->update(['current_version_id' => $newId, 'latest_version_number' => 2]);

        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertStatus(409)
            ->assertJsonPath('message', 'A newer version of this document was added, so this request can no longer be signed. Nothing was signed.');
        $this->assertSame(0, SignatureRecord::query()->count());
        $this->assertSame('started', $request->fresh()->status->value);
    }

    #[Test]
    public function failures_at_every_step_leave_no_signed_record_and_no_orphaned_file(): void
    {
        [$request, $token] = $this->clientSigning();
        $this->viewAndStart($token);
        $submit = function (int $expected) use ($token) {
            $this->app['cache']->store()->flush();
            $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertStatus($expected);
        };

        // Failing dependencies are injected into a fresh SigningService (route controllers are cached between
        // requests within one test, so container bindings made now would not reach them).
        $attempt = function (array $bindings, string $expected, string $message) {
            foreach ($bindings as $abstract => $instance) {
                $this->app->instance($abstract, $instance);
            }
            try {
                $this->app->make(SigningService::class)->signAsClient(SecureAccessLink::query()->sole(),
                    [...$this->signaturePayload(), 'consent' => true], '127.0.0.1', 'PHPUnit');
                $this->fail('The signing attempt should have failed: '.$message);
            } catch (\Throwable $e) {
                $this->assertInstanceOf($expected, $e, $message.': '.$e->getMessage());
            } finally {
                foreach (array_keys($bindings) as $abstract) {
                    $this->app->forgetInstance($abstract);
                }
            }
            $this->assertNothingSigned();
        };

        // PDF generation fails.
        $engine = Mockery::mock(PdfSigningService::class, [app(PdfInspector::class)])->makePartial();
        $engine->shouldReceive('sign')->andThrow(new SigningFailedException('The signed PDF could not be generated. The original document was not changed.'));
        $attempt([PdfSigningService::class => $engine], SigningFailedException::class, 'generation');

        // Storage write fails.
        $storage = Mockery::mock(DocumentStorage::class)->makePartial();
        $storage->shouldReceive('stageLocalFile')->andThrow(new RuntimeException('disk full'));
        $attempt([DocumentStorage::class => $storage], RuntimeException::class, 'storage');

        // Database write fails (history cannot be written) → rollback, and the new file is deleted.
        $attempt([ActivityLogger::class => new class extends ActivityLogger
        {
            public function recordClient(...$args): never
            {
                throw new RuntimeException('history unavailable');
            }
        }], RuntimeException::class, 'database');
        $this->assertSame('started', $request->fresh()->status->value);
        $this->assertNull(SecureAccessLink::query()->sole()->consumed_at, 'the link is consumed only with a committed signature');

        // Source file tampered with → refused, nothing signed.
        $source = DocumentVersion::query()->sole();
        $original = Storage::disk('documents')->get($source->storage_path);
        Storage::disk('documents')->put($source->storage_path, $original.'tampered');
        $submit(409);
        Storage::disk('documents')->put($source->storage_path, $original);
        // Source file missing → refused.
        Storage::disk('documents')->delete($source->storage_path);
        $submit(409);
        Storage::disk('documents')->put($source->storage_path, $original);
        $this->assertNothingSigned();

        // And the request still works afterwards.
        $submit(201);
        $this->assertSame('signed', $request->fresh()->status->value);
    }

    #[Test]
    public function output_verification_rejects_a_bad_signed_file(): void
    {
        $engine = app(PdfSigningService::class);
        $path = tempnam(sys_get_temp_dir(), 'verify-');
        file_put_contents($path, PdfFactory::bytes(['One page']));
        try {
            $engine->verifyOutput($path, [['width' => 612.0, 'height' => 792.0]], 'LX-MISSING');
            $this->fail('A PDF without the signature caption must fail verification.');
        } catch (SigningFailedException $e) {
            $this->assertStringContainsString('signature not found', $e->getMessage());
        }
        $path = tempnam(sys_get_temp_dir(), 'verify-');
        file_put_contents($path, PdfFactory::bytes(['One page']));
        $this->expectException(SigningFailedException::class);
        $engine->verifyOutput($path, [['width' => 612.0, 'height' => 792.0], ['width' => 612.0, 'height' => 792.0]], 'LX-X');
    }
}
