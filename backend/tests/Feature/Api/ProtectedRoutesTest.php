<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProtectedRoutesTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedEndpoints(): array
    {
        return [
            'me' => ['GET', '/api/auth/me'],
            'logout' => ['POST', '/api/auth/logout'],
            'dashboard' => ['GET', '/api/dashboard/summary'],
            'matters' => ['GET', '/api/matters'],
            'clients' => ['GET', '/api/clients'],
        ];
    }

    #[Test]
    #[DataProvider('protectedEndpoints')]
    public function unauthenticated_requests_are_rejected_with_json_401(string $method, string $uri): void
    {
        $this->fromFrontend()
            ->json($method, $uri)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    #[DataProvider('protectedEndpoints')]
    public function non_spa_clients_without_a_session_are_rejected_too(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    #[Test]
    public function unknown_api_endpoints_return_a_json_404_without_internals(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'The requested endpoint does not exist.']);
    }

    #[Test]
    public function cors_preflight_allows_the_frontend_origin_with_credentials(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    #[Test]
    public function cors_does_not_allow_foreign_origins(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/auth/login');

        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function the_csrf_cookie_endpoint_issues_an_xsrf_token_cookie(): void
    {
        $this->fromFrontend()
            ->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }
}
