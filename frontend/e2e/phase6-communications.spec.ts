import { readFileSync } from "node:fs";
import path from "node:path";

import { expect, test, type Page } from "@playwright/test";

import { API_URL, STORAGE_STATE, apiGet, apiSend, createMatter, uniqueSuffix } from "./fixtures";
import { clickEmailLink, mailMessage, runCommunications, smtp } from "./mailpit";
import { makePdf } from "./pdf";
import { createRequestApi, publicApi, requestDetail, tokenOf } from "./requests";
import { drawSignature, uploadPdfApi } from "./signing";

test.use({ storageState: STORAGE_STATE });
test.describe.configure({ timeout: 180_000 });

interface Delivery {
  id: number;
  communication_type: string;
  initiated_by: string;
  status: string;
  recipient_email: string;
  provider_message_id: string | null;
}

async function deliveries(page: Page, target: "document-requests" | "signing-requests", matterId: number, id: number): Promise<Delivery[]> {
  return (await apiGet<{ data: Delivery[] }>(page.request, `/api/matters/${matterId}/${target}/${id}/communications`)).data;
}

async function unreadCount(page: Page): Promise<number> {
  return (await apiGet<{ data: { unread_count: number } }>(page.request, "/api/notifications/unread-count")).data.unread_count;
}

/** The client side of an upload, on the page the e-mail link opened. */
async function uploadOnClientPage(client: Page, name: string, pages: string[]) {
  await expect(client.getByRole("heading", { level: 1 })).toBeVisible();
  await client.locator("#client-file").setInputFiles({ name, mimeType: "application/pdf", buffer: makePdf(pages) });
  await client.getByRole("button", { name: /Submit (corrected )?document/ }).click();
  await expect(client.getByTestId("upload-success")).toBeVisible();
}

