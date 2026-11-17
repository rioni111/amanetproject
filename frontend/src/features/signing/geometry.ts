/**
 * Placement geometry shared by the signing UI. Mirrors backend App\Services\Signing\SignaturePlacement:
 * positions are fractions of the page AS DISPLAYED (PDF.js applies /Rotate; FPDI imports rotated pages the
 * same way), origin top-left; the box height follows from its width so preview and PDF cannot disagree.
 */
export const BOX_RATIO = 0.36; // height = width × ratio (points)
export const SIGNATURE_AREA = 0.68; // upper part of the box holds the signature, the rest the caption
export const MIN_WIDTH_PT = 100;
export const MAX_WIDTH_PT = 320;
/** Width / height of the drawing pad = width / (width × BOX_RATIO × SIGNATURE_AREA). */
export const PAD_ASPECT = 1 / (BOX_RATIO * SIGNATURE_AREA);

export interface PageSize {
  width: number; // points
  height: number;
}

export interface Placement {
  page: number;
  x: number; // fractions of the displayed page
  y: number;
  width: number;
}

export function heightFraction(width: number, size: PageSize): number {
  return (width * size.width * BOX_RATIO) / size.height;
}

export function clampPlacement(p: Placement, size: PageSize): Placement {
  const minW = Math.min(1, MIN_WIDTH_PT / size.width);
  const maxW = Math.min(1, MAX_WIDTH_PT / size.width, (size.height / (size.width * BOX_RATIO)) * 0.98);
  const width = Math.min(maxW, Math.max(minW, p.width));
  const h = heightFraction(width, size);
  return {
    page: p.page,
    width,
    x: Math.min(1 - width, Math.max(0, p.x)),
    y: Math.min(1 - h, Math.max(0, p.y)),
  };
}

/** Bottom-right of the page, a common place for a signature. */
export function defaultPlacement(page: number, size: PageSize): Placement {
  const width = Math.min(0.42, 220 / size.width);
  const h = heightFraction(width, size);
  return clampPlacement({ page, width, x: 1 - width - 0.07, y: 1 - h - 0.08 }, size);
}
