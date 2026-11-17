<?php

namespace Tests\Feature\Phase5;

use App\Enums\ActivityEventType;
use App\Models\ActivityEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SecureAccessLink;
use App\Models\SignatureRecord;
use App\Support\SigningConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\Support\SignedPdf;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Scenarios E and F (backend): passwordless client signing and the integrity of the signed PDF. */
class ClientSigningTest extends TestCase
{
    use RefreshDatabase, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    #[Test]
    public function a_client_signs_with_a_drawn_signature_and_a_new_signed_version_is_stored(): void
    {
        $matter = $this->signingMatter();
        $sourceBytes = PdfFactory::bytes(['Client Service Agreement', 'Schedule A', 'Signature page']);
        $document = $this->uploadDocument($matter, $sourceBytes);
        $source = $document->currentVersion;
        [$request, $token] = $this->clientSigning($matter, $document, ['message' => 'Please sign on page 3.']);

        // What the signer sees: only this request.
        $info = $this->publicSigning('GET', '', $token)->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('data.document_title', 'Client Service Agreement')
            ->assertJsonPath('data.signer_name', 'Ana Krasniqi')
            ->assertJsonPath('data.signer_email', 'a••••••@northwind.example')
            ->assertJsonPath('data.message', 'Please sign on page 3.')
            ->assertJsonPath('data.consent.version', SigningConsent::VERSION);
        foreach ([$matter->matter_number, $matter->title, 'Northwind Holdings', 'Elena', 'matter_id', 'storage', 'sha256'] as $secret) {
            $this->assertStringNotContainsString($secret, $info->getContent());
        }
        $this->assertSame('ready', $request->fresh()->status->value, 'reading the request does not count as viewing');

        // The designated PDF, byte for byte; fetching it is not a "view" either.
        $pdf = $this->publicSigning('GET', '/document', $token)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($sourceBytes, $pdf->streamedContent());
        $this->assertSame('ready', $request->fresh()->status->value);

        $this->publicSigning('POST', '/view', $token)->assertOk()->assertJsonPath('data.status', 'viewed');
        $this->publicSigning('POST', '/start', $token)->assertOk()->assertJsonPath('data.status', 'started');
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload(['page' => 3]), ['User-Agent' => 'PHPUnit Browser/1.0'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('message', 'The document was signed successfully. The firm has received your signed document.');

        $request->refresh();
        $this->assertSame('signed', $request->status->value);
        $this->assertNotNull($request->signed_at);
        $signed = DocumentVersion::query()->findOrFail($request->signed_document_version_id);
        $this->assertSame('signed', $signed->kind->value);
        $this->assertSame('client', $signed->origin->value);
        $this->assertSame(2, $signed->version_number);
        $this->assertSame('service-agreement-signed.pdf', $signed->original_filename);
        $this->assertSame($signed->id, Document::query()->find($document->id)->current_version_id, 'the signed PDF becomes current');

        // Source untouched; output is a different, valid PDF with the same pages and a visible signature.
        $disk = Storage::disk('documents');
        $this->assertSame($sourceBytes, $disk->get($source->storage_path));
        $this->assertSame(hash('sha256', $sourceBytes), $source->fresh()->sha256_checksum);
        $signedBytes = $disk->get($signed->storage_path);
        $this->assertSame(hash('sha256', $signedBytes), $signed->sha256_checksum);
        $this->assertNotSame($sourceBytes, $signedBytes);
        $this->assertStringStartsWith('%PDF-', $signedBytes);
        $this->assertSame(SignedPdf::pageSizes($sourceBytes), SignedPdf::pageSizes($signedBytes));
        $this->assertTrue(SignedPdf::hasCaption($signedBytes, 'Ana Krasniqi'));
        $this->assertGreaterThan(20, SignedPdf::lineSegments($signedBytes), 'the drawn strokes are vector lines in the PDF');
        $this->assertSame([], $disk->allFiles('_staging'));

        // Evidence.
        $record = SignatureRecord::query()->sole();
        $this->assertSame('client', $record->signer_type->value);
        $this->assertSame('Ana Krasniqi', $record->signer_name);
        $this->assertSame('ana.krasniqi@northwind.example', $record->signer_email);
        $this->assertSame('Ana Krasniqi', $record->confirmed_name);
        $this->assertSame('drawn', $record->signature_method->value);
        $this->assertSame(3, $record->page_number);
        $this->assertSame($source->id, $record->source_document_version_id);
        $this->assertSame($source->sha256_checksum, $record->source_sha256);
        $this->assertSame($signed->sha256_checksum, $record->signed_sha256);
        $this->assertSame(SigningConsent::VERSION, $record->consent_version);
        $this->assertSame(SigningConsent::TEXT, $record->consent_text);
        $this->assertSame('PHPUnit Browser/1.0', $record->user_agent);
        $this->assertNotNull($record->ip_address);
        $this->assertTrue($request->signed_at->equalTo($record->signed_at));
        $this->assertNull($record->signer_user_id);

        // The link is used up; the same token can never sign again (consistent answer for retries).
        $this->assertNotNull(SecureAccessLink::query()->sole()->consumed_at);
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertStatus(410)->assertJsonPath('code', 'already_signed');
        $this->publicSigning('POST', '/view', $token)->assertStatus(410);
        $this->assertSame(1, SignatureRecord::query()->count());
        $this->assertSame(2, DocumentVersion::query()->count());

        // History: viewed/started/completed by the client; document.signed with versions and checksum.
        $types = ActivityEvent::query()->where('matter_id', $matter->id)->orderBy('id')->pluck('event_type')->map->value->all();
        $this->assertSame(['document.uploaded', 'signing.requested', 'signing.link_created', 'signing.viewed', 'signing.started', 'signing.completed', 'document.signed'], $types);
        $completed = ActivityEvent::query()->where('event_type', ActivityEventType::SigningCompleted->value)->sole();
        $this->assertSame('client', $completed->actor_type->value);
        $this->assertSame('Ana Krasniqi', $completed->actor_label);
        $this->assertSame($signed->id, $completed->metadata['signed_version_id']);
        $this->assertStringNotContainsString($token, json_encode(ActivityEvent::all()->toArray()));
        // Signature strokes are never persisted.
        $this->assertStringNotContainsString('0.8415', json_encode([DB::table('signature_records')->get(), DB::table('activity_events')->get()]));
    }

    #[Test]
    public function a_client_signs_with_a_typed_signature(): void
    {
        [$request, $token] = $this->clientSigning();
        $this->viewAndStart($token);
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload([
            'method' => 'typed', 'strokes' => null, 'typed_name' => 'ana krasniqi',
        ]))->assertCreated();

        $signed = DocumentVersion::query()->findOrFail($request->fresh()->signed_document_version_id);
        $bytes = Storage::disk('documents')->get($signed->storage_path);
        $this->assertTrue(SignedPdf::hasTypedSignature($bytes, 'ana krasniqi'));
        $this->assertTrue(SignedPdf::hasCaption($bytes, 'Ana Krasniqi'));
        $this->assertSame('typed', SignatureRecord::query()->sole()->signature_method->value);
    }

