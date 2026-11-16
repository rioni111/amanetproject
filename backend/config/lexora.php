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
