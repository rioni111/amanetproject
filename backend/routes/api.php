<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentFileController;
use App\Http\Controllers\Api\DocumentRequestController;
use App\Http\Controllers\Api\DocumentRequestWorkflowController;
use App\Http\Controllers\Api\DocumentVersionController;
use App\Http\Controllers\Api\MatterActivityController;
use App\Http\Controllers\Api\MatterController;
use App\Http\Controllers\Api\MatterLifecycleController;
use App\Http\Controllers\Api\MatterNoteController;
use App\Http\Controllers\Api\MatterTaskController;
use App\Http\Controllers\Api\PublicDocumentUploadController;
use App\Http\Controllers\Api\PublicSigningController;
use App\Http\Controllers\Api\SigningRequestController;
use App\Http\Middleware\AuthorizePublicSigningLink;
use App\Http\Middleware\AuthorizePublicUploadLink;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

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

        // Phase 3 — documents. Each id is resolved through its parent (matter → document → version),
        // so a document of another matter or a version of another document is a 404.
        Route::prefix('matters/{matter}/documents')->name('matters.documents.')->whereNumber(['matter', 'document', 'version'])->group(function () {
            Route::get('/', [DocumentController::class, 'index'])->name('index');
            Route::post('/', [DocumentController::class, 'store'])->name('store');
            Route::get('{document}', [DocumentController::class, 'show'])->name('show');
            Route::patch('{document}', [DocumentController::class, 'update'])->name('update');
            Route::post('{document}/archive', [DocumentController::class, 'archive'])->name('archive');
            Route::post('{document}/restore', [DocumentController::class, 'restore'])->name('restore');
            Route::get('{document}/versions', [DocumentVersionController::class, 'index'])->name('versions.index');
            Route::post('{document}/versions', [DocumentVersionController::class, 'store'])->name('versions.store');
            Route::get('{document}/versions/{version}', [DocumentVersionController::class, 'show'])->name('versions.show');
            Route::get('{document}/versions/{version}/preview', [DocumentFileController::class, 'preview'])->name('versions.preview');
            Route::get('{document}/versions/{version}/download', [DocumentFileController::class, 'download'])->name('versions.download');
        });

        // Phase 4 — client document requests. Request, link and submission ids are resolved through
        // their parents, so a request of another matter or a link of another request is a 404.
        Route::prefix('matters/{matter}/document-requests')->name('matters.document-requests.')->whereNumber(['matter', 'documentRequest', 'link'])->group(function () {
            Route::get('/', [DocumentRequestController::class, 'index'])->name('index');
            Route::post('/', [DocumentRequestController::class, 'store'])->name('store');
            Route::get('{documentRequest}', [DocumentRequestController::class, 'show'])->name('show');
            Route::patch('{documentRequest}', [DocumentRequestController::class, 'update'])->name('update');
            Route::post('{documentRequest}/cancel', [DocumentRequestController::class, 'cancel'])->name('cancel');
            Route::post('{documentRequest}/links', [DocumentRequestWorkflowController::class, 'issueLink'])->name('links.store');
            Route::post('{documentRequest}/links/rotate', [DocumentRequestWorkflowController::class, 'rotateLink'])->name('links.rotate');
            Route::post('{documentRequest}/links/{link}/revoke', [DocumentRequestWorkflowController::class, 'revokeLink'])->name('links.revoke');
            Route::get('{documentRequest}/submissions', [DocumentRequestWorkflowController::class, 'submissions'])->name('submissions');
            Route::post('{documentRequest}/start-review', [DocumentRequestWorkflowController::class, 'startReview'])->name('start-review');
            Route::post('{documentRequest}/approve', [DocumentRequestWorkflowController::class, 'approve'])->name('approve');
            Route::post('{documentRequest}/request-correction', [DocumentRequestWorkflowController::class, 'requestCorrection'])->name('request-correction');
        });
    });

    // Phase 5 — electronic signing. Request and link ids are resolved through their parents (scoped bindings).
    Route::scopeBindings()->prefix('matters/{matter}')->whereNumber(['matter', 'signingRequest', 'link', 'document', 'version'])->name('matters.')->group(function () {
        Route::get('signing-requests', [SigningRequestController::class, 'index'])->name('signing-requests.index');
        Route::post('signing-requests', [SigningRequestController::class, 'store'])->name('signing-requests.store');
        Route::prefix('signing-requests/{signingRequest}')->name('signing-requests.')->group(function () {
            Route::get('/', [SigningRequestController::class, 'show'])->name('show');
            Route::get('evidence', [SigningRequestController::class, 'evidence'])->name('evidence');
            Route::post('links', [SigningRequestController::class, 'issueLink'])->name('links.store');
            Route::post('links/rotate', [SigningRequestController::class, 'rotateLink'])->name('links.rotate');
            Route::post('links/{link}/revoke', [SigningRequestController::class, 'revokeLink'])->name('links.revoke');
            Route::post('mark-shared', [SigningRequestController::class, 'markShared'])->name('mark-shared');
            Route::post('cancel', [SigningRequestController::class, 'cancel'])->name('cancel');
            Route::post('admin-sign', [SigningRequestController::class, 'adminSign'])->name('admin-sign');
        });
        Route::post('documents/{document}/sign', [SigningRequestController::class, 'signDocument'])->name('documents.sign');
        Route::get('documents/{document}/versions/{version}/signing-eligibility', [SigningRequestController::class, 'eligibility'])->name('documents.versions.signing-eligibility');
    });

    // Workspace-wide signing requests.
    Route::get('signing-requests', [SigningRequestController::class, 'library'])->name('signing-requests.index');

    // Workspace-wide document library (search, filters, sorting, pagination).
    Route::get('documents', [DocumentController::class, 'library'])->name('documents.index');

    // Workspace-wide client document requests (outstanding requirements).
    Route::get('document-requests', [DocumentRequestController::class, 'library'])->name('document-requests.index');
});

