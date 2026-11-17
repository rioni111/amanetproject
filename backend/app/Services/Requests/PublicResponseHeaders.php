<?php

namespace App\Services\Requests;

/** Headers on every public (token-authorised) API response. */
final class PublicResponseHeaders
{
    public const HEADERS = [
        'Cache-Control' => 'no-store, private, max-age=0',
        'Pragma' => 'no-cache',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        'X-Content-Type-Options' => 'nosniff',
    ];
}
