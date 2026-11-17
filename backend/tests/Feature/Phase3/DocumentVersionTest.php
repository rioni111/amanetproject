<?php

namespace Tests\Feature\Phase3;

use App\Enums\ActivityEventType;
use App\Models\ActivityEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\TestCase;

class DocumentVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** @return array{0: Matter, 1: int} */
    private function documentWithVersion(string $text = 'Original'): array
    {
        $matter = Matter::factory()->create();
        $id = $this->actingAsAdmin()->post("/api/matters/{$matter->id}/documents", [
            'file' => PdfFactory::upload('agreement.pdf', [$text]),
            'title' => 'Employment Agreement',
            'category' => 'contracts',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return [$matter, $id];
    }

    private function addVersion(Matter $matter, int $id, array $pages, array $extra = []): TestResponse
    {
        return $this->post("/api/matters/{$matter->id}/documents/{$id}/versions",
            ['file' => PdfFactory::upload('agreement-rev.pdf', $pages), ...$extra], ['Accept' => 'application/json']);
    }

    #[Test]
    public function new_versions_are_numbered_sequentially_and_previous_files_stay_intact(): void
    {
        [$matter, $id] = $this->documentWithVersion();
        $v1 = DocumentVersion::query()->sole();
        $v1Bytes = Storage::disk('documents')->get($v1->storage_path);

        $this->addVersion($matter, $id, ['Revised'])->assertCreated()
            ->assertJsonPath('data.version_number', 2)->assertJsonPath('data.label', 'v2')->assertJsonPath('data.is_current', true);
        $this->addVersion($matter, $id, ['Final revision'])->assertCreated()->assertJsonPath('data.version_number', 3);

        $this->getJson("/api/matters/{$matter->id}/documents/{$id}/versions")
            ->assertOk()
            ->assertJsonPath('data.*.version_number', [3, 2, 1])
            ->assertJsonPath('data.*.is_current', [true, false, false]);
        $this->getJson("/api/matters/{$matter->id}/documents/{$id}")
            ->assertJsonPath('data.current_version.version_number', 3)
            ->assertJsonPath('data.versions_count', 3);

        $this->assertSame($v1Bytes, Storage::disk('documents')->get($v1->fresh()->storage_path), 'version 1 was not overwritten');
        $this->assertSame(hash('sha256', $v1Bytes), $v1->fresh()->sha256_checksum);
        $this->assertCount(3, Storage::disk('documents')->allFiles((string) $matter->id));
        $this->assertSame(2, ActivityEvent::query()->where('event_type', ActivityEventType::DocumentVersionAdded->value)->count());
    }

    #[Test]
    public function an_upload_identical_to_the_current_version_is_rejected(): void
    {
        [$matter, $id] = $this->documentWithVersion('Same');

        $this->addVersion($matter, $id, ['Same'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'This PDF is identical to the current version (v1). Upload the revised file.');
        $this->addVersion($matter, $id, ['Different'])->assertCreated();
        // Reverting to the original content is a legitimate new version.
        $this->addVersion($matter, $id, ['Same'])->assertCreated()->assertJsonPath('data.version_number', 3);
        $this->assertSame([], Storage::disk('documents')->allFiles('_staging'));
    }

    #[Test]
    public function versions_are_immutable_in_the_model_and_in_the_database(): void
    {
        [, $id] = $this->documentWithVersion();
        $version = DocumentVersion::query()->sole();

        try {
            $version->forceFill(['original_filename' => 'changed.pdf'])->save();
            $this->fail('Model update must be refused.');
        } catch (LogicException) {
        }
        foreach (['UPDATE document_versions SET sha256_checksum = repeat(\'0\', 64)', 'DELETE FROM document_versions'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("{$sql} must be refused by the trigger.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        $this->assertSame($version->sha256_checksum, DocumentVersion::query()->sole()->sha256_checksum);
    }

    #[Test]
    public function the_database_enforces_unique_numbers_and_current_version_ownership(): void
    {
        [$matter, $id] = $this->documentWithVersion();
        [, $otherId] = $this->documentWithVersion('Other document');
        $v1 = DocumentVersion::query()->where('document_id', $id)->sole();
        $foreign = DocumentVersion::query()->where('document_id', $otherId)->sole();

        try {
            DB::transaction(fn () => DB::table('document_versions')->insert([
                'uuid' => (string) Str::uuid(), 'document_id' => $id, 'version_number' => 1,
                'kind' => 'upload', 'origin' => 'admin', 'original_filename' => 'x.pdf', 'storage_disk' => 'documents',
                'storage_path' => $matter->id.'/'.Str::uuid().'/'.Str::uuid().'.pdf',
                'mime_type' => 'application/pdf', 'file_size' => 10, 'sha256_checksum' => str_repeat('a', 64),
            ]));
            $this->fail('Duplicate version number must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('document_versions_document_id_version_number_unique', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('documents')->where('id', $id)->update(['current_version_id' => $foreign->id]));
            $this->fail('A version of another document cannot be current.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('documents_current_version_fk', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('document_versions')->insert([
                'uuid' => (string) Str::uuid(), 'document_id' => $id, 'version_number' => 9,
                'kind' => 'upload', 'origin' => 'admin', 'original_filename' => 'x.pdf', 'storage_disk' => 'documents',
                'storage_path' => '../../public/escape.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
                'sha256_checksum' => str_repeat('a', 64),
            ]));
            $this->fail('Paths outside the generated layout must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('document_versions_path_format', $e->getMessage());
        }
        $this->assertSame($v1->id, Document::findOrFail($id)->current_version_id);
    }

    #[Test]
    public function metadata_can_be_edited_safely_without_touching_files(): void
    {
        [$matter, $id] = $this->documentWithVersion();
        $before = DocumentVersion::query()->sole()->toArray();

        $this->patchJson("/api/matters/{$matter->id}/documents/{$id}", [
            'title' => 'Employment Agreement (executed)', 'category' => 'agreements', 'description' => 'Confidential remark', 'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.title', 'Employment Agreement (executed)')
            ->assertJsonPath('data.category', 'agreements')
            ->assertJsonPath('data.lock_version', 2);

        $this->assertSame($before, DocumentVersion::query()->sole()->toArray(), 'versions are untouched by metadata edits');
        $event = ActivityEvent::query()->where('event_type', ActivityEventType::DocumentUpdated->value)->sole();
        $this->assertEquals(['from' => 'contracts', 'to' => 'agreements'], $event->metadata['changes']['category']);
        $this->assertSame(['changed' => true], $event->metadata['changes']['description']);
        $this->assertStringNotContainsString('Confidential', json_encode($event->metadata));

        // Stale copy, file replacement through PATCH and server fields are refused.
        $this->patchJson("/api/matters/{$matter->id}/documents/{$id}", ['title' => 'Stale', 'lock_version' => 1])->assertConflict();
        $this->patchJson("/api/matters/{$matter->id}/documents/{$id}", ['status' => 'archived', 'current_version_id' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'current_version_id']);
        $this->patch("/api/matters/{$matter->id}/documents/{$id}", ['file' => PdfFactory::upload()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Upload a new version to change the file.');
    }

    #[Test]
    public function archived_documents_and_archived_matters_are_read_only(): void
    {
        [$matter, $id] = $this->documentWithVersion();

        $this->postJson("/api/matters/{$matter->id}/documents/{$id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->addVersion($matter, $id, ['After archive'])->assertConflict();
        $this->patchJson("/api/matters/{$matter->id}/documents/{$id}", ['title' => 'x'])->assertConflict();
        $this->getJson("/api/matters/{$matter->id}/documents")->assertJsonPath('meta.total', 0);
        $this->getJson("/api/matters/{$matter->id}/documents?status=archived")->assertJsonPath('meta.total', 1);
        $this->postJson("/api/matters/{$matter->id}/documents/{$id}/restore")->assertOk()->assertJsonPath('data.status', 'active');

        $this->postJson("/api/matters/{$matter->id}/archive")->assertOk(); // archive the matter
        $this->addVersion($matter, $id, ['Matter archived'])->assertConflict();
        $this->patchJson("/api/matters/{$matter->id}/documents/{$id}", ['title' => 'x'])->assertConflict();
        $this->postJson("/api/matters/{$matter->id}/documents/{$id}/archive")->assertConflict();
        // Reading stays possible.
        $this->getJson("/api/matters/{$matter->id}/documents/{$id}")->assertOk();
        $version = DocumentVersion::query()->sole();
        $this->get("/api/matters/{$matter->id}/documents/{$id}/versions/{$version->id}/download")->assertOk();
        $this->assertSame(1, DocumentVersion::count());
    }

    #[Test]
    public function nested_resources_cannot_be_reached_through_the_wrong_parent(): void
    {
        [$matterA, $docA] = $this->documentWithVersion('A');
        [$matterB, $docB] = $this->documentWithVersion('B');
        $versionB = DocumentVersion::query()->where('document_id', $docB)->sole();

        $this->getJson("/api/matters/{$matterA->id}/documents/{$docB}")->assertNotFound();
        $this->patchJson("/api/matters/{$matterA->id}/documents/{$docB}", ['title' => 'Hijack'])->assertNotFound();
        $this->addVersion($matterA, $docB, ['Hijack'])->assertNotFound();
        $this->getJson("/api/matters/{$matterA->id}/documents/{$docA}/versions/{$versionB->id}")->assertNotFound();
        $this->get("/api/matters/{$matterA->id}/documents/{$docA}/versions/{$versionB->id}/download")->assertNotFound();
        $this->get("/api/matters/{$matterB->id}/documents/{$docB}/versions/999999/preview")->assertNotFound();
        $this->assertSame(1, DocumentVersion::query()->where('document_id', $docB)->count());
    }
}
