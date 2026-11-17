<?php

namespace App\Services\Signing;

use App\Enums\SecureLinkPurpose;
use App\Enums\SigningCancellationReason;
use App\Enums\SigningStatus;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use App\Services\Communications\EmailLinkActivationService;
use App\Services\Requests\PublicLinkException;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Resolves the X-Signing-Token header into the ONE signing link it authorises (purpose document_signing
 * only: an upload token is "unknown" here, a signing token is unknown to the upload endpoints). The same
 * rules are re-applied under row locks by every state change (view, start, complete).
 */
class PublicSigningLinkResolver
{
    public const HEADER = 'X-Signing-Token';

    private const LOCKOUT_SECONDS = 900;

    public function __construct(private readonly EmailLinkActivationService $activation) {}

    public function resolve(Request $request): SecureAccessLink
    {
        $limiterKey = 'public-signing-invalid:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, max(1, (int) config('lexora.public_links.invalid_attempts', 20)))) {
            throw PublicLinkException::signing('too_many_attempts');
        }

        $token = (string) $request->header(self::HEADER, '');
        $find = fn () => SecureAccessLink::query()
            ->where('token_hash', SecureAccessLinkService::hashToken($token))
            ->where('purpose', SecureLinkPurpose::DocumentSigning->value)
            ->with(['signingRequest.matter', 'signingRequest.document', 'signingRequest.sourceVersion'])
            ->first();
        $link = SecureAccessLinkService::isWellFormed($token) ? $find() : null;
        // Phase 7: the token of an e-mail whose sending was never confirmed is activated on first use.
        if ($link === null && SecureAccessLinkService::isWellFormed($token)
            && $this->activation->activate($token, SecureLinkPurpose::DocumentSigning) !== null) {
            $link = $find();
        }

        if ($link === null || $link->signingRequest === null || ! hash_equals($link->token_hash, SecureAccessLinkService::hashToken($token))) {
            RateLimiter::hit($limiterKey, self::LOCKOUT_SECONDS);
            throw PublicLinkException::signing('link_invalid');
        }

        try {
            $this->assertUsable($link, $link->signingRequest, $link->signingRequest->matter);
        } catch (PublicLinkException $e) {
            if ($e->reason === 'link_invalid') {
                RateLimiter::hit($limiterKey, self::LOCKOUT_SECONDS);
            }
            throw $e;
        }

        return $link;
    }

    /** Every rule a signing link must satisfy right now. Lifecycle outcomes are explained; manual revocation is not. */
    public function assertUsable(SecureAccessLink $link, SigningRequest $request, Matter $matter): void
    {
        if ($link->purpose !== SecureLinkPurpose::DocumentSigning || $link->signing_request_id !== $request->id
            || $request->matter_id !== $matter->id || $link->matter_id !== $matter->id || ! $request->isClientSigner()) {
            throw PublicLinkException::signing('link_invalid');
        }
        if ($request->status === SigningStatus::Signed || $link->consumed_at !== null) {
            throw PublicLinkException::signing('already_signed');
        }
        if ($request->status === SigningStatus::Cancelled) {
            // Archiving closes a request for good (restoring the matter does not revive it); explain it the same way.
            $archived = $matter->isArchived() || $request->cancellation_reason === SigningCancellationReason::MatterArchived;
            throw PublicLinkException::signing($archived ? 'request_unavailable' : 'request_cancelled');
        }
        if ($matter->isArchived()) {
            throw PublicLinkException::signing('request_unavailable');
        }
        if ($link->revoked_at !== null) {
            throw PublicLinkException::signing($link->revocation_reason?->isLifecycleClosure() ? 'request_unavailable' : 'link_invalid');
        }
        if ($link->expires_at->isPast()) {
            throw PublicLinkException::signing('link_expired');
        }
    }
}
