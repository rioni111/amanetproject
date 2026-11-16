import { defineConfig, devices } from "@playwright/test";

/**
 * End-to-end tests run against the REAL stack: Next.js (3000) → Laravel (8000) → PostgreSQL.
 * Prerequisites: PostgreSQL running and `php artisan migrate --seed` executed in ../backend.
 * Both dev servers are started automatically unless already running.
 */
const FRONTEND_URL = process.env.E2E_BASE_URL ?? "http://localhost:3000";
const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export default defineConfig({
  testDir: "./e2e",
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [["list"], ["html", { open: "never" }]],
  globalSetup: "./e2e/global-setup.ts",
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
      command: "php artisan serve --host=localhost --port=8000",
      cwd: "../backend",
      url: `${API_URL}/up`,
      reuseExistingServer: true,
      timeout: 60_000,
    },
    {
      command: "npm run dev",
      url: `${FRONTEND_URL}/login`,
      reuseExistingServer: true,
      timeout: 120_000,
    },
  ],
});
