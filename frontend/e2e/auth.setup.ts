import { expect, test as setup } from "@playwright/test";

import { STORAGE_STATE, loginThroughUi } from "./fixtures";

// Sign in once and reuse the session cookie for the remaining specs (keeps us under the login rate limit).
setup("authenticate as demo administrator", async ({ page }) => {
  await loginThroughUi(page);
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole("heading", { level: 1 })).toContainText(/Good (morning|afternoon|evening), Elena/);
  await page.context().storageState({ path: STORAGE_STATE });
});
