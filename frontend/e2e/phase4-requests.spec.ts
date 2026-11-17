import { readFileSync } from "node:fs";

import { expect, request as playwrightRequest, test, type Browser, type Page } from "@playwright/test";

import { FRONTEND_URL } from "./env";
import { API_URL, STORAGE_STATE, apiGet, apiSend, createMatter, uniqueSuffix, type ApiMatter } from "./fixtures";
import { pdfFile, sha256 } from "./pdf";
import { anonymousPage, clientUploads, createRequestApi, e2eFixture, publicApi, requestDetail, tokenOf, type WithLink } from "./requests";

test.use({ storageState: STORAGE_STATE });
// Multi-context workflows (administrator + anonymous client) on development servers take longer than 30 s.
test.describe.configure({ timeout: 180_000 });

/** Opens a client link in a fresh, cookie-less browser context (an external client). */
async function asClient(browser: Browser, url: string): Promise<Page> {
  const client = await anonymousPage(browser);
  await client.goto(url);
  return client;
}

async function reviewAction(page: Page, request: WithLink["data"], action: "start-review" | "approve") {
  const current = await requestDetail(page, request);
  return apiSend(page, "POST", `/api/matters/${request.matter_id}/document-requests/${request.id}/${action}`, {
    submission_id: current.latest_submission!.id,
    lock_version: current.lock_version,
  });
}

