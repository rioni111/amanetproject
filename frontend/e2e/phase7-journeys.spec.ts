import { expect, request as playwrightRequest, test } from "@playwright/test";

import { API_URL, SPA_HEADERS, STORAGE_STATE, apiGet, apiSend, createMatter, dashboardStats, uniqueSuffix } from "./fixtures";
import { clickEmailLink, mailMessage } from "./mailpit";
import { makePdf } from "./pdf";
import { createRequestApi, publicApi, tokenOf } from "./requests";
import { uploadPdfApi } from "./signing";

/**
 * Phase 7 — final acceptance journeys across phases: everything an Amanet reviewer would do in one sitting,
 * through the real UI, the real API, real SMTP (isolated Mailpit) and an anonymous client browser.
 */
test.use({ storageState: STORAGE_STATE });
test.describe.configure({ timeout: 240_000 });

function isoDate(daysFromToday: number): string {
  const d = new Date();
  d.setDate(d.getDate() + daysFromToday);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

// Journeys A + B — new client → matter → task and note → e-mailed document request → client upload → approval.
test("a new client's matter goes from intake to an approved client document", async ({ page, browser }) => {
  const suffix = uniqueSuffix();
  const clientName = `Halden & Moss Partners ${suffix}`;
  const reference = `CL-J-${suffix}`;
  const matterTitle = `Shareholder agreement review ${suffix}`;
  const before = await dashboardStats(page);

  // A1 — client.
  await page.goto("/clients");
  await page.getByRole("button", { name: "New client" }).click();
  const newClient = page.getByRole("dialog", { name: "New client" });
  await newClient.getByLabel("Client name").fill(clientName);
  await newClient.getByLabel("Client type").selectOption("organization");
  await newClient.getByLabel("Email").fill(`legal+${suffix.toLowerCase()}@halden-moss.example`);
  await newClient.getByLabel("Reference number").fill(reference);
  await newClient.getByRole("button", { name: "Create client" }).click();
  await expect(page.getByText("Client created")).toBeVisible();

  // A2 — matter with status, priority and dates.
  await page.goto("/matters");
  await page.getByRole("button", { name: "New matter" }).click();
  const newMatter = page.getByRole("dialog", { name: "New matter" });
  await newMatter.getByLabel("Matter title").fill(matterTitle);
  await newMatter.getByLabel("Client").selectOption({ label: `${clientName} (${reference})` });
  await newMatter.getByLabel("Matter type").fill("Contract Review");
  await newMatter.getByLabel("Priority").selectOption("high");
  await newMatter.getByLabel("Deadline").fill(isoDate(12));
  await newMatter.getByRole("button", { name: "Create matter" }).click();
  await expect(page).toHaveURL(/\/matters\/\d+$/);
  await expect(page.getByRole("heading", { level: 1, name: matterTitle })).toBeVisible();
  const matterId = Number(page.url().split("/").pop());

  // A3 — task and internal note.
  await page.goto(`/matters/${matterId}?tab=tasks`);
  await page.getByRole("button", { name: "Add the first task" }).click();
  const task = page.getByRole("dialog", { name: "New task" });
  await task.getByLabel("Task title").fill("Collect signed shareholder register");
  await task.getByRole("button", { name: "Add task" }).click();
  await expect(page.getByText("Task added")).toBeVisible();
  await page.goto(`/matters/${matterId}?tab=notes`);
  await page.getByLabel("New internal note").fill("Client wants drag-along threshold at 75%.");
  await page.getByRole("button", { name: "Add note" }).click();
  await expect(page.getByText("Internal note added")).toBeVisible();

  // A4 — the dashboard and the matter list reflect it.
  const after = await dashboardStats(page);
  expect(after.total_clients).toBe(before.total_clients + 1);
  expect(after.active_matters).toBe(before.active_matters + 1);
  expect(after.open_tasks).toBe(before.open_tasks + 1);
  await page.goto(`/matters?search=${encodeURIComponent(suffix)}`);
  await expect(page.getByRole("table", { name: "Matters" }).getByRole("link", { name: matterTitle })).toBeVisible();

  // B1 — request a document and e-mail it to the client (real SMTP, isolated Mailpit).
  const matter = { id: matterId } as Parameters<typeof createRequestApi>[1];
  const request = await createRequestApi(page, matter, { title: `Signed shareholder register ${suffix}`, issue_link: false, due_date: isoDate(7) });
  await page.goto(`/matters/${matterId}/requests/${request.data.id}`);
  await page.getByRole("button", { name: "Send e-mail" }).click();
  const send = page.getByRole("dialog", { name: "E-mail the document request" });
  await expect(send.getByTestId("email-recipient")).toContainText(clientName);
  await send.getByRole("button", { name: "Send e-mail" }).click();
  await expect(send.getByTestId("email-accepted")).toBeVisible();
  await send.getByRole("button", { name: "Done" }).click();
  const [delivery] = (await apiGet<{ data: { provider_message_id: string; recipient_email: string }[] }>(page.request,
    `/api/matters/${matterId}/document-requests/${request.data.id}/communications`)).data;
  expect((await mailMessage(delivery.provider_message_id)).To[0].Address).toBe(`legal+${suffix.toLowerCase()}@halden-moss.example`);

  // B2 — the client opens the delivered e-mail and uploads without an account.
  const client = await clickEmailLink(browser, delivery.provider_message_id, "Upload the document securely");
  await client.locator("#client-file").setInputFiles({ name: "shareholder-register.pdf", mimeType: "application/pdf", buffer: makePdf(["Register of members", "Signed"]) });
  await client.getByRole("button", { name: "Submit document" }).click();
  await expect(client.getByTestId("upload-success")).toBeVisible();
  await client.context().close();

  // B3 — notified, reviewed and approved in the workspace.
  await page.reload();
  await expect(page.getByTestId("notification-count")).toBeVisible();
  const card = page.getByTestId("review-card");
  await card.getByRole("button", { name: "Start review" }).click();
  await expect(page.getByTestId("request-status").first()).toHaveText("Under review");
  await card.getByRole("button", { name: "Approve" }).click();
  await page.getByRole("alertdialog", { name: "Approve v1?" }).getByRole("button", { name: "Approve document" }).click();
  await expect(page.getByTestId("request-status").first()).toHaveText("Approved");

  // B4 — the document is in the library under this matter, and the activity tells the whole story in order.
  await page.goto(`/documents?search=${encodeURIComponent(suffix)}`);
  await expect(page.getByRole("link", { name: `Signed shareholder register ${suffix}` }).first()).toBeVisible();
  await page.goto(`/matters/${matterId}?tab=activity`);
  const history = page.getByRole("list", { name: "Activity history" });
  for (const label of ["Matter opened", "Task added", "Internal note added", "Document requested from client", "E-mail accepted by the mail server",
    "Client submitted a document", "Review started", "Document approved"]) {
    await expect(history.getByText(label, { exact: true }).first()).toBeVisible();
  }
  await expect(history).not.toContainText("drag-along"); // note text is never copied into the history
});

// Journey H — identifiers and tokens cannot be used to cross boundaries.
test("ids, tokens and public links cannot reach another matter, another purpose or administrator data", async ({ page }) => {
  const matterA = await createMatter(page);
  const matterB = await createMatter(page);
  const { document } = await uploadPdfApi(page, matterA, `Boundary PDF ${uniqueSuffix()}`, ["Confidential to matter A"]);
  const requestA = await createRequestApi(page, matterA);
  const signing = await apiSend<{ data: { id: number }; link: { url: string } }>(page, "POST", `/api/matters/${matterA.id}/signing-requests`, {
    document_id: document.id, document_version_id: document.current_version.id, signer_type: "client",
    signer_name: "Ana Krasniqi", signer_email: "ana.krasniqi@northwind.example", issue_link: true,
  });

  // Matter A's resources addressed through matter B: not found (scoped bindings), never another matter's data.
  for (const path of [
    `/api/matters/${matterB.id}/documents/${document.id}`,
    `/api/matters/${matterB.id}/documents/${document.id}/versions/${document.current_version.id}/download`,
    `/api/matters/${matterB.id}/document-requests/${requestA.data.id}`,
    `/api/matters/${matterB.id}/signing-requests/${signing.data.id}`,
    `/api/matters/${matterB.id}/signing-requests/${signing.data.id}/communications`,
  ]) {
    const response = await page.request.get(`${API_URL}${path}`, { headers: SPA_HEADERS });
    expect(response.status(), path).toBe(404);
  }

  // Wrong purpose: a signing token is unknown to the upload API, an upload token to the signing API.
  const signingToken = tokenOf(signing.link.url);
  const uploadToken = tokenOf(requestA.link!.url);
  expect((await publicApi(signingToken)).status).toBe(404);
  const anon = await playwrightRequest.newContext();
  const wrong = await anon.get(`${API_URL}/api/public/signing`, { headers: { Accept: "application/json", "X-Signing-Token": uploadToken } });
  expect(wrong.status()).toBe(404);

  // A token is never an administrator credential, and the public responses carry no internal data.
  for (const [header, token] of [["X-Upload-Token", uploadToken], ["X-Signing-Token", signingToken]] as const) {
    for (const path of [`/api/matters/${matterA.id}`, `/api/matters/${matterA.id}/notes`, "/api/notifications", "/api/dashboard/summary"]) {
      const response = await anon.get(`${API_URL}${path}`, { headers: { Accept: "application/json", [header]: token } });
      expect(response.status(), `${header} → ${path}`).toBe(401);
    }
  }
  const view = await publicApi(uploadToken);
  expect(view.status).toBe(200);
  const body = JSON.stringify(view.body);
  for (const secret of [matterA.matter_number, matterA.title, "Harborline", "Elena Marsh"]) expect(body).not.toContain(secret);
  await anon.dispose();
});
