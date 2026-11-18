<?php

namespace App\Providers;

use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestSubmission;
use App\Models\DocumentVersion;
use App\Models\EmailDelivery;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\SecureAccessLink;
use App\Models\SignatureRecord;
use App\Models\SigningRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\Requests\PublicUploadLinkResolver;
use App\Services\Signing\PublicSigningLinkResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch N+1 queries, silently discarded attributes and missing attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Phase 7: behind a TLS-terminating reverse proxy, trust its X-Forwarded-* headers — and only its own
        // (TRUSTED_PROXIES: comma-separated addresses/CIDRs, or "*" when PHP is reachable only through the proxy).
        // Unset (the default, local development): no forwarded header is trusted.
        $proxies = trim((string) config('lexora.trusted_proxies'));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }

        // Stable, class-name independent identifiers for polymorphic columns.
        Relation::enforceMorphMap([
            'user' => User::class,
            'client' => Client::class,
            'matter' => Matter::class,
            'task' => Task::class,
            'matter_note' => MatterNote::class,
            'activity_event' => ActivityEvent::class,
            'document' => Document::class,
            'document_version' => DocumentVersion::class,
            'document_request' => DocumentRequest::class,
            'document_request_submission' => DocumentRequestSubmission::class,
            'secure_access_link' => SecureAccessLink::class,
            'signing_request' => SigningRequest::class,
            'signature_record' => SignatureRecord::class,
            'email_delivery' => EmailDelivery::class,
        ]);

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(12)->mixedCase()->numbers());

        Gate::define('view-dashboard', fn (User $user) => $user->isAdmin());

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Route-level backstop against credential stuffing across many emails from one IP.
        // (Per email+IP lockout after 5 failures lives in LoginRequest.)
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by('login-ip:'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));

        // Public, token-authorised client endpoints (no account, no session). Opening the link is
        // bounded per IP; submissions per IP and per token. Repeated unknown tokens from one IP are
        // additionally locked out by PublicUploadLinkResolver (enumeration defence).
        RateLimiter::for('public-link', fn (Request $request) => Limit::perMinute((int) config('lexora.public_links.views_per_minute', 60))
            ->by('public-link-ip:'.$request->ip()));
        RateLimiter::for('public-upload', fn (Request $request) => [
            Limit::perMinute((int) config('lexora.public_links.uploads_per_minute', 10))->by('public-upload-ip:'.$request->ip()),
            Limit::perMinute(5)->by('public-upload-token:'.hash('sha256', (string) $request->header(PublicUploadLinkResolver::HEADER))),
        ]);

        // Phase 5 public signing: page/PDF/progress requests per IP; completions per IP and per token.
        // Phase 6: administrators' e-mail actions (abuse brake on top of the per-request cooldown) and test e-mails.
        RateLimiter::for('communications', fn (Request $request) => Limit::perMinute((int) config('lexora.communications.sends_per_minute', 20))
            ->by('communications:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('test-email', fn (Request $request) => Limit::perMinutes(10, 3)->by('test-email:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('public-signing', fn (Request $request) => Limit::perMinute((int) config('lexora.signing.views_per_minute', 120))
            ->by('public-signing-ip:'.$request->ip()));
        RateLimiter::for('public-signing-complete', fn (Request $request) => [
            Limit::perMinute((int) config('lexora.signing.completions_per_minute', 10))->by('public-signing-complete-ip:'.$request->ip()),
            Limit::perMinute(5)->by('public-signing-token:'.hash('sha256', (string) $request->header(PublicSigningLinkResolver::HEADER))),
        ]);
    }
}
