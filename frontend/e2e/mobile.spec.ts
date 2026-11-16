import { expect, test } from "@playwright/test";

import { STORAGE_STATE } from "./fixtures";

test.use({ storageState: STORAGE_STATE });

test("mobile navigation opens, navigates and closes", async ({ page }) => {
  await page.goto("/dashboard");
  await expect(page.getByRole("region", { name: "Key figures" })).toBeVisible();

  // Desktop sidebar is hidden on phones.
  await expect(page.getByRole("navigation", { name: "Main" })).toBeHidden();

  await page.getByRole("button", { name: "Open navigation" }).click();
  const drawer = page.getByRole("dialog", { name: "Navigation" });
  await expect(drawer).toBeVisible();

  await drawer.getByRole("link", { name: "Clients" }).click();
  await expect(page).toHaveURL(/\/clients$/);
  await expect(drawer).toBeHidden();
  await expect(page.getByRole("list", { name: "Clients" })).toBeVisible();
});

test("pages do not scroll horizontally on a phone", async ({ page }) => {
  for (const path of ["/dashboard", "/matters", "/clients"]) {
    await page.goto(path);
    await page.waitForLoadState("networkidle");
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, path).toBeLessThanOrEqual(0);
  }
});

test("the drawer can be closed with Escape", async ({ page }) => {
  await page.goto("/matters");
  await page.getByRole("button", { name: "Open navigation" }).click();
  await expect(page.getByRole("dialog", { name: "Navigation" })).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog", { name: "Navigation" })).toBeHidden();
});
