<?php

namespace Tests\Support;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/** Reads generated PDFs in tests: page count and displayed sizes (by re-parsing with FPDI), caption text. */
final class SignedPdf
{
    /** @return list<array{width: float, height: float}> */
    public static function pageSizes(string $bytes): array
    {
        $pdf = new Fpdi('P', 'pt');
        $count = $pdf->setSourceFile(StreamReader::createByString($bytes));
        $sizes = [];
        for ($i = 1; $i <= $count; $i++) {
            $size = $pdf->getTemplateSize($pdf->importPage($i));
            $sizes[] = ['width' => round((float) $size['width'], 1), 'height' => round((float) $size['height'], 1)];
        }

        return $sizes;
    }

    /** The signed PDF's content streams are uncompressed, so the visible caption can be read directly. */
    public static function hasCaption(string $bytes, string $signerName): bool
    {
        return str_contains($bytes, '(Electronically signed by '.mb_convert_encoding($signerName, 'Windows-1252', 'UTF-8').')');
    }

    /** Number of straight line segments (drawn strokes, signature line) — "l S" operators written by FPDF. */
    public static function lineSegments(string $bytes): int
    {
        return preg_match_all('/ l S\b/', $bytes);
    }

    /** Text drawn in the Times-Italic signature font. */
    public static function hasTypedSignature(string $bytes, string $name): bool
    {
        return str_contains($bytes, '/BaseFont /Times-Italic') && str_contains($bytes, '('.mb_convert_encoding($name, 'Windows-1252', 'UTF-8').') Tj');
    }
}
