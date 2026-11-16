<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MatterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lexora Workspace API (prefix: /api)
|--------------------------------------------------------------------------
| CSRF cookie:  GET /sanctum/csrf-cookie (registered by Sanctum)
| All routes below the auth group require an authenticated administrator session.
*/

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
});

Route::middleware(['auth:sanctum', 'admin', 'throttle:api'])->group(function () {
    Route::get('dashboard/summary', DashboardController::class)->name('dashboard.summary');

    Route::get('matters', [MatterController::class, 'index'])->name('matters.index');
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
});
