import { execSync } from "node:child_process";
import { rmSync } from "node:fs";
import path from "node:path";

import { E2E_DATABASE, E2E_DOCUMENTS_DIR, E2E_MAIL_UI_URL, FRONTEND_URL, SESSION_COOKIE, assertDisposableDatabase } from "./env";
import { clearE2eInbox } from "./mailpit";

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
      // Phase 7: the demo documents seeder stays off — the suites count records from the documented baseline,
      // and this process does not use the E2E document storage folder.
      env: { ...process.env, DB_DATABASE: E2E_DATABASE, LEXORA_DEMO_DOCUMENTS: "false" },
    });
    // The E2E API stores PDFs in its own private folder; start every run with an empty one.
    if (!/^e2e-[a-z0-9-]+$/.test(E2E_DOCUMENTS_DIR)) throw new Error(`Refusing to clear "${E2E_DOCUMENTS_DIR}".`);
    rmSync(path.resolve(__dirname, "../../backend/storage/app/private", E2E_DOCUMENTS_DIR), { recursive: true, force: true });
  }
  // Phase 6: the isolated E2E inbox must be running, and every run starts with it empty.
  try {
    await clearE2eInbox();
  } catch {
    throw new Error(`The E2E Mailpit is not reachable at ${E2E_MAIL_UI_URL}. Start Docker Desktop, then: docker compose up -d mailpit-e2e`);
  }
  await warmUp();
}

/**
 * `next dev` compiles each route on first request, which can take longer than an assertion timeout
 * on a cold build directory. Request every page once (a placeholder cookie lets the optimistic proxy
 * pass; the API still rejects it, so nothing is accessed). Failures are ignored.
 */
async function warmUp() {
  const routes = ["/login", "/dashboard", "/matters", "/clients", "/documents", "/matters/1", "/clients/1", "/matters/1/documents/1", "/document-requests", "/matters/1/requests/1", "/client-upload", "/signatures", "/matters/1/signatures/1", "/client-sign", "/notifications"];
  for (const route of routes) {
    try {
      await fetch(`${FRONTEND_URL}${route}`, { headers: { cookie: `${SESSION_COOKIE}=warm-up` }, signal: AbortSignal.timeout(120_000) });
    } catch {
      // Server not up yet or route failed to compile: the tests will report it.
    }
  }
}
