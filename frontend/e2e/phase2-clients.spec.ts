import { expect, test } from "@playwright/test";

import { API_URL, SPA_HEADERS, STORAGE_STATE, apiGet, dashboardStats, uniqueSuffix } from "./fixtures";

test.use({ storageState: STORAGE_STATE });

// Scenario A — create, view and edit a client; changes persist in PostgreSQL.
test("an administrator creates a client, opens it and edits it", async ({ page }) => {
  const suffix = uniqueSuffix();
  const name = `Northwind Trading ${suffix}`;
  const reference = `CL-E2E-${suffix}`;
  const before = await dashboardStats(page);

  await page.goto("/clients");
  await page.getByRole("button", { name: "New client" }).click();
  const dialog = page.getByRole("dialog", { name: "New client" });
  await expect(dialog).toBeVisible();

  // Client-side validation runs before any request.
  await dialog.getByRole("button", { name: "Create client" }).click();
  await expect(dialog.getByText("Enter the client's name.")).toBeVisible();

  await dialog.getByLabel("Client name").fill(name);
  await dialog.getByLabel("Client type").selectOption("organization");
  await dialog.getByLabel("Email").fill(`legal+${suffix.toLowerCase()}@northwind.example.com`);
  await dialog.getByLabel("Phone").fill("+1 555 0199 7788");
  await dialog.getByLabel("Address").fill("1 Harbour Street\nExample City");
  await dialog.getByLabel("Reference number").fill(reference);
  await dialog.getByRole("button", { name: "Create client" }).click();

  // Success feedback only after the API confirmed; the new client's page opens.
  await expect(page.getByText("Client created")).toBeVisible();
  await expect(page).toHaveURL(/\/clients\/\d+$/);
  await expect(page.getByRole("heading", { level: 1, name })).toBeVisible();
  await expect(page.getByText(reference).first()).toBeVisible();
  expect((await dashboardStats(page)).total_clients).toBe(before.total_clients + 1);

  // It appears in the list (server-side search).
  await page.goto(`/clients?search=${encodeURIComponent(suffix)}`);
  const table = page.getByRole("table", { name: "Clients" });
  await expect(table.getByRole("link", { name, exact: true })).toBeVisible();
  await table.getByRole("link", { name, exact: true }).click();
  await expect(page).toHaveURL(/\/clients\/\d+$/);

  // Edit and confirm the change survives a reload.
  await page.getByRole("button", { name: "Edit client" }).click();
  const edit = page.getByRole("dialog", { name: "Edit client" });
  await expect(edit.getByLabel("Client name")).toHaveValue(name);
  await edit.getByLabel("Phone").fill("+1 555 0100 4242");
  await edit.getByLabel("Address").fill("22 Quay Road, Example City");
  await edit.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByText("Client updated")).toBeVisible();

  await page.reload();
  await expect(page.getByRole("link", { name: "+1 555 0100 4242" })).toBeVisible();
  await expect(page.getByText("22 Quay Road, Example City")).toBeVisible();

  // Same record (no duplicate) and the change is stored.
  const found = await apiGet<{ data: { phone: string }[]; meta: { total: number } }>(page.request, `/api/clients?search=${suffix}`);
  expect(found.meta.total).toBe(1);
  expect(found.data[0].phone).toBe("+1 555 0100 4242");
});

test("duplicate reference numbers are rejected by the server with a field error", async ({ page }) => {
  await page.goto("/clients");
  await page.getByRole("button", { name: "New client" }).click();
  const dialog = page.getByRole("dialog", { name: "New client" });
  await dialog.getByLabel("Client name").fill(`Duplicate Ref ${uniqueSuffix()}`);
  await dialog.getByLabel("Reference number").fill("CL-1001"); // seeded: Harborline Freight Ltd.
  await dialog.getByRole("button", { name: "Create client" }).click();

  await expect(dialog.getByText("Another client already uses this reference number.")).toBeVisible();
  await expect(dialog.getByLabel("Reference number")).toHaveAttribute("aria-invalid", "true");
});

test("a client with matters cannot be deleted", async ({ page }) => {
  const clients = await apiGet<{ data: { id: number }[] }>(page.request, "/api/clients?search=CL-1001");
  await page.goto(`/clients/${clients.data[0].id}`);
  await expect(page.getByRole("heading", { level: 1, name: "Harborline Freight Ltd." })).toBeVisible();
  await expect(page.getByRole("button", { name: "Delete client" })).toBeDisabled();
  await expect(page.getByRole("table", { name: /Matters for Harborline/ })).toContainText("LEX-2026-0001");

  // The API enforces the rule too (409), independent of the disabled button.
  await page.request.get(`${API_URL}/sanctum/csrf-cookie`, { headers: SPA_HEADERS });
  const xsrf = (await page.context().cookies(API_URL)).find((c) => c.name === "XSRF-TOKEN");
  const response = await page.request.delete(`${API_URL}/api/clients/${clients.data[0].id}`, {
    headers: { ...SPA_HEADERS, "X-XSRF-TOKEN": decodeURIComponent(xsrf?.value ?? "") },
  });
  expect(response.status()).toBe(409);
});

test("an unknown client id shows a not-found state", async ({ page }) => {
  await page.goto("/clients/987654");
  await expect(page.getByText("Client not found")).toBeVisible();
});
