<?php

namespace App\Services\Signing;

use InvalidArgumentException;

/**
 * Where the signature goes, in coordinates that do not depend on the viewer: page number plus the top-left
 * corner and width of the signature box as FRACTIONS of the page as displayed (after /Rotate), origin top-left.
 * PDF.js renders the displayed page and FPDI imports the page with its rotation applied, so the same
 * fractions address the same spot in both. The box height follows from its width (fixed aspect ratio), so
 * the preview and the PDF can never disagree about the shape.
 */
final readonly class SignaturePlacement
{
    /** Box height = width × RATIO (in points). */
    public const RATIO = 0.36;

    public const MIN_WIDTH_PT = 100.0;

    public const MAX_WIDTH_PT = 320.0;

    private function __construct(
        public int $page,
        public float $x,
        public float $y,
        public float $width,
        public float $pageWidthPt,
        public float $pageHeightPt,
    ) {}

    /**
     * @param  list<array{width: float, height: float}>  $pageSizes  displayed page sizes (points) of the source PDF
     */
    public static function fromInput(mixed $page, mixed $x, mixed $y, mixed $width, array $pageSizes): self
    {
        foreach ([$x, $y, $width] as $value) {
            if (! is_int($value) && ! is_float($value) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('The signature position is not valid.');
            }
        }
        if (! is_int($page) || $page < 1 || $page > count($pageSizes)) {
            throw new InvalidArgumentException('Choose a page of this document for the signature.');
        }
        $size = $pageSizes[$page - 1];
        $placement = new self($page, (float) $x, (float) $y, (float) $width, (float) $size['width'], (float) $size['height']);
        $rect = $placement->rectPt();
        if ($rect['width'] < self::MIN_WIDTH_PT - 0.01 || $rect['width'] > self::MAX_WIDTH_PT + 0.01) {
            throw new InvalidArgumentException('The signature box must be between '.(int) self::MIN_WIDTH_PT.' and '.(int) self::MAX_WIDTH_PT.' points wide.');
        }
        $tolerance = 0.5; // half a point of rounding slack from the browser
        if ($rect['x'] < -$tolerance || $rect['y'] < -$tolerance
            || $placement->pageWidthPt + $tolerance < $rect['x'] + $rect['width']
            || $placement->pageHeightPt + $tolerance < $rect['y'] + $rect['height']) {
            throw new InvalidArgumentException('The signature must be placed completely inside the page.');
        }

        return $placement;
    }

    /** @return array{x: float, y: float, width: float, height: float} in PDF points, origin top-left */
    public function rectPt(): array
    {
        $width = $this->width * $this->pageWidthPt;

        return [
            'x' => max(0.0, $this->x * $this->pageWidthPt),
            'y' => max(0.0, $this->y * $this->pageHeightPt),
            'width' => $width,
            'height' => $width * self::RATIO,
        ];
    }

    /** @return array<string, mixed> stored with the signature record */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'x' => round($this->x, 6),
            'y' => round($this->y, 6),
            'width' => round($this->width, 6),
            'page_width_pt' => round($this->pageWidthPt, 2),
            'page_height_pt' => round($this->pageHeightPt, 2),
            'rect_pt' => array_map(fn (float $v) => round($v, 2), $this->rectPt()),
        ];
    }
}