// Scenarios A, B, C, E — request through the UI, copy the link, anonymous upload, preview, verify bytes, approve.
test("a client uploads a requested PDF without an account and the firm reviews and approves it", async ({ page, browser, context }) => {
  await context.grantPermissions(["clipboard-read", "clipboard-write"], { origin: FRONTEND_URL });
  const matter = await createMatter(page);
  const title = `Signed Engagement Letter ${uniqueSuffix()}`;

  // A — create the request (with validation) from the matter's Client requests tab.
  await page.goto(`/matters/${matter.id}?tab=requests`);
  await expect(page.getByText("No documents have been requested from the client yet.")).toBeVisible();
  await page.getByRole("button", { name: "Request the first document" }).click();
  const dialog = page.getByRole("dialog", { name: "Request a document from the client" });
  await expect(dialog).toContainText("Requested from Harborline Freight Ltd.");
  await dialog.getByRole("button", { name: "Create request" }).click();
  await expect(dialog.getByText("Enter a title for the request.")).toBeVisible();
  await expect(dialog.getByText("Tell the client what to upload.")).toBeVisible();
  await expect(dialog.getByText("Choose the document category.")).toBeVisible();
  await dialog.getByLabel("Request title").fill(title);
  await dialog.getByLabel("Document category").selectOption("agreements");
  await dialog.getByLabel("Instructions for the client").fill("Please upload the engagement letter signed by both directors.");
  await dialog.getByRole("button", { name: "Create request" }).click();

  // B — the link is shown once, can be copied, and carries the localhost warning in development.
  const reveal = page.getByRole("dialog", { name: "Secure upload link" });
  const url = await reveal.getByTestId("issued-link-url").inputValue();
  expect(url).toMatch(new RegExp(`^${FRONTEND_URL}/client-upload#[A-Za-z0-9_-]{43}$`));
  await expect(reveal.getByTestId("localhost-warning")).toContainText("only accessible from the development machine");
  await reveal.getByRole("button", { name: "Copy link" }).click();
  await expect(reveal.getByText("Link copied to the clipboard.")).toBeVisible();
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(url);
  await reveal.getByRole("button", { name: "Done" }).click();
  await expect(page).toHaveURL(new RegExp(`/matters/${matter.id}/requests/\\d+$`));
  await expect(page.getByRole("heading", { level: 1, name: title })).toBeVisible();
  await page.reload();
  await expect(page.getByTestId("request-status").first()).toHaveText("Awaiting client");
  await expect(page.getByTestId("link-card").getByTestId("link-state").first()).toHaveText("Active");
  expect(await page.content(), "the token is never shown again").not.toContain(tokenOf(url));

  // The client sees only this request — no matter, client or firm-internal data.
  const client = await asClient(browser, url);
  await expect(client.getByRole("heading", { level: 1, name: title })).toBeVisible();
  await expect(client.getByTestId("request-instructions")).toHaveText("Please upload the engagement letter signed by both directors.");
  const visible = await client.locator("body").innerText();
  for (const secret of [matter.matter_number, matter.title, "Harborline", "Elena Marsh"]) expect(visible).not.toContain(secret);
  await expect(client).toHaveTitle(/Secure document upload/);

  // C — real upload, no login, no cookies.
  const file = pdfFile("engagement-letter-signed.pdf", [`${title} — page 1`, "Signature page"]);
  await clientUploads(client, url, file, "Signed by both directors.");
  expect(await client.context().cookies(API_URL), "the public page never receives an API cookie").toHaveLength(0);
  await client.context().close();

  await page.reload();
  await expect(page.getByTestId("request-status").first()).toHaveText("Submitted");
  const card = page.getByTestId("review-card");
  await expect(card.getByTestId("submission-file")).toHaveText("engagement-letter-signed.pdf");
  await expect(card).toContainText("Signed by both directors.");
  await expect(card).toContainText("Version v1");

  // E — preview the actual PDF, verify the downloaded bytes, start review, approve.
  await card.getByRole("button", { name: "Preview v1" }).click();
  const viewer = page.getByRole("dialog", { name: new RegExp(`${title} · v1`) });
  await expect(viewer.locator('canvas[data-rendered="true"]').first()).toBeVisible();
  await expect(viewer.getByTestId("pdf-page-status")).toHaveText("Page 1 of 2");
  const [download] = await Promise.all([page.waitForEvent("download"), viewer.getByRole("button", { name: "Download v1" }).click()]);
  expect(sha256(readFileSync((await download.path())!))).toBe(sha256(file.buffer));
  await viewer.getByRole("button", { name: "Close" }).click();

  await card.getByRole("button", { name: "Start review" }).click();
  await expect(page.getByTestId("request-status").first()).toHaveText("Under review");
  await card.getByRole("button", { name: "Approve" }).click();
  await page.getByRole("alertdialog", { name: "Approve v1?" }).getByRole("button", { name: "Approve document" }).click();
  await expect(page.getByTestId("approved-banner")).toContainText("version v1 was accepted");
  await expect(page.getByTestId("request-status").first()).toHaveText("Approved");

  // The submitted PDF is a document of this matter, and the history tells the story with readable labels.
  await page.goto(`/matters/${matter.id}?tab=documents`);
  await expect(page.getByRole("table", { name: `Documents of ${matter.matter_number}` }).getByRole("link", { name: title, exact: true })).toBeVisible();
  await page.goto(`/matters/${matter.id}?tab=activity`);
  const history = page.getByRole("list", { name: "Activity history" });
  for (const label of ["Document requested from client", "Upload link generated", "Client submitted a document", "Review started", "Document approved"]) {
    await expect(history.getByText(label, { exact: true })).toBeVisible();
  }
  await expect(history.getByText("client, via secure link", { exact: false }).first()).toBeVisible();
  await expect(history).toContainText("Under review → Approved");
});

