<?php

namespace Tests\Feature\Phase7;

use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/**
 * Phase 7 §31 — N+1 regression guard. Every list endpoint must run the SAME number of SQL queries whether it
 * returns 2 rows or 8: a growing count means a relation is being loaded per row.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase, RequestWorkflow, SigningWorkflow;

    private ?Matter $matter = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Mail::fake();
    }

    /** Adds $n matters, each with a document, a client request with a client upload, and a signing request. */
    private function grow(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $matter = $this->signingMatter("Client {$i} ".uniqid());
            // A deadline inside the dashboard window, so its "upcoming deadlines" list is never empty (Laravel
            // skips an eager-load query for an empty list, which would make the counts differ for no reason).
            Matter::query()->whereKey($matter->id)->update(['due_at' => now()->addDays(5)]);
            $this->matter ??= $matter;
            $document = $this->uploadDocument($matter, PdfFactory::bytes(["Document {$i}", 'Second page']), "Document {$i}");
            $this->createSigning($matter, $document)->assertCreated();
            $token = $this->tokenFrom($this->createRequest($matter)->assertCreated());
            $this->publicSubmit($token, PdfFactory::upload("upload-{$i}.pdf", ["Client upload {$i}"]))->assertCreated();
        }
    }

    private function queries(string $path): int
    {
        $this->actingAsAdmin($this->signingAdmin());
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->getJson($path)->assertOk();
        DB::getEventDispatcher()?->forget('Illuminate\Database\Events\QueryExecuted');

        return $count;
    }

    #[Test]
    public function list_endpoints_do_not_issue_a_query_per_row(): void
    {
        $paths = fn () => [
            '/api/matters?per_page=50',
            '/api/clients?per_page=50',
            '/api/documents?per_page=50',
            '/api/document-requests?per_page=50',
            '/api/signing-requests?per_page=50',
            '/api/notifications?per_page=50',
            '/api/dashboard/summary',
            "/api/matters/{$this->matter->id}",
            "/api/matters/{$this->matter->id}/activity",
        ];

        $this->grow(2);
        $small = array_map(fn ($p) => $this->queries($p), $paths());
        $this->grow(6);
        $large = array_map(fn ($p) => $this->queries($p), $paths());

        foreach ($paths() as $i => $path) {
            if (getenv('PRINT_QUERY_BUDGET')) {
                fwrite(STDERR, sprintf("%-40s %2d queries (2 rows) %2d queries (8 rows)\n", preg_replace('#/\d+#', '/{id}', $path), $small[$i], $large[$i]));
            }
            $this->assertSame($small[$i], $large[$i], "{$path}: {$small[$i]} queries for 2 rows, {$large[$i]} for 8 (N+1)");
            $this->assertLessThanOrEqual(25, $large[$i], "{$path} runs {$large[$i]} queries");
        }
    }
}
