import { execFileSync } from "node:child_process";
import path from "node:path";

import { expect, request as playwrightRequest, type Browser, type Page } from "@playwright/test";

import { E2E_DATABASE, assertDisposableDatabase } from "./env";
import { API_URL, SPA_HEADERS, apiSend, uniqueSuffix, type ApiMatter } from "./fixtures";

/** Phase 4 helpers: client document requests, secure links and the anonymous client side. */

export interface ApiRequest {
  id: number;
  matter_id: number;
  title: string;
  status: string;
  lock_version: number;
  latest_submission: { id: number; submission_number: number; version: { label: string; sha256_checksum: string } } | null;
  active_link: { id: number; state: string } | null;
}

export interface WithLink {
  data: ApiRequest;
  link: { id: number; url: string; expires_at: string; is_local_url: boolean } | null;
}

export function tokenOf(url: string): string {
  return url.slice(url.indexOf("#") + 1);
}

/** Creates a request (and its upload link) through the administrator API. */
export async function createRequestApi(page: Page, matter: ApiMatter, overrides: Record<string, unknown> = {}): Promise<WithLink> {
  return apiSend<WithLink>(page, "POST", `/api/matters/${matter.id}/document-requests`, {
    title: `Requested document ${uniqueSuffix()}`,
    instructions: "Please upload the signed document as one PDF, all pages included.",
    requested_category: "agreements",
    issue_link: true,
    ...overrides,
  });
}

export async function requestDetail(page: Page, request: { id: number; matter_id: number }): Promise<ApiRequest> {
  const response = await page.request.get(`${API_URL}/api/matters/${request.matter_id}/document-requests/${request.id}`, {
    headers: SPA_HEADERS,
  });
  expect(response.status()).toBe(200);
  return ((await response.json()) as { data: ApiRequest }).data;
}

/** A browser context WITHOUT any cookies or stored state: what an external client has. */
export async function anonymousPage(browser: Browser, options: Parameters<Browser["newContext"]>[0] = {}): Promise<Page> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, ...options });
  return context.newPage();
}

/** Calls the public API exactly like a non-browser client would: no cookies, token in the header. */
export async function publicApi(token: string, method: "GET" | "POST" = "GET", multipart?: Record<string, unknown>) {
  const context = await playwrightRequest.newContext();
  const response = await context.fetch(`${API_URL}/api/public/document-upload${method === "POST" ? "/submit" : ""}`, {
    method,
    headers: { Accept: "application/json", "X-Upload-Token": token },
    multipart: multipart as never,
  });
  const result = { status: response.status(), body: (await response.json().catch(() => null)) as Record<string, unknown> | null };
  await context.dispose();
  return result;
}

/** Uploads through the public page in an anonymous browser page; waits for the confirmation. */
export async function clientUploads(page: Page, url: string, file: { name: string; mimeType: string; buffer: Buffer }, message?: string) {
  await page.goto(url);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  await page.locator("#client-file").setInputFiles(file);
  await expect(page.getByTestId("client-selected-file")).toHaveText(file.name);
  if (message) await page.getByLabel(/Message to the firm/).fill(message);
  await page.getByRole("button", { name: /Submit (corrected )?document/ }).click();
  await expect(page.getByTestId("upload-success")).toHaveText("Your document was submitted successfully. The firm will review it.");
}

/** Test-only database preparation on the DISPOSABLE E2E database (see backend/tests/Support/e2e-link-fixture.php). */
export function e2eFixture(...args: string[]): string {
  assertDisposableDatabase(E2E_DATABASE);
  return execFileSync("php", [path.resolve(__dirname, "../../backend/tests/Support/e2e-link-fixture.php"), ...args], {
    cwd: path.resolve(__dirname, "../../backend"),
    env: { ...process.env, DB_DATABASE: E2E_DATABASE },
    encoding: "utf8",
  }).trim();
}