// Scenario D — every kind of unusable link is refused, by the page and by the API.
test("expired, revoked, replaced, used, invalid and wrong-purpose links cannot upload", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const pdf = () => pdfFile("attempt.pdf", [`Attempt ${uniqueSuffix()}`]);
  const multipart = () => {
    const f = pdf();
    return { file: { name: f.name, mimeType: f.mimeType, buffer: f.buffer } };
  };

  // Expired.
  const expired = await createRequestApi(page, matter);
  e2eFixture("expire", String(expired.link!.id));
  const c1 = await asClient(browser, expired.link!.url);
  await expect(c1.getByRole("heading", { name: "This link has expired" })).toBeVisible();
  expect((await publicApi(tokenOf(expired.link!.url), "POST", multipart())).status).toBe(410);
  await c1.context().close();

  // Revoked through the UI.
  const revoked = await createRequestApi(page, matter);
  await page.goto(`/matters/${matter.id}/requests/${revoked.data.id}`);
  await page.getByTestId("link-card").getByRole("button", { name: "Revoke link" }).click();
  await page.getByRole("alertdialog", { name: "Revoke the upload link?" }).getByRole("button", { name: "Revoke link" }).click();
  await expect(page.getByText("Link revoked", { exact: true })).toBeVisible();
  const c2 = await asClient(browser, revoked.link!.url);
  await expect(c2.getByRole("heading", { name: "This link is not valid" })).toBeVisible();
  expect((await publicApi(tokenOf(revoked.link!.url), "POST", multipart())).status).toBe(404);
  await c2.context().close();

  // Replaced (rotated) through the UI: the old link dies, the new one works.
  const rotated = await createRequestApi(page, matter);
  await page.goto(`/matters/${matter.id}/requests/${rotated.data.id}`);
  await page.getByTestId("link-card").getByRole("button", { name: "Generate replacement link" }).click();
  await page.getByRole("alertdialog", { name: "Replace the upload link?" }).getByRole("button", { name: "Generate replacement" }).click();
  const newUrl = await page.getByRole("dialog", { name: "Secure upload link" }).getByTestId("issued-link-url").inputValue();
  expect(newUrl).not.toBe(rotated.link!.url);
  const c3 = await asClient(browser, rotated.link!.url);
  await expect(c3.getByRole("heading", { name: "This link is not valid" })).toBeVisible();
  await c3.context().close();
  const c4 = await asClient(browser, newUrl);
  await expect(c4.getByRole("heading", { level: 1, name: rotated.data.title })).toBeVisible();
  await c4.context().close();

  // Used: one successful upload per link.
  const used = await createRequestApi(page, matter);
  const c5 = await anonymousPage(browser);
  await clientUploads(c5, used.link!.url, pdf());
  await c5.context().close();
  const c6 = await asClient(browser, used.link!.url);
  await expect(c6.getByRole("heading", { name: "This link has already been used" })).toBeVisible();
  expect((await publicApi(tokenOf(used.link!.url), "POST", multipart())).status).toBe(410);
  await c6.context().close();

  // Invalid or incomplete links.
  for (const bad of ["#not-a-real-token", `#${"A".repeat(43)}`, ""]) {
    const c = await asClient(browser, `${FRONTEND_URL}/client-upload${bad}`);
    await expect(c.getByRole("heading", { name: "This link is not valid" })).toBeVisible();
    await c.context().close();
  }

  // A token of another purpose (Phase 5 signing) is unknown to the upload page and API.
  const signing = e2eFixture("signing-token", String(used.data.id));
  const c7 = await asClient(browser, `${FRONTEND_URL}/client-upload#${signing}`);
  await expect(c7.getByRole("heading", { name: "This link is not valid" })).toBeVisible();
  await c7.context().close();
  expect((await publicApi(signing)).status).toBe(404);

  // A link token is never an administrator credential.
  const anonymous = await playwrightRequest.newContext();
  for (const path of ["/api/matters", `/api/matters/${matter.id}/document-requests`, "/api/document-requests"]) {
    const response = await anonymous.get(`${API_URL}${path}`, { headers: { Accept: "application/json", "X-Upload-Token": tokenOf(newUrl) } });
    expect(response.status(), path).toBe(401);
  }
  await anonymous.dispose();
});

