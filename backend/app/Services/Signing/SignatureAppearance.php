<?php

namespace App\Services\Signing;

use App\Enums\SignatureMethod;
use InvalidArgumentException;

/**
 * The validated visual signature. Drawn signatures arrive as vector strokes (lists of normalised points in
 * the signature area, 0..1) — never as images, SVG or HTML — and are drawn as PDF line segments. Typed
 * signatures are rendered as text in a standard PDF font. Nothing executable can reach the PDF.
 */
final readonly class SignatureAppearance
{
    public const MAX_STROKES = 60;

    public const MAX_POINTS_PER_STROKE = 500;

    public const MAX_TOTAL_POINTS = 4000;

    public const MIN_TOTAL_POINTS = 6;

    /** @param list<list<array{0: float, 1: float}>> $strokes */
    private function __construct(
        public SignatureMethod $method,
        public array $strokes,
        public ?string $typedName,
    ) {}

    /** @param mixed $strokes raw input */
    public static function drawn(mixed $strokes): self
    {
        if (! is_array($strokes) || $strokes === [] || count($strokes) > self::MAX_STROKES) {
            throw new InvalidArgumentException('Draw your signature (at most '.self::MAX_STROKES.' strokes).');
        }
        $clean = [];
        $total = 0;
        $minX = $minY = 1.0;
        $maxX = $maxY = 0.0;
        foreach (array_values($strokes) as $stroke) {
            if (! is_array($stroke) || count($stroke) < 2 || count($stroke) > self::MAX_POINTS_PER_STROKE) {
                throw new InvalidArgumentException('The drawn signature is not valid.');
            }
            $points = [];
            foreach (array_values($stroke) as $point) {
                if (! is_array($point) || count($point) !== 2) {
                    throw new InvalidArgumentException('The drawn signature is not valid.');
                }
                [$x, $y] = array_values($point);
                if (! is_int($x) && ! is_float($x) || ! is_int($y) && ! is_float($y) || ! is_finite((float) $x) || ! is_finite((float) $y)
                    || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
                    throw new InvalidArgumentException('The drawn signature is outside the signature area.');
                }
                $points[] = [round((float) $x, 4), round((float) $y, 4)];
                $minX = min($minX, (float) $x);
                $maxX = max($maxX, (float) $x);
                $minY = min($minY, (float) $y);
                $maxY = max($maxY, (float) $y);
            }
            $total += count($points);
            $clean[] = $points;
        }
        if ($total > self::MAX_TOTAL_POINTS) {
            throw new InvalidArgumentException('The drawn signature is too detailed. Clear it and sign again.');
        }
        // An empty-looking scribble (a dot or a tiny tick) is not accepted as a signature.
        if ($total < self::MIN_TOTAL_POINTS || ($maxX - $minX) < 0.08 && ($maxY - $minY) < 0.08) {
            throw new InvalidArgumentException('The signature is too small. Please draw your full signature.');
        }

        return new self(SignatureMethod::Drawn, $clean, null);
    }

    public static function typed(mixed $name): self
    {
        $name = is_string($name) ? trim(preg_replace('/\s+/u', ' ', $name) ?? '') : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || ! preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .,'-]*$/u", $name)) {
            throw new InvalidArgumentException('Type your full name (letters, spaces, apostrophes, hyphens and dots only).');
        }
        if (self::toPdfText($name) === null) {
            throw new InvalidArgumentException('Your name contains characters the signature font cannot display. Please use the drawn signature instead.');
        }

        return new self(SignatureMethod::Typed, [], $name);
    }

    /**
     * Converts UTF-8 to the single-byte encoding of the standard PDF fonts (Windows-1252). Returns null when
     * the text cannot be represented exactly (no silent "?" substitutions in a legal signature).
     */
    public static function toPdfText(string $text): ?string
    {
        $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        if (! is_string($converted) || mb_convert_encoding($converted, 'UTF-8', 'Windows-1252') !== $text) {
            return null;
        }

        return $converted;
    }

    public function pointCount(): int
    {
        return array_sum(array_map('count', $this->strokes));
    }
}
