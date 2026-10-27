import { chromium, expect } from "@playwright/test";
import assert from "node:assert/strict";
import fs from "node:fs";
const base = process.argv[2] ?? "http://127.0.0.1:18081";
const browser = await chromium.launch({
  headless: true,
  executablePath: process.env.VV_CHROMIUM_PATH || undefined,
  args: ["--no-sandbox"],
});
const errors = [];
fs.mkdirSync("/tmp/venue-rebuild-ui", { recursive: true });
try {
  const context = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
    }),
    page = await context.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  async function click(name) {
    await page.getByRole("button", { name, exact: true }).click();
  }
  async function fill(label, value) {
    await page.getByLabel(label, { exact: true }).fill(value);
  }
  async function select(label, name) {
    await page.getByLabel(label, { exact: true }).selectOption({ label: name });
  }
  async function saved() {
    await expect(page.getByRole("status")).toContainText("Saved successfully");
  }
  async function login(name, password) {
    await fill("Username", name);
    await fill("Password", password);
    const response = page.waitForResponse(r => new URL(r.url()).searchParams.get("route") === "login" && r.status() === 200);
    await click("Sign in"); await response;
    await expect(page.locator(".role")).toContainText(name === "testowner" ? "Owner" : "Barista");
  }
  await page.goto(base);
  await login("testowner", "Owner-Test-Only-42");
  await click("Ingredients");
  await fill("Ingredient name", "Browser milk");
  await fill("Low-stock threshold", "100");
  await fill("Opened shelf life (hours; blank if not applicable)", "48");
  await click("Save ingredient");
  await saved();
  await click("Catalogue");
  await fill("Product name", "Browser Latte");
  await click("Save product");
  await saved();
  await select("Variant product", "Browser Latte");
  await fill("SKU", "BROWSER-LATTE-M");
  await fill("VAT-inclusive price", "150.00");
  await click("Save variant");
  await saved();
  await click("Recipes");
  await select("Recipe variant", "Browser Latte M HOT · BROWSER-LATTE-M");
  await click("Add recipe row");
  await select("Ingredient 1", "Browser milk (mL)");
  await fill("Quantity 1", "220");
  await click("Save recipe");
  await saved();
  await click("Stock");
  await select("Delivery ingredient", "Browser milk (mL)");
  await fill("Delivery reference", "Browser delivery");
  await fill("Container count", "2");
  await fill("Quantity per container (base units)", "1000");
  await fill("Expiration date", "2099-12-31");
  await fill("Lot reference (optional)", "UI-LOT");
  await click("Receive delivery");
  await saved();
  await expect(
    page.getByRole("row").filter({ hasText: "Browser delivery" }).first(),
  ).toContainText("2000.000");
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage2-stock.png",
    fullPage: true,
  });
  await click("Sign out");
  await login("testbarista", "Staff-Test-Only-42");
  await click("Add Browser Latte M HOT");
  await click("Add one Browser Latte M HOT");
  await fill("Cash received", "500");
  await click("Charge");
  await expect(page.getByLabel("Review payment")).toContainText("200.00");
  // Let the host commit, then drop its response: reload must retry the same UUID.
  let committed;
  await page.route(
    "**/api/index.php?route=checkout",
    async (route) => {
      const response = await route.fetch();
      committed = await response.json();
      await route.abort("failed");
    },
    { times: 1 },
  );
  await click("Confirm sale");
  await expect(
    page.getByRole("heading", { name: "Awaiting host confirmation" }),
  ).toBeVisible();
  await expect(page.getByRole("alert")).toContainText("Cannot reach");
  assert.equal(committed.ok, true);
  await page.reload();
  await expect(
    page.getByRole("heading", { name: `Sale #${committed.data.id} confirmed` }),
  ).toBeVisible();
  const receipt = page.getByLabel("Sale receipt");
  await expect(receipt).toContainText("267.86");
  await expect(receipt).toContainText("32.14");
  await expect(receipt).toContainText("200.00");
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage2-register.png",
    fullPage: true,
  });
  await page.setViewportSize({ width: 768, height: 1024 });
  assert.equal(
    await page.evaluate(
      () => document.documentElement.scrollWidth > innerWidth,
    ),
    false,
  );
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage2-tablet.png",
    fullPage: true,
  });
  await click("Sign out");
  await login("testowner", "Owner-Test-Only-42");
  await page.getByRole("link", { name: "Management", exact: true }).click();
  await click("Ingredients");
  await expect(
    page.getByRole("row").filter({ hasText: "Browser milk" }),
  ).toContainText("1560.000");
  await click("Stock");
  await expect(
    page.getByRole("row").filter({ hasText: "Browser delivery" }).first(),
  ).toContainText("560.000");
  await click("Overview");
  await click(`View sale #${committed.data.id}`);
  await expect(page.getByLabel("Sale receipt")).toContainText("300.00");
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage2-overview.png",
    fullPage: true,
  });
  assert.deepEqual(errors, []);
  console.log(
    "PASS browser Stage 2: owner creates ingredient/product/variant/recipe/delivery; barista cash sale; lost committed response survives reload and replays once; VAT/change receipt, owner stock/totals and tablet layout",
  );
} finally {
  await browser.close();
}
