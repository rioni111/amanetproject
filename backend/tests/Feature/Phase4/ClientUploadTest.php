<?php

namespace Tests\Feature\Phase4;

use App\Enums\ActivityEventType;
use App\Models\ActivityEvent;
use App\Models\Document;
use App\Models\DocumentRequestSubmission;
use App\Models\DocumentVersion;
use App\Models\MatterNote;
use App\Models\SecureAccessLink;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\TestCase;

/** Scenarios D and E (backend): passwordless client upload and every rejection path. */
class ClientUploadTest extends TestCase
{
    use RefreshDatabase, RequestWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function resetRateLimits(): void
    {
        $this->app['cache']->store()->flush();
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, DocumentVersion::query()->count());
        $this->assertSame(0, DocumentRequestSubmission::query()->count());
        $this->assertSame([], Storage::disk('documents')->allFiles(), 'no file (and no staging leftover) remains');
    }

    #[Test]
    public function a_client_uploads_the_requested_pdf_without_any_account(): void
    {
        $matter = $this->matterFor('Northwind Holdings Ltd');
        MatterNote::factory()->for($matter)->create(['body' => 'INTERNAL: settlement ceiling 40k']);
        [$request, $token] = $this->requestWithLink($matter, ['due_date' => now('UTC')->addDays(5)->format('Y-m-d')]);

        // What the client sees: only this request.
        $view = $this->publicView($token)->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertJsonPath('data.title', 'Signed Service Agreement')
            ->assertJsonPath('data.category_label', 'Agreements')
            ->assertJsonPath('data.is_correction', false)
            ->assertJsonPath('data.max_file_mb', 20)
            ->assertJsonPath('data.firm_name', 'Lexora Legal');
        $this->assertSame(['firm_name', 'title', 'instructions', 'category', 'category_label', 'due_date', 'is_overdue', 'expires_at', 'max_file_mb', 'is_correction', 'correction'], array_keys($view->json('data')));
        foreach ([$matter->matter_number, $matter->title, 'Northwind', 'INTERNAL', 'Elena Marsh', 'matter_id', 'client_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $view->getContent());
        }
        $this->assertNotNull(SecureAccessLink::query()->sole()->first_opened_at, 'opening is recorded…');
        $this->assertNull(SecureAccessLink::query()->sole()->consumed_at, '…but does not consume the link');

        $bytes = PdfFactory::bytes(['Service Agreement', 'Signature page']);
        $response = $this->publicSubmit($token, PdfFactory::fromBytes('Service Agreement (signed).pdf', $bytes), "Signed copy attached.\x07")
            ->assertCreated()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('message', 'Your document was submitted successfully. The firm will review it.')
            ->assertJsonPath('data.submission_number', 1)
            ->assertJsonPath('data.original_filename', 'Service Agreement (signed).pdf')
            ->assertJsonPath('data.file_size', strlen($bytes))
            ->assertJsonPath('data.is_correction', false);
        $this->assertSame(['submission_number', 'submitted_at', 'original_filename', 'file_size', 'is_correction'], array_keys($response->json('data')));
        foreach (['storage', 'document_id', 'version_id', 'matter', 'sha256'] as $internal) {
            $this->assertStringNotContainsString($internal, $response->getContent());
        }

        // A real document in the correct matter, origin client, immutable v1 with the exact bytes.
        $document = Document::query()->sole();
        $this->assertSame($matter->id, $document->matter_id);
        $this->assertSame('client', $document->origin->value);
        $this->assertSame('Signed Service Agreement', $document->title);
        $this->assertSame('agreements', $document->category->value);
        $this->assertNull($document->uploaded_by, 'no administrator identity is invented');
        $version = DocumentVersion::query()->sole();
        $this->assertSame(1, $version->version_number);
        $this->assertSame('client', $version->origin->value);
        $this->assertSame('Northwind Holdings Ltd (client)', $version->uploaded_by_name);
        $this->assertNull($version->uploaded_by);
        $this->assertSame(hash('sha256', $bytes), $version->sha256_checksum);
        $this->assertMatchesRegularExpression('#^'.$matter->id.'/[0-9a-f-]{36}/[0-9a-f-]{36}\.pdf$#', $version->storage_path);
        $this->assertSame($bytes, Storage::disk('documents')->get($version->storage_path));
        $this->assertSame([], Storage::disk('documents')->allFiles('_staging'));

        $submission = DocumentRequestSubmission::query()->sole();
        $this->assertSame($version->id, $submission->document_version_id);
        $this->assertSame('Signed copy attached.', $submission->client_message, 'control characters removed');
        $this->assertSame('pending', $submission->review_status->value);

        $request->refresh();
        $this->assertSame('submitted', $request->status->value);
        $this->assertSame($document->id, $request->document_id);
        $this->assertSame(1, $request->submissions_count);
        $link = SecureAccessLink::query()->sole();
        $this->assertSame(1, $link->successful_uses);
        $this->assertNotNull($link->consumed_at);

        $event = ActivityEvent::query()->where('event_type', ActivityEventType::DocumentSubmitted->value)->sole();
        $this->assertSame('client', $event->actor_type->value);
        $this->assertSame('Northwind Holdings Ltd', $event->actor_label);
        $this->assertSame($request->id, $event->actor_id);
        $this->assertSame('secure_link', $event->metadata['source']);
        $this->assertSame(1, $event->metadata['submission_number']);
        $this->assertTrue($event->metadata['has_client_message']);
        $this->assertStringNotContainsString('Signed copy attached', json_encode($event->metadata), 'client messages stay out of the history');
        $this->assertStringNotContainsString($token, json_encode($event->toArray()));

        // It appears in the existing Documents tab and the global library.
        $this->actingAsAdmin($this->admin())->getJson("/api/matters/{$matter->id}/documents")->assertJsonPath('data.0.origin', 'client');
        $this->getJson('/api/documents')->assertJsonPath('data.0.id', $document->id);
        $this->showRequest($request)->assertJsonPath('data.latest_submission.version.label', 'v1')
            ->assertJsonPath('data.latest_submission.client_message', 'Signed copy attached.')
            ->assertJsonPath('data.allowed_actions.start_review', true);
    }

    #[Test]
    public function invalid_files_are_rejected_and_leave_nothing_behind(): void
    {
        [$request, $token] = $this->requestWithLink();
        $cases = [
            'not a pdf' => [UploadedFile::fake()->create('photo.png', 10, 'image/png'), 'Only PDF files (.pdf) can be uploaded.'],
            'renamed text' => [PdfFactory::notAPdf(), null],
            'empty' => [PdfFactory::fromBytes('empty.pdf', ''), null],
            'truncated' => [PdfFactory::truncated(), null],
            'encrypted' => [PdfFactory::encrypted(), null],
            'oversized' => [UploadedFile::fake()->create('huge.pdf', 21 * 1024, 'application/pdf'), 'The PDF is larger than the 20 MB limit.'],
        ];
        foreach ($cases as $label => [$file, $message]) {
            $this->resetRateLimits(); // 5 submissions per token per minute (tested separately)
            $response = $this->publicSubmit($token, $file)->assertUnprocessable();
            $this->assertNotEmpty($response->json('errors.file'), $label);
            if ($message) {
                $this->assertContains($message, $response->json('errors.file'), $label);
            }
        }
        $this->resetRateLimits();
        $this->publicSubmit($token, null)->assertUnprocessable()->assertJsonPath('errors.file.0', 'Choose a PDF file to upload.');
        $this->publicSubmit($token, PdfFactory::upload(), str_repeat('x', 2001))->assertUnprocessable()->assertJsonValidationErrors(['message']);
        // Server-managed fields cannot be smuggled in.
        $this->publicSubmit($token, PdfFactory::upload(), null, [], ['matter_id' => 1, 'document_id' => 1, 'status' => 'approved', 'origin' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['matter_id', 'document_id', 'status', 'origin']);

        $this->resetRateLimits();
        $this->assertNothingStored();
        // Invalid attempts do not count as uses: the link still works.
        $this->assertSame(0, SecureAccessLink::query()->sole()->successful_uses);
        $this->publicSubmit($token, PdfFactory::upload())->assertCreated();
        $this->assertSame('submitted', $request->fresh()->status->value);
    }

    #[Test]
    public function an_unknown_token_never_reaches_file_validation(): void
    {
        $this->requestWithLink();
        $this->publicSubmit('A'.str_repeat('b', 42), PdfFactory::notAPdf())
            ->assertNotFound()->assertJsonMissingPath('errors')->assertJsonPath('code', 'link_invalid');
        $this->publicSubmit(null, PdfFactory::upload())->assertNotFound();
        $this->assertNothingStored();
    }

    #[Test]
    public function a_failure_inside_the_transaction_rolls_back_and_keeps_the_link_usable(): void
    {
        [$request, $token] = $this->requestWithLink();
        $this->app->instance(ActivityLogger::class, new class extends ActivityLogger
        {
            public function recordClient(...$args): never
            {
                throw new RuntimeException('history unavailable');
            }
        });

        $this->publicSubmit($token, PdfFactory::upload())->assertServerError();

        $this->assertNothingStored();
        $link = SecureAccessLink::query()->sole();
        $this->assertNull($link->consumed_at, 'a link is consumed only together with a committed version');
        $this->assertSame(0, $link->successful_uses);
        $this->assertSame('pending', $request->fresh()->status->value);
    }

    #[Test]
    public function the_public_api_is_isolated_from_admin_sessions_csrf_and_foreign_origins(): void
    {
        [, $token] = $this->requestWithLink();

        // From the SPA origin, without any CSRF token or cookie: accepted (token-authorised, no session).
        $this->publicSubmit($token, PdfFactory::upload(), null, ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])
            ->assertCreated()->assertCookieMissing(config('session.cookie'));

        // Another website cannot drive the endpoint from a browser.
        [, $other] = $this->requestWithLink();
        $this->publicView($other, ['Origin' => 'https://evil.example'])->assertForbidden()->assertJsonPath('code', 'origin_not_allowed');
        $this->publicSubmit($other, PdfFactory::upload('b.pdf', ['B']), null, ['Origin' => 'https://evil.example'])->assertForbidden();

        // A link token is not an admin credential.
        $this->asGuest()->getJson('/api/matters', ['X-Upload-Token' => $other])->assertUnauthorized();
        $this->asGuest()->getJson('/api/dashboard/summary', ['X-Upload-Token' => $other])->assertUnauthorized();
    }

    #[Test]
    public function repeated_invalid_tokens_are_locked_out_and_requests_are_rate_limited(): void
    {
        config(['lexora.public_links.invalid_attempts' => 3]);
        [, $token] = $this->requestWithLink();
        for ($i = 0; $i < 3; $i++) {
            $this->publicView('guess'.$i)->assertNotFound();
        }
        $this->publicView('guess-again')->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        $this->publicView($token)->assertStatus(429); // the lockout is per IP, before any lookup

        // Per-token submission limit (5 per minute) also applies to valid tokens.
        $this->app['cache']->store()->flush();
        [, $fresh] = $this->requestWithLink();
        for ($i = 0; $i < 5; $i++) {
            $this->publicSubmit($fresh, PdfFactory::notAPdf())->assertUnprocessable();
        }
        $this->publicSubmit($fresh, PdfFactory::upload())->assertStatus(429);
    }
}
