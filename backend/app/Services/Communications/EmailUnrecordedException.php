<?php

namespace App\Services\Communications;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 7 (P7-001): the SMTP server accepted the message, but recording the outcome failed (database error
 * in the finish transaction). Nothing is lost for the client: the link's hash was reserved before sending, so
 * the link is activated when the client first opens it. The delivery stays "sending" until the scheduler's
 * stale-send recovery closes it as "interrupted" (outcome unknown), which is what the history will show.
 */
class EmailUnrecordedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(500, 'The mail server accepted the e-mail, but recording the result failed. The link in the e-mail will work when the client opens it. Check the e-mail history in a few minutes before sending again.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'email_unrecorded'], 500);
    }
}
