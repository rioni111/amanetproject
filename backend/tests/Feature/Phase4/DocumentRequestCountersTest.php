<?php

namespace Tests\Feature\Phase4;

use App\Models\DocumentRequest;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\TestCase;

/** Scenario G (backend): outstanding requirements, overdue logic, filters and dashboard figures. */
class DocumentRequestCountersTest extends TestCase
{
    use RefreshDatabase, RequestWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['lexora.timezone' => 'Europe/Budapest']);
    }

    /** One request in every state, two of them overdue, plus one on an archived matter (not counted). */
    private function workspace(): Matter
    {
        Carbon::setTestNow('2026-11-17 12:00:00'); // 13:00 in Budapest
        $matter = $this->matterFor('Counter Client');

        [$pendingOverdue] = $this->requestWithLink($matter, ['title' => 'Overdue ID', 'due_date' => '2026-11-18']);
        $this->requestWithLink($matter, ['title' => 'Pending later', 'due_date' => '2026-12-31']);
        $this->requestWithLink($matter, ['title' => 'Due today', 'due_date' => '2026-11-18']);
        [$submitted, $t1] = $this->requestWithLink($matter, ['title' => 'Submitted one', 'due_date' => '2026-11-18']);
        $this->publicSubmit($t1, PdfFactory::upload('s.pdf', ['Submitted']))->assertCreated();
        [$review, $t2] = $this->requestWithLink($matter, ['title' => 'In review']);
        $this->submitAndStartReview($review, $t2, 'Review');
        [$correction, $t3] = $this->requestWithLink($matter, ['title' => 'Needs fixing', 'due_date' => '2026-11-18']);
        $id = $this->submitAndStartReview($correction, $t3, 'Fix me');
        $this->adminPost($correction, 'request-correction', ['submission_id' => $id, 'lock_version' => $this->lockVersion($correction), 'reason' => 'Please include the missing appendix.'])->assertOk();
        [$approved, $t4] = $this->requestWithLink($matter, ['title' => 'All good']);
        $id = $this->submitAndStartReview($approved, $t4, 'Good');
        $this->adminPost($approved, 'approve', ['submission_id' => $id, 'lock_version' => $this->lockVersion($approved)])->assertOk();
        [$cancelled] = $this->requestWithLink($matter, ['title' => 'Not needed']);
        $this->adminPost($cancelled, 'cancel', ['lock_version' => $this->lockVersion($cancelled)])->assertOk();

        $archivedMatter = $this->matterFor('Archived Client');
        $this->requestWithLink($archivedMatter, ['title' => 'On archived matter', 'due_date' => '2026-11-18']);
        $this->actingAsAdmin($this->admin())->postJson("/api/matters/{$archivedMatter->id}/archive", ['lock_version' => $archivedMatter->fresh()->lock_version])->assertOk();

        // Two days later: due dates of 18 Nov have passed in the firm's time zone.
        Carbon::setTestNow('2026-11-19 23:30:00'); // 00:30 on 20 Nov in Budapest
        $this->assertSame('pending', $pendingOverdue->fresh()->status->value);
        $this->assertSame('submitted', $submitted->fresh()->status->value);

        return $matter;
    }

    #[Test]
    public function dashboard_and_matter_counters_count_each_request_once(): void
    {
        $matter = $this->workspace();

        $stats = $this->actingAsAdmin($this->admin())->getJson('/api/dashboard/summary')->assertOk()->json('data.stats');
        $this->assertSame([
            'requests_outstanding' => 6,   // 3 pending + submitted + in review + correction (archived matter excluded)
            'requests_pending' => 3,
            'requests_awaiting_review' => 2,
            'requests_correction' => 1,
            'requests_overdue' => 3,       // pending "Overdue ID", "Due today" (18 Nov) and the correction; submitted is the firm's turn
            'requests_approved' => 1,
        ], array_intersect_key($stats, array_flip(['requests_outstanding', 'requests_pending', 'requests_awaiting_review', 'requests_correction', 'requests_overdue', 'requests_approved'])));

        $this->getJson("/api/matters/{$matter->id}")->assertOk()->assertJsonPath('data.requests', [
            'open' => 6, 'pending' => 3, 'awaiting_review' => 2, 'correction_requested' => 1, 'approved' => 1, 'overdue' => 3,
        ]);
    }

    #[Test]
    public function the_request_list_filters_searches_sorts_and_paginates_on_the_server(): void
    {
        $matter = $this->workspace();
        $this->actingAsAdmin($this->admin());

        $this->assertSame(3, $this->getJson('/api/document-requests?overdue=1')->assertOk()->json('meta.total'));
        $this->assertSame(['Needs fixing'], array_column($this->getJson('/api/document-requests?status=correction_requested')->json('data'), 'title'));
        $this->assertSame(2, $this->getJson('/api/document-requests?status=awaiting_review')->json('meta.total'));
        $this->assertSame(4, $this->getJson('/api/document-requests?status=awaiting_client')->json('meta.total')); // 3 pending + correction
        $this->assertSame(3, $this->getJson('/api/document-requests?status=pending')->json('meta.total'), 'same figure as the dashboard: archived matters excluded');
        $this->assertSame(1, $this->getJson('/api/document-requests?status=approved')->json('meta.total'));
        $this->assertSame(['All good'], array_column($this->getJson('/api/document-requests?search=all%20go')->json('data'), 'title'));
        $this->assertSame(1, $this->getJson('/api/document-requests?search=Archived%20Client')->json('meta.total'));
        $this->assertSame(8, $this->getJson('/api/document-requests?search='.$matter->matter_number)->json('meta.total'));

        $page = $this->getJson('/api/document-requests?per_page=3&page=2&sort=title')->assertOk();
        $this->assertSame(['current_page' => 2, 'last_page' => 3, 'per_page' => 3, 'total' => 9, 'from' => 4, 'to' => 6], $page->json('meta'));
        $this->assertSame(['Needs fixing', 'Not needed', 'On archived matter'], array_column($page->json('data'), 'title'));
        $due = array_column($this->getJson('/api/document-requests?sort=due_at&per_page=50')->json('data'), 'due_date');
        $this->assertSame(array_values(array_filter($due)), array_slice($due, 0, count(array_filter($due))), 'requests without a due date sort last');

        $this->getJson('/api/document-requests?status=signed')->assertUnprocessable();
        $this->getJson('/api/document-requests?sort=token_hash')->assertUnprocessable();
        $this->assertSame(9, DocumentRequest::query()->count());
    }
}
