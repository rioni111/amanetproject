<?php

namespace Tests\Feature\Phase3;

use App\Enums\ActivityEventType;
use App\Models\ActivityEvent;
use App\Models\DocumentVersion;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\TestCase;

class DocumentFileAccessTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private int $documentId;

    private string $v1Bytes;

    private string $v2Bytes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->matter = Matter::factory()->create();
        $this->v1Bytes = PdfFactory::bytes(['Version one', 'Page two']);
        $this->v2Bytes = PdfFactory::bytes(['Version two']);

        $this->documentId = $this->actingAsAdmin()->post("/api/matters/{$this->matter->id}/documents", [
            'file' => PdfFactory::fromBytes('Vertrag – Müller "final".pdf', $this->v1Bytes),
            'category' => 'contracts',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->post("/api/matters/{$this->matter->id}/documents/{$this->documentId}/versions", [
            'file' => PdfFactory::fromBytes('contract-v2.pdf', $this->v2Bytes),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    private function url(DocumentVersion $version, string $action): string
    {
        return "/api/matters/{$this->matter->id}/documents/{$this->documentId}/versions/{$version->id}/{$action}";
    }

    private function version(int $number): DocumentVersion
    {
        return DocumentVersion::query()->where('version_number', $number)->sole();
    }

    #[Test]
    public function each_version_downloads_its_exact_bytes_with_safe_headers(): void
    {
        $v1 = $this->get($this->url($this->version(1), 'download'))->assertOk();
        $v1Content = $v1->streamedContent();
        $this->assertSame($this->v1Bytes, $v1Content, 'historical version returns version 1 — not the latest');
        $this->assertSame(hash('sha256', $this->v1Bytes), hash('sha256', $v1Content));
        $v1->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Document-Version', '1')
            ->assertHeader('X-Document-Sha256', hash('sha256', $this->v1Bytes));
        $this->assertStringContainsString('no-store', $v1->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', $v1->headers->get('Content-Security-Policy'));

        // Unicode name: ASCII fallback + RFC 5987 UTF-8 name; quotes removed, no header injection.
        $disposition = $v1->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''Vertrag%20%E2%80%93%20M%C3%BCller%20final.pdf", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);

        $v2 = $this->get($this->url($this->version(2), 'download'))->assertOk();
        $this->assertSame($this->v2Bytes, $v2->streamedContent());
        $this->assertStringContainsString('filename=contract-v2.pdf', $v2->headers->get('Content-Disposition'));
    }

    #[Test]
    public function previews_are_inline_and_not_recorded_but_downloads_are(): void
    {
        $preview = $this->get($this->url($this->version(2), 'preview'))->assertOk();
        $this->assertSame($this->v2Bytes, $preview->streamedContent());
        $this->assertStringStartsWith('inline;', $preview->headers->get('Content-Disposition'));
        $this->assertSame(0, ActivityEvent::query()->where('event_type', ActivityEventType::DocumentDownloaded->value)->count());

        $this->get($this->url($this->version(1), 'download'))->assertOk()->streamedContent();
        $event = ActivityEvent::query()->where('event_type', ActivityEventType::DocumentDownloaded->value)->sole();
        $this->assertSame(1, $event->metadata['version_number']);
        $this->assertSame($this->matter->id, $event->matter_id);
    }

    #[Test]
    public function private_files_are_not_reachable_outside_the_api(): void
    {
        $path = $this->version(1)->storage_path;

        foreach (["/storage/{$path}", "/storage/documents/{$path}", "/storage/app/private/documents/{$path}", '/documents/'.$path] as $url) {
            $response = $this->get($url);
            $this->assertNotSame(200, $response->getStatusCode(), $url);
            $this->assertStringNotContainsString('%PDF', (string) $response->getContent(), $url);
        }
        $this->assertFalse(file_exists(public_path('storage/'.$path)));
    }

    #[Test]
    public function a_missing_or_tampered_file_fails_safely(): void
    {
        $v1 = $this->version(1);
        Storage::disk('documents')->put($v1->storage_path, PdfFactory::bytes(['Tampered']));
        $this->get($this->url($v1, 'download'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This file failed its integrity check and cannot be opened. The incident has been logged.');

        Storage::disk('documents')->delete($v1->storage_path);
        $response = $this->get($this->url($v1, 'preview'))->assertNotFound();
        $this->assertStringNotContainsString($v1->storage_path, $response->getContent());
        $this->assertStringNotContainsString('storage', strtolower((string) $response->json('message')));
    }
}
