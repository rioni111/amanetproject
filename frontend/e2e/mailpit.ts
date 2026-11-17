import { execFileSync } from "node:child_process";
import path from "node:path";

import { expect, type Browser, type Page } from "@playwright/test";

import { E2E_DATABASE, E2E_DOCUMENTS_DIR, E2E_MAIL_SMTP_PORT, E2E_MAIL_UI_URL, E2E_SMTP_RELAY_CONTROL, FRONTEND_URL, assertDisposableDatabase } from "./env";

/**
 * Phase 6 helpers around the isolated E2E Mailpit. Links are never printed: tests open the delivered
 * message's own HTML (Mailpit's /view/<id>.html) in an anonymous browser and CLICK the link, exactly as a
 * client would, so the token only ever exists inside the e-mail and the client's browser.
 */
export interface MailSummary {
  ID: string;
  Subject: string;
  To: { Address: string; Name: string }[];
  Created: string;
}

export async function mailSearch(query: string): Promise<MailSummary[]> {
  const res = await fetch(`${E2E_MAIL_UI_URL}/api/v1/search?query=${encodeURIComponent(query)}&limit=50`);
  if (!res.ok) throw new Error(`Mailpit search failed: ${res.status}`);
  return ((await res.json()) as { messages: MailSummary[] | null }).messages ?? [];
}

/** Waits until exactly `count` messages match (newest first) and returns them. */
export async function waitForMail(query: string, count = 1): Promise<MailSummary[]> {
  let found: MailSummary[] = [];
  await expect
    .poll(async () => {
      found = await mailSearch(query);
      return found.length;
    }, { timeout: 15_000, message: `messages matching ${query}` })
    .toBe(count);
  return found;
}

export async function mailMessage(id: string): Promise<{ Subject: string; From: { Address: string }; To: { Address: string }[]; Text: string; HTML: string }> {
  const res = await fetch(`${E2E_MAIL_UI_URL}/api/v1/message/${id}`);
  if (!res.ok) throw new Error(`Mailpit message failed: ${res.status}`);
  return res.json();
}

/**
 * Opens the delivered e-mail in a fresh anonymous browser context (no cookies, no workspace session) and
 * clicks its action button. Returns the client page that the e-mail link opened.
 */
export async function clickEmailLink(browser: Browser, id: string, buttonName: string | RegExp, options: Parameters<Browser["newContext"]>[0] = {}): Promise<Page> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, ...options });
  const inbox = await context.newPage();
  await inbox.goto(`${E2E_MAIL_UI_URL}/view/${id}.html`);
  const [client] = await Promise.all([context.waitForEvent("page"), inbox.getByRole("link", { name: buttonName }).click()]);
  await client.waitForLoadState("domcontentloaded");
  expect(client.url().startsWith(`${FRONTEND_URL}/client-`), "the e-mail link opens the workspace's public client page").toBe(true);
  expect(new URL(client.url()).search, "the token is not in a query string").toBe("");
  await inbox.close();
  return client;
}

/** Switches the test SMTP relay: "down" refuses connections (a real transport failure), "up" restores it. */
export async function smtp(state: "up" | "down"): Promise<void> {
  const res = await fetch(`${E2E_SMTP_RELAY_CONTROL}/${state}`, { method: "POST" });
  expect(res.ok).toBe(true);
}

/** Runs `php artisan lexora:communications` against the DISPOSABLE E2E database and the E2E Mailpit. */
export function runCommunications(): string {
  assertDisposableDatabase(E2E_DATABASE);
  return execFileSync("php", ["artisan", "lexora:communications"], {
    cwd: path.resolve(__dirname, "../../backend"),
    env: {
      ...process.env,
      DB_DATABASE: E2E_DATABASE,
      DOCUMENTS_STORAGE_DIR: E2E_DOCUMENTS_DIR,
      FRONTEND_URL,
      MAIL_MAILER: "smtp",
      MAIL_HOST: "127.0.0.1",
      MAIL_PORT: String(E2E_MAIL_SMTP_PORT),
      MAIL_USERNAME: "",
      MAIL_PASSWORD: "",
    },
    encoding: "utf8",
  });
}

/** Deletes every message in the E2E inbox (never the development inbox). */
export async function clearE2eInbox(): Promise<void> {
  const res = await fetch(`${E2E_MAIL_UI_URL}/api/v1/messages`, { method: "DELETE" });
  if (!res.ok) throw new Error(`Could not clear the E2E inbox: ${res.status}`);
}