// Scenario F — correction loop: v1 → correction with a fresh link → v2, both kept, v2 approved.
test("the firm requests a correction and approves the corrected version while keeping the original", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const created = await createRequestApi(page, matter, { title: `Client Service Agreement ${uniqueSuffix()}` });
  const v1 = pdfFile("service-agreement.pdf", ["Client Service Agreement — pages 1-2 only"]);
  const v2 = pdfFile("service-agreement-complete.pdf", ["Client Service Agreement — page 1", "page 2", "page 3"]);

  const client1 = await anonymousPage(browser);
  await clientUploads(client1, created.link!.url, v1);
  await client1.context().close();

  await page.goto(`/matters/${matter.id}/requests/${created.data.id}`);
  const card = page.getByTestId("review-card");
  await card.getByRole("button", { name: "Start review" }).click();
  await expect(page.getByTestId("request-status").first()).toHaveText("Under review");
  await card.getByRole("button", { name: "Request correction" }).click();
  const correction = page.getByRole("dialog", { name: "Request a correction" });
  await correction.getByRole("button", { name: "Request correction" }).click();
  await expect(correction.getByText("Give the client a clear reason (at least 10 characters).")).toBeVisible();
  const reason = "Please upload the agreement with all pages included.";
  await correction.getByLabel("What needs to be corrected?").fill(reason);
  await correction.getByRole("button", { name: "Request correction" }).click();
  const reveal = page.getByRole("dialog", { name: "Correction link" });
  const correctionUrl = await reveal.getByTestId("issued-link-url").inputValue();
  expect(correctionUrl).not.toBe(created.link!.url);
  await reveal.getByRole("button", { name: "Done" }).click();
  await expect(page.getByTestId("request-status").first()).toHaveText("Correction requested");

  // The client opens the NEW link, sees the instructions and uploads the corrected file.
  const client2 = await asClient(browser, correctionUrl);
  await expect(client2.getByTestId("correction-notice")).toContainText(reason);
  await expect(client2.getByTestId("correction-notice")).toContainText("Earlier files are kept on record by the firm.");
  await clientUploads(client2, correctionUrl, v2);
  await client2.context().close();

  await page.reload();
  await expect(page.getByTestId("request-status").first()).toHaveText("Submitted");
  await expect(page.getByTestId("review-card")).toContainText("Version v2");
  const rows = page.getByRole("list", { name: "Submissions" }).getByTestId("submission-row");
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(1)).toContainText("Correction requested");
  await expect(rows.nth(1)).toContainText(reason);

  // v1 is still available: preview it from the history.
  await rows.nth(1).getByRole("button", { name: "Preview submission 1 (v1)" }).click();
  const viewer = page.getByRole("dialog", { name: /· v1/ });
  await expect(viewer.locator('canvas[data-rendered="true"]').first()).toBeVisible();
  await viewer.getByRole("button", { name: "Close" }).click();

  await page.getByTestId("review-card").getByRole("button", { name: "Start review" }).click();
  await expect(page.getByTestId("request-status").first()).toHaveText("Under review");
  await page.getByTestId("review-card").getByRole("button", { name: "Approve" }).click();
  await page.getByRole("alertdialog", { name: "Approve v2?" }).getByRole("button", { name: "Approve document" }).click();
  await expect(page.getByTestId("approved-banner")).toContainText("version v2 was accepted");

  // Both immutable versions exist with their exact checksums; one document only.
  const detail = await requestDetail(page, created.data);
  const versions = await apiGet<{ data: { version_number: number; sha256_checksum: string; origin: string }[] }>(
    page.request,
    `/api/matters/${matter.id}/documents/${(detail as unknown as { document_id: number }).document_id}/versions`,
  );
  expect(versions.data.map((v) => [v.version_number, v.sha256_checksum, v.origin]).sort()).toEqual([
    [1, sha256(v1.buffer), "client"],
    [2, sha256(v2.buffer), "client"],
  ]);
  // The correction link is closed now.
  expect((await publicApi(tokenOf(correctionUrl))).status).toBe(410);
});

