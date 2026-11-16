<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Builds small, structurally valid PDFs (catalog, page tree, Helvetica, one text line per page,
 * correct xref byte offsets) for tests, plus deliberately broken variants. Fictional content only.
 */
final class PdfFactory
{
    /** @param list<string> $pages one line of text per page */
    public static function bytes(array $pages = ['Lexora test document'], string $trailerExtra = ''): string
    {
        $objects = [];
        $count = count($pages);
        $kids = [];
        for ($i = 0; $i < $count; $i++) {
            $kids[] = (4 + $i * 2).' 0 R';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$count.' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        foreach ($pages as $i => $text) {
            $pageId = 4 + $i * 2;
            $contentId = $pageId + 1;
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
            $stream = "BT /F1 24 Tf 72 720 Td ({$safe}) Tj ET";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R {$trailerExtra}>>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    /** @param list<string> $pages */
    public static function upload(string $name = 'agreement.pdf', array $pages = ['Lexora test document']): UploadedFile
    {
        return self::fromBytes($name, self::bytes($pages));
    }

    public static function fromBytes(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lexora-pdf-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    public static function encrypted(): UploadedFile
    {
        return self::fromBytes('locked.pdf', self::bytes(['Secret'], '/Encrypt 9 0 R '));
    }

    public static function truncated(): UploadedFile
    {
        $bytes = self::bytes(['Truncated']);

        return self::fromBytes('truncated.pdf', substr($bytes, 0, (int) (strlen($bytes) * 0.6)));
    }

    public static function notAPdf(): UploadedFile
    {
        return self::fromBytes('notes.pdf', "Meeting notes\nThis is plain text renamed to .pdf\n");
    }
}
