import { execSync } from "node:child_process";
import path from "node:path";

import { E2E_DATABASE, FRONTEND_URL, SESSION_COOKIE, assertDisposableDatabase } from "./env";

/**
 * Resets the DISPOSABLE end-to-end database to the documented demo workspace before every run, so
 * Phase 1 assertions (seeded counts) and Phase 2 workflows (which create records) are repeatable.
 * Only a database whose name ends in "_e2e" is ever reset; the development database is never touched.
 * Set E2E_SKIP_SEED=1 to skip (e.g. when the backend runs elsewhere).
 */
export default async function globalSetup() {
  if (!process.env.E2E_SKIP_SEED) {
    assertDisposableDatabase(E2E_DATABASE);
    execSync("php artisan migrate:fresh --seed --force --no-interaction", {
      cwd: path.resolve(__dirname, "../../backend"),
      stdio: "inherit",
      env: { ...process.env, DB_DATABASE: E2E_DATABASE },
    });
  }
  await warmUp();
}

/**
 * `next dev` compiles each route on first request, which can take longer than an assertion timeout
 * on a cold build directory. Request every page once (a placeholder cookie lets the optimistic proxy
 * pass; the API still rejects it, so nothing is accessed). Failures are ignored.
 */
async function warmUp() {
  const routes = ["/login", "/dashboard", "/matters", "/clients", "/matters/1", "/clients/1"];
  for (const route of routes) {
    try {
      await fetch(`${FRONTEND_URL}${route}`, { headers: { cookie: `${SESSION_COOKIE}=warm-up` }, signal: AbortSignal.timeout(120_000) });
    } catch {
      // Server not up yet or route failed to compile: the tests will report it.
    }
  }
}