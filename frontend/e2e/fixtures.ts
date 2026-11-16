import { expect, type APIRequestContext, type Page } from "@playwright/test";

export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
export const FRONTEND_ORIGIN = process.env.E2E_BASE_URL ?? "http://localhost:3000";
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
