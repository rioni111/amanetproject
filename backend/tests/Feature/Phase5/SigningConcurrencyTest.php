<?php

namespace Tests\Feature\Phase5;

use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\TestCase;

/**
 * Scenario H: independent PHP processes (separate connections/transactions) start at the same instant and
 * act through the real services. Fixtures are committed on a separate connection and removed afterwards.
 */
class SigningConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const STORAGE_DIR = 'phpunit-signing-race';

    private Connection $db;

    private string $root;

    private int $user;

    private int $client;

    /** @var list<int> */
    private array $matters = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.pgsql_fixture' => config('database.connections.pgsql')]);
        $this->db = DB::connection('pgsql_fixture');
        $this->root = storage_path('app/private/'.self::STORAGE_DIR);
        File::deleteDirectory($this->root);
        $this->user = $this->db->table('users')->insertGetId(['name' => 'Race Admin', 'email' => Str::uuid().'@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'admin']);
        $this->client = $this->db->table('clients')->insertGetId(['name' => 'Race Client', 'client_type' => 'organization', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function four_simultaneous_signatures_with_one_link_commit_exactly_one_signed_pdf(): void
    {
        [$request, $token] = $this->startedClientRequest();
        $results = $this->race(array_fill(0, 4, ['sign', $token]));

        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok '))), implode(' | ', $results));
        foreach (array_filter($results, fn ($r) => ! str_starts_with($r, 'ok ')) as $refused) {
            $this->assertContains($refused, ['refused already_signed', 'refused http_409'], implode(' | ', $results));
        }
        $this->assertSame(1, $this->db->table('signature_records')->where('signing_request_id', $request)->count());
        $this->assertSame(1, $this->db->table('document_versions')->where('kind', 'signed')->count());
        $this->assertSame('signed', $this->db->table('signing_requests')->where('id', $request)->value('status'));
        $this->assertStorageMatchesDatabase();
    }

    #[Test]
    public function signing_racing_revocation_cancellation_and_archiving_stays_consistent(): void
    {
        foreach (['revoke', 'cancel', 'archive'] as $competitor) {
            [$request, $token, $matter] = $this->startedClientRequest();
            $other = $competitor === 'archive' ? ['archive', (string) $matter, (string) $this->user] : [$competitor, (string) $matter, (string) $request, (string) $this->user];
            $results = $this->race([['sign', $token], ['sign', $token], $other]);
            $signedOk = count(array_filter(array_slice($results, 0, 2), fn ($r) => str_starts_with($r, 'ok ')));
            $context = $competitor.': '.implode(' | ', $results);

            $this->assertLessThanOrEqual(1, $signedOk, $context);
            $status = $this->db->table('signing_requests')->where('id', $request)->value('status');
            $records = $this->db->table('signature_records')->where('signing_request_id', $request)->count();
            $this->assertSame($signedOk, $records, $context);
            $this->assertSame($signedOk === 1 ? 'signed' : ($competitor === 'revoke' ? 'started' : 'cancelled'), $status, $context);
            // Never a usable link left behind for a finished request.
            if ($status !== 'started') {
                $this->assertSame(0, $this->db->table('secure_access_links')->where('signing_request_id', $request)->whereNull('revoked_at')->whereNull('consumed_at')->count(), $context);
            }
        }
        $this->assertStorageMatchesDatabase();
    }

    #[Test]
    public function two_simultaneous_administrator_signatures_produce_one_signed_version(): void
    {
        $matter = $this->matter();
        [$document, $version] = $this->document($matter);
        $request = $this->db->table('signing_requests')->insertGetId([
            'uuid' => (string) Str::uuid(), 'matter_id' => $matter, 'document_id' => $document, 'document_version_id' => $version,
            'signer_type' => 'admin', 'signer_user_id' => $this->user, 'signer_name' => 'Race Admin', 'status' => 'ready',
            'delivery_status' => 'not_shared', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $results = $this->race([['adminsign', (string) $matter, (string) $request, (string) $this->user], ['adminsign', (string) $matter, (string) $request, (string) $this->user]]);

        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok '))), implode(' | ', $results));
        $this->assertSame(1, $this->db->table('signature_records')->count());
        $this->assertStorageMatchesDatabase();
    }

    // ------------------------------------------------------------------ fixtures

    private function matter(): int
    {
        $id = $this->db->table('matters')->insertGetId(['matter_number' => 'LEX-2096-'.random_int(10000, 99999), 'client_id' => $this->client,
            'title' => 'Signing race', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->matters[] = $id;

        return $id;
    }

    /** @return array{0: int, 1: int} document id and its current (source) version id, with the file on disk */
    private function document(int $matter): array
    {
        $bytes = PdfFactory::bytes(['Race agreement '.Str::random(8)]);
        $docUuid = (string) Str::uuid();
        $verUuid = (string) Str::uuid();
        $path = "{$matter}/{$docUuid}/{$verUuid}.pdf";
        File::ensureDirectoryExists(dirname($this->root.'/'.$path));
        file_put_contents($this->root.'/'.$path, $bytes);
        $document = $this->db->table('documents')->insertGetId(['uuid' => $docUuid, 'matter_id' => $matter, 'title' => 'Race agreement',
            'category' => 'agreements', 'created_at' => now(), 'updated_at' => now()]);
        $version = $this->db->table('document_versions')->insertGetId(['uuid' => $verUuid, 'document_id' => $document, 'version_number' => 1,
            'kind' => 'upload', 'origin' => 'admin', 'original_filename' => 'race.pdf', 'storage_disk' => 'documents', 'storage_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'sha256_checksum' => hash('sha256', $bytes), 'created_at' => now()]);
        $this->db->table('documents')->where('id', $document)->update(['current_version_id' => $version, 'latest_version_number' => 1]);

        return [$document, $version];
    }

    /** @return array{0: int, 1: string, 2: int} a client signing request in "started" state, its raw token and matter */
    private function startedClientRequest(): array
    {
        $matter = $this->matter();
        [$document, $version] = $this->document($matter);
        $request = $this->db->table('signing_requests')->insertGetId([
            'uuid' => (string) Str::uuid(), 'matter_id' => $matter, 'document_id' => $document, 'document_version_id' => $version,
            'signer_type' => 'client', 'client_id' => $this->client, 'signer_name' => 'Race Signer', 'status' => 'started',
            'delivery_status' => 'not_shared', 'viewed_at' => now()->subMinute(), 'started_at' => now()->subSeconds(30),
            'created_at' => now()->subMinutes(2), 'updated_at' => now(),
        ]);
        $token = SecureAccessLinkService::generateToken();
        $this->db->table('secure_access_links')->insert(['uuid' => (string) Str::uuid(), 'purpose' => 'document_signing', 'matter_id' => $matter,
            'signing_request_id' => $request, 'token_hash' => SecureAccessLinkService::hashToken($token), 'expires_at' => now()->addDays(7),
            'max_uses' => 1, 'successful_uses' => 0, 'created_by' => $this->user, 'created_at' => now()->subMinutes(2), 'updated_at' => now()]);

        return [$request, $token, $matter];
    }

    /** @param list<list<string>> $commands @return list<string> */
    private function race(array $commands): array
    {
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DOCUMENTS_STORAGE_DIR' => self::STORAGE_DIR]);
        $startAt = sprintf('%.6F', microtime(true) + 2.5);
        $processes = [];
        $pipes = [];
        foreach ($commands as $i => $command) {
            $processes[$i] = proc_open([PHP_BINARY, base_path('tests/Support/signing-race-worker.php'), $startAt, ...$command],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
        }
        $results = [];
        foreach ($processes as $i => $process) {
            $out = trim(str_replace("\r", '', stream_get_contents($pipes[$i][1])));
            $err = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $this->assertSame(0, proc_close($process), "worker {$i} failed: {$out} {$err}");
            $results[$i] = $out;
        }

        return $results;
    }

    private function assertStorageMatchesDatabase(): void
    {
        $files = collect(File::allFiles($this->root))->map(fn ($f) => str_replace('\\', '/', $f->getRelativePathname()))->sort()->values()->all();
        $expected = $this->db->table('document_versions')->whereIn('document_id', $this->db->table('documents')->whereIn('matter_id', $this->matters)->pluck('id'))
            ->pluck('storage_path')->sort()->values()->all();
        $this->assertSame($expected, $files, 'every stored file is a committed version; no staging or orphaned signed files');
    }

    private function cleanup(): void
    {
        $matters = $this->matters;
        $triggers = ['activity_events activity_events_no_update_delete', 'document_versions document_versions_no_update_delete',
            'signature_records signature_records_no_update_delete', 'signing_requests signing_requests_update_guard'];
        $this->db->transaction(function () use ($matters, $triggers) {
            foreach ($triggers as $t) {
                [$table, $trigger] = explode(' ', $t);
                $this->db->statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
            }
            $documents = $this->db->table('documents')->whereIn('matter_id', $matters)->pluck('id');
            $this->db->table('activity_events')->whereIn('matter_id', $matters)->delete();
            $this->db->table('signature_records')->whereIn('document_id', $documents)->delete();
            $this->db->table('secure_access_links')->whereIn('matter_id', $matters)->delete();
            $this->db->table('signing_requests')->whereIn('matter_id', $matters)->delete();
            $this->db->table('documents')->whereIn('id', $documents)->update(['current_version_id' => null, 'latest_version_number' => 0]);
            $this->db->table('document_versions')->whereIn('document_id', $documents)->delete();
            $this->db->table('documents')->whereIn('id', $documents)->delete();
            $this->db->table('matters')->whereIn('id', $matters)->delete();
            $this->db->table('clients')->where('id', $this->client)->delete();
            // Phase 6: client signatures notify the (committed) race administrator.
            $this->db->table('notifications')->where('notifiable_type', 'user')->where('notifiable_id', $this->user)->delete();
            $this->db->table('users')->where('id', $this->user)->delete();
            foreach ($triggers as $t) {
                [$table, $trigger] = explode(' ', $t);
                $this->db->statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
            }
        });
        DB::purge('pgsql_fixture');
        File::deleteDirectory($this->root);
    }
}
