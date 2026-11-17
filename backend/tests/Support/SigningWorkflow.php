<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\Document;
use App\Models\Matter;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\SigningConsent;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 5 helpers: documents, signing requests through the real admin API, and anonymous calls to the
 * public signing API with the token in the X-Signing-Token header.
 *
 * @mixin TestCase
 */
trait SigningWorkflow
{
    protected ?User $signingAdmin = null;

    protected function signingAdmin(): User
    {
        return $this->signingAdmin ??= User::factory()->create(['name' => 'Elena Marsh', 'email' => 'elena.marsh@lexora.test']);
    }

    protected function signingMatter(string $client = 'Northwind Holdings Ltd'): Matter
    {
        return Matter::factory()->for(Client::factory()->state(['name' => $client, 'email' => 'legal@northwind.example']))->create();
    }

    /** Uploads a PDF through the admin API and returns the document (with current version). */
    protected function uploadDocument(Matter $matter, ?string $bytes = null, string $title = 'Client Service Agreement'): Document
    {
        $id = $this->actingAsAdmin($this->signingAdmin())->post("/api/matters/{$matter->id}/documents", [
            'file' => PdfFactory::fromBytes('service-agreement.pdf', $bytes ?? PdfFactory::bytes(['Client Service Agreement', 'Terms', 'Signature page'])),
            'title' => $title,
            'category' => 'agreements',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return Document::query()->with('currentVersion')->findOrFail($id);
    }

    protected function createSigning(Matter $matter, Document $document, array $overrides = []): TestResponse
    {
        return $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$matter->id}/signing-requests", [
            'document_id' => $document->id,
            'document_version_id' => $document->current_version_id,
            'signer_type' => 'client',
            'signer_name' => 'Ana Krasniqi',
            'signer_email' => 'ana.krasniqi@northwind.example',
            'issue_link' => true,
            ...$overrides,
        ]);
    }

    /** @return array{0: SigningRequest, 1: string} request and raw signing token */
    protected function clientSigning(?Matter $matter = null, ?Document $document = null, array $overrides = []): array
    {
        $matter ??= $this->signingMatter();
        $document ??= $this->uploadDocument($matter);
        $response = $this->createSigning($matter, $document, $overrides)->assertCreated();

        return [SigningRequest::query()->findOrFail($response->json('data.id')), $response->json('link.url') ? $this->signingTokenFrom($response) : ''];
    }

    protected function signingTokenFrom(TestResponse $response): string
    {
        $url = (string) $response->json('link.url');
        $this->assertMatchesRegularExpression('#/client-sign\#[A-Za-z0-9_-]{43}$#', $url);

        return substr($url, strrpos($url, '#') + 1);
    }

    protected function signingAction(SigningRequest $request, string $action, array $body = []): TestResponse
    {
        return $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$request->matter_id}/signing-requests/{$request->id}/{$action}", $body);
    }

    protected function signingDetail(SigningRequest $request): TestResponse
    {
        return $this->actingAsAdmin($this->signingAdmin())->getJson("/api/matters/{$request->matter_id}/signing-requests/{$request->id}");
    }

    protected function forgetUser(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this;
    }

    protected function publicSigning(string $method, string $path, ?string $token, array $body = [], array $headers = []): TestResponse
    {
        $headers = ['Accept' => 'application/json', ...($token !== null ? ['X-Signing-Token' => $token] : []), ...$headers];

        return $this->forgetUser()->json($method, '/api/public/signing'.$path, $body, $headers);
    }

    /** Opens, views and starts — what the signing page does before the signer signs. */
    protected function viewAndStart(string $token): void
    {
        $this->publicSigning('GET', '', $token)->assertOk();
        $this->publicSigning('POST', '/view', $token)->assertOk();
        $this->publicSigning('POST', '/start', $token)->assertOk()->assertJsonPath('data.status', 'started');
    }

    /** A plausible drawn signature (vector strokes, normalised to the signature area). */
    protected function strokes(): array
    {
        $wave = [];
        for ($i = 0; $i <= 20; $i++) {
            $wave[] = [0.05 + $i * 0.04, 0.5 + 0.3 * sin($i / 2)];
        }

        return [$wave, [[0.2, 0.8], [0.4, 0.7], [0.6, 0.85], [0.8, 0.75]]];
    }

    /** @return array<string, mixed> */
    protected function signaturePayload(array $overrides = []): array
    {
        return [
            'method' => 'drawn',
            'strokes' => $this->strokes(),
            'page' => 1,
            'x' => 0.5,
            'y' => 0.75,
            'width' => 0.35,
            'consent' => true,
            'consent_version' => SigningConsent::VERSION,
            'confirmed_name' => 'Ana Krasniqi',
            ...$overrides,
        ];
    }
}
