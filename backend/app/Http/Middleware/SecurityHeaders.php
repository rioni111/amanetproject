<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 7 (issue P7-004): baseline HTTP security headers for every API response.
 *
 * The API serves JSON and PDFs to the Lexora web app only; nothing it returns is meant to be framed, sniffed
 * as another type, cached by intermediaries or rendered as a page. Headers a controller set deliberately
 * (the PDF responses' Content-Security-Policy sandbox, the public endpoints' Referrer-Policy and Cache-Control)
 * are kept. HSTS is sent only over HTTPS in production, so local http://localhost development is unaffected.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'no-referrer');
        }
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
        // Laravel's default for API responses is "no-cache, private"; private data must not be stored at all.
        $cache = (string) $headers->get('Cache-Control');
        if ($cache === '' || $cache === 'no-cache, private') {
            $headers->set('Cache-Control', 'no-store, private');
        }
        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By'); // added by PHP itself (expose_php) outside the response object
        }

        return $response;
    }
}