    #[Test]
    public function the_signer_cannot_skip_steps_change_identity_or_send_invalid_signatures(): void
    {
        [$request, $token] = $this->clientSigning();
        // Not started yet.
        $this->publicSigning('POST', '/start', $token)->assertStatus(409);
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload())->assertStatus(409);
        $this->viewAndStart($token);
        $this->app['cache']->store()->flush();

        $cases = [
            'no consent' => [['consent' => false], 'consent'],
            'old consent' => [['consent_version' => '2020-01'], 'consent_version'],
            'other person' => [['confirmed_name' => 'Somebody Else'], 'confirmed_name'],
            'identity field' => [['signer_name' => 'Mallory', 'document_id' => 99, 'signed_at' => '2020-01-01'], 'signer_name'],
            'typed other name' => [['method' => 'typed', 'strokes' => null, 'typed_name' => 'Mallory Evil'], 'typed_name'],
            'markup in name' => [['method' => 'typed', 'strokes' => null, 'typed_name' => '<script>x</script>'], 'signature'],
            'empty strokes' => [['strokes' => []], 'strokes'],
            'dot only' => [['strokes' => [[[0.5, 0.5], [0.51, 0.5]]]], 'signature'],
            'outside area' => [['strokes' => [[[0.1, 0.1], [1.5, 0.2], [0.3, 0.3], [0.4, 0.4], [0.5, 0.5], [0.6, 0.6]]]], 'signature'],
            'non-numeric' => [['strokes' => [['<svg/>', '<svg/>']]], 'signature'],
            'off the page' => [['x' => 0.9, 'y' => 0.95], 'placement'],
            'page 9' => [['page' => 9], 'placement'],
            'too small' => [['width' => 0.05], 'placement'],
            'bad method' => [['method' => 'image'], 'method'],
        ];
        foreach ($cases as $label => [$override, $field]) {
            $this->app['cache']->store()->flush(); // per-token completion limit (tested separately)
            $response = $this->publicSigning('POST', '/complete', $token, $this->signaturePayload($override));
            $response->assertUnprocessable();
            $this->assertNotEmpty($response->json("errors.{$field}"), "{$label}: ".$response->getContent());
        }
        // Oversized payload.
        $this->app['cache']->store()->flush();
        $huge = array_fill(0, 60, array_fill(0, 500, [0.5, 0.5]));
        $this->publicSigning('POST', '/complete', $token, $this->signaturePayload(['strokes' => $huge]))->assertStatus(413);

