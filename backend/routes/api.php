<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MatterActivityController;
use App\Http\Controllers\Api\MatterController;
use App\Http\Controllers\Api\MatterLifecycleController;
use App\Http\Controllers\Api\MatterNoteController;
use App\Http\Controllers\Api\MatterTaskController;
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

    // Clients (DELETE only succeeds for clients without matters — retention rule, 409 otherwise).
    Route::apiResource('clients', ClientController::class)->whereNumber('client');

    // Matters. Matters are never deleted: they are closed and archived instead.
    Route::apiResource('matters', MatterController::class)->except('destroy')->whereNumber('matter');
    Route::prefix('matters/{matter}')->whereNumber('matter')->name('matters.')->group(function () {
        Route::post('status', [MatterLifecycleController::class, 'status'])->name('status');
        Route::post('archive', [MatterLifecycleController::class, 'archive'])->name('archive');
        Route::post('restore', [MatterLifecycleController::class, 'restore'])->name('restore');
        Route::get('activity', MatterActivityController::class)->name('activity');
    });

    // Tasks and internal notes are nested and scoped: /matters/1/tasks/9 is 404 unless task 9
    // belongs to matter 1.
    Route::scopeBindings()->group(function () {
        Route::apiResource('matters.tasks', MatterTaskController::class)->except('show')
            ->whereNumber(['matter', 'task']);
        Route::apiResource('matters.notes', MatterNoteController::class)->except('show')
            ->whereNumber(['matter', 'note']);
    });
});
