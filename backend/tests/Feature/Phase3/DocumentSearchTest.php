<?php

namespace Tests\Feature\Phase3;

use App\Models\Client;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\TestCase;

class DocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    private Matter $alpha;

    private Matter $beta;

    private Client $northwind;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->northwind = Client::factory()->create(['name' => 'Northwind Trading']);
        $this->alpha = Matter::factory()->for($this->northwind)->create(['matter_number' => 'LEX-2026-0501', 'title' => 'Distribution dispute']);
        $this->beta = Matter::factory()->create(['matter_number' => 'LEX-2026-0502', 'title' => 'Office lease']);

        $this->actingAsAdmin();
        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->store($this->alpha, 'Supply Contract', 'contracts', 'supply-contract-signed.pdf');
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->store($this->alpha, 'Expert Witness Report', 'evidence', 'expert-report.pdf');
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->store($this->beta, 'Lease Heads of Terms', 'agreements', 'heads_of_terms.pdf');
        $archived = $this->store($this->beta, 'Old Correspondence', 'correspondence', 'letter.pdf');
        $this->postJson("/api/matters/{$this->beta->id}/documents/{$archived}/archive")->assertOk();
        Carbon::setTestNow();
    }

    private function store(Matter $matter, string $title, string $category, string $filename): int
    {
        return $this->post("/api/matters/{$matter->id}/documents", [
            'file' => PdfFactory::upload($filename, [$title]),
            'title' => $title,
            'category' => $category,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    /** @return list<string> */
    private function titles(string $query): array
    {
        return $this->getJson('/api/documents'.$query)->assertOk()->json('data.*.title');
    }

    #[Test]
    public function search_covers_title_filename_matter_client_and_category(): void
    {
        $this->assertSame(['Supply Contract'], $this->titles('?search=supply'));
        $this->assertSame(['Expert Witness Report'], $this->titles('?search=expert-report'));      // original filename
        $this->assertSame(['Lease Heads of Terms'], $this->titles('?search=LEX-2026-0502'));       // matter number
        $this->assertSame(['Lease Heads of Terms'], $this->titles('?search=office%20lease'));       // matter title
        $this->assertEqualsCanonicalizing(['Supply Contract', 'Expert Witness Report'], $this->titles('?search=northwind')); // client
        $this->assertSame(['Expert Witness Report'], $this->titles('?search=evidence'));           // category label
        $this->assertSame([], $this->titles('?search=%25'));                                         // literal %, no wildcard
    }

    #[Test]
    public function filters_combine_and_archived_documents_are_opt_in(): void
    {
        $this->assertCount(3, $this->titles(''));
        $this->assertSame(['Old Correspondence'], $this->titles('?status=archived'));
        $this->assertCount(4, $this->titles('?status=all'));
        $this->assertSame(['Expert Witness Report'], $this->titles('?category=evidence'));
        $this->assertEqualsCanonicalizing(['Supply Contract', 'Expert Witness Report'], $this->titles("?matter_id={$this->alpha->id}"));
        $this->assertEqualsCanonicalizing(['Supply Contract', 'Expert Witness Report'], $this->titles("?client_id={$this->northwind->id}"));
        $this->assertSame(['Supply Contract'], $this->titles("?client_id={$this->northwind->id}&category=contracts"));
        $this->assertSame(['Expert Witness Report'], $this->titles('?uploaded_from=2026-11-03&uploaded_to=2026-11-06'));
    }

    #[Test]
    public function sorting_and_pagination_are_stable_and_server_side(): void
    {
        $this->assertSame(['Expert Witness Report', 'Lease Heads of Terms', 'Supply Contract'], $this->titles('?sort=title'));
        $this->assertSame(['Lease Heads of Terms', 'Expert Witness Report', 'Supply Contract'], $this->titles('?sort=created_at'));
        $this->assertSame('LEX-2026-0501', $this->getJson('/api/documents?sort=matter_number')->json('data.0.matter.matter_number'));
        $this->assertSame('LEX-2026-0502', $this->getJson('/api/documents?sort=matter_number&direction=desc')->json('data.0.matter.matter_number'));

        $this->getJson('/api/documents?per_page=2&page=2&sort=title')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.title', 'Supply Contract')
            ->assertJsonStructure(['data' => [['id', 'title', 'category', 'current_version' => ['label', 'file_size'], 'matter' => ['matter_number', 'client' => ['name']]]]]);
    }

    #[Test]
    public function the_matter_list_is_scoped_and_invalid_filters_are_rejected(): void
    {
        $this->getJson("/api/matters/{$this->alpha->id}/documents")->assertJsonPath('meta.total', 2);
        $this->getJson("/api/matters/{$this->alpha->id}/documents?search=lease")->assertJsonPath('meta.total', 0);
        $this->getJson("/api/matters/{$this->alpha->id}/documents?category=contracts")->assertJsonPath('data.0.title', 'Supply Contract');

        $this->getJson('/api/documents?category=secret&sort=storage_path&status=deleted&uploaded_from=yesterday')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'sort', 'status', 'uploaded_from']);
    }
}