// Scenario G — outstanding requirements: counters, overdue, filters in the URL and pagination.
test("outstanding requirements are counted, filtered and paginated consistently", async ({ page }) => {
  const before = (await apiGet<{ data: { stats: Record<string, number> } }>(page.request, "/api/dashboard/summary")).data.stats;
  const matter = await createMatter(page);
  const suffix = uniqueSuffix();
  const make = (name: string, extra: Record<string, unknown> = {}) => createRequestApi(page, matter, { title: `${name} ${suffix}`, ...extra });

  const overdue = await make("Overdue passport copy");
  e2eFixture("overdue", String(overdue.data.id), "3");
  await make("Pending bank statement");
  const submitted = await make("Submitted lease");
  expect((await publicApi(tokenOf(submitted.link!.url), "POST", { file: pdfFile("lease.pdf", [`Lease ${suffix}`]) })).status).toBe(201);
  const correction = await make("Corrected invoice");
  expect((await publicApi(tokenOf(correction.link!.url), "POST", { file: pdfFile("invoice.pdf", [`Invoice ${suffix}`]) })).status).toBe(201);
  await reviewAction(page, correction.data, "start-review");
  const current = await requestDetail(page, correction.data);
  await apiSend(page, "POST", `/api/matters/${matter.id}/document-requests/${correction.data.id}/request-correction`, {
    submission_id: current.latest_submission!.id,
    lock_version: current.lock_version,
    reason: "Please send the invoice with the VAT number.",
  });
  const approved = await make("Approved deed");
  expect((await publicApi(tokenOf(approved.link!.url), "POST", { file: pdfFile("deed.pdf", [`Deed ${suffix}`]) })).status).toBe(201);
  await reviewAction(page, approved.data, "start-review");
  await reviewAction(page, approved.data, "approve");

  const after = (await apiGet<{ data: { stats: Record<string, number> } }>(page.request, "/api/dashboard/summary")).data.stats;
  const delta = (key: string) => after[key] - before[key];
  expect({
    outstanding: delta("requests_outstanding"),
    pending: delta("requests_pending"),
    review: delta("requests_awaiting_review"),
    correction: delta("requests_correction"),
    overdue: delta("requests_overdue"),
    approved: delta("requests_approved"),
  }).toEqual({ outstanding: 4, pending: 2, review: 1, correction: 1, overdue: 1, approved: 1 });

  // The dashboard shows the same figures and links to the matching list.
  await page.goto("/dashboard");
  const summary = page.getByTestId("requests-summary");
  await expect(summary.getByTestId("requests-stat-overdue")).toHaveText(String(after.requests_overdue));
  await expect(summary.getByTestId("requests-stat-awaiting-review")).toHaveText(String(after.requests_awaiting_review));
  await summary.getByRole("link", { name: `Overdue: ${after.requests_overdue}` }).click();
  await expect(page).toHaveURL(/\/document-requests\?overdue=1$/);
  const list = page.getByRole("list", { name: "Client requests" });
  await expect(list.getByTestId("request-row")).toHaveCount(after.requests_overdue);
  await expect(list.getByRole("link", { name: `Overdue passport copy ${suffix}` })).toBeVisible();

  // Status filter through the UI, kept in the URL across a reload.
  await page.goto(`/document-requests?search=${suffix}`);
  await expect(page.getByRole("list", { name: "Client requests" }).getByTestId("request-row")).toHaveCount(5);
  await page.getByLabel("Status", { exact: true }).selectOption("correction_requested");
  await expect(page).toHaveURL(new RegExp(`search=${suffix}.*status=correction_requested|status=correction_requested.*search=${suffix}`));
  await expect(page.getByRole("list", { name: "Client requests" }).getByTestId("request-row")).toHaveCount(1);
  await page.reload();
  await expect(page.getByLabel("Status", { exact: true })).toHaveValue("correction_requested");
  await expect(page.getByRole("list", { name: "Client requests" }).getByRole("link", { name: `Corrected invoice ${suffix}` })).toBeVisible();

  // Pagination (10 per page) on the server.
  for (let i = 1; i <= 7; i++) await make(`Batch item ${i}`, { issue_link: false });
  await page.goto(`/document-requests?search=${suffix}`);
  await expect(page.getByRole("list", { name: "Client requests" }).getByTestId("request-row")).toHaveCount(10);
  await page.getByRole("button", { name: "Next page" }).click();
  await expect(page).toHaveURL(/page=2/);
  await expect(page.getByRole("list", { name: "Client requests" }).getByTestId("request-row")).toHaveCount(2);
  await page.reload();
  await expect(page.getByRole("list", { name: "Client requests" }).getByTestId("request-row")).toHaveCount(2);
});

