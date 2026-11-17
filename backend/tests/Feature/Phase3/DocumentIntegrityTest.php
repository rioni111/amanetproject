<?php

namespace Tests\Feature\Phase3;

use App\Models\ActivityEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentStorage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\PdfFactory;
use Tests\TestCase;

/** Failure handling of the upload workflow: no metadata without a file, no file without metadata. */
class DocumentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->admin = User::factory()->create();
        $this->matter = Matter::factory()->create();
    }

    private function failingLogger(): ActivityLogger
    {
        $logger = Mockery::mock(ActivityLogger::class);
        $logger->shouldReceive('record')->andThrow(new RuntimeException('history unavailable'));

        return $logger;
    }

    private function expectFailure(callable $work, string $message): void
    {
        try {
            $work();
            $this->fail('Expected the operation to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    #[Test]
    public function a_storage_failure_creates_no_metadata(): void
    {
        $storage = Mockery::mock(DocumentStorage::class)->makePartial();
        $storage->shouldReceive('stage')->andThrow(new RuntimeException('disk full'));
        $service = new DocumentService(app(ActivityLogger::class), $storage);

        $this->expectFailure(fn () => $service->create($this->matter, PdfFactory::upload(), ['category' => 'contracts'], $this->admin), 'disk full');

        $this->assertSame(0, Document::count());
        $this->assertSame(0, ActivityEvent::count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    #[Test]
    public function a_failed_database_write_removes_the_new_file_and_keeps_committed_versions(): void
    {
        $working = app(DocumentService::class);
        $document = $working->create($this->matter, PdfFactory::upload('a.pdf', ['First']), ['category' => 'contracts', 'title' => 'Contract'], $this->admin);
        $v1 = DocumentVersion::query()->sole();
        $v1Bytes = Storage::disk('documents')->get($v1->storage_path);

        $failing = new DocumentService($this->failingLogger(), app(DocumentStorage::class));
        // New document: history fails → document, version and file all disappear.
        $this->expectFailure(fn () => $failing->create($this->matter, PdfFactory::upload('b.pdf', ['Second']), ['category' => 'evidence'], $this->admin), 'history unavailable');
        // New version: same, and version 1 is untouched.
        $this->expectFailure(fn () => $failing->addVersion($this->matter, $document, PdfFactory::upload('a2.pdf', ['First, revised']), $this->admin), 'history unavailable');
        // Metadata edit: rolled back.
        $this->expectFailure(fn () => $failing->update($this->matter, $document, ['title' => 'Renamed'], $this->admin), 'history unavailable');

        $this->assertSame(1, Document::count());
        $this->assertSame('Contract', $document->fresh()->title);
        $this->assertSame(1, $document->fresh()->latest_version_number);
        $this->assertSame([$v1->storage_path], Storage::disk('documents')->allFiles(), 'only the committed file remains; no staging leftovers');
        $this->assertSame($v1Bytes, Storage::disk('documents')->get($v1->storage_path));
        $this->assertSame(1, ActivityEvent::count());
    }

    #[Test]
    public function a_file_that_cannot_be_moved_into_place_leaves_nothing_behind(): void
    {
        $storage = Mockery::mock(DocumentStorage::class)->makePartial();
        $storage->shouldReceive('finalize')->andThrow(new RuntimeException('move failed'));
        $service = new DocumentService(app(ActivityLogger::class), $storage);

        $this->expectFailure(fn () => $service->create($this->matter, PdfFactory::upload(), ['category' => 'contracts'], $this->admin), 'move failed');

        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('documents')->allFiles(), 'staging file removed');
    }

    #[Test]
    public function foreign_keys_prevent_orphans(): void
    {
        $this->expectException(QueryException::class);
        DB::table('documents')->insert([
            'uuid' => (string) Str::uuid(), 'matter_id' => 999999, 'title' => 'Orphan', 'category' => 'other',
        ]);
    }
}
