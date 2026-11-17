<?php

return [

    /*
     | Dashboard aggregation rules. "Upcoming" deadlines are active matters whose
     | due date falls between now and now + window days (inclusive of today).
     */
    'dashboard' => [
        'upcoming_window_days' => (int) env('LEXORA_UPCOMING_WINDOW_DAYS', 14),
        'recent_matters_limit' => 6,
        'upcoming_deadlines_limit' => 6,
    ],

    /*
     | The firm's time zone. Date-only legal values (deadlines, opening dates) are calendar dates in
     | this zone; a deadline is the end of that day. Timestamps are always stored in UTC.
     */
    'timezone' => env('LEXORA_TIMEZONE', 'UTC'),

    /*
     | Document library (Phase 3). The PHP limits upload_max_filesize / post_max_size must be larger
     | (setup-windows.bat sets 25M / 30M).
     */
    'documents' => [
        'disk' => 'documents',
        'max_upload_mb' => (int) env('DOCUMENTS_MAX_UPLOAD_MB', 20),
    ],

    /*
     | Phase 4 — client document requests and secure links.
     | public_app_url: the client-facing web app that serves /client-upload (defaults to the first
     | FRONTEND_URL). A localhost URL only works on the development machine itself.
     */
    'firm_name' => env('LEXORA_FIRM_NAME', 'Lexora Legal'),
    'public_app_url' => rtrim((string) env('PUBLIC_APP_URL', trim(explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))[0])), '/'),
    'secure_links' => [
        'upload_expiry_days' => (int) env('UPLOAD_LINK_EXPIRY_DAYS', 7),
        'max_expiry_days' => 30,
    ],
    /*
     | Phase 5 — electronic signing. Signing links stay valid while the signer reviews the document and are
     | consumed only by a completed signature.
     */
    'signing' => [
        'link_expiry_days' => (int) env('SIGNING_LINK_EXPIRY_DAYS', 7),
        'views_per_minute' => (int) env('PUBLIC_SIGNING_VIEWS_PER_MINUTE', 120),
        'completions_per_minute' => (int) env('PUBLIC_SIGNING_COMPLETIONS_PER_MINUTE', 10),
    ],

    'public_links' => [
        'views_per_minute' => (int) env('PUBLIC_LINK_VIEWS_PER_MINUTE', 60),
        'uploads_per_minute' => (int) env('PUBLIC_LINK_UPLOADS_PER_MINUTE', 10),
        // Failed lookups (unknown, malformed or revoked tokens) per IP before a 15-minute lockout.
        'invalid_attempts' => (int) env('PUBLIC_LINK_INVALID_ATTEMPTS', 20),
    ],

    'pagination' => [
        'default_per_page' => 15,
        'max_per_page' => 100,
    ],

    /*
     | Demo administrator — only used by the development seeder.
     */
    'demo_admin' => [
        'name' => env('DEMO_ADMIN_NAME', 'Elena Marsh'),
        'email' => env('DEMO_ADMIN_EMAIL'),
        'password' => env('DEMO_ADMIN_PASSWORD'),
    ],

];