// Scenario I — archiving revokes links; restoring does not bring them back.
test("archiving a matter blocks its client links and restoring does not reactivate them", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const created = await createRequestApi(page, matter);
  const archivedMatter = await apiSend<{ data: ApiMatter }>(page, "POST", `/api/matters/${matter.id}/archive`, { lock_version: matter.lock_version });

  const c1 = await asClient(browser, created.link!.url);
  await expect(c1.getByRole("heading", { name: "This request is no longer available" })).toBeVisible();
  await c1.context().close();
  expect((await publicApi(tokenOf(created.link!.url), "POST", { file: pdfFile("late.pdf", ["Too late"]) })).status).toBe(410);

  await page.goto(`/matters/${matter.id}/requests/${created.data.id}`);
  await expect(page.getByText("Client uploads are refused and its upload links were revoked.")).toBeVisible();
  await expect(page.getByTestId("link-card").getByRole("button", { name: /Generate/ })).toHaveCount(0);

  await apiSend(page, "POST", `/api/matters/${matter.id}/restore`, { lock_version: archivedMatter.data.lock_version });
  const c2 = await asClient(browser, created.link!.url);
  await expect(c2.getByRole("heading", { name: "This request is no longer available" })).toBeVisible();
  await c2.context().close();

  // Only a deliberately issued new link works again.
  await page.reload();
  await page.getByTestId("link-card").getByRole("button", { name: "Generate upload link" }).click();
  const fresh = await page.getByRole("dialog", { name: "Secure upload link" }).getByTestId("issued-link-url").inputValue();
  const c3 = await asClient(browser, fresh);
  await expect(c3.getByRole("heading", { level: 1, name: created.data.title })).toBeVisible();
  await c3.context().close();
});

// Scenario J — security regression: notes, admin pages, existing downloads stay private.
test("the public side exposes nothing internal and admin data stays protected", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const secretNote = `INTERNAL settlement ceiling ${uniqueSuffix()}`;
  await apiSend(page, "POST", `/api/matters/${matter.id}/notes`, { body: secretNote });
  const created = await createRequestApi(page, matter);
  const token = tokenOf(created.link!.url);

  const view = await publicApi(token);
  expect(view.status).toBe(200);
  const json = JSON.stringify(view.body);
  for (const secret of [secretNote, matter.matter_number, matter.title, "Harborline", "matter_id", "client_id", "token"]) {
    expect(json, secret).not.toContain(secret);
  }
  expect((await publicApi(token, "POST", { file: pdfFile("contract.pdf", ["Contract"]) })).status).toBe(201);
  const detail = await requestDetail(page, created.data);
  const doc = (detail as unknown as { document_id: number }).document_id;
  const versionId = (await apiGet<{ data: { id: number }[] }>(page.request, `/api/matters/${matter.id}/documents/${doc}/versions`)).data[0].id;

  const anonymous = await playwrightRequest.newContext();
  for (const path of [
    `/api/matters/${matter.id}/notes`,
    `/api/matters/${matter.id}/documents/${doc}/versions/${versionId}/download`,
    `/api/matters/${matter.id}/documents/${doc}/versions/${versionId}/preview`,
    `/api/matters/${matter.id}/activity`,
  ]) {
    const response = await anonymous.get(`${API_URL}${path}`, { headers: { Accept: "application/json", "X-Upload-Token": token } });
    expect(response.status(), path).toBe(401);
  }
  await anonymous.dispose();

  // Administrator pages require a session; the public page does not.
  const outsider = await anonymousPage(browser);
  await outsider.goto("/document-requests");
  await expect(outsider).toHaveURL(/\/login\?next=%2Fdocument-requests/);
  await outsider.goto(`/matters/${matter.id}/requests/${created.data.id}`);
  await expect(outsider).toHaveURL(/\/login/);
  await outsider.context().close();
});
