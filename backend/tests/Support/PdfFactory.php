<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Builds small, structurally valid PDFs (catalog, page tree, Helvetica, one text line per page,
 * correct xref byte offsets) for tests, plus deliberately broken variants. Fictional content only.
 */
final class PdfFactory
{
    /**
     * Pages with their own size and rotation (Phase 5 signing tests: landscape and /Rotate pages).
     *
     * @param  list<array{text: string, width?: int, height?: int, rotate?: int}>  $specs
     */
    public static function pages(array $specs): string
    {
        $objects = [];
        $count = count($specs);
        $kids = [];
        for ($i = 0; $i < $count; $i++) {
            $kids[] = (4 + $i * 2).' 0 R';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$count.' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        foreach ($specs as $i => $spec) {
            $pageId = 4 + $i * 2;
            $w = $spec['width'] ?? 612;
            $h = $spec['height'] ?? 792;
            $rotate = isset($spec['rotate']) ? ' /Rotate '.$spec['rotate'] : '';
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $spec['text']);
            $stream = 'BT /F1 24 Tf 72 '.($h - 72)." Td ({$safe}) Tj ET";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}]{$rotate} /Resources << /Font << /F1 3 0 R >> >> /Contents ".($pageId + 1).' 0 R >>';
            $objects[$pageId + 1] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        return self::assemble($objects, '');
    }

    /**
     * A valid one-page PDF with extra catalog entries and extra objects (ids from 10), e.g. an interactive
     * form (/AcroForm) or a signature dictionary (/ByteRange), with correct xref offsets.
     *
     * @param  array<int, string>  $extraObjects
     */
    public static function withFeatures(string $catalogExtra, array $extraObjects = [], string $text = 'Featured PDF'): string
    {
        $stream = 'BT /F1 24 Tf 72 720 Td ('.$text.') Tj ET';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R '.$catalogExtra.' >>',
            2 => '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R >>',
            5 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];
        for ($id = 6; $id < 10; $id++) {
            $objects[$id] = 'null';
        }

        return self::assemble($objects + $extraObjects, '');
    }

    /** @param array<int, string> $objects */
    private static function assemble(array $objects, string $trailerExtra): string
    {
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

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R {$trailerExtra}>>\nstartxref\n{$xref}\n%%EOF\n";
    }

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

    /**
     * A one-page PDF 1.5 whose cross-reference table is a cross-reference STREAM (/Type /XRef), the format
     * written by most modern tools. Structurally valid; FPDI's free parser cannot read it (Phase 5 signing).
     */
    public static function xrefStream(string $text = 'Modern PDF'): string
    {
        $stream = 'BT /F1 24 Tf 72 720 Td ('.$text.') Tj ET';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R >>',
            5 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];
        $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $offsets[6] = $xrefOffset;
        // /W [1 4 2]: type (1 byte), offset (4 bytes), generation (2 bytes) — uncompressed binary rows.
        $rows = pack('CNn', 0, 0, 65535);
        for ($id = 1; $id <= 6; $id++) {
            $rows .= pack('CNn', 1, $offsets[$id], 0);
        }
        $pdf .= "6 0 obj\n<< /Type /XRef /Size 7 /W [1 4 2] /Root 1 0 R /Length ".strlen($rows)." >>\nstream\n{$rows}\nendstream\nendobj\n";

        return $pdf."startxref\n{$xrefOffset}\n%%EOF\n";
    }

    /** A PDF whose startxref points to the wrong place: passes upload validation, but no parser can follow it. */
    public static function brokenCrossReference(): string
    {
        return (string) preg_replace('/startxref\n\d+/', "startxref\n12", self::bytes(['Damaged']));
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
