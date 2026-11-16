<?php

namespace Tests\Feature\Phase2;

use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use App\Services\MatterService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Real concurrency: several independent PHP processes (separate database connections and
 * transactions) draw numbers from the same counter at the same time.
 */
class MatterNumberConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2099; // a year no other test uses

    private const WORKERS = 4;

    private const PER_WORKER = 25;

    #[Test]
    public function concurrent_processes_never_receive_the_same_matter_number(): void
    {
        $this->cleanup();

        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
        ]);
        $script = base_path('tests/Support/matter-number-worker.php');

        $processes = [];
        $pipes = [];
        for ($w = 0; $w < self::WORKERS; $w++) {
            $processes[$w] = proc_open(
                [PHP_BINARY, $script, (string) self::PER_WORKER, (string) self::YEAR],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$w],
                base_path(),
                $env,
            );
            $this->assertIsResource($processes[$w]);
        }

        $numbers = [];
        $errors = '';
        foreach ($processes as $w => $process) {
            $numbers = array_merge($numbers, array_filter(explode("\n", str_replace("\r", '', stream_get_contents($pipes[$w][1])))));
            $errors .= stream_get_contents($pipes[$w][2]);
            fclose($pipes[$w][1]);
            fclose($pipes[$w][2]);
            $this->assertSame(0, proc_close($process), "worker {$w} failed: {$errors}");
        }

        $total = self::WORKERS * self::PER_WORKER;
        $this->assertCount($total, $numbers, $errors);
        $this->assertCount($total, array_unique($numbers), 'duplicate matter numbers were issued');

        $expected = array_map(fn (int $i) => sprintf('LEX-%d-%04d', self::YEAR, $i), range(1, $total));
        sort($numbers);
        $this->assertSame($expected, $numbers, 'numbers must be gap-free when no creation fails');

        $this->cleanup();
    }

    #[Test]
    public function the_database_rejects_a_duplicate_number_even_without_the_generator(): void
    {
        $client = Client::factory()->create();
        $first = app(MatterService::class)->create(['title' => 'A', 'client_id' => $client->id], User::factory()->create());

        $this->expectException(UniqueConstraintViolationException::class);
        Matter::factory()->for($client)->create(['matter_number' => $first->matter_number]);
    }

    /** Worker commits happen outside the test transaction, so clean up on a separate connection. */
    private function cleanup(): void
    {
        config(['database.connections.pgsql_cleanup' => config('database.connections.pgsql')]);
        DB::connection('pgsql_cleanup')->table('matter_number_counters')->where('year', self::YEAR)->delete();
        DB::purge('pgsql_cleanup');
    }
}
