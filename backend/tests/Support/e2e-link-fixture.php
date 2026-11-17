<?php

/**
 * Test-only helper for the Playwright suite (Phase 4): prepares states the product deliberately
 * cannot produce through its UI or API — an expired link, an overdue request, a token of another
 * purpose. It refuses to run against any database whose name does not end in "_e2e".
 *
 * Usage (DB_DATABASE must be the disposable E2E database):
 *   php e2e-link-fixture.php expire <linkId>           link created 2 days ago, expired 1 minute ago
 *   php e2e-link-fixture.php overdue <requestId> <days> due date moved <days> days into the past
 *   php e2e-link-fixture.php signing-token <requestId> inserts a document_signing link; prints its raw token
 */

use App\Services\Requests\SecureAccessLinkService;
use App\Support\LegalDate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');
if (! preg_match('/_e2e$/', $database)) {
    fwrite(STDERR, "Refusing to modify database \"{$database}\": only *_e2e databases are allowed.\n");
    exit(2);
}

[$action, $id] = [$argv[1] ?? '', (int) ($argv[2] ?? 0)];
$now = Carbon::now();

switch ($action) {
    case 'expire':
        DB::table('secure_access_links')->where('id', $id)->update([
            'created_at' => $now->copy()->subDays(2), 'expires_at' => $now->copy()->subMinute(), 'updated_at' => $now,
        ]) === 1 || exit(1);
        echo "ok\n";
        break;
    case 'overdue':
        $days = max(1, (int) ($argv[3] ?? 1));
        DB::table('document_requests')->where('id', $id)->update([
            'due_at' => LegalDate::endOfDay($now->copy()->subDays($days)->setTimezone(LegalDate::timezone())->format('Y-m-d')),
        ]) === 1 || exit(1);
        echo "ok\n";
        break;
    case 'signing-token':
        // Since Phase 5 a signing link must target a signing request: create one for the document the
        // client submitted for this document request, and a real signing link for it.
        $request = DB::table('document_requests')->find($id) ?? exit(1);
        $document = DB::table('documents')->find($request->document_id) ?? exit(1);
        $signing = DB::table('signing_requests')->insertGetId([
            'uuid' => (string) Str::uuid(), 'matter_id' => $request->matter_id, 'document_id' => $document->id,
            'document_version_id' => $document->current_version_id, 'signer_type' => 'client', 'client_id' => $request->client_id,
            'signer_name' => 'E2E Signer', 'status' => 'ready', 'delivery_status' => 'not_shared',
            'created_at' => $now->copy()->subSecond(), 'updated_at' => $now,
        ]);
        $token = SecureAccessLinkService::generateToken();
        DB::table('secure_access_links')->insert([
            'uuid' => (string) Str::uuid(), 'purpose' => 'document_signing', 'matter_id' => $request->matter_id,
            'signing_request_id' => $signing, 'token_hash' => SecureAccessLinkService::hashToken($token),
            'expires_at' => $now->copy()->addDay(), 'max_uses' => 1, 'successful_uses' => 0,
            'created_at' => $now->copy()->subSecond(), 'updated_at' => $now,
        ]);
        echo $token."\n";
        break;
    case 'expire-signing':
        // Expire the usable signing link of a signing request (Phase 5 E2E).
        DB::table('secure_access_links')->where('signing_request_id', $id)->whereNull('revoked_at')->whereNull('consumed_at')
            ->update(['created_at' => $now->copy()->subDays(2), 'expires_at' => $now->copy()->subMinute(), 'updated_at' => $now]) === 1 || exit(1);
        echo "ok\n";
        break;
    default:
        fwrite(STDERR, "Unknown action.\n");
        exit(1);
}
