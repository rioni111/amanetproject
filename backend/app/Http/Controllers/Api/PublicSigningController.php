<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Signing\PublicSignatureRequest;
use App\Models\SecureAccessLink;
use App\Services\Documents\DocumentStorage;
use App\Services\Signing\SigningService;
use App\Support\SigningConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Public, passwordless client signing (authorised by AuthorizePublicSigningLink). Only the designated source
 * PDF of THIS request and the minimum needed to sign it are exposed: no matter, client record, notes, other
 * documents or versions, internal ids or storage paths.
 */
class PublicSigningController extends Controller
{
    public function __construct(
        private readonly SigningService $signing,
        private readonly DocumentStorage $storage,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $signingRequest = $link->signingRequest;

        return response()->json(['data' => [
            'firm_name' => (string) config('lexora.firm_name'),
            'document_title' => $signingRequest->document->title,
            'version_label' => $signingRequest->sourceVersion->label(),
            'file_name' => $signingRequest->sourceVersion->original_filename,
            'signer_name' => $signingRequest->signer_name,
            'signer_email' => self::maskEmail($signingRequest->signer_email),
            'message' => $signingRequest->message,
            'status' => $signingRequest->status->value,
            'viewed' => $signingRequest->viewed_at !== null,
            'started' => $signingRequest->started_at !== null,
            'expires_at' => $link->expires_at->toIso8601String(),
            'consent' => ['version' => SigningConsent::VERSION, 'text' => SigningConsent::TEXT],
        ]]);
    }

    /** The designated source PDF (bytes verified against the stored SHA-256). Fetching it does NOT record a view. */
    public function document(Request $request): BinaryFileResponse
    {
        $version = $this->link($request)->signingRequest->sourceVersion;
        $path = $this->storage->absolutePath($version->storage_path);
        if (! is_file($path) || ! hash_equals($version->sha256_checksum, (string) hash_file('sha256', $path))) {
            throw new ConflictHttpException('The document could not be loaded. Please contact the firm.');
        }

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', 'document.pdf'),
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Resource-Policy' => 'same-site',
        ]);
    }

    /** Called by the signing page after the PDF has been rendered in the signer's browser. */
    public function view(Request $request): JsonResponse
    {
        $signingRequest = $this->signing->recordViewed($this->link($request));

        return response()->json(['data' => ['status' => $signingRequest->status->value]]);
    }

    public function start(Request $request): JsonResponse
    {
        $signingRequest = $this->signing->recordStarted($this->link($request));

        return response()->json(['data' => ['status' => $signingRequest->status->value]]);
    }

    public function complete(PublicSignatureRequest $request): JsonResponse
    {
        $signingRequest = $this->signing->signAsClient($this->link($request), $request->signatureInput(), $request->ip(), $request->userAgent());

        return response()->json([
            'message' => 'The document was signed successfully. The firm has received your signed document.',
            'data' => [
                'status' => $signingRequest->status->value,
                'signed_at' => $signingRequest->signed_at?->toIso8601String(),
                'document_title' => $signingRequest->document()->value('title'),
            ],
        ], 201);
    }

    private function link(Request $request): SecureAccessLink
    {
        return $request->attributes->get('signing_link');
    }

    /** The designated address is shown partially, so a leaked link does not reveal a full e-mail address. */
    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('•', max(1, min(6, mb_strlen($local) - 1))).'@'.$domain;
    }
}
