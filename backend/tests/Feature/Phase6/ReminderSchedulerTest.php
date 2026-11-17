<?php

namespace Tests\Feature\Phase6;

use App\Enums\EmailDeliveryStatus;
use App\Enums\MatterStatus;
use App\Mail\ClientActionMail;
use App\Models\DocumentRequest;
use App\Models\EmailDelivery;
use App\Models\Matter;
use App\Models\SecureAccessLink;
use App\Models\SigningRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CommunicationWorkflow;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Phase 6 §18–§20: scheduled reminders and time-based notifications. */
class ReminderSchedulerTest extends TestCase
{
    use CommunicationWorkflow, RefreshDatabase, RequestWorkflow, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runScheduler(): void
    {
        $this->assertSame(0, Artisan::call('lexora:communications'));
    }

    private function reminders(): int
    {
        return Mail::sent(ClientActionMail::class, fn (ClientActionMail $m) => $m->automatic)->count();
    }

    #[Test]
    public function document_reminders_follow_the_due_date_schedule_once_per_slot(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        [$request, $token] = $this->requestWithLink(null, ['due_date' => '2026-11-15']);

        $this->runScheduler();
        $this->assertSame(0, $this->reminders(), 'nothing due five days before');

        Carbon::setTestNow('2026-11-12 09:00:00'); // 3 days before
        $this->runScheduler();
        $this->runScheduler(); // a second run (or process) never repeats a slot
        $this->assertSame(1, $this->reminders());
        $first = EmailDelivery::query()->where('initiated_by', 'automatic')->sole();
        $this->assertSame('document_request_reminder', $first->communication_type->value);
        $this->assertSame('pending:due-3:2026-11-15', $first->reminder_slot);
        $this->assertSame('Reminder: document request — Lexora Legal', $this->lastMail()->mailContent->subject);
        $this->asGuest()->publicView($token)->assertNotFound(); // the reminder carries the new link
        $reminderToken = $this->tokenFromMail($this->lastMail());
        $this->publicView($reminderToken)->assertOk();

        Carbon::setTestNow('2026-11-15 09:00:00'); // due date
        $this->runScheduler();
        Carbon::setTestNow('2026-11-17 09:00:00'); // 2 days overdue
        $this->runScheduler();
        $this->assertSame(3, $this->reminders());
        $this->assertSame(['pending:due-3:2026-11-15', 'pending:due+0:2026-11-15', 'pending:due+2:2026-11-15'],
            EmailDelivery::query()->where('initiated_by', 'automatic')->orderBy('id')->pluck('reminder_slot')->all());

        Carbon::setTestNow('2026-11-25 09:00:00'); // no further slots
        $this->runScheduler();
        $this->assertSame(3, $this->reminders());
        $this->assertSame(1, SecureAccessLink::query()->where('document_request_id', $request->id)->whereNull('revoked_at')->count());
    }

    #[Test]
    public function reminders_are_never_sent_for_requests_that_no_longer_need_the_client(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        $submitted = $this->requestWithLink(null, ['due_date' => '2026-11-03']);
        $this->publicSubmit($submitted[1], PdfFactory::upload('a.pdf', ['Uploaded']))->assertCreated();
        [$cancelled] = $this->requestWithLink(null, ['due_date' => '2026-11-03']);
        $this->adminPost($cancelled, 'cancel', ['lock_version' => $this->lockVersion($cancelled)])->assertOk();
        [$archived] = $this->requestWithLink(null, ['due_date' => '2026-11-03']);
        Matter::query()->whereKey($archived->matter_id)->update(['status' => MatterStatus::Archived->value, 'archived_at' => now(), 'archived_from_status' => 'open']);
        [$revoked] = $this->requestWithLink(null, ['due_date' => '2026-11-03']);
        $this->adminPost($revoked, 'links/'.SecureAccessLink::query()->where('document_request_id', $revoked->id)->value('id').'/revoke')->assertOk();
        $neverShared = DocumentRequest::query()->findOrFail($this->createRequest($this->matterFor('Quiet Client'), ['due_date' => '2026-11-03'], false)->json('data.id'));

        Carbon::setTestNow('2026-11-06 10:00:00');
        $this->runScheduler();
        $this->assertSame(0, $this->reminders());
        $this->assertSame(0, EmailDelivery::query()->count());
        $this->assertNotNull($neverShared->fresh()->overdue_notified_at, 'still overdue → the firm is told, the client is not e-mailed');
    }

