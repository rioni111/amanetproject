import { expect, test } from "@playwright/test";

import { STORAGE_STATE, apiSend, createMatter, expectNoHorizontalOverflow, uniqueSuffix } from "./fixtures";
import { makePdf } from "./pdf";
import { anonymousPage, createRequestApi } from "./requests";
import { drawSignature, uploadPdfApi } from "./signing";

/**
 * Phase 7 — the smallest supported phone width (320 px): the client pages many clients will open on a
 * phone, and the administrator's navigation. Runs in the "mobile" project (touch, Pixel 7 user agent).
 */
test.use({ storageState: STORAGE_STATE });
test.describe.configure({ timeout: 180_000 });

const SMALL = { width: 320, height: 640 };

test("a client uploads and signs on a 320 px phone without horizontal scrolling", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const request = await createRequestApi(page, matter, { title: `Proof of address ${uniqueSuffix()}` });
  const { document } = await uploadPdfApi(page, matter, `Small-screen agreement ${uniqueSuffix()}`, ["Agreement", "Signature page"]);
  const signing = await apiSend<{ link: { url: string } }>(page, "POST", `/api/matters/${matter.id}/signing-requests`, {
    document_id: document.id, document_version_id: document.current_version.id, signer_type: "client",
    signer_name: "Ana Krasniqi", signer_email: "ana.krasniqi@northwind.example", issue_link: true,
  });

  const client = await anonymousPage(browser, { viewport: SMALL, isMobile: true, hasTouch: true });
  await client.goto(request.link!.url);
  await expect(client.getByRole("heading", { level: 1 })).toBeVisible();
  await expectNoHorizontalOverflow(client, "client upload page at 320 px");
  await client.locator("#client-file").setInputFiles({ name: "proof-of-address.pdf", mimeType: "application/pdf", buffer: makePdf(["Utility bill"]) });
  const submit = client.getByRole("button", { name: "Submit document" });
  const box = (await submit.boundingBox())!;
  expect(box.height, "a comfortable touch target").toBeGreaterThanOrEqual(40);
  await submit.tap();
  await expect(client.getByTestId("upload-success")).toBeVisible();
  await expectNoHorizontalOverflow(client, "upload confirmation at 320 px");

  await client.goto(signing.link.url);
  await expect(client.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();
  await expectNoHorizontalOverflow(client, "client signing page at 320 px");
  await client.getByRole("button", { name: "Start signing" }).tap();
  await drawSignature(client);
  await expectNoHorizontalOverflow(client, "signature step at 320 px");
  await client.context().close();
});

test("the administrator can navigate every section at 320 px", async ({ page }) => {
  await page.setViewportSize(SMALL);
  await page.goto("/dashboard");
  for (const section of ["Matters", "Clients", "Documents", "Client requests", "Signatures"]) {
    await page.getByRole("button", { name: "Open navigation" }).tap();
    await page.getByRole("dialog").getByRole("link", { name: section }).tap();
    await expect(page.getByRole("heading", { level: 1 }).first()).toBeVisible();
    await expectNoHorizontalOverflow(page, `${section} at 320 px`);
  }
  await page.getByTestId("notification-bell").tap();
  await expect(page.getByRole("region", { name: "Recent notifications" })).toBeVisible();
  await expectNoHorizontalOverflow(page, "notification panel at 320 px");
});
