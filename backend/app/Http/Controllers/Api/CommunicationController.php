<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmailCommunicationType;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communications\SendEmailRequest;
use App\Http\Resources\EmailDeliveryResource;
use App\Mail\ClientActionMail;
use App\Models\DocumentRequest;
use App\Models\EmailDelivery;
use App\Models\Matter;
use App\Models\SigningRequest;
use App\Services\Communications\DocumentRequestEmailService;
use App\Services\Communications\EmailDeliveryService;
use App\Services\Communications\MailComposer;
use App\Services\Communications\SigningEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — e-mail actions and communication history (administrators only; client tokens never reach here).
 *
 * Responses are honest about the outcome:
 *   201 accepted by the configured mail server · 200 repeated request (the first result is returned)
 *   502 the attempt failed (nothing about the request changed; no delivery recorded as successful)
 *   409 not allowed now (closed request, cooldown, already sending) · 503 e-mail is not configured
 */
class CommunicationController extends Controller
{
    public function __construct(
        private readonly EmailDeliveryService $deliveries,
        private readonly DocumentRequestEmailService $documentEmails,
        private readonly SigningEmailService $signingEmails,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->deliveries->transportStatus()]);
    }

    public function sendDocumentRequest(SendEmailRequest $request, Matter $matter, DocumentRequest $documentRequest): JsonResponse
    {
        $delivery = $this->documentEmails->send($matter, $documentRequest, $request->user(),
            $request->validated('idempotency_key'), $request->validated('link_expires_in_days'));

        return $this->outcome($delivery, $request->filled('idempotency_key'));
    }

    public function sendSigningInvitation(SendEmailRequest $request, Matter $matter, SigningRequest $signingRequest): JsonResponse
    {
        $delivery = $this->signingEmails->send($matter, $signingRequest, $request->user(),
            $request->validated('idempotency_key'), $request->validated('link_expires_in_days'));

        return $this->outcome($delivery, $request->filled('idempotency_key'));
    }

    public function documentRequestHistory(Matter $matter, DocumentRequest $documentRequest): AnonymousResourceCollection
    {
        Gate::authorize('view', $documentRequest);

        return EmailDeliveryResource::collection(EmailDelivery::query()->where('document_request_id', $documentRequest->id)
            ->with(['link', 'creator:id,name'])->orderByDesc('id')->limit(100)->get());
    }

    public function signingHistory(Matter $matter, SigningRequest $signingRequest): AnonymousResourceCollection
    {
        Gate::authorize('view', $matter);

        return EmailDeliveryResource::collection(EmailDelivery::query()->where('signing_request_id', $signingRequest->id)
            ->with(['link', 'creator:id,name'])->orderByDesc('id')->limit(100)->get());
    }

    public function matterHistory(Request $request, Matter $matter): AnonymousResourceCollection
    {
        Gate::authorize('view', $matter);
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        return EmailDeliveryResource::collection(EmailDelivery::query()->where('matter_id', $matter->id)
            ->with(['link', 'creator:id,name'])->orderByDesc('id')->paginate($perPage));
    }

    /**
     * Sends a test message to the signed-in administrator's OWN address (no other recipient is possible) to
     * check the transport. Rate limited; recorded like every other e-mail.
     */
    public function testEmail(Request $request, MailComposer $composer): JsonResponse
    {
        $this->deliveries->assertAvailable();
        $user = $request->user();
        if (! EmailDeliveryService::isValidAddress($user->email)) {
            throw ValidationException::withMessages(['recipient' => ['Your account has no valid e-mail address.']]);
        }
        $delivery = DB::transaction(fn () => $this->deliveries->claim([
            'communication_type' => EmailCommunicationType::Test,
            'initiated_by' => EmailTrigger::Manual,
            'recipient_email' => $user->email,
            'recipient_name' => EmailDeliveryService::cleanName($user->name),
            'subject' => $composer->subject(EmailCommunicationType::Test),
            'idempotency_key' => 'manual:test:'.$user->id.':'.Str::uuid(),
            'created_by' => $user->id,
        ]));
        $outcome = $this->deliveries->send($delivery, new ClientActionMail($composer->test($delivery->recipient_name), $delivery->uuid));
        if (! $outcome->accepted) {
            $delivery = $this->deliveries->markFailed($delivery, $outcome->failure, null, $user, false, 'Test e-mail');
        } else {
            $delivery = DB::transaction(function () use ($delivery, $outcome) {
                $locked = EmailDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
                $locked->forceFill(['status' => EmailDeliveryStatus::Accepted, 'accepted_at' => now(), 'provider_message_id' => $outcome->messageId])->save();

                return $locked;
            });
        }

        return $this->outcome($delivery, false);
    }

    private function outcome(EmailDelivery $delivery, bool $mayBeReplay): JsonResponse
    {
        $delivery->loadMissing(['link', 'creator:id,name']);
        $fresh = $delivery->wasRecentlyCreated || $delivery->wasChanged('status') || $delivery->status === EmailDeliveryStatus::Failed;
        $resource = new EmailDeliveryResource($delivery);

        return match ($delivery->status) {
            EmailDeliveryStatus::Accepted => response()->json([
                'data' => $resource,
                'message' => 'E-mail accepted by the configured mail server.',
                'replayed' => $mayBeReplay && ! $fresh,
            ], $mayBeReplay && ! $fresh ? 200 : 201),
            EmailDeliveryStatus::Failed => response()->json([
                'data' => $resource,
                'code' => 'email_failed',
                'message' => 'E-mail could not be sent. No successful delivery has been recorded. '.$delivery->failure_category?->label(),
            ], 502),
            default => response()->json(['data' => $resource, 'message' => 'This e-mail is still being processed.'], 200),
        };
    }
}