/** "Today" in the firm's time zone (backend LEXORA_TIMEZONE), as the scheduler sees it. */
function firmDate(offsetDays: number): string {
  const env = readFileSync(path.resolve(__dirname, "../../backend/.env"), "utf8");
  const tz = /^LEXORA_TIMEZONE=(.+)$/m.exec(env)?.[1]?.trim() || "UTC";
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
  const d = new Date(`${today}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + offsetDays);
  return d.toISOString().slice(0, 10);
}

// Scenarios A, D and I — the request is e-mailed, the client uploads through the e-mailed link, the firm is notified.
test("a document request is e-mailed, the client uploads through the e-mailed link and the administrator is notified", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const title = `Proof of insurance ${uniqueSuffix()}`;
  const created = await createRequestApi(page, matter, { title, issue_link: false });
  const unreadBefore = await unreadCount(page);

  await page.goto(`/matters/${matter.id}/requests/${created.data.id}`);
  await page.getByRole("button", { name: "Send e-mail" }).click();
  const dialog = page.getByRole("dialog", { name: "E-mail the document request" });
  await expect(dialog.getByTestId("email-recipient")).toContainText("Harborline Freight Ltd."); // the matter's client, chosen by the server
  await dialog.getByRole("button", { name: "Send e-mail" }).click();
  await expect(dialog.getByTestId("email-accepted")).toContainText("E-mail accepted by the configured mail server.");
  await expect(dialog.getByTestId("email-accepted")).toContainText("does not prove it reached the inbox");
  await dialog.getByRole("button", { name: "Done" }).click();
  await expect(page.getByTestId("communication-history").getByTestId("communication-item").first()).toHaveAttribute("data-status", "accepted");

  // The real message in the E2E inbox.
  const [delivery] = await deliveries(page, "document-requests", matter.id, created.data.id);
  expect(delivery.status).toBe("accepted");
  const message = await mailMessage(delivery.provider_message_id!);
  expect(message.Subject).toBe("Document request — Lexora Legal");
  expect(message.To[0].Address).toBe(delivery.recipient_email);
  expect(message.HTML).toContain(title);
  expect(message.Text).toContain(title);

  // The client clicks the button in the e-mail: no account, the upload page, the PDF reaches the matter.
  const client = await clickEmailLink(browser, delivery.provider_message_id!, "Upload the document securely");
  await expect(client.getByRole("heading", { level: 1, name: title })).toBeVisible();
  await uploadOnClientPage(client, "insurance.pdf", ["Certificate of insurance"]);
  // The client's browser cannot reach administrator data.
  const anonymous = await client.context().request.get(`${API_URL}/api/notifications`, { headers: { Accept: "application/json" } });
  expect(anonymous.status()).toBe(401);
  await client.context().close();
  expect((await requestDetail(page, created.data)).status).toBe("submitted");

  // The administrator's bell shows the new notification; opening it leads to the request and marks it read.
  await page.reload();
  await expect.poll(() => unreadCount(page)).toBe(unreadBefore + 1);
  await expect(page.getByTestId("notification-count")).toHaveText(String(unreadBefore + 1));
  await page.getByTestId("notification-bell").click();
  const panel = page.getByRole("region", { name: "Recent notifications" });
  const item = panel.getByTestId("notification-item").first();
  await expect(item).toContainText("Client document received — Review required");
  await expect(item).toHaveAttribute("data-unread", "true");
  await item.getByRole("link").click();
  await expect(page).toHaveURL(new RegExp(`/matters/${matter.id}/requests/${created.data.id}$`));
  await expect.poll(() => unreadCount(page)).toBe(unreadBefore);
  await page.reload(); // persisted
  await page.goto("/notifications");
  await expect(page.getByTestId("notification-item").first()).toHaveAttribute("data-unread", "false");

  // History of the matter tells the story.
  await page.goto(`/matters/${matter.id}?tab=activity`);
  await expect(page.getByRole("list", { name: "Activity history" }).getByText("E-mail accepted by the mail server", { exact: true })).toBeVisible();
});

// Scenarios B and E — an e-mailed invitation: Sent on SMTP acceptance, then viewed, started and signed via the e-mailed link.
test("a signing invitation is e-mailed and the e-mailed link completes the signature", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const { document } = await uploadPdfApi(page, matter, `Engagement letter ${uniqueSuffix()}`, ["Engagement letter", "Signature page"]);
  const created = await apiSend<{ data: { id: number } }>(page, "POST", `/api/matters/${matter.id}/signing-requests`, {
    document_id: document.id, document_version_id: document.current_version.id, signer_type: "client",
    signer_name: "Arta Gashi", signer_email: "arta.gashi@northwind.example", issue_link: false,
  });

  await page.goto(`/matters/${matter.id}/signatures/${created.data.id}`);
  await expect(page.getByTestId("signing-status")).toHaveText("Ready to send");
  await page.getByRole("button", { name: "Send signing invitation" }).click();
  const dialog = page.getByRole("dialog", { name: "E-mail the signing invitation" });
  await expect(dialog.getByTestId("email-recipient")).toContainText("arta.gashi@northwind.example");
  await dialog.getByRole("button", { name: "Send e-mail" }).click();
  await expect(dialog.getByTestId("email-accepted")).toBeVisible();
  await dialog.getByRole("button", { name: "Done" }).click();
  await expect(page.getByTestId("signing-status")).toHaveText("Sent");
  await expect(page.getByTestId("progress-sent")).toContainText("Invitation e-mail accepted by the configured mail server.");

  const [delivery] = await deliveries(page, "signing-requests", matter.id, created.data.id);
  expect((await mailMessage(delivery.provider_message_id!)).Subject).toBe("Signature requested — Lexora Legal");
  const client = await clickEmailLink(browser, delivery.provider_message_id!, "Review and sign the document");
  await expect(client.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();
  await client.getByRole("button", { name: "Start signing" }).click();
  await drawSignature(client);
  await client.getByLabel("Your full name", { exact: true }).fill("Arta Gashi");
  await client.getByTestId("consent-checkbox").check();
  await client.getByRole("button", { name: "Sign document" }).click();
  await expect(client.getByTestId("sign-success")).toBeVisible();
  await client.context().close();

  await page.reload();
  await expect(page.getByTestId("signing-status")).toHaveText("Signed");
  for (const step of ["sent", "viewed", "started", "signed"]) await expect(page.getByTestId(`progress-${step}`)).toHaveAttribute("data-reached", "true");
  await expect(page.getByTestId("communication-history")).toContainText("Signing invitation e-mail");
  await expect(page.getByRole("button", { name: /invitation/ })).toHaveCount(0); // completed: nothing more to send

  // Signature notification → the signing request with its evidence.
  await page.getByTestId("notification-bell").click();
  const item = page.getByRole("region", { name: "Recent notifications" }).getByTestId("notification-item").first();
  await expect(item).toContainText("Document signed successfully");
  await item.getByRole("link").click();
  await expect(page).toHaveURL(new RegExp(`/matters/${matter.id}/signatures/${created.data.id}$`));
  await expect(page.getByTestId("evidence-card")).toContainText("Arta Gashi");
});

// Scenario C — the correction decision sends its own e-mail; the corrected upload is the next version.
test("a correction request is e-mailed and the corrected PDF arrives as the next version", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const created = await createRequestApi(page, matter);
  expect((await publicApi(tokenOf(created.link!.url), "POST", { file: { name: "draft.pdf", mimeType: "application/pdf", buffer: makePdf(["Draft"]) } })).status).toBe(201);
  let detail = await requestDetail(page, created.data);
  await apiSend(page, "POST", `/api/matters/${matter.id}/document-requests/${created.data.id}/start-review`, {
    submission_id: detail.latest_submission!.id, lock_version: detail.lock_version,
  });

  await page.goto(`/matters/${matter.id}/requests/${created.data.id}`);
  await page.getByRole("button", { name: "Request correction" }).click();
  const dialog = page.getByRole("dialog", { name: "Request a correction" });
  await dialog.getByLabel("What needs to be corrected?").fill("Page 2 is missing the second signature.");
  await dialog.getByLabel("E-mail the correction request to the client").check();
  await dialog.getByRole("button", { name: "Request correction" }).click();
  await expect(page.getByText("Correction e-mail accepted by the configured mail server.")).toBeVisible();

  const [delivery] = await deliveries(page, "document-requests", matter.id, created.data.id);
  expect(delivery.communication_type).toBe("document_correction");
  const message = await mailMessage(delivery.provider_message_id!);
  expect(message.Subject).toBe("Correction requested — Lexora Legal");
  expect(message.HTML).not.toContain("second signature"); // the reason stays behind the secure link

  const client = await clickEmailLink(browser, delivery.provider_message_id!, "Review and upload the correction");
  await expect(client.getByTestId("correction-notice")).toContainText("Page 2 is missing the second signature.");
  await uploadOnClientPage(client, "corrected.pdf", ["Corrected", "Both signatures"]);
  await client.context().close();

  detail = await requestDetail(page, created.data);
  expect(detail.status).toBe("submitted");
  expect(detail.latest_submission!.submission_number).toBe(2);
  await page.getByTestId("notification-bell").click();
  await expect(page.getByRole("region", { name: "Recent notifications" }).getByTestId("notification-item").first()).toContainText("Corrected document received");
});

// Scenario F — a real SMTP failure is reported as a failure; nothing is marked Sent; a retry succeeds.
test("a failed invitation e-mail is reported honestly and can be retried", async ({ page }) => {
  const matter = await createMatter(page);
  const { document } = await uploadPdfApi(page, matter, `Retainer ${uniqueSuffix()}`, ["Retainer"]);
  const created = await apiSend<{ data: { id: number } }>(page, "POST", `/api/matters/${matter.id}/signing-requests`, {
    document_id: document.id, document_version_id: document.current_version.id, signer_type: "client",
    signer_name: "Ana Krasniqi", signer_email: "ana.krasniqi@northwind.example", issue_link: false,
  });
  await page.goto(`/matters/${matter.id}/signatures/${created.data.id}`);
  await page.getByRole("button", { name: "Send signing invitation" }).click();
  const dialog = page.getByRole("dialog", { name: "E-mail the signing invitation" });

  await smtp("down");
  try {
    await dialog.getByRole("button", { name: "Send e-mail" }).click();
    await expect(dialog.getByTestId("email-failed")).toContainText("E-mail could not be sent. No successful delivery has been recorded.");
    await expect(dialog.getByTestId("email-failed")).toContainText("The mail server could not be reached.");
  } finally {
    await smtp("up");
  }
  await expect(page.getByTestId("signing-status")).toHaveText("Ready to send");
  await expect(page.getByTestId("progress-sent")).toHaveAttribute("data-reached", "false");

  await dialog.getByRole("button", { name: "Try again" }).click();
  await expect(dialog.getByTestId("email-accepted")).toBeVisible();
  await dialog.getByRole("button", { name: "Done" }).click();
  await expect(page.getByTestId("signing-status")).toHaveText("Sent");
  const history = page.getByTestId("communication-history").getByTestId("communication-item");
  await expect(history).toHaveCount(2);
  await expect(history.nth(0)).toHaveAttribute("data-status", "accepted");
  await expect(history.nth(1)).toHaveAttribute("data-status", "failed");
  await expect(history.nth(1)).toContainText("Not sent · The mail server could not be reached.");
});

// Scenario G — the scheduler sends due reminders once, skips requests that no longer need the client.
test("the reminder scheduler sends due reminders once and skips closed requests", async ({ page }) => {
  const matter = await createMatter(page);
  const due = await createRequestApi(page, matter, { due_date: firmDate(3) }); // "3 days before" slot is today
  const closed = await createRequestApi(page, matter, { due_date: firmDate(3) });
  const lock = (await requestDetail(page, closed.data)).lock_version;
  await apiSend(page, "POST", `/api/matters/${matter.id}/document-requests/${closed.data.id}/cancel`, { lock_version: lock });

  runCommunications();
  runCommunications(); // a second run (or a parallel scheduler) never repeats a slot

  const sent = await deliveries(page, "document-requests", matter.id, due.data.id);
  expect(sent.map((d) => [d.communication_type, d.initiated_by, d.status])).toEqual([["document_request_reminder", "automatic", "accepted"]]);
  expect((await mailMessage(sent[0].provider_message_id!)).Subject).toBe("Reminder: document request — Lexora Legal");
  expect(await deliveries(page, "document-requests", matter.id, closed.data.id)).toEqual([]);
  // The reminder carries the request's new link: the earlier one stopped working.
  expect((await publicApi(tokenOf(due.link!.url))).status).toBe(404);

  await page.goto(`/matters/${matter.id}/requests/${due.data.id}`);
  await expect(page.getByTestId("communication-history")).toContainText("automatic");
});
