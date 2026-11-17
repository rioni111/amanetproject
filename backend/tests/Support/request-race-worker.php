<?php

/**
 * Worker for DocumentRequestConcurrencyTest: boots the application in its own PHP process (own DB
 * connection and transactions), waits until a shared start time and then performs ONE action
 * through the real services. Prints "ok <detail>" or "refused <reason>".
 *
 * Usage: php request-race-worker.php <startAtMicrotime> <action> <args…>
 *   upload  <token> <label>                      client submission through ClientSubmissionService
 *   cancel  <matterId> <requestId> <userId>
 *   revoke  <matterId> <requestId> <userId>      revoke the request's usable link
 *   archive <matterId> <userId>
 *   approve|correct <matterId> <requestId> <submissionId> <userId> <lockVersion>
 */

use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\User;
use App\Services\MatterService;
use App\Services\Requests\ClientSubmissionService;
use App\Services\Requests\DocumentRequestService;
use App\Services\Requests\DocumentReviewService;
use App\Services\Requests\PublicLinkException;
use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\PdfFactory;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$startAt = (float) $argv[1];
$action = $argv[2];
$args = array_slice($argv, 3);

// Prepare everything first (files, models), then start together.
$prepared = match ($action) {
    'upload' => [
        SecureAccessLink::query()->with(['documentRequest.matter', 'documentRequest.client'])->where('token_hash', SecureAccessLinkService::hashToken($args[0]))->firstOrFail(),
        PdfFactory::upload("race-{$args[1]}.pdf", ["Race upload {$args[1]} ".bin2hex(random_bytes(4))]),
    ],
    default => null,
};
while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $result = match ($action) {
        'upload' => 'ok '.$app->make(ClientSubmissionService::class)->submit($prepared[0], $prepared[1], null)->submission_number,
        'cancel' => 'ok '.$app->make(DocumentRequestService::class)->cancel(Matter::findOrFail($args[0]), DocumentRequest::findOrFail($args[1]), User::findOrFail($args[2]), null)->status->value,
        'revoke' => (function () use ($app, $args) {
            $request = DocumentRequest::findOrFail($args[1]);
            $link = SecureAccessLink::query()->where('document_request_id', $request->id)->whereNull('revoked_at')->whereNull('consumed_at')->first();
            if ($link === null) {
                return 'refused no_usable_link';
            }

            return 'ok '.$app->make(DocumentRequestService::class)->revokeLink(Matter::findOrFail($args[0]), $request, $link, User::findOrFail($args[2]))->revocation_reason->value;
        })(),
        'archive' => 'ok '.$app->make(MatterService::class)->archive(Matter::findOrFail($args[0]), User::findOrFail($args[1]))->status->value,
        'approve' => 'ok '.$app->make(DocumentReviewService::class)->approve(Matter::findOrFail($args[0]), DocumentRequest::findOrFail($args[1]), (int) $args[2], User::findOrFail($args[3]), (int) $args[4])->status->value,
        'correct' => 'ok '.$app->make(DocumentReviewService::class)->requestCorrection(Matter::findOrFail($args[0]), DocumentRequest::findOrFail($args[1]), (int) $args[2], 'Please include every page of the agreement.', User::findOrFail($args[3]), (int) $args[4], null)->link->id,
    };
} catch (PublicLinkException $e) {
    $result = 'refused '.$e->reason;
} catch (HttpExceptionInterface $e) {
    $result = 'refused http_'.$e->getStatusCode();
} catch (ValidationException $e) {
    $result = 'refused validation';
}

fwrite(STDOUT, $result.PHP_EOL);
