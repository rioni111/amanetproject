import { expect, request as playwrightRequest, type Page } from "@playwright/test";

import { API_URL, SPA_HEADERS, type ApiMatter } from "./fixtures";
import { makePdf, sha256 } from "./pdf";

/** Phase 5 helpers: documents and signing requests through the admin API, the public signing API and drawing. */

export interface ApiVersion {
  id: number;
  label: string;
  kind: "upload" | "signed";
  sha256_checksum: string;
}

export interface ApiSigning {
  id: number;
  matter_id: number;
  status: string;
  lock_version: number;
  signed_version: ApiVersion | null;
  source_version: ApiVersion;
}

/** Uploads a PDF through the admin API (multipart, same session + CSRF as the SPA). */
export async function uploadPdfApi(page: Page, matter: ApiMatter, title: string, pages: string[]) {
  const bytes = makePdf(pages);
  await page.request.get(`${API_URL}/sanctum/csrf-cookie`, { headers: SPA_HEADERS });
  const xsrf = (await page.context().cookies(API_URL)).find((c) => c.name === "XSRF-TOKEN");
  const response = await page.request.post(`${API_URL}/api/matters/${matter.id}/documents`, {
    headers: { ...SPA_HEADERS, "X-XSRF-TOKEN": decodeURIComponent(xsrf?.value ?? "") },
    multipart: { file: { name: `${title.toLowerCase().replace(/\W+/g, "-")}.pdf`, mimeType: "application/pdf", buffer: bytes }, title, category: "agreements" },
  });
  expect(response.status(), await response.text()).toBe(201);
  const document = (await response.json()).data as { id: number; current_version: ApiVersion };
  return { document, bytes, sha: sha256(bytes) };
}

export async function signingDetail(page: Page, matterId: number, id: number) {
  const response = await page.request.get(`${API_URL}/api/matters/${matterId}/signing-requests/${id}`, { headers: SPA_HEADERS });
  expect(response.status()).toBe(200);
  return (await response.json()) as { data: ApiSigning; evidence: { signed_sha256: string; source_sha256: string; signature_method: string } | null };
}

/** Admin-only binary download of a version (returns the bytes). */
export async function downloadVersionApi(page: Page, matterId: number, documentId: number, versionId: number): Promise<Buffer> {
  const response = await page.request.get(`${API_URL}/api/matters/${matterId}/documents/${documentId}/versions/${versionId}/download`, { headers: SPA_HEADERS });
  expect(response.status()).toBe(200);
  return Buffer.from(await response.body());
}

/** Public signing API as an anonymous client (no cookies; token in the header). */
export async function publicSigning(token: string, method: "GET" | "POST", path = "", data?: unknown) {
  const context = await playwrightRequest.newContext();
  const response = await context.fetch(`${API_URL}/api/public/signing${path}`, {
    method,
    headers: { Accept: "application/json", "X-Signing-Token": token, ...(data ? { "Content-Type": "application/json" } : {}) },
    data: data ? JSON.stringify(data) : undefined,
  });
  const result = { status: response.status(), body: (await response.json().catch(() => null)) as Record<string, unknown> | null };
  await context.dispose();
  return result;
}

export const SIGNATURE_PAYLOAD = {
  method: "drawn",
  strokes: [Array.from({ length: 21 }, (_, i) => [0.05 + i * 0.04, 0.5 + 0.3 * Math.sin(i / 2)])],
  typed_name: null,
  page: 1,
  x: 0.5,
  y: 0.75,
  width: 0.35,
  consent: true,
  consent_version: "2026-11-v1",
};

/** Draws a signature on the pad with pointer movements (mouse, or touch-emulated pointer on phones). */
export async function drawSignature(page: Page) {
  const pad = page.getByTestId("signature-pad");
  await pad.scrollIntoViewIfNeeded();
  const box = (await pad.boundingBox())!;
  await page.mouse.move(box.x + box.width * 0.08, box.y + box.height * 0.6);
  await page.mouse.down();
  for (let i = 1; i <= 22; i++) {
    await page.mouse.move(box.x + box.width * (0.08 + i * 0.038), box.y + box.height * (0.5 + 0.28 * Math.sin(i / 2)), { steps: 2 });
  }
  await page.mouse.up();
}

/**
 * Counts dark pixels of a rendered PDF page canvas inside a region given as fractions of the page —
 * used to prove the signature is actually visible where it was placed.
 */
export async function darkPixels(page: Page, canvasSelector: string, region: { x0: number; y0: number; x1: number; y1: number }) {
  return page.locator(canvasSelector).evaluate((canvas: HTMLCanvasElement, r) => {
    const ctx = canvas.getContext("2d")!;
    const x = Math.floor(canvas.width * r.x0);
    const y = Math.floor(canvas.height * r.y0);
    const w = Math.max(1, Math.floor(canvas.width * (r.x1 - r.x0)));
    const h = Math.max(1, Math.floor(canvas.height * (r.y1 - r.y0)));
    const data = ctx.getImageData(x, y, w, h).data;
    let dark = 0;
    for (let i = 0; i < data.length; i += 4) if (data[i + 3] > 200 && data[i] < 110 && data[i + 1] < 110 && data[i + 2] < 110) dark++;
    return dark;
  }, region);
}
