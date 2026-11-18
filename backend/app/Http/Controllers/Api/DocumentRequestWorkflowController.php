<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmailDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentRequests\DocumentRequestActionRequest;
use App\Http\Resources\DocumentRequestResource;
use App\Http\Resources\DocumentRequestSubmissionResource;
use App\Http\Resources\EmailDeliveryResource;
use App\Http\Resources\SecureAccessLinkResource;
use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Services\Communications\DocumentRequestEmailService;
use App\Services\Communications\EmailUnrecordedException;
use App\Services\Requests\DocumentRequestService;
use App\Services\Requests\DocumentReviewService;
use App\Services\Requests\IssuedLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Upload links (issue, replace, revoke), submission history and review decisions for one request.
 * Responses that create a link include its URL exactly once (`link.url`); it is never retrievable again.
 */
class DocumentRequestWorkflowController extends Controller
{
    public function __construct(
        private readonly DocumentRequestService $requests,
        private readonly DocumentReviewService $reviews,
    ) {}

    public function issueLink(DocumentRequestActionRequest $request, Matter $matter, DocumentRequest $documentRequest): JsonResponse
    {
        $issued = $this->requests->issueLink($matter, $documentRequest, $request->user(), $request->validated('expires_in_days'), $request->validated('lock_version'));

        return $this->withLink($documentRequest, $issued, 201);
    }

    public function rotateLink(DocumentRequestActionRequest $request, Matter $matter, DocumentRequest $documentRequest): JsonResponse
    {
        $issued = $this->requests->rotateLink($matter, $documentRequest, $request->user(), $request->validated('expires_in_days'), $request->validated('lock_version'));

        return $this->withLink($documentRequest, $issued, 201);
    }

    public function revokeLink(DocumentRequestActionRequest $request, Matter $matter, DocumentRequest $documentRequest, SecureAccessLink $link): JsonResponse
    {
        $link = $this->requests->revokeLink($matter, $documentRequest, $link, $request->user());

        return response()->json([
            'data' => new DocumentRequestResource(DocumentRequestController::loadDetail($documentRequest)),
            'revoked_link' => new SecureAccessLinkResource($link),
        ]);
    }

    public function submissions(Matter $matter, DocumentRequest $documentRequest): AnonymousResourceCollection
    {
        Gate::authorize('view', $documentRequest);

        return DocumentRequestSubmissionResource::collection(
            $documentRequest->submissions()->with('version')->orderByDesc('submission_number')->get()
        );
    }

    public function startReview(DocumentRequestActionRequest $request, Matter $matter, DocumentRequest $documentRequest): DocumentRequestResource
    {
        $documentRequest = $this->reviews->startReview($matter, $documentRequest, (int) $request->validated('submission_id'), $request->user(), $request->validated('lock_version'));

        return new DocumentRequestResource(DocumentRequestController::loadDetail($documentRequest));
    }

    public function approve(DocumentRequestActionRequest $request, Matter $matter, DocumentRequest $documentRequest): DocumentRequestResource
    {
        $documentRequest = $this->reviews->approve($matter, $documentRequest, (int) $request->validated('submission_id'), $request->user(), $request->validated('lock_version'));

        return new DocumentRequestResource(DocumentRequestController::loadDetail($documentRequest));
    }

    public function requestCorrection(DocumentRequestActionRequest $request, Matter $matter, DocumentRequest $documentRequest, DocumentRequestEmailService $emails): JsonResponse
    {
        $sendEmail = $request->boolean('send_email');
        $issued = $this->reviews->requestCorrection($matter, $documentRequest, (int) $request->validated('submission_id'),
            trim((string) $request->validated('reason')), $request->user(), $request->validated('lock_version'), $request->validated('expires_in_days'), ! $sendEmail);
        if ($issued !== null) {
            return $this->withLink($documentRequest, $issued, 200);
        }

        // The correction decision is committed. Now the e-mail — its failure never undoes the decision.
        try {
            $delivery = $emails->send($matter, $documentRequest, $request->user(), null, $request->validated('expires_in_days'));
            $email = [
                'status' => $delivery->status->value,
                'delivery' => new EmailDeliveryResource($delivery->loadMissing(['link', 'creator:id,name', 'reservation.link'])),
                'message' => $delivery->status === EmailDeliveryStatus::Accepted
                    ? 'Correction e-mail accepted by the configured mail server.'
                    : 'Correction requested, but the e-mail could not be sent. No successful delivery has been recorded. '.$delivery->failure_category?->label(),
            ];
        } catch (EmailUnrecordedException $e) {
            $email = ['status' => 'unrecorded', 'delivery' => null, 'message' => 'Correction requested. '.$e->getMessage()];
        } catch (HttpExceptionInterface $e) {
            $email = ['status' => 'not_sent', 'delivery' => null,
                'message' => 'Correction requested, but no e-mail was sent: '.$e->getMessage().' Send it again or generate a link to copy.'];
        }

        return response()->json([
            'data' => new DocumentRequestResource(DocumentRequestController::loadDetail($documentRequest)),
            'link' => null,
            'email' => $email,
        ])->header('Cache-Control', 'no-store, private');
    }

    private function withLink(DocumentRequest $documentRequest, IssuedLink $issued, int $status): JsonResponse
    {
        return response()->json([
            'data' => new DocumentRequestResource(DocumentRequestController::loadDetail($documentRequest)),
            'link' => $issued->toResponse(),
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
