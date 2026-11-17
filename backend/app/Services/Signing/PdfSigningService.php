<?php

namespace App\Services\Signing;

use App\Enums\SignatureMethod;
use App\Services\Documents\PdfInspector;
use Carbon\CarbonInterface;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Generates a NEW PDF with a visible electronic signature (FPDI 2.6 imports the source pages, FPDF draws).
 *
 * - Every page of the source is imported in order at its displayed size (FPDI applies /Rotate), so page
 *   count, order, dimensions and orientation are preserved; the signature is vector content (lines for a
 *   drawn signature, Times Italic text for a typed one) plus a caption with signer, UTC time and reference.
 * - The source file is only read, never written. The output is verified before it can be stored:
 *   structurally (PdfInspector), by re-parsing it (page count and sizes) and by finding the caption reference.
 * - Content streams are written uncompressed so the verification (and any later audit) can read them.
 *
 * Unsupported sources are refused BEFORE signing with a clear reason (UnsupportedPdfException):
 *   - cross-reference streams / object streams (PDF 1.5+ compression) — the free FPDI parser cannot read them;
 *   - interactive forms (/AcroForm), annotations (/Annots: links, comments) and existing digital signatures
 *     (/ByteRange) — page import would drop them, which must not happen silently to a legal document;
 *   - encrypted, damaged or empty files.
 */
class PdfSigningService
{
    public function __construct(private readonly PdfInspector $inspector) {}

    /**
     * @return list<array{width: float, height: float}> displayed page sizes in points
     *
     * @throws UnsupportedPdfException
     */
    public function inspect(string $path): array
    {
        $problem = $this->inspector->problem($path);
        if ($problem !== null) {
            throw new UnsupportedPdfException($problem);
        }
        $bytes = (string) file_get_contents($path);
        $features = [
            '/AcroForm' => 'This PDF contains form fields. Signing it here would remove them, so it cannot be signed in Lexora. Flatten or print the form to PDF first.',
            '/Annots' => 'This PDF contains annotations (for example links or comments) that would be lost when signing. Export a version without annotations (e.g. "print to PDF") and sign that.',
            '/ByteRange' => 'This PDF already contains a digital signature. Signing it again here would invalidate that signature.',
        ];
        foreach ($features as $marker => $message) {
            if (str_contains($bytes, $marker)) {
                throw new UnsupportedPdfException($message);
            }
        }

        $pdf = new Fpdi('P', 'pt');
        try {
            $count = $pdf->setSourceFile($path);
            $sizes = [];
            for ($i = 1; $i <= $count; $i++) {
                $size = $pdf->getTemplateSize($pdf->importPage($i));
                $sizes[] = ['width' => (float) $size['width'], 'height' => (float) $size['height']];
            }
        } catch (CrossReferenceException $e) {
            throw new UnsupportedPdfException($e->getCode() === CrossReferenceException::COMPRESSED_XREF
                ? 'This PDF uses a compressed format (PDF 1.5+ cross-reference streams) that the signing engine cannot read. Save or print it as a PDF 1.4 / PDF/A-1b file and upload that version.'
                : 'This PDF could not be read by the signing engine.');
        } catch (PdfParserException $e) {
            throw new UnsupportedPdfException('This PDF could not be read by the signing engine.');
        } finally {
            $pdf->cleanUp(true); // close the source file now (Windows keeps open files locked)
        }
        if ($count < 1) {
            throw new UnsupportedPdfException('This PDF has no pages.');
        }

        return $sizes;
    }

    /**
     * Writes the signed PDF to $outputPath and verifies it.
     *
     * @param  list<array{width: float, height: float}>  $pageSizes  from inspect()
     *
     * @throws SigningFailedException
     */
    public function sign(
        string $sourcePath,
        string $outputPath,
        array $pageSizes,
        SignatureAppearance $appearance,
        SignaturePlacement $placement,
        string $signerName,
        CarbonInterface $signedAt,
        string $reference,
    ): void {
        try {
            $pdf = new SigningPdf('P', 'pt');
            $pdf->SetCompression(false);
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetCreator('Lexora Workspace', true);
            $count = $pdf->setSourceFile($sourcePath);
            for ($i = 1; $i <= $count; $i++) {
                $template = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
                if ($i === $placement->page) {
                    $this->drawSignature($pdf, $appearance, $placement->rectPt(), $signerName, $signedAt, $reference);
                }
            }
            $pdf->Output('F', $outputPath);
            $pdf->cleanUp(true);
        } catch (Throwable $e) {
            isset($pdf) && $pdf->cleanUp(true);
            @unlink($outputPath);
            throw new SigningFailedException('The signed PDF could not be generated. The original document was not changed.', 0, $e);
        }

        $this->verifyOutput($outputPath, $pageSizes, $reference);
    }

