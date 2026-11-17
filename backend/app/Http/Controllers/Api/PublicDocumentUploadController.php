<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentRequests\PublicSubmissionRequest;
use App\Models\SecureAccessLink;
use App\Services\Requests\ClientSubmissionService;
use App\Services\Requests\PublicUploadLinkResolver;
use App\Support\LegalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, passwordless client upload (authorised by the link resolved in AuthorizePublicUploadLink).
 * Responses contain only what the client needs to complete THIS upload: no matter, client, user,
 * document, version or storage identifiers, no other requests, no internal notes or history.
 */
class PublicDocumentUploadController extends Controller
{
    public function __construct(
        private readonly PublicUploadLinkResolver $resolver,
        private readonly ClientSubmissionService $submissions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $this->resolver->markOpened($link);
        $documentRequest = $link->documentRequest;
        $correction = $documentRequest->status === DocumentRequestStatus::CorrectionRequested
            ? $documentRequest->submissions()->orderByDesc('submission_number')->first(['id', 'correction_reason', 'reviewed_at'])
            : null;

        return response()->json(['data' => [
            'firm_name' => (string) config('lexora.firm_name'),
            'title' => $documentRequest->title,
            'instructions' => $documentRequest->instructions,
            'category' => $documentRequest->requested_category->value,
            'category_label' => $documentRequest->requested_category->label(),
            'due_date' => LegalDate::toDate($documentRequest->due_at),
            'is_overdue' => $documentRequest->isOverdue(),
            'expires_at' => $link->expires_at->toIso8601String(),
            'max_file_mb' => (int) config('lexora.documents.max_upload_mb', 20),
            'is_correction' => $correction !== null,
            'correction' => $correction ? [
                'reason' => $correction->correction_reason,
                'requested_at' => $correction->reviewed_at?->toIso8601String(),
            ] : null,
        ]]);
    }

    public function submit(PublicSubmissionRequest $request): JsonResponse
    {
        $submission = $this->submissions->submit($this->link($request), $request->file('file'), $request->clientMessage());

        return response()->json([
            'message' => 'Your document was submitted successfully. The firm will review it.',
            'data' => [
                'submission_number' => $submission->submission_number,
                'submitted_at' => $submission->submitted_at->toIso8601String(),
                'original_filename' => $submission->version->original_filename,
                'file_size' => $submission->version->file_size,
                'is_correction' => $submission->submission_number > 1,
            ],
        ], 201);
    }

    private function link(Request $request): SecureAccessLink
    {
        return $request->attributes->get('upload_link');
    }
}
