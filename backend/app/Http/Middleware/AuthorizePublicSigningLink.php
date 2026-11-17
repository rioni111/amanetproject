<?php

namespace App\Http\Middleware;

use App\Services\Requests\PublicResponseHeaders;
use App\Services\Signing\PublicSigningLinkResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate of the public signing endpoints (no account, no session, no cookies): optional Origin allow-list,
 * X-Signing-Token → one usable document_signing link (checked before any input is processed),
 * and no-store / no-referrer / noindex headers on every response.
 */
class AuthorizePublicSigningLink
{
    public function __construct(private readonly PublicSigningLinkResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $allowed = array_map(fn (string $o) => rtrim($o, '/'), [...config('cors.allowed_origins', []), (string) config('lexora.public_app_url')]);
        if ($origin !== null && ! in_array(rtrim($origin, '/'), $allowed, true)) {
            return response()->json(['message' => 'This request is not allowed.', 'code' => 'origin_not_allowed'], 403)
                ->withHeaders(PublicResponseHeaders::HEADERS);
        }

        $request->attributes->set('signing_link', $this->resolver->resolve($request));

        $response = $next($request);
        $response->headers->add(PublicResponseHeaders::HEADERS);

        return $response;
    }
}
