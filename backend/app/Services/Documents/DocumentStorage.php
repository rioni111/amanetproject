<?php

namespace App\Services\Documents;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private PDF storage. Every path is generated here — never from user input:
 *
 *   _staging/{uuid}.part                               while a request is being processed
 *   {matter_id}/{document_uuid}/{version_uuid}.pdf     committed version (never overwritten)
 *
 * The disk ("documents") is a local private disk outside the web root with HTTP serving disabled;
 * files are only ever streamed by an authorised controller.
 */
class DocumentStorage
{
    public const STAGING_DIR = '_staging';

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('lexora.documents.disk', 'documents');
    }

    /**
     * Copies the upload to a private staging file and verifies the copy byte-for-byte (size and
     * SHA-256), so a failed or partial write can never become a version.
     */
    public function stage(UploadedFile $file, string $expectedSha256): string
    {
        $path = self::STAGING_DIR.'/'.Str::uuid()->toString().'.part';
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $this->disk()->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($this->disk()->size($path) !== $file->getSize() || $this->sha256($path) !== $expectedSha256) {
            $this->discard($path);
            throw new RuntimeException('The uploaded file could not be stored intact.');
        }

        return $path;
    }

    /** Moves a verified staging file to its final, never-before-used location. */
    public function finalize(string $stagingPath, int $matterId, string $documentUuid, string $versionUuid): string
    {
        $final = sprintf('%d/%s/%s.pdf', $matterId, $documentUuid, $versionUuid);
        if ($this->disk()->exists($final)) {
            throw new RuntimeException('Refusing to overwrite an existing stored version.');
        }
        $this->disk()->move($stagingPath, $final);

        return $final;
    }

    public function discard(?string $path): void
    {
        if ($path !== null && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    public function sha256(string $path): string
    {
        $context = hash_init('sha256');
        $stream = $this->disk()->readStream($path);
        try {
            hash_update_stream($context, $stream);
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }
}