    #[Test]
    public function requests_without_a_due_date_use_the_fallback_schedule_and_the_cap(): void
    {
        config(['lexora.communications.reminders.max_per_request' => 1]);
        Carbon::setTestNow('2026-11-01 10:00:00');
        [$request] = $this->requestWithLink();

        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->runScheduler();
        $this->assertSame(0, $this->reminders());
        Carbon::setTestNow('2026-11-05 10:00:00'); // 4 days after the first link
        $this->runScheduler();
        $this->assertSame(1, $this->reminders());
        $this->assertSame('pending:nodue+4', EmailDelivery::query()->sole()->reminder_slot);

        Carbon::setTestNow('2026-11-11 10:00:00'); // second slot, but the cap (1) is reached
        $this->runScheduler();
        $this->runScheduler();
        $this->assertSame(1, $this->reminders());
        $skipped = EmailDelivery::query()->where('status', EmailDeliveryStatus::Skipped->value)->sole();
        $this->assertSame(['pending:nodue+10', 'reminder_limit'], [$skipped->reminder_slot, $skipped->failure_category->value]);
    }

    #[Test]
    public function a_recent_email_delays_the_reminder_by_the_cooldown(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        [$request] = $this->requestWithLink(null, ['due_date' => '2026-11-04']);
        Carbon::setTestNow('2026-11-01 20:00:00');
        $this->adminPost($request, 'send-email')->assertCreated(); // manual e-mail at 20:00

        Carbon::setTestNow('2026-11-02 08:00:00'); // due-3 slot is due, but only 12h since the last e-mail
        $this->runScheduler();
        $this->assertSame(0, $this->reminders());
        Carbon::setTestNow('2026-11-02 20:30:00');
        $this->runScheduler();
        $this->assertSame(1, $this->reminders());
    }

    #[Test]
    public function the_firm_time_zone_decides_which_day_it_is(): void
    {
        config(['lexora.timezone' => 'America/New_York']);
        Carbon::setTestNow('2026-11-01 15:00:00');
        $this->requestWithLink(null, ['due_date' => '2026-11-15']);

        Carbon::setTestNow('2026-11-12 03:00:00'); // already 12 Nov in UTC, still 11 Nov 22:00 in New York
        $this->runScheduler();
        $this->assertSame(0, $this->reminders());
        Carbon::setTestNow('2026-11-12 06:00:00'); // 12 Nov 01:00 in New York
        $this->runScheduler();
        $this->assertSame(1, $this->reminders());
    }

    #[Test]
    public function signing_reminders_respect_sharing_activity_completion_and_expiry(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        [$request, $token] = $this->clientSigning();
        $this->signingAction($request, 'mark-shared', ['lock_version' => $request->fresh()->lock_version])->assertOk();

        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->runScheduler();
        $this->assertSame(0, $this->reminders(), 'not before 3 days after sharing');

        Carbon::setTestNow('2026-11-04 11:00:00');
        $this->runScheduler();
        $this->runScheduler();
        $this->assertSame(1, $this->reminders());
        $mail = $this->lastMail();
        $this->assertTrue($mail->hasTo('ana.krasniqi@northwind.example'));
        $this->assertSame('Reminder: signature requested — Lexora Legal', $mail->mailContent->subject);
        $newToken = $this->tokenFromMail($mail, 'client-sign');
        $this->forgetUser()->publicSigning('GET', '', $token)->assertNotFound();

        // The signer opens the new link: no reminder while they are active (it would replace their link).
        $this->viewAndStart($newToken);
        Carbon::setTestNow('2026-11-10 12:00:00'); // close to the new link's expiry (+7 days from 4 Nov 11:00)
        $request->forceFill([])->refresh();
        DB::table('signing_requests')->where('id', $request->id)->update(['viewed_at' => '2026-11-10 11:00:00', 'started_at' => '2026-11-10 11:30:00']);
        $this->runScheduler();
        $this->assertSame(1, $this->reminders());

        // Signed: never again.
        $this->publicSigning('POST', '/complete', $newToken, $this->signaturePayload())->assertCreated();
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->runScheduler();
        $this->assertSame(1, $this->reminders());
    }

