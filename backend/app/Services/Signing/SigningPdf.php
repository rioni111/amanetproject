<?php

namespace App\Services\Signing;

use setasign\Fpdi\Fpdi;

/** FPDI with round line caps and joins for natural-looking pen strokes. */
final class SigningPdf extends Fpdi
{
    public function setRoundStrokes(): void
    {
        $this->_out('1 J 1 j');
    }
}
