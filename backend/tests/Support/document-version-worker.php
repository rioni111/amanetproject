<?php

/**
 * Worker for DocumentVersionConcurrencyTest: boots the application in its own PHP process (own DB
 * connection) and uploads new versions of one document through the real DocumentService.
 *
 * Usage: php document-version-worker.php <matterId> <documentId> <userId> <count> <worker>
 * Database and storage folder come from the environment passed by the test.
 */

use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Services\Documents\DocumentService;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\PdfFactory;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$matterId, $documentId, $userId, $count, $worker] = array_map('intval', array_slice($argv, 1, 5));
$service = $app->make(DocumentService::class);
$matter = Matter::query()->findOrFail($matterId);
$document = Document::query()->findOrFail($documentId);
$user = User::query()->findOrFail($userId);

for ($i = 0; $i < $count; $i++) {
    $file = PdfFactory::upload("revision-{$worker}-{$i}.pdf", ["Worker {$worker} revision {$i} ".bin2hex(random_bytes(4))]);
    $version = $service->addVersion($matter, $document, $file, $user);
    fwrite(STDOUT, $version->version_number.' '.$version->sha256_checksum.PHP_EOL);
}
