<?php

/**
 * Worker for SigningConcurrencyTest: boots the application in its own PHP process (own connection and
 * transactions), waits for a shared start time and performs ONE action through the real services.
 * Prints "ok <detail>" or "refused <reason>".
 *
 * Usage: php signing-race-worker.php <startAt> <action> <args…>
 *   sign    <token>                              client completes (drawn signature, page 1)
 *   revoke  <matterId> <requestId> <userId>      revoke the usable link
 *   cancel  <matterId> <requestId> <userId>
 *   archive <matterId> <userId>
 *   adminsign <matterId> <requestId> <userId>
 */

use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\MatterService;
use App\Services\Requests\PublicLinkException;
use App\Services\Requests\SecureAccessLinkService;
use App\Services\Signing\SigningRequestService;
use App\Services\Signing\SigningService;
use App\Support\SigningConsent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$startAt = (float) $argv[1];
$action = $argv[2];
$args = array_slice($argv, 3);
$payload = [
    'method' => 'drawn', 'strokes' => [[[0.1, 0.5], [0.2, 0.3], [0.3, 0.2], [0.4, 0.6], [0.5, 0.8], [0.7, 0.5], [0.9, 0.3]]],
    'page' => 1, 'x' => 0.5, 'y' => 0.75, 'width' => 0.35, 'consent' => true, 'consent_version' => SigningConsent::VERSION,
    'confirmed_name' => 'Race Signer',
];
$link = $action === 'sign' ? SecureAccessLink::query()->where('token_hash', SecureAccessLinkService::hashToken($args[0]))->firstOrFail() : null;

while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $result = match ($action) {
        'sign' => 'ok '.$app->make(SigningService::class)->signAsClient($link, $payload, '127.0.0.1', 'race-worker')->signed_document_version_id,
        'adminsign' => 'ok '.$app->make(SigningService::class)->signAsAdmin(Matter::findOrFail($args[0]), SigningRequest::findOrFail($args[1]),
            [...$payload, 'confirmed_name' => null], User::findOrFail($args[2]), null, '127.0.0.1', 'race-worker')->signed_document_version_id,
        'revoke' => (function () use ($app, $args) {
            $link = SecureAccessLink::query()->where('signing_request_id', $args[1])->whereNull('revoked_at')->whereNull('consumed_at')->first();
            if ($link === null) {
                return 'refused no_usable_link';
            }

            return 'ok '.$app->make(SigningRequestService::class)->revokeLink(Matter::findOrFail($args[0]), SigningRequest::findOrFail($args[1]), $link, User::findOrFail($args[2]))->revocation_reason->value;
        })(),
        'cancel' => 'ok '.$app->make(SigningRequestService::class)->cancel(Matter::findOrFail($args[0]), SigningRequest::findOrFail($args[1]), User::findOrFail($args[2]), null)->status->value,
        'archive' => 'ok '.$app->make(MatterService::class)->archive(Matter::findOrFail($args[0]), User::findOrFail($args[1]))->status->value,
    };
} catch (PublicLinkException $e) {
    $result = 'refused '.$e->reason;
} catch (HttpExceptionInterface $e) {
    $result = 'refused http_'.$e->getStatusCode();
} catch (ValidationException $e) {
    $result = 'refused validation';
} catch (QueryException $e) {
    $result = 'refused db_'.$e->getCode();
}

fwrite(STDOUT, $result.PHP_EOL);
