<?php

namespace Tests\Feature\Phase4;

use App\Services\Requests\SecureAccessLinkService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Real concurrency (scenario F): independent PHP processes — separate connections and transactions —
 * start the same instant and act through the real services. Fixtures are committed on a separate
 * connection because the workers cannot see the test's own transaction; they are removed afterwards.
 */
class DocumentRequestConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const STORAGE_DIR = 'phpunit-request-race';

    private Connection $db;

    private string $root;

    /** @var array{user: int, client: int, matters: list<int>} */
    private array $created = ['user' => 0, 'client' => 0, 'matters' => []];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.pgsql_fixture' => config('database.connections.pgsql')]);
        $this->db = DB::connection('pgsql_fixture');
        $this->root = storage_path('app/private/'.self::STORAGE_DIR);
        File::deleteDirectory($this->root);
        $this->created['user'] = $this->db->table('users')->insertGetId(['name' => 'Race Admin', 'email' => Str::uuid().'@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'admin']);
        $this->created['client'] = $this->db->table('clients')->insertGetId(['name' => 'Race Client', 'client_type' => 'organization', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function one_link_used_by_four_simultaneous_uploads_produces_exactly_one_submission(): void
    {
        $matter = $this->matter();
        [$request, $token] = $this->request($matter);

        $results = $this->race(array_map(fn ($i) => ['upload', $token, "w{$i}"], range(1, 4)));

        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok '))), implode(' | ', $results));
        $this->assertSame(3, count(array_filter($results, fn ($r) => $r === 'refused link_used')), implode(' | ', $results));
        $this->assertSame(1, $this->db->table('document_request_submissions')->where('document_request_id', $request)->count());
        $this->assertSame(1, $this->db->table('document_versions')->count());
        $link = $this->db->table('secure_access_links')->where('document_request_id', $request)->first();
        $this->assertSame(1, (int) $link->successful_uses);
        $this->assertNotNull($link->consumed_at);
        $this->assertSame('submitted', $this->db->table('document_requests')->where('id', $request)->value('status'));
        $this->assertStorageMatchesDatabase();
    }

    #[Test]
    public function uploads_racing_cancellation_revocation_and_archiving_leave_a_consistent_state(): void
    {
        foreach (['cancel', 'revoke', 'archive'] as $competitor) {
            $matter = $this->matter();
            [$request, $token] = $this->request($matter);
            $args = match ($competitor) {
                'cancel', 'revoke' => [$competitor, (string) $matter, (string) $request, (string) $this->created['user']],
                'archive' => ['archive', (string) $matter, (string) $this->created['user']],
            };
            $results = $this->race([['upload', $token, 'a'], ['upload', $token, 'b'], $args]);
            $uploads = array_slice($results, 0, 2);
            $succeeded = count(array_filter($uploads, fn ($r) => str_starts_with($r, 'ok ')));
            $context = $competitor.': '.implode(' | ', $results);

            $this->assertLessThanOrEqual(1, $succeeded, $context);
            $submissions = $this->db->table('document_request_submissions')->where('document_request_id', $request)->get();
            $this->assertCount($succeeded, $submissions, $context);
            // No link can still be used afterwards (either consumed by the upload or revoked).
            $this->assertSame(0, $this->db->table('secure_access_links')->where('document_request_id', $request)->whereNull('revoked_at')->whereNull('consumed_at')->count(), $context);
            $status = $this->db->table('document_requests')->where('id', $request)->value('status');
            if ($competitor === 'cancel') {
                $this->assertSame('ok cancelled', $results[2], $context);
                $this->assertSame('cancelled', $status, $context);
                foreach ($submissions as $s) {
                    $this->assertSame('cancelled', $s->review_status, $context);
                }
            } else {
                $this->assertSame($succeeded === 1 ? 'submitted' : 'pending', $status, $context);
            }
            if ($competitor === 'archive') {
                $this->assertSame('ok archived', $results[2], $context);
            }
        }
        $this->assertStorageMatchesDatabase();
    }

    #[Test]
    public function two_administrators_deciding_at_once_cannot_both_win(): void
    {
        $matter = $this->matter();
        [$request, $token] = $this->request($matter);
        $this->assertSame(['ok 1'], $this->race([['upload', $token, 'x']]));
        $submission = $this->db->table('document_request_submissions')->where('document_request_id', $request)->value('id');
        $this->db->table('document_request_submissions')->where('id', $submission)->update(['review_status' => 'in_review', 'review_started_at' => now()]);
        $this->db->table('document_requests')->where('id', $request)->update(['status' => 'under_review', 'review_started_at' => now(), 'lock_version' => 5]);

        $results = $this->race([
            ['approve', (string) $matter, (string) $request, (string) $submission, (string) $this->created['user'], '5'],
            ['correct', (string) $matter, (string) $request, (string) $submission, (string) $this->created['user'], '5'],
        ]);

        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok '))), implode(' | ', $results));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'refused http_409')), implode(' | ', $results));
        $final = $this->db->table('document_requests')->where('id', $request)->value('status');
        $review = $this->db->table('document_request_submissions')->where('id', $submission)->value('review_status');
        $this->assertContains([$final, $review], [['approved', 'approved'], ['correction_requested', 'correction_requested']]);
    }

    // ------------------------------------------------------------------ helpers

    private function matter(): int
    {
        $id = $this->db->table('matters')->insertGetId([
            'matter_number' => 'LEX-2097-'.random_int(10000, 99999), 'client_id' => $this->created['client'], 'title' => 'Race matter',
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->created['matters'][] = $id;

        return $id;
    }

    /** @return array{0: int, 1: string} request id and raw upload token */
    private function request(int $matter): array
    {
        $request = $this->db->table('document_requests')->insertGetId([
            'uuid' => (string) Str::uuid(), 'matter_id' => $matter, 'client_id' => $this->created['client'], 'title' => 'Race request',
            'instructions' => 'Upload the agreement.', 'requested_category' => 'agreements', 'status' => 'pending',
            'created_by' => $this->created['user'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = SecureAccessLinkService::generateToken();
        $this->db->table('secure_access_links')->insert([
            'uuid' => (string) Str::uuid(), 'purpose' => 'document_upload', 'matter_id' => $matter, 'document_request_id' => $request,
            'token_hash' => SecureAccessLinkService::hashToken($token), 'expires_at' => now()->addDays(7), 'max_uses' => 1,
            'successful_uses' => 0, 'created_by' => $this->created['user'], 'created_at' => now()->subSecond(), 'updated_at' => now(),
        ]);

        return [$request, $token];
    }

    /**
     * @param  list<list<string>>  $commands
     * @return list<string> one result line per command, in order
     */
    private function race(array $commands): array
    {
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DOCUMENTS_STORAGE_DIR' => self::STORAGE_DIR,
            'LEXORA_TIMEZONE' => 'UTC',
        ]);
        $startAt = sprintf('%.6F', microtime(true) + 2.5); // all workers boot first, then start together
        $processes = [];
        $pipes = [];
        foreach ($commands as $i => $command) {
            $processes[$i] = proc_open([PHP_BINARY, base_path('tests/Support/request-race-worker.php'), $startAt, ...$command],
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
        $files = collect(File::exists($this->root) ? File::allFiles($this->root) : [])
            ->map(fn ($f) => str_replace('\\', '/', $f->getRelativePathname()))->sort()->values()->all();
        $expected = $this->db->table('document_versions')->orderBy('storage_path')->pluck('storage_path')->sort()->values()->all();
        $this->assertSame($expected, $files, 'every stored file belongs to exactly one committed version; no staging leftovers');
    }

    private function cleanup(): void
    {
        $matters = $this->created['matters'];
        // Test database only: append-only/immutable triggers are lifted inside one transaction.
        $this->db->transaction(function () use ($matters) {
            foreach (['activity_events activity_events_no_update_delete', 'document_versions document_versions_no_update_delete', 'document_request_submissions document_request_submissions_guard'] as $t) {
                [$table, $trigger] = explode(' ', $t);
                $this->db->statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
            }
            $requests = $this->db->table('document_requests')->whereIn('matter_id', $matters)->pluck('id');
            $documents = $this->db->table('documents')->whereIn('matter_id', $matters)->pluck('id');
            $this->db->table('activity_events')->whereIn('matter_id', $matters)->delete();
            $this->db->table('document_request_submissions')->whereIn('document_request_id', $requests)->delete();
            $this->db->table('secure_access_links')->whereIn('matter_id', $matters)->delete();
            $this->db->table('document_requests')->whereIn('id', $requests)->delete();
            $this->db->table('documents')->whereIn('id', $documents)->update(['current_version_id' => null, 'latest_version_number' => 0]);
            $this->db->table('document_versions')->whereIn('document_id', $documents)->delete();
            $this->db->table('documents')->whereIn('id', $documents)->delete();
            $this->db->table('matters')->whereIn('id', $matters)->delete();
            $this->db->table('clients')->where('id', $this->created['client'])->delete();
            $this->db->table('users')->where('id', $this->created['user'])->delete();
            foreach (['activity_events activity_events_no_update_delete', 'document_versions document_versions_no_update_delete', 'document_request_submissions document_request_submissions_guard'] as $t) {
                [$table, $trigger] = explode(' ', $t);
                $this->db->statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
            }
        });
        DB::purge('pgsql_fixture');
        File::deleteDirectory($this->root);
    }
}
