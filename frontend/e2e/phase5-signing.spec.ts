import { readFileSync } from "node:fs";

import { expect, request as playwrightRequest, test, type Browser, type Page } from "@playwright/test";

import { FRONTEND_URL } from "./env";
import { API_URL, STORAGE_STATE, apiGet, apiSend, createMatter, uniqueSuffix } from "./fixtures";
import { makePdf, sha256 } from "./pdf";
import { anonymousPage, e2eFixture, publicApi, tokenOf, type WithLink } from "./requests";
import { SIGNATURE_PAYLOAD, darkPixels, downloadVersionApi, drawSignature, publicSigning, signingDetail, uploadPdfApi } from "./signing";

test.use({ storageState: STORAGE_STATE });
test.describe.configure({ timeout: 180_000 });

interface SigningWithLink {
  data: { id: number; matter_id: number; status: string; signer_name: string };
  link: { url: string } | null;
}

async function asClient(browser: Browser, url: string): Promise<Page> {
  const client = await anonymousPage(browser);
  await client.goto(url);
  return client;
}

async function createSigningApi(page: Page, matterId: number, documentId: number, versionId: number, overrides: Record<string, unknown> = {}) {
  return apiSend<SigningWithLink>(page, "POST", `/api/matters/${matterId}/signing-requests`, {
    document_id: documentId,
    document_version_id: versionId,
    signer_type: "client",
    signer_name: "Ana Krasniqi",
    signer_email: "ana.krasniqi@northwind.example",
    issue_link: true,
    ...overrides,
  });
}

