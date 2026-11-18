import { expect, test } from "@playwright/test";

import { STORAGE_STATE } from "./fixtures";

test.use({ storageState: STORAGE_STATE });

test("sidebar navigation moves between workspace sections", async ({ page }) => {
  await page.goto("/dashboard");
  const nav = page.getByRole("navigation", { name: "Main" });

  await nav.getByRole("link", { name: "Matters" }).click();
  await expect(page).toHaveURL(/\/matters$/);
  await expect(nav.getByRole("link", { name: "Matters" })).toHaveAttribute("aria-current", "page");
  await expect(page.getByRole("table", { name: "Matters" })).toBeVisible();

  await nav.getByRole("link", { name: "Clients" }).click();
  await expect(page).toHaveURL(/\/clients$/);
  await expect(page.getByRole("table", { name: "Clients" }).getByRole("row")).toHaveCount(9);

  // Planned sections used to be visibly disabled, not links. Phase 3 (Documents), Phase 4 (Client requests) and
  // Phase 5 (Signatures) made every planned section a working one, so no "Coming soon" item remains and each
  // section link is asserted instead.
  await expect(nav.getByText("Coming soon")).toHaveCount(0);
  await expect(nav.getByRole("link", { name: "Documents" })).toHaveAttribute("href", "/documents");
  await expect(nav.getByRole("link", { name: "Client requests" })).toHaveAttribute("href", "/document-requests");
  await expect(nav.getByRole("link", { name: "Signatures" })).toHaveAttribute("href", "/signatures");
});

test("matters can be searched and filtered against the API", async ({ page }) => {
  await page.goto("/matters");

  await page.getByLabel("Status").selectOption("on_hold");
  await expect(page).toHaveURL(/status=on_hold/);
  const table = page.getByRole("table", { name: "Matters" });
  await expect(table.getByRole("row")).toHaveCount(2);
  await expect(table).toContainText("Unpaid Fee Recovery Proceedings");

  await page.getByLabel("Status").selectOption("");
  await page.getByLabel("Search matters").fill("harborline");
  await expect(page).toHaveURL(/search=harborline/);
  await expect(table.getByRole("row")).toHaveCount(3);

  await page.getByLabel("Search matters").fill("no-such-matter-xyz");
  await expect(page.getByText("No matters match your filters")).toBeVisible();
  await page.getByRole("button", { name: "Clear filters" }).click();
  // Phase 7: the default view leaves out archived (read-only) matters; one filter option brings them back.
  await expect(page.getByText(/Showing 1–9 of 9 matters/)).toBeVisible();
  await page.getByLabel("Status").selectOption("all");
  await expect(page).toHaveURL(/status=all/);
  await expect(page.getByText(/Showing 1–10 of 10 matters/)).toBeVisible();
  await expect(table).toContainText("Supplier Framework Agreement");
});

test("clients can be filtered by type", async ({ page }) => {
  await page.goto("/clients");
  await page.getByLabel("Client type").selectOption("organization");
  await expect(page.getByText(/Showing 1–4 of 4 clients/)).toBeVisible();
});

test("keyboard users can skip to the main content", async ({ page }) => {
  await page.goto("/dashboard");
  await expect(page.getByRole("region", { name: "Key figures" })).toBeVisible();
  const skip = page.getByRole("link", { name: "Skip to content" });

  // The skip link is the first focusable element of the app (in `next dev` the
  // framework's dev-tools button may come first, so allow one extra Tab).
  for (let i = 0; i < 2 && !(await skip.evaluate((el) => el === document.activeElement)); i++) {
    await page.keyboard.press("Tab");
  }
  await expect(skip).toBeFocused();
  await expect(skip).toBeInViewport();

  await page.keyboard.press("Enter");
  await expect(page.locator("#main-content")).toBeFocused();
});
