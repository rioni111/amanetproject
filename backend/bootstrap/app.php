<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA authentication: requests from SANCTUM_STATEFUL_DOMAINS get
        // cookies, sessions and CSRF verification on /api routes.
        $middleware->statefulApi();

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // The API never redirects guests to an HTML login page.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always answer API requests with JSON: { "message": string, "errors"?: {field: string[]} }
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('sanctum/*') || $request->expectsJson()
        );

        // Do not leak model class names / IDs in 404 responses.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getPrevious() instanceof ModelNotFoundException
                        ? 'The requested resource was not found.'
                        : 'The requested endpoint does not exist.',
                ], 404);
            }
        });

        // The request body exceeded PHP's post_max_size (e.g. a very large PDF): friendly JSON 413.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The file is too large. PDFs may be at most '.config('lexora.documents.max_upload_mb', 20).' MB.',
                ], 413);
            }
        });

        // Credentials and tokens must never be written to logs or flashed back.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token']);
    })->create();
