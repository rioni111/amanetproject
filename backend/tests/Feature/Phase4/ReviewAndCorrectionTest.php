<?php

namespace Tests\Feature\Phase4;

use App\Enums\ActivityEventType;
use App\Models\ActivityEvent;
use App\Models\Document;
use App\Models\DocumentRequestSubmission;
use App\Models\DocumentVersion;
use App\Models\SecureAccessLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\TestCase;

/** Scenarios E–F (backend): review, approval, correction loops and their guarantees. */
class ReviewAndCorrectionTest extends TestCase
{
    use RefreshDatabase, RequestWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    #[Test]
    public function an_admin_reviews_and_approves_the_exact_submitted_version(): void
    {
        [$request, $token] = $this->requestWithLink();
        $this->publicSubmit($token, PdfFactory::upload('a.pdf', ['Agreement']))->assertCreated();
        $submission = DocumentRequestSubmission::query()->sole();

        // Invalid transitions are refused server-side.
        $this->adminPost($request, 'approve', ['submission_id' => $submission->id, 'lock_version' => $this->lockVersion($request)])
            ->assertUnprocessable()->assertJsonPath('errors.status.0', 'A request that is Submitted cannot become Approved.');
        $this->adminPost($request, 'request-correction', ['submission_id' => $submission->id, 'lock_version' => $this->lockVersion($request), 'reason' => 'Please add page 3 of the agreement.'])
            ->assertUnprocessable();

        $this->adminPost($request, 'start-review', ['submission_id' => $submission->id, 'lock_version' => $this->lockVersion($request)])
            ->assertOk()->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.latest_submission.review_status', 'in_review')
            ->assertJsonPath('data.allowed_actions.approve', true);
        $this->adminPost($request, 'start-review', ['submission_id' => $submission->id, 'lock_version' => $this->lockVersion($request)])->assertUnprocessable();

        // A stale page (old lock_version) cannot decide.
        $this->adminPost($request, 'approve', ['submission_id' => $submission->id, 'lock_version' => 1])->assertStatus(409);

        $this->adminPost($request, 'approve', ['submission_id' => $submission->id, 'lock_version' => $this->lockVersion($request)])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_version.label', 'v1')
            ->assertJsonPath('data.reviewed_by.name', 'Elena Marsh')
            ->assertJsonPath('data.latest_submission.review_status', 'approved')
            ->assertJsonPath('data.latest_submission.reviewed_by.name', 'Elena Marsh')
            ->assertJsonPath('data.allowed_actions.cancel', false)
            ->assertJsonPath('data.allowed_actions.issue_link', false);

        $request->refresh();
        $this->assertSame($submission->document_version_id, $request->approved_version_id);
        $this->assertNotNull($request->approved_at);
        $this->assertNotNull($submission->fresh()->reviewed_at);
        // Approval is not a signature: the document and its version are unchanged.
        $this->assertSame('upload', DocumentVersion::query()->sole()->kind->value);
        $this->assertSame(1, Document::query()->sole()->latest_version_number);

        // Closed: no further links, edits, decisions or cancellation.
        $this->adminPost($request, 'links')->assertStatus(409);
        $this->adminPost($request, 'links/rotate')->assertStatus(409);
        $this->adminPost($request, 'cancel', ['lock_version' => $this->lockVersion($request)])->assertUnprocessable();
        $this->actingAsAdmin($this->admin())->patchJson("/api/matters/{$request->matter_id}/document-requests/{$request->id}", ['title' => 'x', 'lock_version' => $this->lockVersion($request)])->assertStatus(409);

        $types = ActivityEvent::query()->where('matter_id', $request->matter_id)->orderBy('id')->pluck('event_type')->map->value->all();
        $this->assertSame(['document.requested', 'link.created', 'document.submitted', 'document.review_started', 'document.approved'], $types);
        $approved = ActivityEvent::query()->where('event_type', ActivityEventType::DocumentApproved->value)->sole();
        $this->assertSame('Document approved: Signed Service Agreement (v1).', $approved->description);
        $this->assertSame(['under_review', 'approved'], [$approved->metadata['from'], $approved->metadata['to']]);
        $this->assertSame($submission->document_version_id, $approved->metadata['version_id']);
    }

