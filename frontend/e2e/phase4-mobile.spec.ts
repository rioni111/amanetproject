import { devices, expect, test } from "@playwright/test";

import { STORAGE_STATE, createMatter, expectNoHorizontalOverflow } from "./fixtures";
import { pdfFile } from "./pdf";
import { createRequestApi } from "./requests";

test.use({ storageState: STORAGE_STATE });
// Multi-context workflows (administrator + anonymous client) on development servers take longer than 30 s.
test.describe.configure({ timeout: 180_000 });

// Scenario H — the passwordless client upload on a phone (Pixel 7), plus the admin request page.
test("a client uploads a requested PDF on a phone", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const created = await createRequestApi(page, matter, { due_date: new Date(Date.now() + 5 * 86_400_000).toISOString().slice(0, 10) });

  const context = await browser.newContext({ ...devices["Pixel 7"], storageState: { cookies: [], origins: [] } });
  const client = await context.newPage();
  await client.goto(created.link!.url);
  await expect(client.getByRole("heading", { level: 1, name: created.data.title })).toBeVisible();
  await expectNoHorizontalOverflow(client, "client upload page");

  // Controls are reachable, labelled and inside the screen.
  const viewport = client.viewportSize()!;
  const choose = client.getByRole("button", { name: "Choose PDF file" });
  const submit = client.getByRole("button", { name: "Submit document" });
  for (const control of [choose, submit]) {
    await expect(control).toBeVisible();
    const box = (await control.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
    expect(box.height).toBeGreaterThanOrEqual(36);
  }
  await expect(client.getByLabel("Message to the firm (optional)")).toBeVisible();

  // Submitting without a file is explained accessibly.
  await submit.tap();
  await expect(client.getByRole("alert").filter({ hasText: "Choose the PDF file to upload." })).toBeVisible();

  await client.locator("#client-file").setInputFiles(pdfFile("id-card.pdf", ["Identity document — front", "back"]));
  await expect(client.getByTestId("client-selected-file")).toHaveText("id-card.pdf");
  await expectNoHorizontalOverflow(client, "client upload page with a file");
  await submit.tap();
  await expect(client.getByTestId("upload-success")).toBeVisible();
  await expectNoHorizontalOverflow(client, "upload confirmation");
  await context.close();

  // The administrator's review page also fits the phone.
  await page.goto(`/matters/${matter.id}/requests/${created.data.id}`);
  await expect(page.getByTestId("request-status").first()).toHaveText("Submitted");
  await page.waitForLoadState("networkidle");
  await expectNoHorizontalOverflow(page, "request review page");
  await page.goto("/document-requests");
  await page.waitForLoadState("networkidle");
  await expectNoHorizontalOverflow(page, "client requests list");
});
