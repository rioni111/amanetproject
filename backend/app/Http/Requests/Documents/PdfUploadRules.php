<?php

namespace App\Http\Requests\Documents;

use App\Services\Documents\PdfInspector;
use Closure;
use Illuminate\Http\UploadedFile;

/** Shared file rules: size limit, .pdf extension, then byte-level PDF checks (never the client MIME). */
trait PdfUploadRules
{
    /** @return list<mixed> */
    protected function pdfFileRules(): array
    {
        $maxKb = (int) config('lexora.documents.max_upload_mb', 20) * 1024;

        return [
            'required',
            'file',
            'max:'.$maxKb,
            'extensions:pdf',
            function (string $attribute, mixed $value, Closure $fail) {
                if ($value instanceof UploadedFile && $value->isValid()) {
                    $problem = app(PdfInspector::class)->problem($value->getRealPath());
                    if ($problem !== null) {
                        $fail($problem);
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    protected function pdfFileMessages(): array
    {
        $max = (int) config('lexora.documents.max_upload_mb', 20);

        return [
            'file.required' => 'Choose a PDF file to upload.',
            'file.file' => 'The file did not upload completely. Please try again.',
            'file.uploaded' => 'The file did not upload completely. Please try again.',
            'file.max' => "The PDF is larger than the {$max} MB limit.",
            'file.extensions' => 'Only PDF files (.pdf) can be uploaded.',
        ];
    }

    /** Server-managed attributes are rejected, never silently ignored. */
    protected function prohibitedServerFields(): array
    {
        return array_fill_keys([
            'matter_id', 'document_id', 'uploaded_by', 'origin', 'status', 'storage_path', 'storage_disk',
            'version_number', 'sha256_checksum', 'mime_type', 'file_size', 'current_version_id', 'kind', 'uuid', 'id',
        ], ['prohibited']);
    }
}
