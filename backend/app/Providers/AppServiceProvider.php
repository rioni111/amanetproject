<?php

namespace App\Providers;

use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\Task;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
    }
}
