<?php

namespace Tests\Feature\Phase4;

use App\Models\DocumentRequestSubmission;
use App\Models\SecureAccessLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\TestCase;

/** Scenario J (backend): authorisation, nested scoping, server-owned fields and token leakage. */
class DocumentRequestSecurityTest extends TestCase
{
    use RefreshDatabase, RequestWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    #[Test]
    public function every_administrator_endpoint_requires_an_authenticated_session(): void
    {
        [$request] = $this->requestWithLink();
        $link = SecureAccessLink::query()->sole();
        $base = "/api/matters/{$request->matter_id}/document-requests";
        $this->asGuest();

        foreach ([
            ['get', '/api/document-requests'], ['get', $base], ['post', $base], ['get', "{$base}/{$request->id}"],
            ['patch', "{$base}/{$request->id}"], ['post', "{$base}/{$request->id}/cancel"], ['post', "{$base}/{$request->id}/links"],
            ['post', "{$base}/{$request->id}/links/rotate"], ['post', "{$base}/{$request->id}/links/{$link->id}/revoke"],
            ['get', "{$base}/{$request->id}/submissions"], ['post', "{$base}/{$request->id}/start-review"],
            ['post', "{$base}/{$request->id}/approve"], ['post', "{$base}/{$request->id}/request-correction"],
        ] as [$method, $uri]) {
            $this->json($method, $uri, [], ['Accept' => 'application/json'])->assertUnauthorized();
        }
    }

    #[Test]
    public function nested_resources_cannot_be_reached_through_another_parent(): void
    {
        [$a, $tokenA] = $this->requestWithLink($this->matterFor('Client A'));
        [$b] = $this->requestWithLink($this->matterFor('Client B'));
        $linkA = SecureAccessLink::query()->where('document_request_id', $a->id)->sole();
        $this->publicSubmit($tokenA, PdfFactory::upload())->assertCreated();
        $this->actingAsAdmin($this->admin());

        $this->getJson("/api/matters/{$b->matter_id}/document-requests/{$a->id}")->assertNotFound();
        $this->getJson("/api/matters/{$b->matter_id}/document-requests/{$a->id}/submissions")->assertNotFound();
        $this->postJson("/api/matters/{$b->matter_id}/document-requests/{$a->id}/cancel")->assertNotFound();
        $this->postJson("/api/matters/{$b->matter_id}/document-requests/{$b->id}/links/{$linkA->id}/revoke")->assertNotFound();
        $this->postJson("/api/matters/{$a->matter_id}/document-requests/{$a->id}/start-review", [
            'submission_id' => DocumentRequestSubmission::query()->sole()->id + 1000, 'lock_version' => $this->lockVersion($a),
        ])->assertStatus(409);
        // Matter B's list does not contain matter A's request; nor its submitted document.
        $this->assertSame([$b->id], array_column($this->getJson("/api/matters/{$b->matter_id}/document-requests")->json('data'), 'id'));
        $this->assertSame([], $this->getJson("/api/matters/{$b->matter_id}/documents")->json('data'));
        $this->assertNull($b->fresh()->document_id);
    }

    #[Test]
    public function lifecycle_endpoints_reject_server_managed_fields(): void
    {
        [$request] = $this->requestWithLink();
        $this->adminPost($request, 'cancel', ['status' => 'approved', 'approved_at' => now()->toIso8601String(), 'reviewed_by' => 1, 'token_hash' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'approved_at', 'reviewed_by', 'token_hash']);
        $this->adminPost($request, 'links/rotate', ['expires_at' => now()->addYear()->toIso8601String(), 'max_uses' => 99, 'consumed_at' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors(['expires_at', 'max_uses', 'consumed_at']);
        $this->actingAsAdmin($this->admin())->patchJson("/api/matters/{$request->matter_id}/document-requests/{$request->id}", [
            'client_id' => 5, 'matter_id' => 5, 'document_version_id' => 5, 'lock_version' => $this->lockVersion($request),
        ])->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'matter_id', 'document_version_id']);
        $this->assertSame('pending', $request->fresh()->status->value);
    }

    #[Test]
    public function raw_tokens_never_reach_the_logs_or_persistent_records(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        [$request, $token] = $this->requestWithLink();
        $this->publicView($token)->assertOk();
        $this->publicSubmit($token, PdfFactory::notAPdf())->assertUnprocessable();
        $this->publicSubmit($token, PdfFactory::upload())->assertCreated();
        $this->publicSubmit($token, PdfFactory::upload('again.pdf', ['Again']))->assertStatus(410);
        $id = DocumentRequestSubmission::query()->sole()->id;
        $this->adminPost($request, 'start-review', ['submission_id' => $id, 'lock_version' => $this->lockVersion($request)])->assertOk();
        $second = $this->tokenFrom($this->adminPost($request, 'request-correction', [
            'submission_id' => $id, 'lock_version' => $this->lockVersion($request), 'reason' => 'Please send a clearer scan of page two.',
        ])->assertOk());

        foreach ([$token, $second] as $t) {
            foreach ($logged as $line) {
                $this->assertStringNotContainsString($t, $line);
            }
            foreach (['activity_events', 'secure_access_links', 'document_requests', 'document_request_submissions', 'documents', 'document_versions'] as $table) {
                $this->assertStringNotContainsString($t, json_encode(\DB::table($table)->get()), $table);
            }
        }
        $log = storage_path('logs/laravel.log');
        if (is_file($log)) {
            $contents = (string) file_get_contents($log);
            $this->assertStringNotContainsString($token, $contents);
            $this->assertStringNotContainsString($second, $contents);
        }
    }
}
