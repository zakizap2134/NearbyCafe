import { chromium, expect } from "@playwright/test";
import assert from "node:assert/strict";
import fs from "node:fs";
const base = process.argv[2] ?? "http://127.0.0.1:18081";
async function api(route, body, token) {
  const response = await fetch(base + "/api/index.php?route=" + route, {
    method: body ? "POST" : "GET",
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: "Bearer " + token } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });
  const result = await response.json();
  assert.equal(response.status, 200, JSON.stringify(result));
  return result.data;
}
let owner = await api("login", {
  username: "testowner",
  password: "Owner-Test-Only-42",
});
const browser = await chromium.launch({
  headless: true,
  executablePath: process.env.VV_CHROMIUM_PATH || undefined,
  args: ["--no-sandbox"],
});
const errors = [];
fs.mkdirSync("/tmp/venue-rebuild-ui", { recursive: true });
try {
  const context = await browser.newContext({
      viewport: { width: 1280, height: 960 },
    }),
    page = await context.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("dialog", (d) => d.accept());
  const click = (p, name) =>
    p.getByRole("button", { name, exact: true }).click();
  const fill = (p, label, value) =>
    p.getByLabel(label, { exact: true }).fill(value);
  async function login(p, name = "testowner", password = "Owner-Test-Only-42") {
    await fill(p, "Username", name);
    await fill(p, "Password", password);
    await click(p, "Sign in");
  }
  await page.goto(base);
  await login(page);
  await click(page, "Stock operations");
  const records = await api("management", undefined, owner.token),
    ingredient = records.ingredients.find((i) => i.name === "Stage4 milk");
  const batch = records.batches.find(
      (b) =>
        Number(b.ingredient_id) === Number(ingredient.id) &&
        Number(b.remaining_qty) > 0,
    ),
    container = records.containers.find(
      (c) => Number(c.batch_id) === Number(batch.id),
    );
  await page
    .getByLabel("Container", { exact: true })
    .selectOption(String(container.id));
  await fill(page, "Waste quantity", "10");
  await fill(page, "Reason for stock operation", "Browser waste verification");
  let intercepted = false;
  await context.route(
    "**/api/index.php?route=management%2Finventory",
    async (route) => {
      if (!intercepted) {
        intercepted = true;
        await route.fetch();
        await route.abort("failed");
      } else await route.continue();
    },
  );
  await click(page, "Save stock operation");
  await expect(page.getByRole("alert")).toContainText("Cannot reach");
  await page.reload();
  await click(page, "Stock operations");
  await click(page, "Retry saved operation");
  await expect(page.getByRole("status")).toContainText(
    "Previously saved operation confirmed",
  );
  let state = await api("management", undefined, owner.token);
  assert.equal(
    Number(
      state.containers.find((c) => Number(c.id) === Number(container.id))
        .remaining_qty,
    ),
    Number(container.remaining_qty) - 10,
  );
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage4-stock.png",
    fullPage: true,
  });
  await click(page, "Reports");
  await expect(
    page.getByRole("heading", { name: "Daily summary / Z-reading" }),
  ).toBeVisible();
  const csvPromise = page.waitForEvent("download");
  await click(page, "Download sales CSV");
  const csv = await csvPromise;
  const csvText = fs.readFileSync(await csv.path(), "utf8");
  assert.ok(csvText.includes("gross,net,vat"));
  assert.ok(csvText.includes("TOTAL"));
  await page
    .getByRole("button", { name: /View sale #/ })
    .first()
    .click();
  await expect(page.getByLabel("Sale receipt")).toContainText("VATable Sales");
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage4-reports.png",
    fullPage: true,
  });
  await click(page, "Staff");
  await expect(page.getByRole("button", {name: "Edit testbarista", exact: true})).toBeVisible();
  await fill(page, "Staff username", "browserstage4");
  await fill(page, "Staff display name", "Browser Stage4");
  await fill(page, "Initial staff password", "Browser-Stage4-Only-42");
  await fill(page, "Confirm initial staff password", "Browser-Stage4-Only-42");
  await fill(page, "Your owner password", "Owner-Test-Only-42");
  await click(page, "Save staff account");
  await expect(page.getByRole("status")).toContainText("Staff account saved");
  await click(page, "Audit");
  await expect(
    page.getByRole("heading", { name: "Audit history" }),
  ).toBeVisible();
  await expect(page.locator("tbody")).toContainText("STAFF_SAVED");
  console.log(
    "PASS browser Stage 4: durable stock-operation replay after lost response, reports/receipt/CSV, staff creation and real audit history",
  );

  await click(page, "Backup & restore");
  await fill(page, "Current owner password", "Owner-Test-Only-42");
  await fill(
    page,
    "Backup passphrase (12–128 bytes)",
    "Browser-Backup-Only-42",
  );
  const backupPromise = page.waitForEvent("download");
  await click(page, "Download encrypted backup");
  const backup = await backupPromise;
  const backupPath = await backup.path();
  assert.equal(
    JSON.parse(fs.readFileSync(backupPath, "utf8")).format,
    "venuevue-encrypted-backup",
  );
  await page
    .getByLabel("Encrypted backup file", { exact: true })
    .setInputFiles({
      name: "browser.vvbackup",
      mimeType: "application/json",
      buffer: fs.readFileSync(backupPath),
    });
  // Keep a genuine durable Barista order on a second device across the restore.
  const staffContext = await browser.newContext(),
    staffPage = await staffContext.newPage();
  staffPage.on("pageerror", (e) => errors.push(e.message));
  await staffPage.goto(base);
  await login(staffPage, "testbarista", "Staff-Test-Only-42");
  let network = false;
  await staffContext.route("**/api/index.php?route=checkout", (r) =>
    network ? r.continue() : r.abort("failed"),
  );
  await click(staffPage, "Add Stage4 drink M HOT");
  await fill(staffPage, "Cash received", "50");
  await expect(
    staffPage.getByText("Draft saved on this device.", { exact: true }),
  ).toBeVisible();
  await click(staffPage, "Charge");
  await click(staffPage, "Confirm sale");
  await expect(
    staffPage.getByRole("heading", { name: "Pending orders (1)", exact: true }),
  ).toBeVisible();
  await click(page, "Preview restore");
  await expect(
    page.getByRole("heading", { name: "Review replacement" }),
  ).toBeVisible();
  await api(
    "management/save",
    { action: "product", name: "Browser after backup", category: "Test" },
    owner.token,
  );
  await fill(page, "Type RESTORE to replace current records", "RESTORE");
  await click(page, "Replace records and sign out");
  await expect(page.getByRole("alert")).toContainText("changed after preview");
  await click(page, "Preview restore");
  await expect(
    page.getByRole("heading", { name: "Review replacement" }),
  ).toBeVisible();
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage4-restore.png",
    fullPage: true,
  });
  await fill(page, "Type RESTORE to replace current records", "RESTORE");
  await click(page, "Replace records and sign out");
  await expect(
    page.getByRole("heading", { name: "Sign in to your workspace" }),
  ).toBeVisible({ timeout: 15000 });
  await login(page);
  owner = await api("login", {
    username: "testowner",
    password: "Owner-Test-Only-42",
  });
  state = await api("management", undefined, owner.token);
  assert.ok(!state.products.some((p) => p.name === "Browser after backup"));
  network = true;
  await staffPage.evaluate(() => window.dispatchEvent(new Event("online")));
  await expect(
    staffPage.getByRole("heading", { name: "Sign in to your workspace" }),
  ).toBeVisible({ timeout: 15000 });
  await login(staffPage, "testbarista", "Staff-Test-Only-42");
  await expect(
    staffPage.getByRole("heading", { name: "Order needs review" }),
  ).toBeVisible({ timeout: 15000 });
  await expect(staffPage.getByLabel("Pending orders")).toContainText(
    "restored sales",
  );
  await click(staffPage, "Mark review complete");
  await expect(
    staffPage.getByText("Restore review completed", { exact: true }),
  ).toBeVisible();
  // A truly new order uses the new epoch, even when the previous empty draft existed before restore.
  await click(staffPage, "Add Stage4 drink M HOT");
  await fill(staffPage, "Cash received", "50");
  await expect(
    staffPage.getByText("Draft saved on this device.", { exact: true }),
  ).toBeVisible();
  await click(staffPage, "Charge");
  await click(staffPage, "Confirm sale");
  await expect(
    staffPage.getByRole("heading", { name: /Sale #.* confirmed/ }),
  ).toBeVisible({ timeout: 15000 });
  await page.setViewportSize({ width: 768, height: 1024 });
  await click(page, "Backup & restore");
  assert.ok(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  );
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage4-tablet.png",
    fullPage: true,
  });
  assert.deepEqual(errors, []);
  console.log(
    "PASS browser Stage 4: encrypted download/upload, stale-preview rejection, real restore/sign-out, old pending-order review and safe new checkout after restore; tablet layout and no JS errors",
  );
} finally {
  await browser.close();
}
