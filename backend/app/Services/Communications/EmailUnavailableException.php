<?php

namespace App\Services\Communications;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * E-mail cannot be sent with the current configuration (switched off, no real transport, no sender, or no
 * public HTTPS address in production). 503 with a plain, secret-free explanation; the UI keeps Copy link.
 */
class EmailUnavailableException extends HttpException
{
    public function __construct(string $reason)
    {
        parent::__construct(503, $reason);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'email_unavailable'], 503);
    }
}
