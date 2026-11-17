<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Helpers for Phase 4 tests: drive the real HTTP API as an administrator and as an anonymous client.
 * Public calls are made WITHOUT any authenticated user and with the token in the X-Upload-Token header.
 *
 * @mixin TestCase
 */
trait RequestWorkflow
{
    protected ?User $admin = null;

    protected function admin(): User
    {
        return $this->admin ??= User::factory()->create(['name' => 'Elena Marsh']);
    }

    protected function matterFor(string $clientName = 'Northwind Holdings Ltd'): Matter
    {
        return Matter::factory()->for(Client::factory()->state(['name' => $clientName]))->create();
    }

    /** @param array<string, mixed> $overrides */
    protected function createRequest(Matter $matter, array $overrides = [], bool $issueLink = true): TestResponse
    {
        return $this->actingAsAdmin($this->admin())->postJson("/api/matters/{$matter->id}/document-requests", [
            'title' => 'Signed Service Agreement',
            'instructions' => 'Please upload the signed service agreement, all pages included.',
            'requested_category' => 'agreements',
            'issue_link' => $issueLink,
            ...$overrides,
        ]);
    }

    /** @return array{0: DocumentRequest, 1: string} the request and the raw token of its upload link */
    protected function requestWithLink(?Matter $matter = null, array $overrides = []): array
    {
        $matter ??= $this->matterFor();
        $response = $this->createRequest($matter, $overrides)->assertCreated();

        return [DocumentRequest::query()->findOrFail($response->json('data.id')), $this->tokenFrom($response)];
    }

    protected function tokenFrom(TestResponse $response): string
    {
        $url = (string) $response->json('link.url');
        $this->assertMatchesRegularExpression('#/client-upload\#[A-Za-z0-9_-]{43}$#', $url);

        return substr($url, strrpos($url, '#') + 1);
    }

    protected function adminPost(DocumentRequest $request, string $action, array $body = []): TestResponse
    {
        return $this->actingAsAdmin($this->admin())->postJson("/api/matters/{$request->matter_id}/document-requests/{$request->id}/{$action}", $body);
    }

    protected function showRequest(DocumentRequest $request): TestResponse
    {
        return $this->actingAsAdmin($this->admin())->getJson("/api/matters/{$request->matter_id}/document-requests/{$request->id}");
    }

    /** Anonymous request: forget any authenticated user from earlier calls in the same test. */
    protected function asGuest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this;
    }

    protected function publicView(?string $token, array $headers = []): TestResponse
    {
        return $this->asGuest()->getJson('/api/public/document-upload', [...($token !== null ? ['X-Upload-Token' => $token] : []), ...$headers]);
    }

    protected function publicSubmit(?string $token, ?UploadedFile $file, ?string $message = null, array $headers = [], array $extra = []): TestResponse
    {
        $body = array_filter(['file' => $file, 'message' => $message], fn ($v) => $v !== null) + $extra;

        return $this->asGuest()->post('/api/public/document-upload/submit', $body,
            ['Accept' => 'application/json', ...($token !== null ? ['X-Upload-Token' => $token] : []), ...$headers]);
    }

    /** Submits, starts review and returns the latest submission id. */
    protected function submitAndStartReview(DocumentRequest $request, string $token, string $text): int
    {
        $this->publicSubmit($token, PdfFactory::upload('agreement.pdf', [$text]))->assertCreated();
        $detail = $this->showRequest($request)->assertOk();
        $submissionId = (int) $detail->json('data.latest_submission.id');
        $this->adminPost($request, 'start-review', ['submission_id' => $submissionId, 'lock_version' => $detail->json('data.lock_version')])->assertOk();

        return $submissionId;
    }

    protected function lockVersion(DocumentRequest $request): int
    {
        return (int) DocumentRequest::query()->whereKey($request->id)->value('lock_version');
    }
}
