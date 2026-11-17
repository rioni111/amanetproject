/**
 * Isolated end-to-end environment.
 *
 * Phase 2 specs create clients, matters, tasks and notes, so the suite never runs against the
 * development database or the developer's running servers. Playwright starts its OWN Laravel and
 * Next.js servers on dedicated ports, pointed at a disposable database that global-setup resets
 * (migrate:fresh --seed) before every run:
 *
 *   API   http://localhost:E2E_API_PORT  (default 8011)  → PostgreSQL database E2E_DB_DATABASE (default amanet_e2e)
 *   Web   http://localhost:E2E_WEB_PORT  (default 3011)  → own build directory .next-e2e
 *
 * A distinct session cookie name keeps the E2E browser session apart from any other local app.
 */
export const E2E_API_PORT = Number(process.env.E2E_API_PORT ?? 8011);
export const E2E_WEB_PORT = Number(process.env.E2E_WEB_PORT ?? 3011);
export const API_URL = `http://localhost:${E2E_API_PORT}`;
export const FRONTEND_URL = `http://localhost:${E2E_WEB_PORT}`;
export const E2E_DATABASE = process.env.E2E_DB_DATABASE ?? "amanet_e2e";
export const SESSION_COOKIE = "lexora_e2e_session";
/** Private document storage of the E2E API: backend/storage/app/private/<this>, wiped before each run. */
export const E2E_DOCUMENTS_DIR = "e2e-documents";

/**
 * Phase 6 — e-mail. The E2E API sends through a small test-controlled TCP relay (e2e/smtp-relay.mjs) to the
 * ISOLATED E2E Mailpit (docker compose service mailpit-e2e). Tests read messages through Mailpit's API and
 * can switch the relay to "down" to produce a genuine SMTP connection failure. Never the dev inbox.
 */
export const E2E_MAIL_SMTP_PORT = Number(process.env.LEXORA_E2E_MAIL_SMTP_PORT ?? 1027);
export const E2E_MAIL_UI_URL = `http://127.0.0.1:${Number(process.env.LEXORA_E2E_MAIL_UI_PORT ?? 8027)}`;
export const E2E_SMTP_RELAY_PORT = Number(process.env.E2E_SMTP_RELAY_PORT ?? 1028);
export const E2E_SMTP_RELAY_CONTROL = `http://127.0.0.1:${Number(process.env.E2E_SMTP_RELAY_CONTROL_PORT ?? 1029)}`;

/** Refuse to reset anything that is not clearly a disposable end-to-end database. */
export function assertDisposableDatabase(name: string): void {
  if (!/_e2e$/.test(name)) {
    throw new Error(`Refusing to reset database "${name}": E2E databases must end with "_e2e".`);
  }
}
