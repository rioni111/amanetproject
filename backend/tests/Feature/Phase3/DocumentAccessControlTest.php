<?php

namespace Tests\Feature\Phase3;

use App\Models\Matter;
use App\Models\User;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\TestCase;

/** Fixtures are created through the service; no request in this class is ever authenticated. */
class DocumentAccessControlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_document_endpoint_rejects_requests_without_an_admin_session(): void
    {
        Storage::fake('documents');
        $matter = Matter::factory()->create();
        $document = app(DocumentService::class)->create($matter, PdfFactory::upload(), ['category' => 'contracts'], User::factory()->create());
        $base = "/api/matters/{$matter->id}/documents/{$document->id}";
        $version = "{$base}/versions/{$document->current_version_id}";

        $requests = [
            ['GET', '/api/documents'], ['GET', "/api/matters/{$matter->id}/documents"], ['GET', $base],
            ['PATCH', $base], ['POST', "{$base}/archive"], ['POST', "{$base}/restore"], ['GET', "{$base}/versions"],
            ['POST', "{$base}/versions"], ['GET', $version], ['GET', "{$version}/preview"], ['GET', "{$version}/download"],
        ];
        foreach ($requests as [$method, $uri]) {
            $response = $this->fromFrontend()->json($method, $uri);
            $this->assertSame(401, $response->getStatusCode(), "{$method} {$uri}");
            $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
        }
        // Without the SPA headers too.
        $this->get("{$version}/download")->assertStatus(401);
    }
}
