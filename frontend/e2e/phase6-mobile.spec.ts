import { expect, test } from "@playwright/test";

import { STORAGE_STATE, createMatter, expectNoHorizontalOverflow, uniqueSuffix } from "./fixtures";
import { createRequestApi } from "./requests";

test.use({ storageState: STORAGE_STATE });
test.describe.configure({ timeout: 180_000 });

// Scenario H — e-mail actions, delivery status, history and notifications on a phone (Pixel 7).
test("e-mail actions, history and notifications fit a phone", async ({ page }) => {
  const matter = await createMatter(page);
  const created = await createRequestApi(page, matter, { title: `Mobile request ${uniqueSuffix()}`, issue_link: false });

  await page.goto(`/matters/${matter.id}/requests/${created.data.id}`);
  await page.getByRole("button", { name: "Send e-mail" }).tap();
  const dialog = page.getByRole("dialog", { name: "E-mail the document request" });
  await expect(dialog.getByTestId("email-recipient")).toBeVisible();
  await expectNoHorizontalOverflow(page, "send e-mail dialog");
  await dialog.getByRole("button", { name: "Send e-mail" }).tap();
  await expect(dialog.getByTestId("email-accepted")).toBeVisible();
  await expectNoHorizontalOverflow(page, "send e-mail result");
  await dialog.getByRole("button", { name: "Done" }).tap();
  await expect(page.getByTestId("communication-history")).toContainText("Accepted by the mail server");
  await expectNoHorizontalOverflow(page, "request page with e-mail history");

  await page.getByTestId("notification-bell").tap();
  const panel = page.getByRole("region", { name: "Recent notifications" });
  await expect(panel).toBeVisible();
  const box = (await panel.boundingBox())!;
  const viewport = page.viewportSize()!;
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
  await page.keyboard.press("Escape");
  await expect(panel).toBeHidden();

  await page.goto("/notifications");
  await expect(page.getByRole("heading", { level: 1, name: "Notifications" })).toBeVisible();
  await expectNoHorizontalOverflow(page, "notifications page");
});
