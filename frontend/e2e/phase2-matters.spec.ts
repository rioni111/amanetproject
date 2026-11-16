import { expect, test } from "@playwright/test";

import { STORAGE_STATE, apiGet, createMatter, dashboardStats, uniqueSuffix } from "./fixtures";

test.use({ storageState: STORAGE_STATE });

function isoDate(daysFromToday: number): string {
  const d = new Date();
  d.setDate(d.getDate() + daysFromToday);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

// Scenario B — create a matter with a unique number, then edit title, priority and deadline.
test("an administrator creates a matter and edits it", async ({ page }) => {
  const title = `Distribution Agreement ${uniqueSuffix()}`;
  const before = await dashboardStats(page);

  await page.goto("/matters");
  await page.getByRole("button", { name: "New matter" }).click();
  const dialog = page.getByRole("dialog", { name: "New matter" });
  await dialog.getByLabel("Matter title").fill(title);
  await dialog.getByLabel("Client").selectOption({ label: "Harborline Freight Ltd. (CL-1001)" });
  await dialog.getByLabel("Matter type").fill("Contract Review");
  await dialog.getByLabel("Priority").selectOption("high");
  await dialog.getByLabel("Deadline").fill(isoDate(10));
  await dialog.getByLabel("Description").fill("Review exclusivity, territory and termination clauses.");
  await dialog.getByRole("button", { name: "Create matter" }).click();

  // Redirected to the new matter's workspace.
  await expect(page).toHaveURL(/\/matters\/\d+$/);
  await expect(page.getByRole("heading", { level: 1, name: title })).toBeVisible();
  const number = (await page.locator("header p.font-mono").first().textContent())?.trim() ?? "";
  expect(number).toMatch(/^LEX-\d{4}-\d{4}$/);
  await expect(page.getByText(`Matter ${number} created`)).toBeVisible();

  // The number is unique and the dashboard counts the new active matter.
  const sameNumber = await apiGet<{ meta: { total: number } }>(page.request, `/api/matters?search=${number}`);
  expect(sameNumber.meta.total).toBe(1);
  expect((await dashboardStats(page)).active_matters).toBe(before.active_matters + 1);

  // Edit title, priority and deadline.
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  const edit = page.getByRole("dialog", { name: `Edit ${number}` });
  await expect(edit.getByLabel("Matter title")).toHaveValue(title);
  await edit.getByLabel("Matter title").fill(`${title} (amended)`);
  await edit.getByLabel("Priority").selectOption("urgent");
  await edit.getByLabel("Deadline").fill(isoDate(20));
  await edit.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByText("Matter updated")).toBeVisible();

  await page.reload();
  await expect(page.getByRole("heading", { level: 1, name: `${title} (amended)` })).toBeVisible();
  await expect(page.locator("header").getByText("Urgent")).toBeVisible();
  const matterId = Number(page.url().split("/").pop());
  const stored = await apiGet<{ data: { due_date: string; priority: string; matter_number: string } }>(
    page.request,
    `/api/matters/${matterId}`,
  );
  expect(stored.data).toMatchObject({ due_date: isoDate(20), priority: "urgent", matter_number: number });

  // History records the creation, the edit and the priority change.
  await page.getByRole("tab", { name: "Activity" }).click();
  const history = page.getByRole("list", { name: "Activity history" });
  await expect(history.getByText("Matter opened", { exact: true })).toBeVisible();
  await expect(history.getByText("Details updated", { exact: true })).toBeVisible();
  await expect(history.getByText("Priority changed", { exact: true })).toBeVisible();
  await expect(history.getByText("High → Urgent", { exact: true })).toBeVisible();
});

test("status changes follow the transition policy and are recorded", async ({ page }) => {
  const matter = await createMatter(page);
  await page.goto(`/matters/${matter.id}`);

  const status = page.getByLabel("Status", { exact: true });
  // Only transitions allowed from "open" are offered.
  await expect(status.locator("option")).toHaveText(["Open (current)", "In progress", "On hold", "Closed"]);
  await status.selectOption("in_progress");
  await expect(page.getByText("Status changed to In progress")).toBeVisible();
  await expect(page.locator("header").getByText("In progress")).toBeVisible();

  // Closing asks for confirmation.
  await status.selectOption("closed");
  const confirm = page.getByRole("alertdialog", { name: `Close ${matter.matter_number}?` });
  await confirm.getByRole("button", { name: "Close matter" }).click();
  await expect(page.getByText("Status changed to Closed")).toBeVisible();
  await expect(status.locator("option")).toHaveText(["Closed (current)", "Open", "In progress"]);

  await page.getByRole("tab", { name: "Activity" }).click();
  await expect(page.getByText("Open → In progress", { exact: true })).toBeVisible();
  await expect(page.getByText("In progress → Closed", { exact: true })).toBeVisible();
});

test("an unknown matter id shows a not-found state", async ({ page }) => {
  await page.goto("/matters/987654");
  await expect(page.getByText("Matter not found")).toBeVisible();
});
