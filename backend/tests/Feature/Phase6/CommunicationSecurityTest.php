<?php

namespace Tests\Feature\Phase6;

use App\Mail\ClientActionMail;
use App\Models\EmailDelivery;
use App\Services\Communications\EmailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommunicationWorkflow;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Phase 6 §42: threat model checks for the communication system. */
class CommunicationSecurityTest extends TestCase
{
    use CommunicationWorkflow, RefreshDatabase, RequestWorkflow, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Mail::fake();
    }

    #[Test]
    public function emailed_tokens_are_stored_nowhere_but_in_the_message(): void
    {
        $log = storage_path('logs/laravel.log');
        $logBefore = File::exists($log) ? File::size($log) : 0;
        [$request] = $this->requestWithLink();
        $this->adminPost($request, 'send-email')->assertCreated();
        $docToken = $this->tokenFromMail($this->lastMail());
        $this->asGuest()->publicSubmit($docToken, PdfFactory::upload('a.pdf', ['A']))->assertCreated();
        $this->useUnreachableSmtp();
        [$signing] = $this->clientSigning();
        $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$signing->matter_id}/signing-requests/{$signing->id}/send-email")->assertStatus(502);

        $hash = hash('sha256', $docToken);
        foreach (['email_deliveries', 'activity_events', 'notifications', 'jobs', 'failed_jobs', 'cache', 'sessions'] as $table) {
            $dump = DB::table($table)->get()->toJson();
            $this->assertStringNotContainsString($docToken, $dump, $table);
            $this->assertStringNotContainsString('client-upload#', $dump, $table);
            $this->assertStringNotContainsString('client-sign#', $dump, $table);
            if ($table !== 'secure_access_links') { // (and Phase 7's email_link_reservations, which only ever holds hashes)
                $this->assertStringNotContainsString($hash, $dump, "{$table} never needs the token hash");
            }
        }
        $this->assertSame(0, DB::table('jobs')->count(), 'nothing was queued: e-mails are sent synchronously');
        $newLog = File::exists($log) ? substr((string) File::get($log), $logBefore) : '';
        $this->assertStringNotContainsString($docToken, $newLog);
        $this->assertStringNotContainsString('client-upload#', $newLog);
        // The failed attempt stored only a category, not the transport's error text.
        $this->assertSame('connection', EmailDelivery::query()->where('signing_request_id', $signing->id)->sole()->failure_category->value);
    }

    #[Test]
    public function client_supplied_text_cannot_inject_headers_or_markup(): void
    {
        $matter = $this->matterFor("Evil Corp\r\nBcc: victim@example.test");
        [$request] = $this->requestWithLink($matter, [
            'title' => 'Contract <img src=x onerror=alert(1)>',
            'instructions' => "Line one\r\n<script>alert('x')</script>\r\nSubject: hijacked",
        ]);
        $this->adminPost($request, 'send-email')->assertCreated();

        $delivery = EmailDelivery::query()->sole();
        $this->assertStringNotContainsString("\n", $delivery->recipient_name);
        $mail = $this->lastMail();
        $this->assertFalse($mail->hasBcc('victim@example.test'));
        $this->assertSame('Document request — Lexora Legal', $mail->mailContent->subject, 'user text never reaches the subject');
        $html = $mail->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function client_links_cannot_reach_communication_management_and_matters_are_isolated(): void
    {
        [$request, $token] = $this->requestWithLink();
        [$signing, $signToken] = $this->clientSigning();
        $base = "/api/matters/{$request->matter_id}/document-requests/{$request->id}";
        foreach ([['POST', "{$base}/send-email"], ['GET', "{$base}/communications"], ['GET', "/api/matters/{$request->matter_id}/communications"],
            ['GET', '/api/communications/status'], ['POST', '/api/communications/test-email'], ['GET', '/api/notifications']] as [$method, $uri]) {
            $this->asGuest()->json($method, $uri, [], ['X-Upload-Token' => $token, 'X-Signing-Token' => $signToken])->assertUnauthorized();
        }
        // Another matter's request / signing request through this matter's URL: 404.
        $this->actingAsAdmin($this->admin())->getJson("/api/matters/{$signing->matter_id}/document-requests/{$request->id}/communications")->assertNotFound();
        $this->actingAsAdmin($this->admin())->getJson("/api/matters/{$request->matter_id}/signing-requests/{$signing->id}/communications")->assertNotFound();
        // Public pages expose no communication data.
        $this->asGuest()->publicView($token)->assertOk()->assertJsonMissingPath('data.recipient_email')->assertJsonMissingPath('data.communications');
        Mail::assertNothingSent();
    }

    #[Test]
    public function sending_is_rate_limited_per_administrator_and_test_emails_go_only_to_the_administrator(): void
    {
        config(['lexora.communications.sends_per_minute' => 2]);
        $requests = [];
        for ($i = 0; $i < 3; $i++) {
            $requests[] = $this->requestWithLink()[0];
        }
        $this->adminPost($requests[0], 'send-email')->assertCreated();
        $this->adminPost($requests[1], 'send-email')->assertCreated();
        $this->adminPost($requests[2], 'send-email')->assertStatus(429);

        $admin = $this->admin();
        $this->actingAsAdmin($admin)->postJson('/api/communications/test-email', ['to' => 'someone@else.example'])->assertCreated()
            ->assertJsonPath('data.recipient_email', $admin->email)->assertJsonPath('data.communication_type', 'test');
        Mail::assertSent(ClientActionMail::class, fn (ClientActionMail $m) => $m->hasTo($admin->email) && $m->mailContent->actionUrl === null);
        Mail::assertNotSent(ClientActionMail::class, fn (ClientActionMail $m) => $m->hasTo('someone@else.example'));
    }

    #[Test]
    public function production_refuses_to_email_local_or_plain_http_links(): void
    {
        // Checked at the service level (an HTTP call in "production" would also switch on CSRF for the test client).
        $service = app(EmailDeliveryService::class);
        $this->app['env'] = 'production';
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        try {
            foreach (['http://localhost:3000', 'http://portal.example.test', 'https://localhost'] as $url) {
                config(['lexora.public_app_url' => $url]);
                $status = $service->transportStatus();
                $this->assertFalse($status['available'], $url);
                $this->assertSame('Client links need a public HTTPS address (PUBLIC_APP_URL) before e-mail can be sent.', $status['reason']);
                try {
                    $service->assertAvailable();
                    $this->fail('sending must be refused for '.$url);
                } catch (HttpException $e) {
                    $this->assertSame(503, $e->getStatusCode());
                }
            }
            config(['lexora.public_app_url' => 'https://portal.example.test']);
            $this->assertTrue($service->transportStatus()['available']);
            config(['mail.default' => 'log']);
            $this->assertFalse($service->transportStatus()['available'], 'the log mailer is not real e-mail');
        } finally {
            $this->app['env'] = 'testing';
        }
        Mail::assertNothingSent();
    }
}
