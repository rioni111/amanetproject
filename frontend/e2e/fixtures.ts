import { expect, type APIRequestContext, type Page } from "@playwright/test";

import { API_URL, FRONTEND_URL, SESSION_COOKIE } from "./env";

export { API_URL, SESSION_COOKIE };
export const FRONTEND_ORIGIN = FRONTEND_URL;
export const DEMO_EMAIL = process.env.E2E_ADMIN_EMAIL ?? "admin@lexora.test";
export const DEMO_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? "Lexora!Demo2026";
export const STORAGE_STATE = "e2e/.auth/admin.json";

/** Headers a browser on the SPA origin would send — required for Sanctum stateful requests. */
export const SPA_HEADERS = { Accept: "application/json", Origin: FRONTEND_ORIGIN, Referer: `${FRONTEND_ORIGIN}/` };

export async function loginThroughUi(page: Page, email = DEMO_EMAIL, password = DEMO_PASSWORD) {
  await page.goto("/login");
  await page.getByLabel("Email address").fill(email);
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Sign in" }).click();
}

/** Calls the API with the browser context's cookies (page.request shares the cookie jar). */
export async function apiGet<T>(request: APIRequestContext, path: string): Promise<T> {
  const response = await request.get(`${API_URL}${path}`, { headers: SPA_HEADERS });
  expect(response.status(), `GET ${path}`).toBe(200);
  return (await response.json()) as T;
}

/**
 * State-changing API call with the browser context's session, exactly like the SPA does it:
 * fetch the CSRF cookie, then echo XSRF-TOKEN in the X-XSRF-TOKEN header. Used to prepare data quickly.
 */
export async function apiSend<T>(page: Page, method: "POST" | "PATCH" | "DELETE", path: string, data?: unknown): Promise<T> {
  await page.request.get(`${API_URL}/sanctum/csrf-cookie`, { headers: SPA_HEADERS });
  const xsrf = (await page.context().cookies(API_URL)).find((cookie) => cookie.name === "XSRF-TOKEN");
  const response = await page.request.fetch(`${API_URL}${path}`, {
    method,
    headers: { ...SPA_HEADERS, "Content-Type": "application/json", "X-XSRF-TOKEN": decodeURIComponent(xsrf?.value ?? "") },
    data,
  });
  expect(response.ok(), `${method} ${path} → ${response.status()} ${await response.text()}`).toBe(true);
  return (response.status() === 204 ? undefined : await response.json()) as T;
}

export interface ApiMatter {
  id: number;
  matter_number: string;
  title: string;
  status: string;
  priority: string;
  lock_version: number;
}

/** Unique, readable suffix so repeated runs never collide. */
export function uniqueSuffix(): string {
  return `${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`.toUpperCase();
}

/** Creates a matter for the seeded client "Harborline Freight Ltd." (CL-1001) through the API. */
export async function createMatter(page: Page, overrides: Record<string, unknown> = {}): Promise<ApiMatter> {
  const clients = await apiGet<{ data: { id: number; reference_number: string | null }[] }>(page.request, "/api/clients?search=CL-1001");
  const { data } = await apiSend<{ data: ApiMatter }>(page, "POST", "/api/matters", {
    title: `E2E matter ${uniqueSuffix()}`,
    client_id: clients.data[0].id,
    priority: "medium",
    ...overrides,
  });
  return data;
}

export async function dashboardStats(page: Page) {
  const summary = await apiGet<{ data: { stats: { active_matters: number; open_tasks: number; total_clients: number; total_matters: number } } }>(
    page.request,
    "/api/dashboard/summary",
  );
  return summary.data.stats;
}

/** Fails if the page can be scrolled sideways. */
export async function expectNoHorizontalOverflow(page: Page, label: string) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, `${label} overflows horizontally`).toBeLessThanOrEqual(0);
}