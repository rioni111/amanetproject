<?php

namespace Tests\Feature\Phase6;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\EmailDelivery;
use App\Models\SecureAccessLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CommunicationWorkflow;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\TestCase;

/** Phase 6 §14: correction requests sent by e-mail. */
class CorrectionEmailTest extends TestCase
{
    use CommunicationWorkflow, RefreshDatabase, RequestWorkflow;

    private const REASON = 'Page 3 is missing the second signature and the date on page 1 is wrong.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Mail::fake();
    }

    #[Test]
    public function a_correction_is_emailed_with_a_fresh_scoped_link_and_the_corrected_upload_becomes_a_new_version(): void
    {
        [$request, $token] = $this->requestWithLink();
        $submissionId = $this->submitAndStartReview($request, $token, 'First version');

        $response = $this->adminPost($request, 'request-correction', [
            'submission_id' => $submissionId, 'lock_version' => $this->lockVersion($request), 'reason' => self::REASON, 'send_email' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', 'correction_requested')
            ->assertJsonPath('link', null)
            ->assertJsonPath('email.status', 'accepted')
            ->assertJsonPath('email.delivery.communication_type', 'document_correction');

        $mail = $this->lastMail();
        $this->assertSame('Correction requested — Lexora Legal', $mail->mailContent->subject);
        $html = $mail->render();
        $this->assertStringNotContainsString('second signature', $html, 'the reason stays behind the secure link');
        $newToken = $this->tokenFromMail($mail);
        $this->assertNotSame($token, $newToken);

        // The secure page shows the correction; the corrected upload is the next immutable version.
        $this->asGuest()->publicView($newToken)->assertOk()->assertJsonPath('data.is_correction', true)->assertJsonPath('data.correction.reason', self::REASON);
        $this->publicSubmit($newToken, PdfFactory::upload('corrected.pdf', ['Corrected version']))->assertCreated();
        $document = Document::query()->sole();
        $this->assertSame([1, 2], DocumentVersion::query()->where('document_id', $document->id)->orderBy('version_number')->pluck('version_number')->all());
        $this->assertSame(['correction_submitted', 'document_submitted'], DB::table('notifications')->orderBy('type')->pluck('type')->all(),
            'the administrator was told about the first upload and about the corrected one');
        $this->asGuest()->publicView($token)->assertStatus(410); // the first link was used and stays used
    }

    #[Test]
    public function a_failed_correction_email_keeps_the_decision_and_can_be_retried(): void
    {
        [$request, $token] = $this->requestWithLink();
        $submissionId = $this->submitAndStartReview($request, $token, 'First version');
        $this->useUnreachableSmtp();

        $this->adminPost($request, 'request-correction', [
            'submission_id' => $submissionId, 'lock_version' => $this->lockVersion($request), 'reason' => self::REASON, 'send_email' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', 'correction_requested')
            ->assertJsonPath('email.status', 'failed');
        $this->assertSame('correction_requested', $request->fresh()->status->value, 'the decision stands');
        $this->assertSame(0, SecureAccessLink::query()->whereNull('revoked_at')->whereNull('consumed_at')->count(), 'no half-made link');

        // Transport restored: the administrator retries with "Send e-mail".
        Mail::fake();
        $this->adminPost($request, 'send-email')->assertCreated()->assertJsonPath('data.communication_type', 'document_correction');
        $this->asGuest()->publicView($this->tokenFromMail($this->lastMail()))->assertOk();
        $this->assertSame(['failed', 'accepted'], EmailDelivery::query()->orderBy('id')->pluck('status')->map->value->all());

        // send_email is only accepted on the correction decision.
        $this->adminPost($request, 'cancel', ['send_email' => true, 'lock_version' => $this->lockVersion($request)])->assertUnprocessable();
    }
}
