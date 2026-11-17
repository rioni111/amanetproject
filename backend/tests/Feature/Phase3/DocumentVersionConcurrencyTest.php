<?php

namespace Tests\Feature\Phase3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Real concurrency: independent PHP processes (separate connections and transactions) upload versions
 * of ONE document at the same time through DocumentService. Fixtures are committed on a separate
 * connection because the workers cannot see the test's own transaction.
 */
class DocumentVersionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const WORKERS = 4;

    private const PER_WORKER = 5;

    private const STORAGE_DIR = 'phpunit-concurrency';

    #[Test]
    public function concurrent_uploads_get_unique_sequential_versions_and_intact_files(): void
    {
        config(['database.connections.pgsql_fixture' => config('database.connections.pgsql')]);
        $db = DB::connection('pgsql_fixture');
        $root = storage_path('app/private/'.self::STORAGE_DIR);
        File::deleteDirectory($root);

        $userId = $db->table('users')->insertGetId(['name' => 'Concurrency Admin', 'email' => Str::uuid().'@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'admin']);
        $clientId = $db->table('clients')->insertGetId(['name' => 'Concurrency Client', 'client_type' => 'organization', 'created_at' => now(), 'updated_at' => now()]);
        $matterId = $db->table('matters')->insertGetId(['matter_number' => 'LEX-2098-'.random_int(1000, 9999), 'client_id' => $clientId, 'title' => 'Concurrency', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $documentId = $db->table('documents')->insertGetId(['uuid' => (string) Str::uuid(), 'matter_id' => $matterId, 'title' => 'Contended document', 'category' => 'contracts', 'created_at' => now(), 'updated_at' => now()]);

        try {
            $env = array_merge(getenv(), [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
                'DOCUMENTS_STORAGE_DIR' => self::STORAGE_DIR,
            ]);
            $processes = [];
            $pipes = [];
            for ($w = 0; $w < self::WORKERS; $w++) {
                $processes[$w] = proc_open(
                    [PHP_BINARY, base_path('tests/Support/document-version-worker.php'), (string) $matterId, (string) $documentId, (string) $userId, (string) self::PER_WORKER, (string) $w],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w], base_path(), $env,
                );
            }
            $lines = [];
            $errors = '';
            foreach ($processes as $w => $process) {
                $lines = array_merge($lines, array_filter(explode("\n", str_replace("\r", '', stream_get_contents($pipes[$w][1])))));
                $errors .= stream_get_contents($pipes[$w][2]);
                fclose($pipes[$w][1]);
                fclose($pipes[$w][2]);
                $this->assertSame(0, proc_close($process), "worker {$w} failed: {$errors}");
            }

            $total = self::WORKERS * self::PER_WORKER;
            $numbers = array_map(fn (string $l) => (int) explode(' ', $l)[0], $lines);
            sort($numbers);
            $this->assertSame(range(1, $total), $numbers, 'versions 1..N, no duplicates and no gaps');

            $versions = $db->table('document_versions')->where('document_id', $documentId)->orderBy('version_number')->get();
            $this->assertCount($total, $versions);
            foreach ($versions as $version) {
                $file = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $version->storage_path);
                $this->assertFileExists($file);
                $this->assertSame($version->sha256_checksum, hash_file('sha256', $file), "v{$version->version_number} content intact");
            }
            $document = $db->table('documents')->find($documentId);
            $this->assertSame($total, (int) $document->latest_version_number);
            $this->assertSame((int) $versions->last()->id, (int) $document->current_version_id);
            $this->assertSame([], File::exists($root.'/_staging') ? File::files($root.'/_staging') : []);
        } finally {
            // Test database only: append-only/immutable triggers are lifted inside one transaction.
            $db->transaction(function () use ($db, $documentId, $matterId, $clientId, $userId) {
                $db->statement('ALTER TABLE activity_events DISABLE TRIGGER activity_events_no_update_delete');
                $db->statement('ALTER TABLE document_versions DISABLE TRIGGER document_versions_no_update_delete');
                $db->table('activity_events')->where('matter_id', $matterId)->delete();
                $db->table('documents')->where('id', $documentId)->update(['current_version_id' => null, 'latest_version_number' => 0]);
                $db->table('document_versions')->where('document_id', $documentId)->delete();
                $db->table('documents')->where('id', $documentId)->delete();
                $db->table('matters')->where('id', $matterId)->delete();
                $db->table('clients')->where('id', $clientId)->delete();
                $db->table('users')->where('id', $userId)->delete();
                $db->statement('ALTER TABLE activity_events ENABLE TRIGGER activity_events_no_update_delete');
                $db->statement('ALTER TABLE document_versions ENABLE TRIGGER document_versions_no_update_delete');
            });
            DB::purge('pgsql_fixture');
            File::deleteDirectory($root);
        }
    }
}
