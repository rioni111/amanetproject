<?php

namespace Tests\Feature\Phase6;

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
 * Phase 6 §39: independent PHP processes (separate connections) start at the same instant and act through
 * the real services. Fixtures are committed on a separate connection and removed afterwards.
 */
class CommunicationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const STORAGE_DIR = 'phpunit-communication-race';

    private Connection $db;

    private int $user;

    private int $client;

    /** @var list<int> */
    private array $matters = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.pgsql_fixture' => config('database.connections.pgsql')]);
        $this->db = DB::connection('pgsql_fixture');
        File::deleteDirectory(storage_path('app/private/'.self::STORAGE_DIR));
        $this->user = $this->db->table('users')->insertGetId(['name' => 'Race Admin', 'email' => Str::uuid().'@example.test', 'password' => bcrypt(Str::random(16)), 'role' => 'admin']);
        $this->client = $this->db->table('clients')->insertGetId(['name' => 'Race Client', 'email' => 'race.client@example.test', 'client_type' => 'organization', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function four_administrators_clicking_send_at_once_produce_one_email_and_one_link(): void
    {
        [$matter, $request] = $this->documentRequest();
        $results = $this->race(array_fill(0, 4, ['send-doc', (string) $matter, (string) $request, (string) $this->user]));

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'ok accepted')), implode(' | ', $results));
        foreach (array_filter($results, fn ($r) => $r !== 'ok accepted') as $refused) {
            $this->assertSame('refused http_409', $refused, implode(' | ', $results));
        }
        $this->assertSame(1, $this->db->table('email_deliveries')->where('document_request_id', $request)->count());
        $this->assertSame(1, $this->db->table('secure_access_links')->where('document_request_id', $request)->whereNull('revoked_at')->count());
    }

    #[Test]
    public function three_scheduler_processes_send_each_due_reminder_exactly_once(): void
    {
        [, $first] = $this->documentRequest(dueInDays: 1, communicated: true);
        [, $second] = $this->documentRequest(dueInDays: 0, communicated: true);
        $results = $this->race(array_fill(0, 3, ['scheduler']));

        $this->assertSame(2, array_sum(array_map(fn ($r) => (int) substr($r, 3), $results)), implode(' | ', $results));
        foreach ([$first, $second] as $request) {
            $this->assertSame(1, $this->db->table('email_deliveries')->where('document_request_id', $request)->where('initiated_by', 'automatic')->count());
            $this->assertSame(1, $this->db->table('secure_access_links')->where('document_request_id', $request)->whereNull('revoked_at')->count());
        }
    }

    #[Test]
    public function sending_racing_cancellation_never_leaves_a_usable_link_on_a_cancelled_request(): void
    {
        for ($round = 0; $round < 3; $round++) {
            [$matter, $request] = $this->documentRequest();
            $results = $this->race([['send-doc', (string) $matter, (string) $request, (string) $this->user], ['cancel-doc', (string) $matter, (string) $request, (string) $this->user]]);

            $this->assertSame('ok cancelled', $results[1], implode(' | ', $results));
            $this->assertContains($results[0], ['ok accepted', 'refused http_409'], implode(' | ', $results));
            $this->assertSame('cancelled', $this->db->table('document_requests')->where('id', $request)->value('status'));
            $this->assertSame(0, $this->db->table('secure_access_links')->where('document_request_id', $request)->whereNull('revoked_at')->whereNull('consumed_at')->count(),
                'a cancelled request never has a usable link, whichever finished first');
        }
    }

    #[Test]
    public function an_invitation_racing_the_signature_never_reopens_or_regresses_the_request(): void
    {
        [$matter, $signing, $token] = $this->startedSigningRequest();
        $results = $this->race([['sign', $token], ['send-sign', (string) $matter, (string) $signing, (string) $this->user]]);

        $this->assertSame('ok signed', $results[0], implode(' | ', $results));
        $this->assertContains($results[1], ['ok accepted', 'refused http_409'], implode(' | ', $results));
        $row = $this->db->table('signing_requests')->where('id', $signing)->first();
        $this->assertSame('signed', $row->status);
        $this->assertSame(0, $this->db->table('secure_access_links')->where('signing_request_id', $signing)->whereNull('revoked_at')->whereNull('consumed_at')->count(),
            'an invitation accepted after the signature carries an already-closed link');
    }

    // ------------------------------------------------------------------ fixtures

    private function matter(): int
    {
        $id = $this->db->table('matters')->insertGetId(['matter_number' => 'LEX-2097-'.random_int(10000, 99999), 'client_id' => $this->client,
            'title' => 'Communication race', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->matters[] = $id;

        return $id;
    }

    /** @return array{0: int, 1: int} matter id and a pending document request */
    private function documentRequest(?int $dueInDays = null, bool $communicated = false): array
    {
        $matter = $this->matter();
        $request = $this->db->table('document_requests')->insertGetId([
            'uuid' => (string) Str::uuid(), 'matter_id' => $matter, 'client_id' => $this->client, 'title' => 'Race document',
            'instructions' => 'Upload it.', 'requested_category' => 'other', 'status' => 'pending',
            'due_at' => $dueInDays === null ? null : now()->addDays($dueInDays)->endOfDay(),
            'created_by' => $this->user, 'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5),
        ]);
        if ($communicated) {
            $this->db->table('secure_access_links')->insert(['uuid' => (string) Str::uuid(), 'purpose' => 'document_upload', 'matter_id' => $matter,
                'document_request_id' => $request, 'token_hash' => SecureAccessLinkService::hashToken(SecureAccessLinkService::generateToken()),
                'expires_at' => now()->addDays(7), 'max_uses' => 1, 'successful_uses' => 0, 'created_by' => $this->user,
                'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);
        }

        return [$matter, $request];
    }

    /** @return array{0: int, 1: int, 2: string} */
    private function startedSigningRequest(): array
    {
        $matter = $this->matter();
        $root = storage_path('app/private/'.self::STORAGE_DIR);
        $bytes = PdfFactory::bytes(['Race agreement '.Str::random(8)]);
        [$docUuid, $verUuid] = [(string) Str::uuid(), (string) Str::uuid()];
        $path = "{$matter}/{$docUuid}/{$verUuid}.pdf";
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        file_put_contents($root.'/'.$path, $bytes);
        $document = $this->db->table('documents')->insertGetId(['uuid' => $docUuid, 'matter_id' => $matter, 'title' => 'Race agreement', 'category' => 'agreements', 'created_at' => now(), 'updated_at' => now()]);
        $version = $this->db->table('document_versions')->insertGetId(['uuid' => $verUuid, 'document_id' => $document, 'version_number' => 1, 'kind' => 'upload',
            'origin' => 'admin', 'original_filename' => 'race.pdf', 'storage_disk' => 'documents', 'storage_path' => $path, 'mime_type' => 'application/pdf',
            'file_size' => strlen($bytes), 'sha256_checksum' => hash('sha256', $bytes), 'created_at' => now()]);
        $this->db->table('documents')->where('id', $document)->update(['current_version_id' => $version, 'latest_version_number' => 1]);
        $signing = $this->db->table('signing_requests')->insertGetId([
            'uuid' => (string) Str::uuid(), 'matter_id' => $matter, 'document_id' => $document, 'document_version_id' => $version,
            'signer_type' => 'client', 'client_id' => $this->client, 'signer_name' => 'Race Signer', 'signer_email' => 'race.signer@example.test',
            'status' => 'started', 'delivery_status' => 'not_shared', 'viewed_at' => now()->subMinute(), 'started_at' => now()->subSeconds(30),
            'created_at' => now()->subMinutes(5), 'updated_at' => now(),
        ]);
        $token = SecureAccessLinkService::generateToken();
        $this->db->table('secure_access_links')->insert(['uuid' => (string) Str::uuid(), 'purpose' => 'document_signing', 'matter_id' => $matter,
            'signing_request_id' => $signing, 'token_hash' => SecureAccessLinkService::hashToken($token), 'expires_at' => now()->addDays(7),
            'max_uses' => 1, 'successful_uses' => 0, 'created_by' => $this->user, 'created_at' => now()->subMinutes(5), 'updated_at' => now()]);

        return [$matter, $signing, $token];
    }

    /** @param list<list<string>> $commands @return list<string> */
    private function race(array $commands): array
    {
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'MAIL_MAILER' => 'array',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DOCUMENTS_STORAGE_DIR' => self::STORAGE_DIR]);
        $startAt = sprintf('%.6F', microtime(true) + 2.5);
        $processes = [];
        $pipes = [];
        foreach ($commands as $i => $command) {
            $processes[$i] = proc_open([PHP_BINARY, base_path('tests/Support/communication-race-worker.php'), $startAt, ...$command],
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

    private function cleanup(): void
    {
        $matters = $this->matters;
        $triggers = ['activity_events activity_events_no_update_delete', 'document_versions document_versions_no_update_delete',
            'signature_records signature_records_no_update_delete', 'signing_requests signing_requests_update_guard',
            'email_deliveries email_deliveries_guard', 'email_link_reservations email_link_reservations_guard'];
        $this->db->transaction(function () use ($matters, $triggers) {
            foreach ($triggers as $t) {
                [$table, $trigger] = explode(' ', $t);
                $this->db->statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
            }
            $documents = $this->db->table('documents')->whereIn('matter_id', $matters)->pluck('id');
            $this->db->table('email_link_reservations')->whereIn('matter_id', $matters)->delete(); // Phase 7
            $this->db->table('email_deliveries')->whereIn('matter_id', $matters)->delete();
            $this->db->table('notifications')->where('notifiable_type', 'user')->where('notifiable_id', $this->user)->delete();
            $this->db->table('activity_events')->whereIn('matter_id', $matters)->delete();
            $this->db->table('signature_records')->whereIn('document_id', $documents)->delete();
            $this->db->table('secure_access_links')->whereIn('matter_id', $matters)->delete();
            $this->db->table('signing_requests')->whereIn('matter_id', $matters)->delete();
            $this->db->table('document_requests')->whereIn('matter_id', $matters)->delete();
            $this->db->table('documents')->whereIn('id', $documents)->update(['current_version_id' => null, 'latest_version_number' => 0]);
            $this->db->table('document_versions')->whereIn('document_id', $documents)->delete();
            $this->db->table('documents')->whereIn('id', $documents)->delete();
            $this->db->table('matters')->whereIn('id', $matters)->delete();
            $this->db->table('clients')->where('id', $this->client)->delete();
            $this->db->table('users')->where('id', $this->user)->delete();
            foreach ($triggers as $t) {
                [$table, $trigger] = explode(' ', $t);
                $this->db->statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
            }
        });
        DB::purge('pgsql_fixture');
        File::deleteDirectory(storage_path('app/private/'.self::STORAGE_DIR));
    }
}
