<?php

namespace Tests\Feature\Phase3;

use App\Enums\ActivityEventType;
use App\Enums\MatterStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function upload(Matter $matter, array $data): TestResponse
    {
        return $this->post("/api/matters/{$matter->id}/documents", $data, ['Accept' => 'application/json']);
    }

    /** No staging leftovers, and the only stored files are the committed versions. */
    private function assertStorageMatchesDatabase(): void
    {
        $disk = Storage::disk('documents');
        $this->assertSame([], $disk->allFiles('_staging'), 'staging files must be cleaned up');
        $stored = collect($disk->allFiles())->sort()->values()->all();
        $expected = DocumentVersion::query()->orderBy('storage_path')->pluck('storage_path')->sort()->values()->all();
        $this->assertSame($expected, $stored, 'every stored file belongs to exactly one committed version');
    }

    #[Test]
    public function an_admin_uploads_a_pdf_into_a_matter(): void
    {
        $matter = Matter::factory()->create();
        $admin = User::factory()->create(['name' => 'Elena Marsh']);
        $bytes = PdfFactory::bytes(['Employment Agreement', 'Schedule 1']);

        $response = $this->actingAsAdmin($admin)->upload($matter, [
            'file' => PdfFactory::fromBytes('Employment Agreement.pdf', $bytes),
            'title' => 'Employment Agreement',
            'description' => 'Signed copy pending.',
            'category' => 'contracts',
        ])->assertCreated()
            ->assertJsonPath('data.matter_id', $matter->id)
            ->assertJsonPath('data.title', 'Employment Agreement')
            ->assertJsonPath('data.category', 'contracts')
            ->assertJsonPath('data.category_label', 'Contracts')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.origin', 'admin')
            ->assertJsonPath('data.versions_count', 1)
            ->assertJsonPath('data.current_version.version_number', 1)
            ->assertJsonPath('data.current_version.label', 'v1')
            ->assertJsonPath('data.current_version.original_filename', 'Employment Agreement.pdf')
            ->assertJsonPath('data.current_version.file_size', strlen($bytes))
            ->assertJsonPath('data.current_version.sha256_checksum', hash('sha256', $bytes))
            ->assertJsonPath('data.current_version.uploaded_by.name', 'Elena Marsh')
            ->assertJsonPath('data.matter.matter_number', $matter->matter_number);

        // Internal storage details never leave the server.
        $json = $response->getContent();
        $this->assertStringNotContainsString('storage_path', $json);
        $this->assertStringNotContainsString('storage_disk', $json);
        $this->assertStringNotContainsString(str_replace('\\', '\\\\', storage_path()), $json);

        $version = DocumentVersion::query()->sole();
        $this->assertMatchesRegularExpression('#^'.$matter->id.'/[0-9a-f-]{36}/[0-9a-f-]{36}\.pdf$#', $version->storage_path);
        $this->assertSame($bytes, Storage::disk('documents')->get($version->storage_path), 'stored bytes are identical');
        $this->assertSame('documents', $version->storage_disk);
        $this->assertStorageMatchesDatabase();

        $event = $matter->activityEvents()->sole();
        $this->assertSame(ActivityEventType::DocumentUploaded, $event->event_type);
        $this->assertSame('document', $event->subject_type);
        $this->assertSame(1, $event->metadata['version_number']);
        $this->assertArrayNotHasKey('storage_path', $event->metadata);
        $this->getJson("/api/matters/{$matter->id}")->assertJsonPath('data.documents_count', 1);
    }

    #[Test]
    public function the_title_defaults_to_the_file_name_and_names_are_sanitised(): void
    {
        $matter = Matter::factory()->create();

        $this->actingAsAdmin()->upload($matter, ['file' => PdfFactory::upload('..\\..\\etc/Lease_renewal-draft.pdf'), 'category' => 'agreements'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Lease renewal draft')
            ->assertJsonPath('data.current_version.original_filename', 'Lease_renewal-draft.pdf');
    }

    #[Test]
    public function files_that_are_not_safe_pdfs_are_rejected_and_nothing_is_stored(): void
    {
        $matter = Matter::factory()->create();
        $admin = $this->actingAsAdmin();
        $cases = [
            'plain text renamed to .pdf' => [PdfFactory::notAPdf(), 'This file is not a PDF. Only PDF documents can be uploaded.'],
            'truncated upload' => [PdfFactory::truncated(), 'The PDF appears to be incomplete or damaged. Please upload the complete file.'],
            'encrypted PDF' => [PdfFactory::encrypted(), 'Password-protected or encrypted PDFs are not supported. Remove the protection and upload again.'],
            'empty file' => [PdfFactory::fromBytes('empty.pdf', ''), null],
            'wrong extension' => [PdfFactory::fromBytes('agreement.docx', PdfFactory::bytes()), 'Only PDF files (.pdf) can be uploaded.'],
        ];

        foreach ($cases as $label => [$file, $message]) {
            $response = $admin->upload($matter, ['file' => $file, 'category' => 'contracts'])->assertUnprocessable();
            $response->assertJsonValidationErrors(['file']);
            if ($message !== null) {
                $this->assertSame($message, $response->json('errors.file.0'), $label);
            }
        }
        $admin->upload($matter, ['category' => 'contracts'])->assertUnprocessable()->assertJsonPath('errors.file.0', 'Choose a PDF file to upload.');

        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    #[Test]
    public function oversized_files_are_rejected_with_the_configured_limit(): void
    {
        config(['lexora.documents.max_upload_mb' => 1]);
        $matter = Matter::factory()->create();
        $big = PdfFactory::fromBytes('big.pdf', PdfFactory::bytes([str_repeat('A', 100)], '/Pad ('.str_repeat('x', 1_100_000).') '));

        $this->actingAsAdmin()->upload($matter, ['file' => $big, 'category' => 'evidence'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'The PDF is larger than the 1 MB limit.');
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    #[Test]
    public function server_managed_fields_and_invalid_metadata_are_rejected(): void
    {
        $matter = Matter::factory()->create();

        $this->actingAsAdmin()->upload($matter, [
            'file' => PdfFactory::upload(),
            'category' => 'secret',
            'title' => str_repeat('t', 256),
            'storage_path' => '../../public/x.pdf',
            'origin' => 'client',
            'uploaded_by' => 99,
            'version_number' => 7,
            'matter_id' => 123,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'title', 'storage_path', 'origin', 'uploaded_by', 'version_number', 'matter_id']);

        $this->assertSame(0, Document::count());
    }

    #[Test]
    public function uploads_into_missing_or_archived_matters_are_refused(): void
    {
        $archived = Matter::factory()->status(MatterStatus::Archived)->create();
        $admin = $this->actingAsAdmin();

        $admin->upload($archived, ['file' => PdfFactory::upload(), 'category' => 'contracts'])
            ->assertConflict()
            ->assertJsonPath('message', 'This matter is archived and read-only. Restore it to make changes.');
        $this->post('/api/matters/999999/documents', ['file' => PdfFactory::upload(), 'category' => 'contracts'], ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('documents')->allFiles(), 'no file is left behind');
    }

    #[Test]
    public function the_same_file_cannot_be_stored_twice_in_one_matter(): void
    {
        $matter = Matter::factory()->create();
        $other = Matter::factory()->create();
        $bytes = PdfFactory::bytes(['Witness statement']);
        $admin = $this->actingAsAdmin();

        $admin->upload($matter, ['file' => PdfFactory::fromBytes('statement.pdf', $bytes), 'title' => 'Witness statement', 'category' => 'evidence'])->assertCreated();
        // A double submission / retry of the same file.
        $admin->upload($matter, ['file' => PdfFactory::fromBytes('statement copy.pdf', $bytes), 'category' => 'evidence'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'This PDF is already stored in this matter as "Witness statement" (v1).');
        // Another matter may hold the same file.
        $admin->upload($other, ['file' => PdfFactory::fromBytes('statement.pdf', $bytes), 'category' => 'evidence'])->assertCreated();

        $this->assertSame(1, Document::query()->whereBelongsTo($matter)->count());
        $this->assertStorageMatchesDatabase();
    }

    #[Test]
    public function document_endpoints_require_an_admin_session(): void
    {
        $matter = Matter::factory()->create();

        $this->fromFrontend()->post("/api/matters/{$matter->id}/documents", ['file' => PdfFactory::upload(), 'category' => 'contracts'])->assertUnauthorized();
        $this->fromFrontend()->getJson("/api/matters/{$matter->id}/documents")->assertUnauthorized();
        $this->fromFrontend()->getJson('/api/documents')->assertUnauthorized();
        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    #[Test]
    public function fake_upload_objects_from_the_framework_are_validated_like_real_ones(): void
    {
        $matter = Matter::factory()->create();

        // UploadedFile::fake() produces a zero-filled file with a .pdf name: not a PDF.
        $this->actingAsAdmin()->upload($matter, ['file' => UploadedFile::fake()->create('fake.pdf', 10, 'application/pdf'), 'category' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);
    }
}
