<?php

/**
 * Worker for MatterNumberConcurrencyTest: boots the application in a separate PHP process and
 * draws matter numbers in its own transactions, holding each one briefly to widen the race window.
 *
 * Usage: php matter-number-worker.php <count> <year>
 * The database comes from the environment passed by the test (the isolated amanet_test database).
 */

use App\Services\MatterNumberGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$count, $year] = [(int) ($argv[1] ?? 10), (int) ($argv[2] ?? 2099)];
$generator = $app->make(MatterNumberGenerator::class);

for ($i = 0; $i < $count; $i++) {
    $number = DB::transaction(function () use ($generator, $year) {
        $number = $generator->next($year);
        usleep(random_int(0, 4000)); // keep the counter row locked for a moment

        return $number;
    });
    fwrite(STDOUT, $number.PHP_EOL);
}
