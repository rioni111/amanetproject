<?php

namespace App\Services\Requests;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Why a public link cannot be used, as a safe JSON answer { message, code }.
 *
 * Unknown, malformed, wrong-purpose and manually revoked/replaced tokens all produce the SAME 404
 * body, so the response never reveals whether a token (or request) exists. Only a holder of a real
 * token can see the friendlier lifecycle explanations (expired, used, closed).
 */
final class PublicLinkException extends RuntimeException
{
    private const MESSAGES = [
        'link_invalid' => [404, 'This link is not valid. It may have been mistyped, replaced by a newer link or withdrawn by the firm. Please contact the firm for a new link.'],
        'link_expired' => [410, 'This upload link has expired. Please ask the firm for a new link.'],
        'link_used' => [410, 'A document has already been submitted with this link. If you need to send another file, please ask the firm for a new link.'],
        'request_approved' => [410, 'The firm has already accepted the requested document. Nothing more is needed.'],
        'request_cancelled' => [410, 'The firm has closed this request. No upload is needed.'],
        'request_unavailable' => [410, 'This request is no longer available. Please contact the firm.'],
        'too_many_attempts' => [429, 'Too many attempts. Please wait a few minutes and try again.'],
    ];

    private function __construct(public readonly string $reason, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    /** Phase 5 signing links: same codes where they mean the same, plus signing-specific outcomes. */
    private const SIGNING_MESSAGES = [
        'link_invalid' => [404, 'This signing link is not valid. It may have been mistyped, replaced by a newer link or withdrawn by the firm. Please contact the firm for a new link.'],
        'link_expired' => [410, 'This signing link has expired. Please ask the firm for a new link.'],
        'already_signed' => [410, 'This document has already been signed with this link. No further action is needed.'],
        'request_cancelled' => [410, 'The firm has withdrawn this signing request. Please contact the firm if you have questions.'],
        'request_unavailable' => [410, 'This signing request is no longer available. Please contact the firm.'],
        'too_many_attempts' => [429, 'Too many attempts. Please wait a few minutes and try again.'],
    ];

    public static function because(string $reason): self
    {
        [$status, $message] = self::MESSAGES[$reason] ?? self::MESSAGES['link_invalid'];

        return new self($reason, $status, $message);
    }

    public static function signing(string $reason): self
    {
        [$status, $message] = self::SIGNING_MESSAGES[$reason] ?? self::SIGNING_MESSAGES['link_invalid'];

        return new self($reason, $status, $message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason], $this->status)
            ->withHeaders(PublicResponseHeaders::HEADERS);
    }

    /** Expected outcome of untrusted input — not an application error worth logging. */
    public function report(): bool
    {
        return true;
    }
}
