<?php

/**
 * Worker for CommunicationConcurrencyTest: boots the application in its own PHP process (own connection,
 * own transactions), waits for a shared start time and performs ONE action through the real services.
 * Mail uses the "array" transport (accepts every message in memory): these tests are about claims, locks
 * and idempotency — real SMTP is covered by SmtpIntegrationTest. Prints "ok <detail>" or "refused <reason>".
 *
 *   send-doc  <matterId> <requestId> <userId>      administrator sends the request e-mail
 *   send-sign <matterId> <signingId> <userId>      administrator sends the signing invitation
 *   cancel-doc <matterId> <requestId> <userId>
 *   scheduler                                      one scheduler run (prints reminders sent)
 *   sign <token>                                   client completes a started signing request
 */

use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\Communications\CommunicationScheduler;
use App\Services\Communications\DocumentRequestEmailService;
use App\Services\Communications\SigningEmailService;
use App\Services\Requests\DocumentRequestService;
use App\Services\Requests\PublicLinkException;
use App\Services\Requests\SecureAccessLinkService;
use App\Services\Signing\SigningService;
use App\Support\SigningConsent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['mail.default' => 'array']);

$startAt = (float) $argv[1];
$action = $argv[2];
$args = array_slice($argv, 3);
$link = $action === 'sign' ? SecureAccessLink::query()->where('token_hash', SecureAccessLinkService::hashToken($args[0]))->firstOrFail() : null;

while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $result = match ($action) {
        'send-doc' => 'ok '.$app->make(DocumentRequestEmailService::class)->send(Matter::findOrFail($args[0]), DocumentRequest::findOrFail($args[1]), User::findOrFail($args[2]))->status->value,
        'send-sign' => 'ok '.$app->make(SigningEmailService::class)->send(Matter::findOrFail($args[0]), SigningRequest::findOrFail($args[1]), User::findOrFail($args[2]))->status->value,
        'cancel-doc' => 'ok '.$app->make(DocumentRequestService::class)->cancel(Matter::findOrFail($args[0]), DocumentRequest::findOrFail($args[1]), User::findOrFail($args[2]), null)->status->value,
        'scheduler' => 'ok '.$app->make(CommunicationScheduler::class)->run()['document_reminders'],
        'sign' => 'ok '.$app->make(SigningService::class)->signAsClient($link, [
            'method' => 'drawn', 'strokes' => [[[0.1, 0.5], [0.2, 0.3], [0.3, 0.2], [0.4, 0.6], [0.5, 0.8], [0.7, 0.5], [0.9, 0.3]]],
            'page' => 1, 'x' => 0.5, 'y' => 0.75, 'width' => 0.35, 'consent' => true, 'consent_version' => SigningConsent::VERSION,
            'confirmed_name' => 'Race Signer',
        ], '127.0.0.1', 'race-worker')->status->value,
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
