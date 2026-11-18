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

    /*
     | Phase 6 — e-mail communication and reminders. SMTP itself is configured with Laravel's MAIL_* variables
     | (config/mail.php). Credentials live only in the environment, never in the repository.
     */
    'communications' => [
        // Master switch: false = e-mail actions are unavailable and the UI offers Copy link only.
        'email_enabled' => filter_var(env('LEXORA_EMAIL_ENABLED', true), FILTER_VALIDATE_BOOL),
        'reply_to' => env('MAIL_REPLY_TO_ADDRESS'),
        'reply_to_name' => env('MAIL_REPLY_TO_NAME'),
        // Shown in e-mail footers. Leave empty to omit (nothing is invented).
        'firm_contact_email' => env('LEXORA_FIRM_CONTACT_EMAIL'),
        'firm_postal_address' => env('LEXORA_FIRM_POSTAL_ADDRESS'),
        // Development only: link to the local Mailpit inbox in the UI (never shown when empty).
        'mail_ui_url' => env('LEXORA_MAIL_UI_URL'),
        // A manual e-mail for the same request at most this often (double clicks, two administrators).
        'manual_cooldown_seconds' => (int) env('LEXORA_EMAIL_MANUAL_COOLDOWN_SECONDS', 60),
        // E-mail actions per administrator per minute (all requests together).
        'sends_per_minute' => (int) env('LEXORA_EMAIL_SENDS_PER_MINUTE', 20),
        // A 'sending' record older than this is treated as interrupted (outcome unknown).
        'stale_sending_minutes' => (int) env('LEXORA_EMAIL_STALE_SENDING_MINUTES', 10),
        'reminders' => [
            'enabled' => filter_var(env('LEXORA_REMINDERS_ENABLED', true), FILTER_VALIDATE_BOOL),
            // Document requests with a due date: days relative to the due date (firm time zone).
            'document_due_offsets' => [-3, 0, 2],
            // Document requests without a due date: days after the first e-mail about the request.
            'document_no_due_days' => [4, 10],
            // Signing: days after the invitation was shared, and days before the signing link expires.
            'signing_after_shared_days' => 3,
            'signing_before_expiry_days' => 1,
            'min_interval_hours' => (int) env('LEXORA_REMINDER_MIN_INTERVAL_HOURS', 24),
            'max_per_request' => (int) env('LEXORA_REMINDER_MAX_PER_REQUEST', 3),
            // Requests processed per scheduler run (bounded batches).
            'batch_size' => (int) env('LEXORA_REMINDER_BATCH_SIZE', 50),
        ],
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

    // Phase 7: reverse proxies whose X-Forwarded-* headers are trusted (see AppServiceProvider, DEPLOYMENT_GUIDE §6).
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Phase 7: fictional documents, requests and signatures for the demo matters (DemoDocumentsSeeder).
    // Off for the PHPUnit and E2E databases, whose tests count records from a known baseline.
    'demo_documents' => filter_var(env('LEXORA_DEMO_DOCUMENTS', true), FILTER_VALIDATE_BOOL),

];
