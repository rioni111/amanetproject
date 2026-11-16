import { expect, test } from "@playwright/test";

import { STORAGE_STATE } from "./fixtures";

test.use({ storageState: STORAGE_STATE });

// Scenario F — search, filters and sorting stay in the URL and survive rapid changes.
test("rapid filter and search changes keep every filter active", async ({ page }) => {
  await page.goto("/matters");
  const table = page.getByRole("table", { name: "Matters" });

  await page.getByLabel("Status").selectOption("active");
  await page.getByLabel("Priority").selectOption("high");
  // Type immediately — before the previous URL updates have necessarily landed.
  await page.getByLabel("Search matters").fill("harbor");
  await page.getByLabel("Search matters").fill("harborline");

  await expect(page).toHaveURL(/status=active/);
  await expect(page).toHaveURL(/priority=high/);
  await expect(page).toHaveURL(/search=harborline/);
  await expect(page.getByLabel("Status")).toHaveValue("active");
  await expect(page.getByLabel("Priority")).toHaveValue("high");
  // Seeded: LEX-2026-0001 (Harborline, in progress, high) is the only active high-priority Harborline matter.
  await expect(table.getByRole("row")).toHaveCount(2);
  await expect(table).toContainText("Master Services Agreement Review");

  // Deadline filter and client filter combine with the others.
  await page.getByLabel("Search matters").fill("");
  await page.getByLabel("Priority").selectOption("");
  await page.getByLabel("Deadline").selectOption("overdue");
  await expect(page).toHaveURL(/deadline=overdue/);
  await expect(page).toHaveURL(/status=active/);
  await expect(table).toContainText("Hotel Lease Renegotiation");

  await page.getByLabel("Client").selectOption({ label: "Meridian Coast Hospitality Group" });
  await expect(page).toHaveURL(/client_id=\d+/);
  await expect(table.getByRole("row")).toHaveCount(2);

  // The URL fully describes the view: reloading restores it.
  await page.reload();
  await expect(page.getByLabel("Deadline")).toHaveValue("overdue");
  await expect(page.getByLabel("Status")).toHaveValue("active");
  await expect(table.getByRole("row")).toHaveCount(2);

});

test("pagination and sorting work against the API", async ({ page }) => {
  await page.goto("/matters?sort=matter_number");
  await expect(page.getByLabel("Sort by")).toHaveValue("matter_number");
  const table = page.getByRole("table", { name: "Matters" });
  await expect(table.getByRole("row").nth(1)).toContainText("LEX-2026-0001");

  // Earlier Phase 2 specs created more than ten matters, so a second page exists.
  await expect(page.getByText(/Page 1 of [2-9]/)).toBeVisible();
  await page.getByRole("button", { name: "Next page" }).click();
  await expect(page).toHaveURL(/page=2/);
  await expect(page.getByText(/Showing 11–/)).toBeVisible();
  await expect(table.getByRole("row").nth(1)).not.toContainText("LEX-2026-0001");

  // Changing a filter returns to page 1.
  await page.getByLabel("Priority").selectOption("urgent");
  await expect(page).not.toHaveURL(/page=2/);
});

test("matters can be opened from the list and navigation works between sections", async ({ page }) => {
  await page.goto("/matters?search=LEX-2026-0002");
  await page.getByRole("table", { name: "Matters" }).getByRole("link", { name: "Wrongful Dismissal Claim" }).click();
  await expect(page).toHaveURL(/\/matters\/\d+$/);
  await expect(page.getByRole("heading", { level: 1, name: "Wrongful Dismissal Claim" })).toBeVisible();

  await page.getByRole("link", { name: "Amelia Okafor" }).first().click();
  await expect(page).toHaveURL(/\/clients\/\d+$/);
  await expect(page.getByRole("heading", { level: 1, name: "Amelia Okafor" })).toBeVisible();

  const nav = page.getByRole("navigation", { name: "Main" });
  await nav.getByRole("link", { name: "Dashboard" }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
});