        $this->assertSame('started', $request->fresh()->status->value);
        $this->assertSame(0, SignatureRecord::query()->count());
        $this->assertSame(1, DocumentVersion::query()->count());
        $this->assertSame([], Storage::disk('documents')->allFiles('_staging'));
    }

    #[Test]
    public function signatures_are_placed_on_landscape_rotated_and_last_pages_with_pages_preserved(): void
    {
        $matter = $this->signingMatter();
        $bytes = PdfFactory::pages([
            ['text' => 'Portrait cover'],
            ['text' => 'Landscape schedule', 'width' => 842, 'height' => 595],
            ['text' => 'Rotated page', 'rotate' => 90],
            ['text' => 'Last page'],
        ]);
        $sourceSizes = SignedPdf::pageSizes($bytes);
        $this->assertSame(['width' => 792.0, 'height' => 612.0], $sourceSizes[2], 'the rotated page is displayed landscape');

        foreach ([2, 3, 4] as $i => $page) {
            $document = $this->uploadDocument($matter, $bytes."\n% variant {$page}\n", "Agreement {$page}");
            [$request, $token] = $this->clientSigning($matter, $document);
            $this->viewAndStart($token);
            $this->app['cache']->store()->flush();
            $this->publicSigning('POST', '/complete', $token, $this->signaturePayload(['page' => $page, 'x' => 0.6, 'y' => 0.7, 'width' => 0.3]))->assertCreated();

            $signed = DocumentVersion::query()->findOrFail($request->fresh()->signed_document_version_id);
            $out = Storage::disk('documents')->get($signed->storage_path);
            $this->assertSame($sourceSizes, SignedPdf::pageSizes($out), "page {$page}: count, order and sizes preserved");
            $record = SignatureRecord::query()->where('signing_request_id', $request->id)->sole();
            $size = $sourceSizes[$page - 1];
            $this->assertEqualsWithDelta(0.6 * $size['width'], $record->placement['rect_pt']['x'], 0.01);
            $this->assertEqualsWithDelta(0.7 * $size['height'], $record->placement['rect_pt']['y'], 0.01);
            $this->assertEqualsWithDelta(0.3 * $size['width'] * 0.36, $record->placement['rect_pt']['height'], 0.01);
        }
    }
}