    #[Test]
    public function the_correction_loop_keeps_every_version_and_approves_the_latest(): void
    {
        [$request, $token] = $this->requestWithLink();
        $v1Bytes = PdfFactory::bytes(['Agreement, pages 1-2']);
        $this->publicSubmit($token, PdfFactory::fromBytes('agreement.pdf', $v1Bytes))->assertCreated();
        $first = DocumentRequestSubmission::query()->sole();
        $this->adminPost($request, 'start-review', ['submission_id' => $first->id, 'lock_version' => $this->lockVersion($request)])->assertOk();

        // A reason is required.
        $this->adminPost($request, 'request-correction', ['submission_id' => $first->id, 'lock_version' => $this->lockVersion($request)])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->adminPost($request, 'request-correction', ['submission_id' => $first->id, 'lock_version' => $this->lockVersion($request), 'reason' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $reason = 'Please upload the agreement with all pages included.';
        $correction = $this->adminPost($request, 'request-correction', ['submission_id' => $first->id, 'lock_version' => $this->lockVersion($request), 'reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', 'correction_requested')
            ->assertJsonPath('data.latest_submission.review_status', 'correction_requested')
            ->assertJsonPath('data.latest_submission.correction_reason', $reason)
            ->assertJsonPath('data.active_link.state', 'active')
            ->assertJsonPath('link.shown_once', true);
        $second = $this->tokenFrom($correction);
        $this->assertNotSame($token, $second);
        $event = ActivityEvent::query()->where('event_type', ActivityEventType::CorrectionRequested->value)->sole();
        $this->assertStringNotContainsString('all pages', json_encode($event->toArray()), 'the reason text is not copied into the history');

        // The old (used) link stays dead; the new link shows the correction instructions only.
        $this->publicView($token)->assertStatus(410);
        $this->publicView($second)->assertOk()->assertJsonPath('data.is_correction', true)->assertJsonPath('data.correction.reason', $reason);

        // An identical file is refused; the corrected PDF becomes v2 of the SAME document.
        $this->publicSubmit($second, PdfFactory::fromBytes('agreement.pdf', $v1Bytes))->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'This file is identical to the one you submitted before. Please upload the corrected PDF.');
        $this->resetLimits();
        $v2Bytes = PdfFactory::bytes(['Agreement, pages 1-3']);
        $this->publicSubmit($second, PdfFactory::fromBytes('agreement-complete.pdf', $v2Bytes))->assertCreated()
            ->assertJsonPath('data.submission_number', 2)->assertJsonPath('data.is_correction', true);

        $document = Document::query()->sole();
        $this->assertSame(2, $document->latest_version_number);
        $versions = DocumentVersion::query()->orderBy('version_number')->get();
        $this->assertSame([1, 2], $versions->pluck('version_number')->all());
        $this->assertSame($v1Bytes, Storage::disk('documents')->get($versions[0]->storage_path), 'v1 is untouched');
        $this->assertSame($v2Bytes, Storage::disk('documents')->get($versions[1]->storage_path));
        $this->assertSame($reason, $first->fresh()->correction_reason, 'the earlier review is preserved');
        $this->assertSame('correction_requested', $first->fresh()->review_status->value);
        $this->assertSame(1, ActivityEvent::query()->where('event_type', ActivityEventType::DocumentResubmitted->value)->count());

        // Approving the OLD submission is impossible (409), and a second round creates v3.
        $secondSubmission = DocumentRequestSubmission::query()->where('submission_number', 2)->sole();
        $this->adminPost($request, 'start-review', ['submission_id' => $first->id, 'lock_version' => $this->lockVersion($request)])->assertStatus(409);
        $this->adminPost($request, 'start-review', ['submission_id' => $secondSubmission->id, 'lock_version' => $this->lockVersion($request)])->assertOk();
        $third = $this->tokenFrom($this->adminPost($request, 'request-correction', [
            'submission_id' => $secondSubmission->id, 'lock_version' => $this->lockVersion($request), 'reason' => 'Page 3 must be signed as well.',
        ])->assertOk());
        $thirdId = $this->submitAndStartReview($request, $third, 'Agreement, pages 1-3, signed');

        $this->adminPost($request, 'approve', ['submission_id' => $thirdId, 'lock_version' => $this->lockVersion($request)])
            ->assertOk()->assertJsonPath('data.approved_version.label', 'v3');
        $this->assertSame(DocumentVersion::query()->where('version_number', 3)->value('id'), $request->fresh()->approved_version_id);
        $this->assertSame(3, Document::query()->sole()->latest_version_number);
        $this->assertSame(1, Document::query()->count(), 'corrections never create unrelated documents');

        $history = $this->actingAsAdmin($this->admin())->getJson("/api/matters/{$request->matter_id}/document-requests/{$request->id}/submissions")->assertOk();
        $this->assertSame([3, 2, 1], array_column($history->json('data'), 'submission_number'));
        $this->assertSame(['approved', 'correction_requested', 'correction_requested'], array_column($history->json('data'), 'review_status'));
        $this->assertSame(['v3', 'v2', 'v1'], array_map(fn ($s) => $s['version']['label'], $history->json('data')));

        // No re-upload after approval.
        $this->publicView($third)->assertStatus(410)->assertJsonPath('code', 'request_approved');
        $this->assertSame(0, SecureAccessLink::query()->whereNull('revoked_at')->whereNull('consumed_at')->count());
    }

    #[Test]
    public function a_cancelled_request_closes_its_pending_submission_without_a_decision(): void
    {
        [$request, $token] = $this->requestWithLink();
        $this->publicSubmit($token, PdfFactory::upload())->assertCreated();
        $this->adminPost($request, 'cancel', ['lock_version' => $this->lockVersion($request)])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.latest_submission.review_status', 'cancelled');
        // The submitted document stays in the matter.
        $this->assertSame(1, Document::query()->count());
        $this->assertSame(1, ActivityEvent::query()->where('event_type', ActivityEventType::DocumentRequestCancelled->value)->count());
    }

    #[Test]
    public function final_review_decisions_and_submissions_are_immutable_in_the_database(): void
    {
        [$request, $token] = $this->requestWithLink();
        $id = $this->submitAndStartReview($request, $token, 'Final');
        $this->adminPost($request, 'approve', ['submission_id' => $id, 'lock_version' => $this->lockVersion($request)])->assertOk();

        foreach ([
            fn () => DB::table('document_request_submissions')->where('id', $id)->update(['review_status' => 'correction_requested', 'correction_reason' => 'Changed my mind later']),
            fn () => DB::table('document_request_submissions')->where('id', $id)->update(['client_message' => 'rewritten']),
            fn () => DB::table('document_request_submissions')->where('id', $id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The database should have refused the change.');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/final review decision cannot be changed|submitted facts are immutable|cannot be deleted/', $e->getMessage());
            }
        }
        $this->assertSame('approved', DocumentRequestSubmission::query()->sole()->review_status->value);
    }

    private function resetLimits(): void
    {
        $this->app['cache']->store()->flush();
    }
}
