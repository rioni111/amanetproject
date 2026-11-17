<?php

namespace Tests\Feature\Phase7;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Phase 7 issue P7-004: every API response carries baseline security headers, without breaking PDF responses. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase, SigningWorkflow;

    private function assertBaseline($response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeaderMissing('X-Powered-By')
            ->assertHeaderMissing('Strict-Transport-Security'); // never on local http
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function json_errors_unknown_routes_and_authenticated_data_carry_the_headers(): void
    {
        $this->assertBaseline($this->getJson('/api/dashboard/summary')->assertUnauthorized());
        $this->assertBaseline($this->getJson('/api/no-such-endpoint')->assertNotFound());
        $response = $this->actingAsAdmin($this->signingAdmin())->getJson('/api/dashboard/summary')->assertOk();
        $this->assertBaseline($response);
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    #[Test]
    public function headers_set_deliberately_by_pdf_and_public_responses_are_kept(): void
    {
        Storage::fake('documents');
        [, $token] = $this->clientSigning();

        $pdf = $this->publicSigning('GET', '/document', $token)->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $pdf->headers->get('Content-Security-Policy'), 'the PDF keeps its own sandboxing policy');
        $this->assertStringContainsString('no-store', (string) $pdf->headers->get('Cache-Control'));
    }

    #[Test]
    public function hsts_is_sent_only_over_https_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
    }
}
