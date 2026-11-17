<?php

namespace Tests\Feature\Phase7;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 7: behind a TLS-terminating reverse proxy Laravel must recognise HTTPS (HSTS, secure URLs) — but only
 * when the request really comes from the configured proxy. Without TRUSTED_PROXIES no forwarded header is
 * trusted (a client cannot claim HTTPS by sending X-Forwarded-Proto itself).
 */
class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function forwardedHttps(): TestResponse
    {
        $this->app['env'] = 'production';

        return $this->get('http://localhost/up', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443']);
    }

    #[Test]
    public function forwarded_https_is_ignored_unless_the_proxy_is_trusted(): void
    {
        config(['lexora.trusted_proxies' => null]);
        (new AppServiceProvider($this->app))->boot();
        $this->forwardedHttps()->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function the_configured_proxy_is_trusted_for_https(): void
    {
        config(['lexora.trusted_proxies' => '127.0.0.1, 10.0.0.5']); // test requests come from 127.0.0.1
        (new AppServiceProvider($this->app))->boot();
        $this->forwardedHttps()->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    #[Test]
    public function a_proxy_that_is_not_listed_is_not_trusted(): void
    {
        config(['lexora.trusted_proxies' => '10.0.0.5']);
        (new AppServiceProvider($this->app))->boot();
        $this->forwardedHttps()->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }
}
