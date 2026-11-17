import { readFileSync } from "node:fs";

import { expect, test, type Page } from "@playwright/test";

import { API_URL, SPA_HEADERS, STORAGE_STATE, apiGet, apiSend, createMatter, uniqueSuffix, type ApiMatter } from "./fixtures";
import { pdfFile, sha256 } from "./pdf";

test.use({ storageState: STORAGE_STATE });

interface ApiDocument {
  id: number;
  title: string;
  matter_id: number;
  current_version: { id: number; label: string; sha256_checksum: string; original_filename: string };
}

/** Uploads through the real UI from the matter's Documents tab and returns the created document. */
async function uploadThroughMatter(page: Page, matter: ApiMatter, file: ReturnType<typeof pdfFile>, title: string, category = "contracts") {
  await page.goto(`/matters/${matter.id}?tab=documents`);
  await page
    .getByRole("button", { name: /Upload (the first )?PDF/ })
    .first()
    .click();
  const dialog = page.getByRole("dialog", { name: "Upload PDF" });
  await expect(dialog).toContainText(`Uploading to ${matter.matter_number}`);
  await dialog.locator("#upload-file").setInputFiles(file);
  await expect(dialog.getByTestId("selected-file")).toHaveText(file.name);
  await dialog.getByLabel("Document title").fill(title);
  await dialog.getByLabel("Category").selectOption(category);
  await dialog.getByRole("button", { name: "Upload PDF" }).click();
  await expect(page.getByText("Document uploaded", { exact: true })).toBeVisible();
  await expect(dialog).toBeHidden();
  const list = await apiGet<{ data: ApiDocument[] }>(
    page.request,
    `/api/matters/${matter.id}/documents?search=${encodeURIComponent(title)}`,
  );
  return list.data[0];
}

async function downloadFrom(page: Page, trigger: () => Promise<void>) {
  const [download] = await Promise.all([page.waitForEvent("download"), trigger()]);
  const path = await download.path();
  return { name: download.suggestedFilename(), bytes: readFileSync(path!) };
}

