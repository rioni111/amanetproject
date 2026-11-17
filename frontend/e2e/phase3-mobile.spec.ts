import { expect, test } from "@playwright/test";

import { STORAGE_STATE, createMatter, expectNoHorizontalOverflow, uniqueSuffix } from "./fixtures";
import { pdfFile } from "./pdf";

test.use({ storageState: STORAGE_STATE });

// Scenario H — document workflows on a phone (Pixel 7).
test("documents can be uploaded, previewed and reviewed on a phone", async ({ page }) => {
  const matter = await createMatter(page);
  const title = `Mobile Lease Draft ${uniqueSuffix()}`;

  await page.goto(`/matters/${matter.id}?tab=documents`);
  await expectNoHorizontalOverflow(page, "documents tab (empty)");
  await page.getByRole("button", { name: "Upload the first PDF" }).click();
  const dialog = page.getByRole("dialog", { name: "Upload PDF" });
  const viewport = page.viewportSize()!;
  const box = (await dialog.boundingBox())!;
  expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
  await dialog.locator("#upload-file").setInputFiles(pdfFile("lease-draft.pdf", [title, "Rent schedule"]));
  await dialog.getByLabel("Document title").fill(title);
  await dialog.getByLabel("Category").selectOption("agreements");
  await dialog.getByRole("button", { name: "Upload PDF" }).tap();
  await expect(page.getByText("Document uploaded", { exact: true })).toBeVisible();

  // Readable card on a phone.
  const card = page.getByTestId("document-card").filter({ hasText: title });
  await expect(card).toBeVisible();
  await expect(card).toContainText("Agreements");
  await expectNoHorizontalOverflow(page, "documents tab with a document");

  // Preview opens and renders.
  await card.getByRole("button", { name: `Preview ${title}` }).tap();
  const viewer = page.getByRole("dialog", { name: new RegExp(title) });
  await expect(viewer.locator('canvas[data-rendered="true"]').first()).toBeVisible();
  await expect(viewer.getByTestId("pdf-page-status")).toHaveText("Page 1 of 2");
  await viewer.getByRole("button", { name: "Close" }).tap();
  await expect(viewer).toBeHidden();

  // Detail page and version history fit the screen.
  await card.getByRole("link", { name: title, exact: true }).tap();
  await expect(page.getByRole("heading", { level: 1, name: title })).toBeVisible();
  await expect(page.getByRole("list", { name: "Versions" }).getByTestId("version-row")).toHaveCount(1);
  await page.waitForLoadState("networkidle");
  await expectNoHorizontalOverflow(page, "document detail");

  await page.goto("/documents");
  await page.waitForLoadState("networkidle");
  await expectNoHorizontalOverflow(page, "document library");
});
