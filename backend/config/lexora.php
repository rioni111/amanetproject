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
