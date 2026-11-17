import { devices, expect, test } from "@playwright/test";

import { STORAGE_STATE, apiSend, createMatter, expectNoHorizontalOverflow, uniqueSuffix } from "./fixtures";
import { drawSignature, signingDetail, uploadPdfApi } from "./signing";

test.use({ storageState: STORAGE_STATE });
test.describe.configure({ timeout: 180_000 });

// Scenario J — passwordless signing on a phone (Pixel 7): review, touch-sized controls, draw, place, consent, sign.
test("a client signs a PDF on a phone", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const { document } = await uploadPdfApi(page, matter, `Mobile consent form ${uniqueSuffix()}`, ["Consent form", "Signature page"]);
  const created = await apiSend<{ data: { id: number }; link: { url: string } }>(page, "POST", `/api/matters/${matter.id}/signing-requests`, {
    document_id: document.id,
    document_version_id: document.current_version.id,
    signer_type: "client",
    signer_name: "Arta Gashi",
    issue_link: true,
  });

  const context = await browser.newContext({ ...devices["Pixel 7"], storageState: { cookies: [], origins: [] } });
  const client = await context.newPage();
  await client.goto(created.link.url);
  await expect(client.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();
  await expectNoHorizontalOverflow(client, "signing page (review)");
  await client.getByRole("button", { name: "Next page" }).tap();
  await expect(client.getByTestId("review-page-status")).toHaveText("Page 2 of 2");

  await client.getByRole("button", { name: "Start signing" }).tap();
  await expect(client.getByTestId("signature-pad")).toBeVisible();
  const viewport = client.viewportSize()!;
  for (const control of [client.getByTestId("signature-pad"), client.getByRole("button", { name: "Sign document" })]) {
    const box = (await control.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
  }
  await drawSignature(client);

  // Place the signature by tapping the page (touch), then confirm.
  const pageBox = (await client.getByTestId("signing-page").boundingBox())!;
  await client.touchscreen.tap(pageBox.x + pageBox.width * 0.3, pageBox.y + pageBox.height * 0.5);
  await expect(client.getByTestId("placement-summary")).toHaveText("Signature on page 2 of 2.");
  await client.getByLabel("Your full name", { exact: true }).fill("Arta Gashi");
  // Consent is required.
  await client.getByRole("button", { name: "Sign document" }).tap();
  await expect(client.getByRole("alert").filter({ hasText: "Confirm the signing statement to sign." })).toBeVisible();
  await client.getByTestId("consent-checkbox").check();
  await expectNoHorizontalOverflow(client, "signing page (signing step)");
  await client.getByRole("button", { name: "Sign document" }).tap();
  await expect(client.getByTestId("sign-success")).toBeVisible();
  await expectNoHorizontalOverflow(client, "signing confirmation");
  await context.close();

  expect((await signingDetail(page, matter.id, created.data.id)).data.status).toBe("signed");
  await page.goto(`/matters/${matter.id}/signatures/${created.data.id}`);
  await page.waitForLoadState("networkidle");
  await expectNoHorizontalOverflow(page, "signing request page");
});
