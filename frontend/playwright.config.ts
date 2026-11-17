import { defineConfig, devices } from "@playwright/test";

import { API_URL, E2E_API_PORT, E2E_DATABASE, E2E_DOCUMENTS_DIR, E2E_WEB_PORT, FRONTEND_URL, SESSION_COOKIE } from "./e2e/env";

/**
 * End-to-end tests run against the REAL stack: Next.js → Laravel → PostgreSQL — on dedicated ports
 * and a disposable database (see e2e/env.ts), never against the development servers or data.
 * Prerequisites: PostgreSQL running and the E2E database created (setup-windows.bat does both).
 */
export default defineConfig({
  testDir: "./e2e",
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [["list"], ["html", { open: "never" }]],
  globalSetup: "./e2e/global-setup.ts",
  // Development servers compile routes on demand; allow for that on slower machines.
  expect: { timeout: 15_000 },
  use: {
    baseURL: FRONTEND_URL,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "setup", testMatch: /auth\.setup\.ts/ },
    {
      name: "desktop",
      use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 } },
      dependencies: ["setup"],
      testIgnore: /mobile\.spec\.ts/,
    },
    {
      name: "mobile",
      use: { ...devices["Pixel 7"] },
      dependencies: ["setup"],
      testMatch: /mobile\.spec\.ts/,
    },
  ],
  webServer: [
    {
      // Process environment overrides backend/.env: same code and credentials, E2E database and origins.
      command: `php artisan serve --host=localhost --port=${E2E_API_PORT}`,
      cwd: "../backend",
      url: `${API_URL}/up`,
      // Never attach to some other server that happens to listen on this port.
      reuseExistingServer: false,
      timeout: 60_000,
      env: {
        DB_DATABASE: E2E_DATABASE,
        APP_URL: API_URL,
        FRONTEND_URL,
        SANCTUM_STATEFUL_DOMAINS: `localhost:${E2E_WEB_PORT}`,
        SESSION_COOKIE,
        // Uploaded PDFs go to their own private folder, never the development document storage.
        DOCUMENTS_STORAGE_DIR: E2E_DOCUMENTS_DIR,
        // Many public-link calls come from one IP (127.0.0.1) within a minute; the limits themselves are
        // verified by PHPUnit (ClientUploadTest), so they are raised for the suite instead of slowing it down.
        PUBLIC_LINK_VIEWS_PER_MINUTE: "600",
        PUBLIC_LINK_UPLOADS_PER_MINUTE: "200",
        PUBLIC_LINK_INVALID_ATTEMPTS: "200",
      },
    },
    {
      command: `npm run dev -- --port ${E2E_WEB_PORT}`,
      url: `${FRONTEND_URL}/login`,
      reuseExistingServer: false,
      timeout: 180_000,
      env: {
        NEXT_PUBLIC_API_URL: API_URL,
        NEXT_PUBLIC_SESSION_COOKIE: SESSION_COOKIE,
        // Separate build directory so it can run next to the developer's own `next dev`.
        NEXT_DIST_DIR: ".next-e2e",
      },
    },
  ],
});