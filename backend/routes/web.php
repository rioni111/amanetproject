<?php

use Illuminate\Support\Facades\Route;

// Laravel serves JSON only; the user interface is the Next.js application.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'service' => 'api',
    'status' => 'ok',
]))->name('root');