// Scenarios A, B, C — upload, preview (multipage, zoom) and download with checksum verification.
test("an administrator uploads, previews and downloads a PDF", async ({ page }) => {
  const matter = await createMatter(page);
  const title = `Share Purchase Agreement ${uniqueSuffix()}`;
  const file = pdfFile("share-purchase-agreement.pdf", [`${title} — page one`, "Schedule 1 — warranties", "Schedule 2 — completion"]);

  // A — upload and persistence.
  const created = await uploadThroughMatter(page, matter, file, title);
  const table = page.getByRole("table", { name: `Documents of ${matter.matter_number}` });
  await expect(table.getByRole("link", { name: title, exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByRole("table", { name: `Documents of ${matter.matter_number}` }).getByRole("link", { name: title, exact: true })).toBeVisible();
  await expect(page.getByRole("tab", { name: /Documents/ })).toContainText("1");
  expect(created.current_version.sha256_checksum).toBe(sha256(file.buffer));

  // B — preview renders the real PDF: three pages, navigation and zoom.
  await page
    .getByRole("button", { name: `Preview ${title}` })
    .first()
    .click();
  const viewer = page.getByRole("dialog", { name: new RegExp(`${title} · v1`) });
  await expect(viewer.locator('canvas[data-rendered="true"]').first()).toBeVisible();
  await expect(viewer.getByTestId("pdf-page-status")).toHaveText("Page 1 of 3");
  await expect(viewer.getByRole("img", { name: "Page 3 of 3" })).toBeAttached();
  const nonBlank = await viewer
    .locator("canvas")
    .first()
    .evaluate((canvas: HTMLCanvasElement) => {
      const ctx = canvas.getContext("2d")!;
      const { data } = ctx.getImageData(0, 0, canvas.width, canvas.height);
      for (let i = 0; i < data.length; i += 4) if (data[i + 3] > 0 && data[i] < 128) return true; // opaque dark (text) pixels
      return false;
    });
  expect(nonBlank, "page 1 contains rendered text").toBe(true);
  await viewer.getByRole("button", { name: "Next page" }).click();
  await expect(viewer.getByTestId("pdf-page-status")).toHaveText("Page 2 of 3");
  const before = Number.parseInt((await viewer.getByTestId("pdf-zoom").textContent()) ?? "0", 10);
  await viewer.getByRole("button", { name: "Zoom in" }).click();
  await expect.poll(async () => Number.parseInt((await viewer.getByTestId("pdf-zoom").textContent()) ?? "0", 10)).toBeGreaterThan(before);
  await page.keyboard.press("Escape");
  await expect(viewer).toBeHidden();
  await page.getByRole("tab", { name: "Overview" }).click(); // the interface remains usable
  await expect(page.getByText("Outstanding work")).toBeVisible();

  // C — download: exact bytes and name.
  await page.goto(`/matters/${matter.id}/documents/${created.id}`);
  const downloaded = await downloadFrom(page, () => page.getByRole("button", { name: "Download", exact: true }).click());
  expect(downloaded.name).toBe("share-purchase-agreement.pdf");
  expect(sha256(downloaded.bytes)).toBe(sha256(file.buffer));
});

// Scenario D — versions are kept, numbered and individually previewable and downloadable.
test("a revised PDF becomes v2 and v1 stays intact", async ({ page }) => {
  const matter = await createMatter(page);
  const title = `Employment Agreement ${uniqueSuffix()}`;
  const v1 = pdfFile("employment-agreement.pdf", [`${title} — original`]);
  const v2 = pdfFile("employment-agreement-rev2.pdf", [`${title} — revised`, "Clause 9 amended"]);
  const document = await uploadThroughMatter(page, matter, v1, title);

  await page.goto(`/matters/${matter.id}/documents/${document.id}`);
  await page.getByRole("button", { name: "New version" }).click();
  const dialog = page.getByRole("dialog", { name: `New version of ${title}` });
  await expect(dialog).toContainText("becomes v2");
  await dialog.locator("#upload-file").setInputFiles(v2);
  await dialog.getByRole("button", { name: "Upload new version" }).click();
  await expect(page.getByText("Version v2 uploaded")).toBeVisible();

  const versions = page.getByRole("list", { name: "Versions" });
  await expect(versions.getByTestId("version-row")).toHaveCount(2);
  await expect(versions.getByTestId("version-row").first()).toContainText("v2");
  await expect(versions.getByTestId("version-row").first()).toContainText("Current");

  // Preview each version.
  for (const [label, pages] of [
    ["v1", 1],
    ["v2", 2],
  ] as const) {
    await page.getByRole("button", { name: `Preview ${label}` }).click();
    const viewer = page.getByRole("dialog", { name: new RegExp(`· ${label}$`) });
    await expect(viewer.locator('canvas[data-rendered="true"]').first()).toBeVisible();
    await expect(viewer.getByTestId("pdf-page-status")).toHaveText(`Page 1 of ${pages}`);
    await page.keyboard.press("Escape");
    await expect(viewer).toBeHidden();
  }

  // Download both: distinct files, each identical to what was uploaded.
  const first = await downloadFrom(page, () => page.getByRole("button", { name: "Download v1" }).click());
  const second = await downloadFrom(page, () => page.getByRole("button", { name: "Download v2" }).click());
  expect(sha256(first.bytes)).toBe(sha256(v1.buffer));
  expect(sha256(second.bytes)).toBe(sha256(v2.buffer));
  expect(sha256(first.bytes)).not.toBe(sha256(second.bytes));
  expect(first.name).toBe("employment-agreement.pdf");

  // Uploading the same file again is refused as an unchanged duplicate.
  await page.getByRole("button", { name: "New version" }).click();
  const again = page.getByRole("dialog", { name: `New version of ${title}` });
  await again.locator("#upload-file").setInputFiles(v2);
  await again.getByRole("button", { name: "Upload new version" }).click();
  await expect(again.getByText("This PDF is identical to the current version (v2). Upload the revised file.")).toBeVisible();
  await again.getByRole("button", { name: "Cancel" }).click();

  // Scenario G — history shows document events, readable labels, no confidential content.
  await page.goto(`/matters/${matter.id}?tab=activity`);
  const history = page.getByRole("list", { name: "Activity history" });
  await expect(history.getByText("Document uploaded", { exact: true })).toBeVisible();
  await expect(history.getByText("New document version added", { exact: true })).toBeVisible();
  await expect(history.getByText("Document downloaded", { exact: true }).first()).toBeVisible();
  await expect(history).toContainText(`New version of ${title}: v2.`);
  await expect(history).not.toContainText("storage");
  await expect(history).not.toContainText("Clause 9 amended");
});

// Scenario E — library search, filters, sorting and URL synchronisation.
test("the document library searches and filters on the server and keeps filters in the URL", async ({ page }) => {
  const matter = await createMatter(page);
  const marker = uniqueSuffix();
  await uploadThroughMatter(
    page,
    matter,
    pdfFile(`witness-statement-${marker}.pdf`, ["Witness"]),
    `Witness Statement ${marker}`,
    "evidence",
  );
  await uploadThroughMatter(page, matter, pdfFile(`retainer-${marker}.pdf`, ["Retainer"]), `Retainer Letter ${marker}`, "correspondence");

  await page.goto("/documents");
  await expect(page.getByRole("heading", { level: 1, name: "Documents" })).toBeVisible();
  const table = page.getByRole("table", { name: "Documents" });

  await page.getByLabel("Search documents").fill(marker);
  await expect(page).toHaveURL(new RegExp(`search=${marker}`));
  await expect(table.getByRole("row")).toHaveCount(3);

  await page.getByLabel("Matter", { exact: true }).selectOption(String(matter.id));
  await page.getByLabel("Category", { exact: true }).selectOption("evidence");
  await page.getByLabel("Sort by").selectOption("title");
  await expect(page).toHaveURL(new RegExp(`matter_id=${matter.id}`));
  await expect(page).toHaveURL(/category=evidence/);
  await expect(page).toHaveURL(/sort=title/);
  await expect(page).toHaveURL(new RegExp(`search=${marker}`));
  await expect(table.getByRole("row")).toHaveCount(2);
  await expect(table).toContainText(`Witness Statement ${marker}`);
  await expect(table).toContainText(matter.matter_number);

  // Search by original file name works too (metadata, not PDF contents).
  await page.getByLabel("Category", { exact: true }).selectOption("");
  await page.getByLabel("Search documents").fill(`retainer-${marker}`);
  await expect(table.getByRole("row")).toHaveCount(2);
  await expect(table).toContainText(`Retainer Letter ${marker}`);

  await page.reload();
  await expect(page.getByLabel("Search documents")).toHaveValue(`retainer-${marker}`);
  await expect(page.getByLabel("Matter", { exact: true })).toHaveValue(String(matter.id));
  await expect(page.getByLabel("Sort by")).toHaveValue("title");
  await expect(table.getByRole("row")).toHaveCount(2);
});

// Scenario F — the API refuses unauthenticated, cross-matter and invalid access; archived matters are read-only.
test("document access is protected and archived matters are read-only", async ({ page, browser }) => {
  const matter = await createMatter(page);
  const other = await createMatter(page);
  const title = `Confidential Memo ${uniqueSuffix()}`;
  const document = await uploadThroughMatter(page, matter, pdfFile("memo.pdf", [title]), title, "correspondence");
  const versionUrl = `/api/matters/${matter.id}/documents/${document.id}/versions/${document.current_version.id}`;

  const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  for (const url of [`${versionUrl}/preview`, `${versionUrl}/download`, `/api/matters/${matter.id}/documents/${document.id}`]) {
    const response = await anonymous.request.get(`${API_URL}${url}`, { headers: SPA_HEADERS });
    expect(response.status(), url).toBe(401);
    expect(await response.text()).not.toContain("%PDF");
  }
  // There is no public file URL to guess.
  const guess = await anonymous.request.get(`${API_URL}/storage/${matter.id}/memo.pdf`);
  expect(guess.status()).not.toBe(200);
  await anonymous.close();

  const cross = await page.request.get(`${API_URL}/api/matters/${other.id}/documents/${document.id}`, { headers: SPA_HEADERS });
  expect(cross.status()).toBe(404);
  const invalid = await page.request.get(`${API_URL}/api/matters/${matter.id}/documents/${document.id}/versions/999999/download`, {
    headers: SPA_HEADERS,
  });
  expect(invalid.status()).toBe(404);
  const listJson = await (await page.request.get(`${API_URL}/api/matters/${matter.id}/documents`, { headers: SPA_HEADERS })).text();
  expect(listJson).not.toContain("storage_path");

  // Archive the matter: documents stay viewable, uploads are refused and nothing is stored.
  await apiSend(page, "POST", `/api/matters/${matter.id}/archive`, {});
  await page.goto(`/matters/${matter.id}?tab=documents`);
  await expect(page.getByRole("link", { name: title, exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: /Upload (the first )?PDF/ })).toHaveCount(0);

  await page.request.get(`${API_URL}/sanctum/csrf-cookie`, { headers: SPA_HEADERS });
  const xsrf = (await page.context().cookies(API_URL)).find((c) => c.name === "XSRF-TOKEN");
  const refused = await page.request.post(`${API_URL}/api/matters/${matter.id}/documents`, {
    headers: { ...SPA_HEADERS, "X-XSRF-TOKEN": decodeURIComponent(xsrf?.value ?? "") },
    multipart: { category: "evidence", title: "Should not exist", file: pdfFile("late.pdf", ["Late"]) },
  });
  expect(refused.status()).toBe(409);
  const after = await apiGet<{ meta: { total: number } }>(page.request, `/api/matters/${matter.id}/documents?status=all`);
  expect(after.meta.total).toBe(1);
});
