<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | Phase 6 — client reminders and time-based administrator notifications. Run the scheduler with
 | `php artisan schedule:work` (development) or a cron entry `* * * * * php artisan schedule:run` (production).
 | withoutOverlapping: a slow run is never doubled; onOneServer: one run per cluster (cache lock). Duplicate
 | sending is additionally impossible per reminder slot (unique idempotency keys).
 */
Schedule::command('lexora:communications')
    ->everyFiveMinutes()
    ->withoutOverlapping(30)
    ->onOneServer();
