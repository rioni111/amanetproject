<?php

/*
 * CORS for the Next.js SPA. Credentials (cookies) are required for Sanctum's
 * session authentication, which in turn forbids a wildcard origin.
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    'supports_credentials' => true,

];
