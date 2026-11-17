import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

import { STORAGE_STATE, apiSend, createMatter, uniqueSuffix } from "./fixtures";
import { anonymousPage, createRequestApi } from "./requests";
import { uploadPdfApi } from "./signing";

/**
 * Phase 7 — automated accessibility checks (axe-core, WCAG 2.0/2.1/2.2 levels A and AA rules) on every
 * page type, the main dialogs and the passwordless client pages, plus keyboard behaviour. Automated rules
 * find a subset of WCAG problems; they complement, not replace, the manual keyboard review in the QA report.
 */
test.use({ storageState: STORAGE_STATE });
test.describe.configure({ timeout: 180_000 });

const TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];

async function expectAccessible(page: Page, label: string, include?: string) {
  let builder = new AxeBuilder({ page }).withTags(TAGS).exclude("nextjs-portal"); // the development-only Next.js overlay
  if (include) builder = builder.include(include);
  const { violations } = await builder.analyze();
  const summary = violations.map((v) => `${v.id} (${v.impact}): ${v.help} — ${v.nodes.slice(0, 3).map((n) => n.target.join(" ")).join(" | ")}`);
  expect(summary, `${label}: accessibility violations`).toEqual([]);
}

async function ready(page: Page, path: string) {
  await page.goto(path);
  await expect(page.getByRole("heading", { level: 1 }).first()).toBeVisible();
  await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);
}

test("workspace pages meet the automated WCAG A/AA checks", async ({ page }) => {
  const matter = await createMatter(page, { title: `Accessibility matter ${uniqueSuffix()}`, due_date: "2027-01-15" });
  const request = await createRequestApi(page, matter, { issue_link: false });
  const { document } = await uploadPdfApi(page, matter, `Accessibility PDF ${uniqueSuffix()}`, ["Page one", "Page two"]);
  const signing = await apiSend<{ data: { id: number } }>(page, "POST", `/api/matters/${matter.id}/signing-requests`, {
    document_id: document.id, document_version_id: document.current_version.id, signer_type: "client",
    signer_name: "Ana Krasniqi", signer_email: "ana.krasniqi@northwind.example", issue_link: false,
  });

  const pages: [string, string][] = [
    ["dashboard", "/dashboard"],
    ["matters", "/matters"],
    ["matter", `/matters/${matter.id}`],
    ["clients", "/clients"],
    ["documents", "/documents"],
    ["document", `/matters/${matter.id}/documents/${document.id}`],
    ["client requests", "/document-requests"],
    ["request", `/matters/${matter.id}/requests/${request.data.id}`],
    ["signatures", "/signatures"],
    ["signing request", `/matters/${matter.id}/signatures/${signing.data.id}`],
    ["notifications", "/notifications"],
  ];
  for (const [label, path] of pages) {
    await ready(page, path);
    await expectAccessible(page, label);
  }

  // Matter tabs render different panels.
  await ready(page, `/matters/${matter.id}`);
  for (const tab of ["Tasks", "Documents", "Client requests", "Signatures", "Internal notes", "Activity"]) {
    await page.getByRole("tab", { name: new RegExp(`^${tab}`) }).click();
    await expect(page.getByRole("tabpanel")).toBeVisible();
    await expectAccessible(page, `matter tab ${tab}`);
  }
});

test("dialogs meet the automated checks and behave correctly with the keyboard", async ({ page }) => {
  const matter = await createMatter(page);
  const request = await createRequestApi(page, matter, { issue_link: false });

  await ready(page, "/matters");
  const opener = page.getByRole("button", { name: "New matter" });
  await opener.focus();
  await page.keyboard.press("Enter");
  const dialog = page.getByRole("dialog", { name: /New matter/ });
  await expect(dialog).toBeVisible();
  await expect(dialog.locator(":focus")).toHaveCount(1); // focus moved into the dialog
  await expectAccessible(page, "new matter dialog", '[role="dialog"]');
  // Submitting the empty form shows field errors that are announced and tied to their inputs.
  await dialog.getByRole("button", { name: /Create matter/ }).click();
  const title = dialog.getByRole("textbox", { name: /Matter title/ });
  await expect(title).toHaveAttribute("aria-invalid", "true");
  await expectAccessible(page, "new matter dialog with errors", '[role="dialog"]');
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused(); // focus returns to the trigger

  await ready(page, `/matters/${matter.id}/requests/${request.data.id}`);
  const sendButton = page.getByRole("button", { name: "Send e-mail" });
  await sendButton.focus();
  await page.keyboard.press("Enter");
  const email = page.getByRole("dialog", { name: "E-mail the document request" });
  await expect(email.getByTestId("email-recipient")).toBeVisible();
  await expectAccessible(page, "send e-mail dialog", '[role="dialog"]');
  await page.keyboard.press("Escape");
  await expect(email).toBeHidden();
  await expect(sendButton).toBeFocused();

  // A confirmation (alert dialog) also returns focus to the button that opened it.
  const cancel = page.getByRole("button", { name: "Cancel request" });
  await cancel.focus();
  await page.keyboard.press("Enter");
  const confirm = page.getByRole("alertdialog");
  await expect(confirm).toBeVisible();
  await expectAccessible(page, "confirmation dialog", '[role="alertdialog"]');
  await page.keyboard.press("Escape");
  await expect(confirm).toBeHidden();
  await expect(cancel).toBeFocused();

  // The notification panel is a disclosure: Escape closes it and returns focus to the bell.
  const bell = page.getByTestId("notification-bell");
  await bell.focus();
  await page.keyboard.press("Enter");
  const panel = page.getByRole("region", { name: "Recent notifications" });
  await expect(panel).toBeVisible();
  await expectAccessible(page, "notification panel", '[aria-label="Recent notifications"]');
  await page.keyboard.press("Escape");
  await expect(panel).toBeHidden();
  await expect(bell).toBeFocused();
});

test("the sign-in page and the passwordless client pages meet the automated checks", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const request = await createRequestApi(page, matter);
  const { document } = await uploadPdfApi(page, matter, `Client page PDF ${uniqueSuffix()}`, ["Agreement", "Signature page"]);
  const signing = await apiSend<{ link: { url: string } }>(page, "POST", `/api/matters/${matter.id}/signing-requests`, {
    document_id: document.id, document_version_id: document.current_version.id, signer_type: "client",
    signer_name: "Ana Krasniqi", signer_email: "ana.krasniqi@northwind.example", issue_link: true,
  });

  const guest = await anonymousPage(browser);
  await guest.goto("/login");
  await expect(guest.getByRole("heading", { level: 1 })).toBeVisible();
  await expectAccessible(guest, "sign-in page");

  await guest.goto(request.link!.url);
  await expect(guest.getByRole("heading", { level: 1 })).toBeVisible();
  await expect(guest.locator("#client-file")).toBeAttached();
  await expectAccessible(guest, "client upload page");

  await guest.goto("/client-upload#not-a-real-token");
  await expect(guest.getByRole("heading", { level: 1, name: "This link is not valid" })).toBeVisible();
  await expectAccessible(guest, "client upload — invalid link");

  await guest.goto(signing.link.url);
  await expect(guest.locator('[data-testid="signing-page"] canvas[data-rendered="true"]')).toBeVisible();
  await expectAccessible(guest, "client signing page");
  await guest.context().close();
});
