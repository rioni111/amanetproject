<?php

namespace Tests\Feature\Phase7;

use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\SignatureRecord;
use App\Models\SigningRequest;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDocumentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Phase 7 §36: realistic, repeatable demo documents created through the real services. */
class DemoDocumentsSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_demo_documents_are_complete_consistent_and_seeded_only_once(): void
    {
        Storage::fake('documents');
        $this->seed(DatabaseSeeder::class); // LEXORA_DEMO_DOCUMENTS=false in phpunit.xml: the baseline has none
        $this->assertSame(0, Document::query()->count());

        $this->seed(DemoDocumentsSeeder::class);
        $counts = fn () => [Document::query()->count(), DocumentVersion::query()->count(), DocumentRequest::query()->count(),
            SigningRequest::query()->count(), DB::table('activity_events')->count()];
        $first = $counts();
        $this->seed(DemoDocumentsSeeder::class); // idempotent: nothing is added the second time
        $this->assertSame($first, $counts());

        // Documents and immutable versions, stored privately with matching checksums.
        $this->assertSame(5 + 3, Document::query()->count(), 'five firm documents and three client uploads');
        foreach (DocumentVersion::query()->get() as $version) {
            $this->assertTrue(Storage::disk('documents')->exists($version->storage_path));
            $this->assertSame($version->sha256_checksum, hash('sha256', Storage::disk('documents')->get($version->storage_path)));
        }
        $msa = Document::query()->where('title', 'Master Services Agreement — carrier draft')->sole();
        $this->assertSame(2, $msa->versions()->count());

        // Every request state the workflow knows.
        $states = DocumentRequest::query()->orderBy('title')->pluck('status', 'title')->map->value->all();
        $this->assertSame([
            'Certificate of insurance' => 'approved',
            'Employment contract and final payslips' => 'under_review',
            'Proof of funds' => 'correction_requested',
            'Termination letter' => 'pending',
        ], $states);

        // Signatures: administrator signed, client signed (with evidence), one ready to send.
        $signing = SigningRequest::query()->with('document')->get()->mapWithKeys(fn ($s) => [$s->document->title => [$s->signer_type->value, $s->status->value]])->all();
        $this->assertSame(['admin', 'signed'], $signing['Board resolution — subsidiary incorporation']);
        $this->assertSame(['client', 'signed'], $signing['Purchase agreement — 18 Elm Street']);
        $this->assertSame(['client', 'ready'], $signing['Heads of terms — hotel lease renewal']);
        $this->assertSame(2, SignatureRecord::query()->count());
        $record = SignatureRecord::query()->where('signer_type', 'client')->sole();
        $signed = DocumentVersion::query()->findOrFail($record->signed_document_version_id);
        $this->assertSame('signed', $signed->kind->value);
        $this->assertSame($record->signed_sha256, hash('sha256', Storage::disk('documents')->get($signed->storage_path)));
        $this->assertStringContainsString('fictional', $record->user_agent, 'demo evidence says it is demo evidence');

        // No secrets: no usable link was kept for a request that still waits for the client.
        $pending = DocumentRequest::query()->where('title', 'Termination letter')->sole();
        $this->assertSame(0, DB::table('secure_access_links')->where('document_request_id', $pending->id)->count());
        $this->assertSame(0, DB::table('secure_access_links')->whereNull('revoked_at')->whereNull('consumed_at')->where('expires_at', '>', now())->count(), 'no usable link exists whose token is unknown');

        // Archived matters are never touched.
        $this->assertSame(0, Document::query()->whereIn('matter_id', Matter::query()->where('status', 'archived')->pluck('id'))->count());
    }
}
