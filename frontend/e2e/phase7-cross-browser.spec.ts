import { expect, test } from "@playwright/test";

import { DEMO_EMAIL, DEMO_PASSWORD, SPA_HEADERS, API_URL, apiSend, uniqueSuffix } from "./fixtures";
import { makePdf } from "./pdf";
import { anonymousPage } from "./requests";
import { drawSignature } from "./signing";

/**
 * Phase 7 — targeted compatibility checks for Firefox and WebKit (opt-in: E2E_CROSS_BROWSER=1, projects
 * "firefox" and "webkit"; the default suite runs in Chromium). Covers what differs between engines: sign-in
 * cookies, navigation and dialogs, PDF.js rendering, file upload, and pointer-drawn signatures.
 */
test.describe.configure({ timeout: 240_000 });

test("sign in, navigate, upload and sign through the client pages", async ({ page, browser }) => {
  // Sign-in through the UI (session cookie + CSRF in this engine).
  await page.goto("/login");
  await page.getByLabel("Email address").fill(DEMO_EMAIL);
  await page.getByLabel("Password", { exact: true }).fill(DEMO_PASSWORD);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).toHaveURL(/\/dashboard/);
  await expect(page.getByRole("region", { name: "Key figures" })).toBeVisible();

  // Navigation and a dialog.
  await page.getByRole("navigation", { name: "Main" }).getByRole("link", { name: "Matters" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Matters" })).toBeVisible();
  await page.getByRole("button", { name: "New matter" }).click();
  await expect(page.getByRole("dialog", { name: "New matter" })).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog", { name: "New matter" })).toBeHidden();

  // Data for the client side.
  const clients = (await (await page.request.get(`${API_URL}/api/clients?search=CL-1001`, { headers: SPA_HEADERS })).json()) as { data: { id: number }[] };
  const matter = (await apiSend<{ data: { id: number } }>(page, "POST", "/api/matters", { title: `Browser matter ${uniqueSuffix()}`, client_id: clients.data[0].id, priority: "medium" })).data;
  const request = await apiSend<{ link: { url: string } }>(page, "POST", `/api/matters/${matter.id}/document-requests`, {
    title: `Browser upload ${uniqueSuffix()}`, instructions: "Please upload the PDF.", requested_category: "other", issue_link: true,
  });
  await page.request.get(`${API_URL}/sanctum/csrf-cookie`, { headers: SPA_HEADERS });
  const xsrf = decodeURIComponent((await page.context().cookies(API_URL)).find((c) => c.name === "XSRF-TOKEN")?.value ?? "");
  const uploaded = await page.request.post(`${API_URL}/api/matters/${matter.id}/documents`, {
    headers: { ...SPA_HEADERS, "X-XSRF-TOKEN": xsrf },
    multipart: { file: { name: "browser-agreement.pdf", mimeType: "application/pdf", buffer: makePdf(["Agreement", "Signature page"]) }, title: "Browser agreement", category: "agreements" },
  });
  expect(uploaded.status()).toBe(201);
  const document = (await uploaded.json()).data as { id: number; current_version: { id: number } };
  const signing = await apiSend<{ link: { url: string } }>(page, "POST", `/api/matters/${matter.id}/signing-requests`, {
    document_id: document.id, document_version_id: document.current_version.id, signer_type: "client", signer_name: "Ana Krasniqi", issue_link: true,
  });

  // The administrator's PDF preview renders (PDF.js in this engine).
  await page.goto(`/matters/${matter.id}/documents/${document.id}`);
  await page.getByRole("button", { name: /^Preview/ }).first().click();
  await expect(page.getByRole("dialog").locator('canvas[data-rendered="true"]').first()).toBeVisible();
  await page.keyboard.press("Escape");

  // Client upload (file input) without an account.
  const client = await anonymousPage(browser);
  await client.goto(request.link.url);
  await client.locator("#client-file").setInputFiles({ name: "client.pdf", mimeType: "application/pdf", buffer: makePdf(["Client document"]) });
  await client.getByRole("button", { name: "Submit document" }).click();
  await expect(client.getByTestId("upload-success")).toBeVisible();

  // Client signing: render, draw with pointer events, place by clicking the page, consent, sign.
  await client.goto(signing.link.url);
  await expect(client.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();
  await client.getByRole("button", { name: "Start signing" }).click();
  await drawSignature(client);
  const box = (await client.getByTestId("signing-page").boundingBox())!;
  await client.mouse.click(box.x + box.width * 0.3, box.y + box.height * 0.6);
  await expect(client.getByTestId("placement-summary")).toContainText("Signature on page");
  await client.getByLabel("Your full name", { exact: true }).fill("Ana Krasniqi");
  await client.getByTestId("consent-checkbox").check();
  await client.getByRole("button", { name: "Sign document" }).click();
  await expect(client.getByTestId("sign-success")).toBeVisible();
  await client.context().close();
});
