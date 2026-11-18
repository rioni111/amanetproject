<?php

namespace Database\Seeders\Support;

use FPDF;

/**
 * Generates readable, fictional demonstration PDFs (FPDF, PDF 1.3: compatible with the signing engine).
 * Every page carries a "fictional demonstration document" footer, so a demo file can never be mistaken for
 * a real one.
 */
final class DemoPdf
{
    /**
     * @param  list<array{0: string, 1: string}>  $sections  [heading, paragraph]
     * @return string the PDF bytes
     */
    public static function make(string $title, string $subtitle, array $sections, bool $signatureBlock = false): string
    {
        $pdf = new class('P', 'pt', 'A4') extends FPDF
        {
            public function Footer(): void
            {
                $this->SetY(-40);
                $this->SetFont('Helvetica', 'I', 8);
                $this->SetTextColor(110, 110, 110);
                $this->Cell(0, 12, DemoPdf::latin('Fictional demonstration document - Lexora Workspace. Page '.$this->PageNo().'.'), 0, 0, 'C');
            }
        };
        $pdf->SetCreator('Lexora Workspace demo data');
        $pdf->SetTitle(self::latin($title));
        $pdf->SetMargins(64, 64, 64);
        $pdf->SetAutoPageBreak(true, 72);
        $pdf->AddPage();

        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->MultiCell(0, 24, self::latin($title));
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(15, 118, 110);
        $pdf->MultiCell(0, 14, self::latin($subtitle));
        $pdf->Ln(14);

        foreach ($sections as [$heading, $paragraph]) {
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->SetTextColor(17, 24, 39);
            $pdf->MultiCell(0, 16, self::latin($heading));
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->SetTextColor(55, 65, 81);
            $pdf->MultiCell(0, 14, self::latin($paragraph));
            $pdf->Ln(10);
        }

        if ($signatureBlock) {
            if ($pdf->GetY() > 620) {
                $pdf->AddPage();
            }
            $pdf->Ln(30);
            $pdf->SetDrawColor(156, 163, 175);
            $pdf->Line(64, $pdf->GetY() + 40, 300, $pdf->GetY() + 40);
            $pdf->SetY($pdf->GetY() + 46);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell(0, 12, 'Signature');
        }

        return $pdf->Output('S');
    }

    /** FPDF's core fonts are Windows-1252. */
    public static function latin(string $text): string
    {
        return (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
    }
}
