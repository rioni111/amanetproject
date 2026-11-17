<?php

namespace App\Http\Middleware;

use App\Services\Requests\PublicResponseHeaders;
use App\Services\Requests\PublicUploadLinkResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate of the public client-upload endpoints (no account, no session, no cookies):
 *  1. a browser Origin, when present, must be one of the configured frontend origins (an extra
 *     browser-side defence — never a substitute for the token check);
 *  2. the X-Upload-Token header must resolve to a usable document_upload link — checked BEFORE any
 *     uploaded file is validated or parsed;
 *  3. every response carries no-store / no-referrer / noindex headers.
 */
class AuthorizePublicUploadLink
{
    public function __construct(private readonly PublicUploadLinkResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! in_array(rtrim($origin, '/'), $this->allowedOrigins(), true)) {
            return response()->json(['message' => 'This request is not allowed.', 'code' => 'origin_not_allowed'], 403)
                ->withHeaders(PublicResponseHeaders::HEADERS);
        }

        $request->attributes->set('upload_link', $this->resolver->resolve($request));

        $response = $next($request);
        $response->headers->add(PublicResponseHeaders::HEADERS);

        return $response;
    }

    /** @return list<string> */
    private function allowedOrigins(): array
    {
        return array_map(fn (string $o) => rtrim($o, '/'), [
            ...config('cors.allowed_origins', []),
            (string) config('lexora.public_app_url'),
        ]);
    }
}