    #[Test]
    public function overdue_requests_expired_signing_links_and_interrupted_sends_notify_the_firm_once(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        [$request] = $this->requestWithLink(null, ['due_date' => '2026-11-02']);
        [$signing] = $this->clientSigning();
        $stuck = EmailDelivery::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'matter_id' => $request->matter_id, 'client_id' => $request->client_id, 'document_request_id' => $request->id,
            'communication_type' => 'document_request', 'initiated_by' => 'manual', 'recipient_email' => 'client@example.test', 'recipient_name' => 'Client',
            'subject' => 'Document request — Lexora Legal', 'channel' => 'email', 'status' => 'sending', 'attempt_count' => 1,
            'last_attempt_at' => Carbon::now(), 'idempotency_key' => 'manual:document_request:'.$request->id.':stuck',
        ]);

        Carbon::setTestNow('2026-11-09 10:00:00'); // overdue, signing link (7 days) expired, claim stale
        $this->runScheduler();
        $this->runScheduler();

        // Two administrators exist in this test (one per helper); each gets exactly one of each, never repeated.
        $this->assertSame(2, DB::table('users')->count());
        foreach (DB::table('users')->pluck('id') as $adminId) {
            $kinds = DB::table('notifications')->where('notifiable_id', $adminId)->orderBy('type')->pluck('type')->all();
            $this->assertSame(['email_failed', 'request_overdue', 'signing_link_expired'], $kinds, 'one notification each, never repeated');
        }
        $this->assertSame(['failed', 'interrupted'], [$stuck->fresh()->status->value, $stuck->fresh()->failure_category->value]);
        $this->assertNotNull($request->fresh()->overdue_notified_at);
        $this->assertNotNull(SecureAccessLink::query()->where('signing_request_id', $signing->id)->value('expiry_notified_at'));
        $this->assertSame(0, Mail::sent(ClientActionMail::class, fn ($m) => str_contains($m->mailContent->subject, 'signature'))->count(), 'an expired signing link is never re-issued automatically');
        foreach (DB::table('notifications')->pluck('data') as $data) {
            $this->assertStringNotContainsString('client-upload#', $data);
            $this->assertStringNotContainsString('client-sign#', $data);
        }
    }

    #[Test]
    public function a_dry_run_counts_without_sending_and_reminders_can_be_switched_off(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->requestWithLink(null, ['due_date' => '2026-11-03']);
        Carbon::setTestNow('2026-11-02 10:00:00');
        Artisan::call('lexora:communications', ['--dry-run' => true]);
        $this->assertStringContainsString('reminders_due        1', Artisan::output());
        $this->assertSame(0, $this->reminders());

        config(['lexora.communications.reminders.enabled' => false]);
        $this->runScheduler();
        $this->assertSame(0, $this->reminders());
        config(['lexora.communications.reminders.enabled' => true, 'lexora.communications.email_enabled' => false]);
        $this->runScheduler();
        $this->assertSame(0, $this->reminders(), 'no e-mail configured → no reminders, no failures');
        $this->assertSame(0, EmailDelivery::query()->count());
        $this->assertSame(0, SigningRequest::query()->count());
    }
}