    /** The reference printed in the caption and searched for during verification. */
    public static function captionReference(string $requestUuid): string
    {
        return 'LX-'.strtoupper(substr(str_replace('-', '', $requestUuid), 0, 12));
    }

    /** @param array{x: float, y: float, width: float, height: float} $rect */
    private function drawSignature(SigningPdf $pdf, SignatureAppearance $appearance, array $rect, string $signerName, CarbonInterface $signedAt, string $reference): void
    {
        ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h] = $rect;
        $signatureHeight = $h * 0.68;
        $pdf->SetDrawColor(17, 24, 39);
        $pdf->SetTextColor(17, 24, 39);

        if ($appearance->method === SignatureMethod::Drawn) {
            $pdf->SetLineWidth(max(0.9, $w * 0.0055));
            $pdf->setRoundStrokes();
            foreach ($appearance->strokes as $stroke) {
                for ($i = 1, $n = count($stroke); $i < $n; $i++) {
                    $pdf->Line(
                        $x + $stroke[$i - 1][0] * $w, $y + $stroke[$i - 1][1] * $signatureHeight,
                        $x + $stroke[$i][0] * $w, $y + $stroke[$i][1] * $signatureHeight,
                    );
                }
            }
        } else {
            $text = (string) SignatureAppearance::toPdfText((string) $appearance->typedName);
            $size = $signatureHeight * 0.62;
            $pdf->SetFont('Times', 'I', $size);
            while ($size > 8 && $pdf->GetStringWidth($text) > $w * 0.94) {
                $size -= 0.5;
                $pdf->SetFontSize($size);
            }
            $pdf->Text($x + ($w - $pdf->GetStringWidth($text)) / 2, $y + $signatureHeight * 0.74, $text);
        }

        // Signature line and caption.
        $pdf->SetDrawColor(107, 114, 128);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($x, $y + $signatureHeight, $x + $w, $y + $signatureHeight);
        $pdf->SetTextColor(55, 65, 81);
        $name = SignatureAppearance::toPdfText($signerName) ?? (string) preg_replace('/[^\x20-\x7E]/', '?', $signerName);
        $lines = [
            'Electronically signed by '.$name,
            $signedAt->copy()->utc()->format('Y-m-d H:i:s').' UTC  ·  Ref '.$reference,
        ];
        $lines[1] = (string) SignatureAppearance::toPdfText($lines[1]);
        $font = min(7.0, ($h - $signatureHeight) / 2.6);
        $pdf->SetFont('Helvetica', '', $font);
        foreach ($lines as $line) {
            while ($font > 4 && $pdf->GetStringWidth($line) > $w) {
                $font -= 0.25;
                $pdf->SetFontSize($font);
            }
        }
        $pdf->Text($x, $y + $signatureHeight + $font * 1.25, $lines[0]);
        $pdf->Text($x, $y + $signatureHeight + $font * 2.45, $lines[1]);
    }

    /**
     * Independent check of a generated file: valid PDF, same page count and sizes, caption reference present.
     *
     * @param  list<array{width: float, height: float}>  $expectedSizes
     */
    public function verifyOutput(string $path, array $expectedSizes, string $reference): void
    {
        $fail = function (string $why) use ($path): never {
            @unlink($path);
            throw new SigningFailedException('The signed PDF failed verification ('.$why.'). The original document was not changed.');
        };
        if (! is_file($path) || filesize($path) === 0) {
            $fail('no output');
        }
        if ($this->inspector->problem($path) !== null) {
            $fail('not a valid PDF');
        }
        try {
            // Parsed from memory: no file handle stays open (Windows could not delete a locked file).
            $check = new Fpdi('P', 'pt');
            $count = $check->setSourceFile(StreamReader::createByString((string) file_get_contents($path)));
            if ($count !== count($expectedSizes)) {
                $fail('page count changed');
            }
            for ($i = 1; $i <= $count; $i++) {
                $size = $check->getTemplateSize($check->importPage($i));
                if (abs($size['width'] - $expectedSizes[$i - 1]['width']) > 0.5 || abs($size['height'] - $expectedSizes[$i - 1]['height']) > 0.5) {
                    $fail('page size changed');
                }
            }
        } catch (PdfParserException $e) {
            $fail('unreadable output');
        }
        if (! str_contains((string) file_get_contents($path), 'Ref '.$reference)) {
            $fail('signature not found');
        }
    }
}
