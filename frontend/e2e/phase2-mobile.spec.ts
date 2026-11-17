import { expect, test } from "@playwright/test";

import { STORAGE_STATE, apiGet, createMatter, expectNoHorizontalOverflow, uniqueSuffix } from "./fixtures";

test.use({ storageState: STORAGE_STATE });

// Scenario G — essential Phase 2 workflows on a phone (Pixel 7 project).
test("matter and client pages fit a phone screen", async ({ page }) => {
  const matter = await createMatter(page, { description: "A".repeat(400) });
  const clients = await apiGet<{ data: { id: number }[] }>(page.request, "/api/clients?search=CL-1001");

  for (const path of [
    `/matters/${matter.id}`,
    `/matters/${matter.id}?tab=tasks`,
    `/matters/${matter.id}?tab=notes`,
    `/matters/${matter.id}?tab=activity`,
    `/clients/${clients.data[0].id}`,
  ]) {
    await page.goto(path);
    await page.waitForLoadState("networkidle");
    await expectNoHorizontalOverflow(page, path);
  }
});

test("dialogs fit the viewport and a task can be created on a phone", async ({ page }) => {
  const matter = await createMatter(page);
  const viewport = page.viewportSize()!;

  await page.goto("/matters");
  await page.getByRole("button", { name: "New matter" }).click();
  const dialog = page.getByRole("dialog", { name: "New matter" });
  await expect(dialog).toBeVisible();
  const box = (await dialog.boundingBox())!;
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
  expect(box.height).toBeLessThanOrEqual(viewport.height);
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();

  // Tabs are reachable and a task is created end-to-end.
  await page.goto(`/matters/${matter.id}`);
  await page.getByRole("tab", { name: /Tasks/ }).click();
  await page.getByRole("button", { name: "Add the first task" }).click();
  const title = `Mobile task ${uniqueSuffix()}`;
  const form = page.getByRole("dialog", { name: "New task" });
  await form.getByLabel("Task title").fill(title);
  await form.getByRole("button", { name: "Add task" }).click();
  await expect(page.getByTestId("task-row").filter({ hasText: title })).toBeVisible();
  await expectNoHorizontalOverflow(page, "tasks tab with a task");

  // Quick actions from the list work on a phone too.
  await page.goto(`/matters?search=${matter.matter_number}`);
  await page.getByRole("button", { name: `Manage ${matter.matter_number}` }).click();
  const quick = page.getByRole("dialog", { name: new RegExp(matter.matter_number) });
  await quick.getByLabel("Priority").selectOption("urgent");
  await expect(page.getByText("Priority set to Urgent")).toBeVisible();
});
