<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams the exact bytes of ONE requested version (never "the latest" when a version was asked for)
 * after authorisation, ownership (scoped bindings matter → document → version), existence and
 * SHA-256 integrity checks. Paths never leave the server.
 */
class DocumentFileController extends Controller
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DocumentService $documents,
    ) {}

    public function preview(Matter $matter, Document $document, DocumentVersion $version): StreamedResponse
    {
        return $this->stream($document, $version, HeaderUtils::DISPOSITION_INLINE);
    }

    public function download(Request $request, Matter $matter, Document $document, DocumentVersion $version): StreamedResponse
    {
        $response = $this->stream($document, $version, HeaderUtils::DISPOSITION_ATTACHMENT);
        $this->documents->recordDownload($matter, $document, $version, $request->user());

        return $response;
    }

    private function stream(Document $document, DocumentVersion $version, string $disposition): StreamedResponse
    {
        Gate::authorize('view', $document);

        $disk = $this->storage->disk();
        if ($version->storage_disk !== $this->storage->diskName() || ! $disk->exists($version->storage_path)) {
            Log::error('Document version file missing', ['document_version_id' => $version->id]);
            throw new NotFoundHttpException('The stored file for this version could not be found.');
        }
        if (! hash_equals($version->sha256_checksum, $this->storage->sha256($version->storage_path))) {
            Log::critical('Document version failed its integrity check', ['document_version_id' => $version->id]);
            throw new HttpException(409, 'This file failed its integrity check and cannot be opened. The incident has been logged.');
        }

        $fallback = Str::ascii($version->original_filename);
        $fallback = preg_replace('/[^A-Za-z0-9 ._()-]/', '_', $fallback) ?: 'document.pdf';
        $path = $version->storage_path;

        return new StreamedResponse(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) $version->file_size,
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $version->original_filename, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'X-Document-Version' => (string) $version->version_number,
            'X-Document-Sha256' => $version->sha256_checksum,
        ]);
    }
}