/** The client side through the real page: review, start, draw or type, confirm, sign. */
async function clientSigns(client: Page, method: "drawn" | "typed", name = "Ana Krasniqi") {
  await expect(client.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();
  await client.getByRole("button", { name: "Start signing" }).click();
  await expect(client.getByRole("heading", { name: "1. Your signature" })).toBeVisible();
  if (method === "drawn") {
    await drawSignature(client);
  } else {
    await client.getByRole("radio", { name: "Type" }).click();
    await client.getByLabel("Type your full name").fill(name);
  }
  await expect(client.getByTestId("signature-box")).toBeVisible();
  await client.getByLabel("Your full name", { exact: true }).fill(name);
  await client.getByTestId("consent-checkbox").check();
  await client.getByRole("button", { name: "Sign document" }).click();
  await expect(client.getByTestId("sign-success")).toHaveText("The document was signed successfully. The firm has received your signed document.");
}

// Scenarios A, B, C, F, H — request in the UI, share, anonymous review → view/start/sign, signed PDF verified.
test("a client reviews and signs a PDF through a secure link and the firm verifies the signed version", async ({ page, browser, context }) => {
  await context.grantPermissions(["clipboard-read", "clipboard-write"], { origin: FRONTEND_URL });
  const matter = await createMatter(page);
  const title = `Client Service Agreement ${uniqueSuffix()}`;
  const { document, sha: sourceSha } = await uploadPdfApi(page, matter, title, [`${title} — terms`, "Schedule A", "Signature page"]);

  // A — create the request from the matter's Signatures tab.
  await page.goto(`/matters/${matter.id}?tab=signatures`);
  await page.getByRole("button", { name: "Create the first signing request" }).click();
  const dialog = page.getByRole("dialog", { name: "New signing request" });
  await dialog.getByLabel("Document").selectOption(String(document.id));
  await expect(dialog.getByLabel("Signer's full name")).toHaveValue("Harborline Freight Ltd.");
  await dialog.getByLabel("Signer's full name").fill("Ana Krasniqi");
  await dialog.getByLabel("Signer's e-mail").fill("ana.krasniqi@northwind.example");
  await dialog.getByRole("button", { name: "Create signing request" }).click();
  const reveal = page.getByRole("dialog", { name: "Secure signing link" });
  const url = await reveal.getByTestId("issued-link-url").inputValue();
  expect(url).toMatch(new RegExp(`^${FRONTEND_URL}/client-sign#[A-Za-z0-9_-]{43}$`));
  await reveal.getByRole("button", { name: "Copy link" }).click();
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(url);
  await reveal.getByRole("button", { name: "Done" }).click();
  await expect(page).toHaveURL(new RegExp(`/matters/${matter.id}/signatures/\\d+$`));
  const requestId = Number(page.url().split("/").pop());

  // F — Ready, then Sent only after the administrator confirms sharing; persists after reload.
  await expect(page.getByTestId("signing-status")).toHaveText("Ready to send");
  await expect(page.getByTestId("progress-sent")).toHaveAttribute("data-reached", "false");
  await page.getByRole("button", { name: "Mark invitation as shared" }).click();
  await page.getByRole("alertdialog", { name: "Mark the invitation as shared?" }).getByRole("button", { name: "Yes, I shared it" }).click();
  await expect(page.getByTestId("signing-status")).toHaveText("Sent");
  await page.reload();
  await expect(page.getByTestId("progress-sent")).toHaveAttribute("data-reached", "true");
  await expect(page.getByTestId("progress-viewed")).toHaveAttribute("data-reached", "false");

  // B — the client opens the link: no login, the right document, nothing else.
  const client = await asClient(browser, url);
  await expect(client.getByRole("heading", { level: 1, name: title })).toBeVisible();
  await expect(client.getByTestId("designated-signer")).toContainText("Ana Krasniqi");
  await expect(client.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();
  const text = await client.locator("body").innerText();
  for (const secret of [matter.matter_number, matter.title, "Harborline", "Elena Marsh"]) expect(text).not.toContain(secret);
  await expect.poll(async () => (await signingDetail(page, matter.id, requestId)).data.status).toBe("viewed");

  // C — start, draw, place, consent, sign.
  await clientSigns(client, "drawn");
  expect(await client.context().cookies(API_URL)).toHaveLength(0);
  await client.context().close();

  // Admin: Signed, progress persisted, evidence; signed PDF verified byte-for-byte; source unchanged.
  await page.reload();
  await expect(page.getByTestId("signing-status")).toHaveText("Signed");
  for (const step of ["sent", "viewed", "started", "signed"]) await expect(page.getByTestId(`progress-${step}`)).toHaveAttribute("data-reached", "true");
  await expect(page.getByTestId("evidence-card")).toContainText("Drawn signature");
  const detail = await signingDetail(page, matter.id, requestId);
  const signed = detail.data.signed_version!;
  expect(signed.kind).toBe("signed");
  const [download] = await Promise.all([page.waitForEvent("download"), page.getByRole("button", { name: `Download ${signed.label}` }).click()]);
  const signedBytes = readFileSync((await download.path())!);
  expect(sha256(signedBytes)).toBe(signed.sha256_checksum);
  expect(sha256(signedBytes)).toBe(detail.evidence!.signed_sha256);
  expect(signedBytes.subarray(0, 5).toString()).toBe("%PDF-");
  expect(sha256(await downloadVersionApi(page, matter.id, document.id, document.current_version.id))).toBe(sourceSha);

  // The signature is visible where it was placed (bottom right of the last page) and the PDF renders.
  await page.getByRole("button", { name: `Preview ${signed.label}` }).click();
  const viewer = page.getByRole("dialog", { name: new RegExp(`${title} · ${signed.label}`) });
  await expect(viewer.getByTestId("pdf-page-status")).toHaveText("Page 1 of 3");
  await viewer.getByRole("button", { name: "Next page" }).click();
  await viewer.getByRole("button", { name: "Next page" }).click();
  const last = viewer.locator('canvas[aria-label="Page 3 of 3"][data-rendered="true"]');
  await expect(last).toBeVisible();
  expect(await darkPixels(page, 'canvas[aria-label="Page 3 of 3"][data-rendered="true"]', { x0: 0.45, y0: 0.7, x1: 0.97, y1: 0.97 })).toBeGreaterThan(150);
  await viewer.getByRole("button", { name: "Close" }).click();

  // Matter activity tells the story with readable labels.
  await page.goto(`/matters/${matter.id}?tab=activity`);
  const history = page.getByRole("list", { name: "Activity history" });
  for (const label of ["Signature requested", "Signing link generated", "Signing invitation shared", "Document viewed for signing", "Signing started", "Document signed", "Signed PDF stored"]) {
    await expect(history.getByText(label, { exact: true })).toBeVisible();
  }
  await expect(history).toContainText("Started → Signed");
});

// Scenario D — typed signature; the output PDF carries the typed name.
test("a client signs with a typed signature", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const { document } = await uploadPdfApi(page, matter, `Typed NDA ${uniqueSuffix()}`, ["Mutual NDA", "Signatures"]);
  const created = await createSigningApi(page, matter.id, document.id, document.current_version.id, { signer_name: "Liridona Berisha" });
  const client = await asClient(browser, created.link!.url);
  await clientSigns(client, "typed", "Liridona Berisha");
  await client.context().close();

  const detail = await signingDetail(page, matter.id, created.data.id);
  expect(detail.data.status).toBe("signed");
  expect(detail.evidence!.signature_method).toBe("typed");
  const bytes = (await downloadVersionApi(page, matter.id, document.id, detail.data.signed_version!.id)).toString("latin1");
  expect(bytes).toContain("/BaseFont /Times-Italic");
  expect(bytes).toContain("(Liridona Berisha) Tj");
  expect(bytes).toContain("(Electronically signed by Liridona Berisha) Tj");
});

// Scenarios E and I — the administrator signs an approved client submission from the document page.
test("the administrator signs an approved client-submitted PDF and the client's original is kept", async ({ page }) => {
  const matter = await createMatter(page);
  const created = await apiSend<WithLink>(page, "POST", `/api/matters/${matter.id}/document-requests`, {
    title: `Signed lease ${uniqueSuffix()}`,
    instructions: "Upload the lease.",
    requested_category: "agreements",
    issue_link: true,
  });
  const leaseBytes = makePdf(["Lease agreement", "Landlord signature"]);
  expect((await publicApi(tokenOf(created.link!.url), "POST", { file: { name: "lease.pdf", mimeType: "application/pdf", buffer: leaseBytes } })).status).toBe(201);
  const reqUrl = `/api/matters/${matter.id}/document-requests/${created.data.id}`;
  let current = (await apiGet<{ data: { lock_version: number; latest_submission: { id: number }; document_id: number } }>(page.request, reqUrl)).data;

  // Not reviewed yet → the server refuses to sign (no implicit approval).
  await page.goto(`/matters/${matter.id}/documents/${current.document_id}`);
  await page.getByRole("button", { name: "Sign PDF" }).click();
  await expect(page.getByTestId("sign-unavailable")).toContainText("Approve the submission before signing it.");
  await page.keyboard.press("Escape");

  await apiSend(page, "POST", `${reqUrl}/start-review`, { submission_id: current.latest_submission.id, lock_version: current.lock_version });
  current = (await apiGet<{ data: typeof current }>(page.request, reqUrl)).data;
  await apiSend(page, "POST", `${reqUrl}/approve`, { submission_id: current.latest_submission.id, lock_version: current.lock_version });

  await page.reload();
  await page.getByRole("button", { name: "Sign PDF" }).click();
  const dialog = page.getByRole("dialog", { name: /^Sign / });
  await expect(dialog.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();
  await drawSignature(page);
  await expect(dialog.getByText("Signing as Elena Marsh", { exact: false })).toBeVisible();
  await dialog.getByRole("button", { name: "Sign document" }).click();
  await expect(dialog.getByText("Confirm the signing statement to sign.")).toBeVisible(); // consent is never pre-checked
  await dialog.getByTestId("consent-checkbox").check();
  await dialog.getByRole("button", { name: "Sign document" }).click();
  await expect(page.getByText("Document signed", { exact: true })).toBeVisible();
  await expect(page).toHaveURL(new RegExp(`/matters/${matter.id}/signatures/\\d+$`));
  await expect(page.getByTestId("signing-status")).toHaveText("Signed");
  await expect(page.getByTestId("evidence-card")).toContainText("Authenticated administrator account");

  const versions = await apiGet<{ data: { version_number: number; kind: string; origin: string; sha256_checksum: string }[] }>(page.request, `/api/matters/${matter.id}/documents/${current.document_id}/versions`);
  expect(versions.data.map((v) => [v.version_number, v.kind, v.origin]).sort()).toEqual([[1, "upload", "client"], [2, "signed", "admin"]]);
  expect(versions.data.find((v) => v.version_number === 1)!.sha256_checksum).toBe(sha256(leaseBytes));
  await page.goto(`/matters/${matter.id}/documents/${current.document_id}`);
  await expect(page.getByTestId("signed-version-badge")).toBeVisible();
  await expect(page.getByRole("button", { name: "Sign PDF" })).toHaveCount(0); // a signed version cannot be signed again
});

// Scenario G — every kind of unusable signing link is refused, and tokens are not interchangeable.
test("invalid, expired, revoked, signed, cancelled, archived and wrong-purpose links cannot sign", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const doc = async (t: string) => (await uploadPdfApi(page, matter, `${t} ${uniqueSuffix()}`, [t])).document;
  const heading = async (url: string, name: string) => {
    const c = await asClient(browser, url);
    await expect(c.getByRole("heading", { name })).toBeVisible();
    await c.context().close();
  };

  for (const bad of ["#not-a-token", `#${"B".repeat(43)}`, ""]) await heading(`${FRONTEND_URL}/client-sign${bad}`, "This link is not valid");

  const d1 = await doc("Expired");
  const expired = await createSigningApi(page, matter.id, d1.id, d1.current_version.id);
  e2eFixture("expire-signing", String(expired.data.id));
  await heading(expired.link!.url, "This link has expired");

  const d2 = await doc("Revoked");
  const revoked = await createSigningApi(page, matter.id, d2.id, d2.current_version.id);
  await page.goto(`/matters/${matter.id}/signatures/${revoked.data.id}`);
  await page.getByTestId("signing-link-card").getByRole("button", { name: "Revoke link" }).click();
  await page.getByRole("alertdialog", { name: "Revoke the signing link?" }).getByRole("button", { name: "Revoke link" }).click();
  await expect(page.getByText("Link revoked", { exact: true })).toBeVisible();
  await heading(revoked.link!.url, "This link is not valid");

  const d3 = await doc("Signed");
  const done = await createSigningApi(page, matter.id, d3.id, d3.current_version.id);
  const t3 = tokenOf(done.link!.url);
  expect((await publicSigning(t3, "POST", "/view")).status).toBe(200);
  expect((await publicSigning(t3, "POST", "/start")).status).toBe(200);
  expect((await publicSigning(t3, "POST", "/complete", { ...SIGNATURE_PAYLOAD, confirmed_name: "Ana Krasniqi" })).status).toBe(201);
  expect((await publicSigning(t3, "POST", "/complete", { ...SIGNATURE_PAYLOAD, confirmed_name: "Ana Krasniqi" })).status).toBe(410);
  await heading(done.link!.url, "Already signed");

  const d4 = await doc("Cancelled");
  const cancelled = await createSigningApi(page, matter.id, d4.id, d4.current_version.id);
  await apiSend(page, "POST", `/api/matters/${matter.id}/signing-requests/${cancelled.data.id}/cancel`, {});
  await heading(cancelled.link!.url, "This signing request was withdrawn");

  // Wrong purpose both ways: an upload token on the signing page and the signing token on the upload page.
  const upload = await apiSend<WithLink>(page, "POST", `/api/matters/${matter.id}/document-requests`, {
    title: "Any file", instructions: "Upload it.", requested_category: "other", issue_link: true,
  });
  await heading(`${FRONTEND_URL}/client-sign#${tokenOf(upload.link!.url)}`, "This link is not valid");
  const d5 = await doc("Archived");
  const archivedReq = await createSigningApi(page, matter.id, d5.id, d5.current_version.id);
  await heading(`${FRONTEND_URL}/client-upload#${tokenOf(archivedReq.link!.url)}`, "This link is not valid");

  // A signing token grants no administrator access and no other PDF.
  const anon = await playwrightRequest.newContext();
  for (const path of ["/api/matters", `/api/matters/${matter.id}/documents/${d1.id}/versions/${d1.current_version.id}/preview`, "/api/signing-requests"]) {
    const r = await anon.get(`${API_URL}${path}`, { headers: { Accept: "application/json", "X-Signing-Token": tokenOf(archivedReq.link!.url) } });
    expect(r.status(), path).toBe(401);
  }
  await anon.dispose();

  // Archived matter: unfinished signing is cancelled and the link stops working (also after restore).
  const fresh = await apiGet<{ data: { lock_version: number } }>(page.request, `/api/matters/${matter.id}`);
  const archived = await apiSend<{ data: { lock_version: number } }>(page, "POST", `/api/matters/${matter.id}/archive`, { lock_version: fresh.data.lock_version });
  await heading(archivedReq.link!.url, "This signing request is no longer available");
  await apiSend(page, "POST", `/api/matters/${matter.id}/restore`, { lock_version: archived.data.lock_version });
  await heading(archivedReq.link!.url, "This signing request is no longer available");
});

// Scenario K — double clicks and retries never create a second signed PDF.
test("double submission and retries produce exactly one signed version", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const { document } = await uploadPdfApi(page, matter, `Retry agreement ${uniqueSuffix()}`, ["Retry agreement"]);
  const created = await createSigningApi(page, matter.id, document.id, document.current_version.id);
  const client = await asClient(browser, created.link!.url);
  await expect(client.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();
  await client.getByRole("button", { name: "Start signing" }).click();
  await drawSignature(client);
  await client.getByLabel("Your full name", { exact: true }).fill("Ana Krasniqi");
  await client.getByTestId("consent-checkbox").check();
  await client.getByRole("button", { name: "Sign document" }).dblclick();
  await expect(client.getByTestId("sign-success")).toBeVisible();
  // A retry of the same request with the same token is refused consistently.
  const retry = await publicSigning(tokenOf(created.link!.url), "POST", "/complete", { ...SIGNATURE_PAYLOAD, confirmed_name: "Ana Krasniqi" });
  expect([retry.status, retry.body?.code]).toEqual([410, "already_signed"]);
  await client.reload();
  await expect(client.getByRole("heading", { name: "Already signed" })).toBeVisible();
  await client.context().close();

  const versions = await apiGet<{ data: { kind: string }[] }>(page.request, `/api/matters/${matter.id}/documents/${document.id}/versions`);
  expect(versions.data.filter((v) => v.kind === "signed")).toHaveLength(1);
});

// Scenario L — accessibility: the whole signing step works with the keyboard alone (WAI-ARIA radio group,
// labelled and keyboard-movable placement box, consent with Space, submit with Enter).
test("the administrator signs with the keyboard alone", async ({ page }) => {
  const matter = await createMatter(page);
  const { document } = await uploadPdfApi(page, matter, `Keyboard agreement ${uniqueSuffix()}`, ["Keyboard agreement"]);
  await page.goto(`/matters/${matter.id}/documents/${document.id}`);
  await page.getByRole("button", { name: "Sign PDF" }).focus();
  await page.keyboard.press("Enter");
  const dialog = page.getByRole("dialog", { name: /^Sign / });
  await expect(dialog.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();

  // Signature method: one Tab stop (the checked option); arrow keys move and select.
  const draw = dialog.getByRole("radio", { name: "Draw" });
  const typed = dialog.getByRole("radio", { name: "Type" });
  await expect(draw).toHaveAttribute("tabindex", "0");
  await expect(typed).toHaveAttribute("tabindex", "-1");
  await draw.focus();
  await page.keyboard.press("ArrowRight");
  await expect(typed).toHaveAttribute("aria-checked", "true");
  await expect(typed).toBeFocused();
  await dialog.getByLabel("Type your full name").focus();
  await page.keyboard.type("Elena Marsh");

  // Placement box: labelled, focusable, moved with the arrow keys (Shift = 5 % steps).
  const box = dialog.getByTestId("signature-box");
  await expect(box).toHaveAccessibleName(/Use the arrow keys to move it/);
  const left = () => box.evaluate((b: HTMLElement) => Number.parseFloat(b.style.left));
  await box.focus();
  const before = await left();
  await page.keyboard.press("Shift+ArrowLeft");
  await expect.poll(left).toBeCloseTo(before - 5, 1);
  const placedX = (await left()) / 100;

  // Consent with Space (never pre-checked), submit with Enter.
  const consent = dialog.getByTestId("consent-checkbox");
  await expect(consent).not.toBeChecked();
  await consent.focus();
  await page.keyboard.press("Space");
  await expect(consent).toBeChecked();
  await dialog.getByRole("button", { name: "Sign document" }).focus();
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(new RegExp(`/matters/${matter.id}/signatures/\\d+$`));
  await expect(page.getByTestId("signing-status")).toHaveText("Signed");

  const detail = (await signingDetail(page, matter.id, Number(page.url().split("/").pop()))) as unknown as {
    evidence: { signature_method: string; placement: { x: number } };
  };
  expect(detail.evidence.signature_method).toBe("typed");
  expect(detail.evidence.placement.x).toBeCloseTo(placedX, 3); // stored exactly where the keyboard put it
});
