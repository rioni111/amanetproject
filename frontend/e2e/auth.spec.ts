import { expect, test } from "@playwright/test";

import { API_URL, DEMO_EMAIL, SPA_HEADERS, loginThroughUi } from "./fixtures";

test.describe("authentication", () => {
  test("login page renders an accessible form", async ({ page }) => {
    await page.goto("/login");

    await expect(page.getByRole("heading", { name: "Welcome back" })).toBeVisible();
    await expect(page.getByLabel("Email address")).toBeVisible();
    await expect(page.getByLabel("Password", { exact: true })).toHaveAttribute("type", "password");
    await expect(page.getByRole("button", { name: "Sign in" })).toBeEnabled();

    await page.getByRole("button", { name: "Show password" }).click();
    await expect(page.getByLabel("Password", { exact: true })).toHaveAttribute("type", "text");
    await expect(page.getByRole("button", { name: "Hide password" })).toHaveAttribute("aria-pressed", "true");
  });

  test("required fields are validated before calling the API", async ({ page }) => {
    let loginCalls = 0;
    page.on("request", (request) => {
      if (request.url().endsWith("/api/auth/login")) loginCalls++;
    });

    await page.goto("/login");
    await page.getByRole("button", { name: "Sign in" }).click();

    await expect(page.getByText("Enter your email address.")).toBeVisible();
    await expect(page.getByText("Enter your password.")).toBeVisible();
    await expect(page.getByLabel("Email address")).toHaveAttribute("aria-invalid", "true");

    await page.getByLabel("Email address").fill("not-an-email");
    await page.getByRole("button", { name: "Sign in" }).click();
    await expect(page.getByText("Enter a valid email address.")).toBeVisible();
    expect(loginCalls).toBe(0);
  });

  test("incorrect credentials show an error and keep the user signed out", async ({ page }) => {
    await loginThroughUi(page, DEMO_EMAIL, "definitely-wrong-password");

    await expect(page.getByRole("alert").filter({ hasText: "credentials" })).toContainText("These credentials do not match our records.");
    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByLabel("Password", { exact: true })).toHaveValue("");
  });

  test("protected routes redirect to login and return after signing in", async ({ page }) => {
    await page.goto("/matters?status=on_hold");
    await expect(page).toHaveURL(/\/login\?next=/);

    await page.getByLabel("Email address").fill(DEMO_EMAIL);
    await page.getByRole("button", { name: "Fill in demo credentials" }).click();
    await page.getByRole("button", { name: "Sign in" }).click();

    await expect(page).toHaveURL(/\/matters\?status=on_hold$/);
    await expect(page.getByRole("heading", { name: "Matters", level: 1 })).toBeVisible();
  });

  test("a successful login stores no tokens in web storage and uses an HTTP-only session cookie", async ({ page, context }) => {
    await loginThroughUi(page);
    await expect(page).toHaveURL(/\/dashboard$/);

    const storage = await page.evaluate(() => ({ local: { ...localStorage }, session: { ...sessionStorage } }));
    expect(storage.local).toEqual({});
    expect(storage.session).toEqual({});

    const session = (await context.cookies()).find((cookie) => cookie.name === "lexora_session");
    expect(session?.httpOnly).toBe(true);
    expect(session?.sameSite).toBe("Lax");
  });

  test("logout ends the session and returns to the login page", async ({ page }) => {
    await loginThroughUi(page);
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.getByRole("button", { name: "Sign out" }).click();
    await expect(page).toHaveURL(/\/login/);

    // The old session no longer works against the API…
    const me = await page.request.get(`${API_URL}/api/auth/me`, { headers: SPA_HEADERS });
    expect(me.status()).toBe(401);

    // …and protected pages send us back to sign in.
    await page.goto("/dashboard");
    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByRole("heading", { name: "Welcome back" })).toBeVisible();
  });
});

test.describe("API protection", () => {
  test("state-changing requests without a CSRF token are rejected with 419", async ({ request }) => {
    const response = await request.post(`${API_URL}/api/auth/login`, {
      headers: { ...SPA_HEADERS, "Content-Type": "application/json" },
      data: { email: DEMO_EMAIL, password: "irrelevant" },
    });
    expect(response.status()).toBe(419);
  });

  test("protected endpoints reject requests without a session", async ({ request }) => {
    for (const path of ["/api/auth/me", "/api/dashboard/summary", "/api/matters", "/api/clients"]) {
      const response = await request.get(`${API_URL}${path}`, { headers: SPA_HEADERS });
      expect(response.status(), path).toBe(401);
    }
  });
});
