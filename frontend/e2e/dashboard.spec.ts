import { expect, test } from "@playwright/test";

import { STORAGE_STATE, apiGet } from "./fixtures";

interface Summary {
  data: {
    stats: { active_matters: number; total_clients: number; upcoming_deadlines: number; open_tasks: number; total_matters: number };
    recent_matters: { matter_number: string; title: string }[];
    upcoming_deadlines: { title: string }[];
  };
}

test.use({ storageState: STORAGE_STATE });

test("dashboard figures come from the live API", async ({ page }) => {
  const apiCalls: string[] = [];
  page.on("response", (response) => {
    if (response.url().includes("/api/dashboard/summary")) apiCalls.push(`${response.status()}`);
  });

  await page.goto("/dashboard");
  const { data } = await apiGet<Summary>(page.request, "/api/dashboard/summary");

  const figures = page.getByRole("region", { name: "Key figures" });
  await expect(figures.getByRole("link", { name: new RegExp(`^Active matters: ${data.stats.active_matters}\\.`) })).toBeVisible();
  await expect(figures.getByRole("link", { name: new RegExp(`^Total clients: ${data.stats.total_clients}\\.`) })).toBeVisible();
  await expect(figures.getByRole("link", { name: new RegExp(`^Upcoming deadlines: ${data.stats.upcoming_deadlines}\\.`) })).toBeVisible();
  await expect(figures.getByText("Open tasks").locator("..").locator("..")).toContainText(String(data.stats.open_tasks));

  // Seeded demo data (see DemoWorkspaceSeeder).
  expect(data.stats).toMatchObject({ active_matters: 7, total_clients: 8, upcoming_deadlines: 4, open_tasks: 11, total_matters: 10 });

  const recent = page.getByRole("table", { name: "Recently updated matters" });
  await expect(recent.getByRole("row")).toHaveCount(data.recent_matters.length + 1);
  await expect(recent).toContainText(data.recent_matters[0].matter_number);

  for (const matter of data.upcoming_deadlines) {
    await expect(page.getByRole("listitem").filter({ hasText: matter.title }).first()).toBeVisible();
  }
  await expect(page.getByRole("link", { name: /past its deadline/ })).toBeVisible();
  expect(apiCalls).toContain("200");
});

test("stat cards link to filtered matter lists", async ({ page }) => {
  await page.goto("/dashboard");
  await page.getByRole("link", { name: /^Active matters:/ }).click();

  await expect(page).toHaveURL(/\/matters\?status=active$/);
  await expect(page.getByLabel("Status")).toHaveValue("active");
  await expect(page.getByText(/Showing 1–7 of 7 matters/)).toBeVisible();
});

test("a failing API shows an error state with a working retry", async ({ page }) => {
  let fail = true;
  await page.route("**/api/dashboard/summary", (route) =>
    fail ? route.fulfill({ status: 500, contentType: "application/json", body: '{"message":"Server Error"}' }) : route.continue(),
  );

  await page.goto("/dashboard");
  await expect(page.getByRole("alert").filter({ hasText: "dashboard" })).toContainText("We couldn't load your dashboard");

  fail = false;
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByRole("region", { name: "Key figures" })).toBeVisible();
});