/*
| Public client endpoints (Phase 4). A separate boundary from everything above:
|  - no Sanctum session: the stateful-SPA middleware is removed, so no session is started, no admin
|    cookie is read and CSRF (a protection for cookie-authenticated requests) does not apply;
|  - authorisation is the purpose-scoped secure link in the X-Upload-Token header (never in the URL);
|  - per-IP / per-token rate limits and an invalid-token lockout; minimal responses, no-store headers.
*/
Route::prefix('public/document-upload')->name('public.document-upload.')
    ->withoutMiddleware([EnsureFrontendRequestsAreStateful::class])
    ->group(function () {
        Route::get('/', [PublicDocumentUploadController::class, 'show'])
            ->middleware(['throttle:public-link', AuthorizePublicUploadLink::class])->name('show');
        Route::post('submit', [PublicDocumentUploadController::class, 'submit'])
            ->middleware(['throttle:public-upload', AuthorizePublicUploadLink::class])->name('submit');
    });

/*
| Public signing endpoints (Phase 5): the same isolation as the upload endpoints, authorised only by a
| document_signing link in the X-Signing-Token header. GET requests never change state; the view and start
| milestones are explicit POSTs from the signing page.
*/
Route::prefix('public/signing')->name('public.signing.')
    ->withoutMiddleware([EnsureFrontendRequestsAreStateful::class])
    ->group(function () {
        Route::get('/', [PublicSigningController::class, 'show'])->middleware(['throttle:public-signing', AuthorizePublicSigningLink::class])->name('show');
        Route::get('document', [PublicSigningController::class, 'document'])->middleware(['throttle:public-signing', AuthorizePublicSigningLink::class])->name('document');
        Route::post('view', [PublicSigningController::class, 'view'])->middleware(['throttle:public-signing', AuthorizePublicSigningLink::class])->name('view');
        Route::post('start', [PublicSigningController::class, 'start'])->middleware(['throttle:public-signing', AuthorizePublicSigningLink::class])->name('start');
        Route::post('complete', [PublicSigningController::class, 'complete'])->middleware(['throttle:public-signing-complete', AuthorizePublicSigningLink::class])->name('complete');
    });
