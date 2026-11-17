<?php

namespace App\Services\Requests;

use App\Enums\DocumentRequestStatus;
use App\Enums\SecureLinkPurpose;
use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Resolves the upload token of a public request into the ONE link it authorises, and decides
 * whether that link may be used now. The same rules are re-applied under row locks when a file is
 * committed (ClientSubmissionService), so a link revoked, used or closed while an upload was in flight
 * is still refused.
 *
 * The token is read from the X-Upload-Token header — never from the URL — so it does not appear in
 * the API's request log. Only document_upload links are accepted here: a signing token (Phase 5) is
 * "unknown" to these endpoints.
 */
class PublicUploadLinkResolver
{
    public const HEADER = 'X-Upload-Token';

    private const LOCKOUT_SECONDS = 900;

    public function resolve(Request $request): SecureAccessLink
    {
        $limiterKey = 'public-link-invalid:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, max(1, (int) config('lexora.public_links.invalid_attempts', 20)))) {
            throw PublicLinkException::because('too_many_attempts');
        }

        $token = (string) $request->header(self::HEADER, '');
        $link = SecureAccessLinkService::isWellFormed($token)
            ? SecureAccessLink::query()
                ->where('token_hash', SecureAccessLinkService::hashToken($token))
                ->where('purpose', SecureLinkPurpose::DocumentUpload->value)
                ->with(['documentRequest.matter', 'documentRequest.client:id,name'])
                ->first()
            : null;

        if ($link === null || ! hash_equals($link->token_hash, SecureAccessLinkService::hashToken($token))) {
            RateLimiter::hit($limiterKey, self::LOCKOUT_SECONDS);
            throw PublicLinkException::because('link_invalid');
        }

        try {
            $this->assertUsable($link, $link->documentRequest, $link->documentRequest->matter);
        } catch (PublicLinkException $e) {
            if ($e->reason === 'link_invalid') {
                RateLimiter::hit($limiterKey, self::LOCKOUT_SECONDS);
            }
            throw $e;
        }

        return $link;
    }

    /** Opening a link never consumes it; the first opening is recorded once for the firm's overview. */
    public function markOpened(SecureAccessLink $link): void
    {
        if ($link->first_opened_at === null) {
            SecureAccessLink::query()->whereKey($link->id)->whereNull('first_opened_at')
                ->update(['first_opened_at' => Carbon::now()]);
        }
    }

    /**
     * Every rule a link must satisfy to accept an upload. Order matters for the message: lifecycle
     * closures are explained, manual revocations look exactly like unknown tokens.
     */
    public function assertUsable(SecureAccessLink $link, DocumentRequest $request, Matter $matter): void
    {
        if ($link->purpose !== SecureLinkPurpose::DocumentUpload || $link->document_request_id !== $request->id
            || $request->matter_id !== $matter->id || $link->matter_id !== $matter->id) {
            throw PublicLinkException::because('link_invalid');
        }
        // The request must still belong to the matter's current client (defence in depth: changing the
        // client of a matter with open requests is also refused by the service and a database trigger).
        if ($request->client_id !== $matter->client_id) {
            throw PublicLinkException::because('link_invalid');
        }
        if ($request->status === DocumentRequestStatus::Approved) {
            throw PublicLinkException::because('request_approved');
        }
        if ($request->status === DocumentRequestStatus::Cancelled) {
            throw PublicLinkException::because('request_cancelled');
        }
        if ($matter->isArchived()) {
            throw PublicLinkException::because('request_unavailable');
        }
        if ($link->revoked_at !== null) {
            throw PublicLinkException::because($link->revocation_reason?->isLifecycleClosure() ? 'request_unavailable' : 'link_invalid');
        }
        if ($link->consumed_at !== null || $link->successful_uses >= $link->max_uses) {
            throw PublicLinkException::because('link_used');
        }
        if ($link->expires_at->isPast()) {
            throw PublicLinkException::because('link_expired');
        }
        if (! $request->status->acceptsUploads()) {
            throw PublicLinkException::because('link_used'); // a submission is already waiting for review
        }
    }
}
