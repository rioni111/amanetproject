<?php

namespace App\Services\Documents;

/**
 * Server-side PDF checks on an uploaded (untrusted) file. Nothing in the file is executed or
 * rendered on the server; the checks are byte-level and never trust the browser's MIME type or name.
 *
 *  - not empty
 *  - libmagic (finfo) detects application/pdf
 *  - "%PDF-" signature within the first 1024 bytes (as the PDF specification allows)
 *  - "%%EOF" and "startxref" near the end — rejects truncated / incomplete uploads
 *  - no /Encrypt dictionary — password-protected or encrypted PDFs are not supported
 *    (they could not be previewed, and Phase 5 signing needs readable content)
 *
 * This is structural validation, not malware scanning (see the Phase 3 report, "Security").
 */
class PdfInspector
{
    /** @return string|null a user-facing reason when the file is rejected, null when acceptable */
    public function problem(string $path): ?string
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return 'The file is empty.';
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime !== 'application/pdf') {
            return 'This file is not a PDF. Only PDF documents can be uploaded.';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 'The file could not be read.';
        }
        try {
            $head = (string) fread($handle, 1024);
            if (! str_contains($head, '%PDF-')) {
                return 'This file is not a valid PDF.';
            }
            fseek($handle, max(0, $size - 2048));
            $tail = (string) fread($handle, 2048);
            if (! str_contains($tail, '%%EOF') || ! str_contains($tail, 'startxref')) {
                return 'The PDF appears to be incomplete or damaged. Please upload the complete file.';
            }
        } finally {
            fclose($handle);
        }

        if ($this->isEncrypted($path)) {
            return 'Password-protected or encrypted PDFs are not supported. Remove the protection and upload again.';
        }

        return null;
    }

    /** Scans in overlapping chunks so large files are never loaded into memory at once. */
    private function isEncrypted(string $path): bool
    {
        $handle = fopen($path, 'rb');
        $carry = '';
        try {
            while (! feof($handle)) {
                $chunk = $carry.fread($handle, 1 << 20);
                if (preg_match('/\/Encrypt\s*(\d+\s+\d+\s+R|<<)/', $chunk) === 1) {
                    return true;
                }
                $carry = substr($chunk, -64);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }
}
