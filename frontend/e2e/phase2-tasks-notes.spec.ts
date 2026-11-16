import { expect, test } from "@playwright/test";

import { API_URL, SPA_HEADERS, STORAGE_STATE, apiGet, createMatter, dashboardStats, uniqueSuffix } from "./fixtures";

test.use({ storageState: STORAGE_STATE });

interface TaskList {
  data: { id: number; title: string; status: string; completed_at: string | null }[];
  meta: { total: number };
}

// Scenario C — create, complete and reopen a task; counters follow; everything is database-backed.
test("an administrator manages a task and the counters follow", async ({ page }) => {
  const matter = await createMatter(page);
  const title = `Send first redline ${uniqueSuffix()}`;
  const before = await dashboardStats(page);

  await page.goto(`/matters/${matter.id}?tab=tasks`);
  await expect(page.getByRole("tab", { name: /Tasks/ })).toHaveAttribute("aria-selected", "true");
  await page.getByRole("button", { name: "Add the first task" }).click();
  const dialog = page.getByRole("dialog", { name: "New task" });
  await dialog.getByLabel("Task title").fill(title);
  await dialog.getByLabel("Priority").selectOption("high");
  // Double-clicking submit must create exactly one task.
  await dialog.getByRole("button", { name: "Add task" }).dblclick();
  await expect(page.getByText("Task added")).toBeVisible();

  const row = page.getByTestId("task-row").filter({ hasText: title });
  await expect(row).toBeVisible();
  await expect(row.getByText("To do")).toBeVisible();
  const created = await apiGet<TaskList>(page.request, `/api/matters/${matter.id}/tasks`);
  expect(created.meta.total).toBe(1);
  expect((await dashboardStats(page)).open_tasks).toBe(before.open_tasks + 1);

  // Complete.
  await row.getByRole("checkbox", { name: `Mark task as completed: ${title}` }).click();
  await expect(page.getByText("Task completed", { exact: true })).toBeVisible();
  await expect(row.getByText("Completed", { exact: true })).toBeVisible();
  await expect(row.getByRole("checkbox")).toHaveAttribute("aria-checked", "true");
  expect((await dashboardStats(page)).open_tasks).toBe(before.open_tasks);
  const completed = await apiGet<TaskList>(page.request, `/api/matters/${matter.id}/tasks`);
  expect(completed.data[0]).toMatchObject({ status: "completed" });
  expect(completed.data[0].completed_at).not.toBeNull();

  // Reopen; the change survives a reload.
  await row.getByRole("checkbox", { name: `Reopen task: ${title}` }).click();
  await expect(page.getByText("Task reopened")).toBeVisible();
  await page.reload();
  await expect(page.getByTestId("task-row").filter({ hasText: title }).getByText("To do")).toBeVisible();
  const reopened = await apiGet<TaskList>(page.request, `/api/matters/${matter.id}/tasks`);
  expect(reopened.data[0]).toMatchObject({ status: "todo", completed_at: null });
  expect((await dashboardStats(page)).open_tasks).toBe(before.open_tasks + 1);

  await page.getByRole("tab", { name: "Activity" }).click();
  const history = page.getByRole("list", { name: "Activity history" });
  for (const label of ["Task added", "Task completed", "Task reopened"]) {
    await expect(history.getByText(label, { exact: true })).toBeVisible();
  }
});

// Scenario D — internal notes are created, edited, persisted and never public.
test("an administrator adds and edits an internal note that stays private", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const secret = `Client prefers settlement ${uniqueSuffix()}`;

  await page.goto(`/matters/${matter.id}?tab=notes`);
  await page.getByLabel("New internal note").fill(`${secret}\nCall back on Friday.`);
  await page.getByRole("button", { name: "Add note" }).click();
  await expect(page.getByText("Internal note added")).toBeVisible();
  const note = page.getByTestId("note").filter({ hasText: secret });
  await expect(note).toBeVisible();
  await expect(note).toContainText("Elena Marsh");

  await note.getByRole("button", { name: "Edit note" }).click();
  await note.getByLabel("Edit note").fill(`${secret} — confirmed in writing.`);
  await note.getByRole("button", { name: "Save note" }).click();
  await expect(page.getByText("Internal note updated")).toBeVisible();

  await page.reload();
  const saved = page.getByTestId("note").filter({ hasText: `${secret} — confirmed in writing.` });
  await expect(saved).toBeVisible();
  await expect(saved).toContainText("edited");

  // HTML is shown as text, never interpreted.
  await page.getByLabel("New internal note").fill('<img src=x onerror="window.__xss=1"><b>bold?</b>');
  await page.getByRole("button", { name: "Add note" }).click();
  await expect(page.getByText('<img src=x onerror="window.__xss=1"><b>bold?</b>')).toBeVisible();
  expect(await page.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBeUndefined();

  // History records the note actions without its confidential text.
  await page.getByRole("tab", { name: "Activity" }).click();
  await expect(page.getByText("Internal note edited", { exact: true })).toBeVisible();
  await expect(page.getByRole("list", { name: "Activity history" })).not.toContainText(secret);

  // Not accessible without an administrator session.
  // Explicitly empty: inside @playwright/test, newContext() would otherwise inherit the admin storageState.
  const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  expect(await anonymous.cookies()).toEqual([]);
  const response = await anonymous.request.get(`${API_URL}/api/matters/${matter.id}/notes`, { headers: SPA_HEADERS });
  expect(response.status()).toBe(401);
  expect(await response.text()).not.toContain(secret);
  await anonymous.close();
});

// Scenario E — archive an active matter and restore it through the permitted workflow.
test("an active matter is archived, stays accessible and is restored", async ({ page }) => {
  const matter = await createMatter(page, { status: "in_progress" });
  const before = await dashboardStats(page);

  await page.goto(`/matters/${matter.id}`);
  await page.getByRole("button", { name: "Archive" }).click();
  const confirm = page.getByRole("alertdialog", { name: `Archive ${matter.matter_number}?` });
  await expect(confirm).toContainText("Tasks, notes and history are kept");
  await confirm.getByRole("button", { name: "Archive matter" }).click();
  await expect(page.getByText("Matter archived", { exact: true })).toBeVisible();
  await expect(page.getByText(/This matter is archived/)).toBeVisible();
  await expect(page.getByRole("button", { name: "Edit", exact: true })).toBeDisabled();

  const afterArchive = await dashboardStats(page);
  expect(afterArchive.active_matters).toBe(before.active_matters - 1);
  expect(afterArchive.total_matters).toBe(before.total_matters);

  // Still available through the archive filter.
  await page.goto("/matters?status=archived");
  await expect(page.getByRole("table", { name: "Matters" }).getByRole("link", { name: matter.title })).toBeVisible();

  // Restore returns it to In progress.
  await page.getByRole("table", { name: "Matters" }).getByRole("link", { name: matter.title }).click();
  await page.getByRole("button", { name: "Restore" }).click();
  await page.getByRole("alertdialog", { name: `Restore ${matter.matter_number}?` }).getByRole("button", { name: "Restore matter" }).click();
  await expect(page.getByText("Matter restored to In progress")).toBeVisible();
  await expect(page.locator("header").getByText("In progress")).toBeVisible();
  expect((await dashboardStats(page)).active_matters).toBe(before.active_matters);

  await page.getByRole("tab", { name: "Activity" }).click();
  const history = page.getByRole("list", { name: "Activity history" });
  await expect(history.getByText("Archived", { exact: true })).toBeVisible();
  await expect(history.getByText("Restored", { exact: true })).toBeVisible();
  await expect(history.getByText("Archived → In progress", { exact: true })).toBeVisible();
});
