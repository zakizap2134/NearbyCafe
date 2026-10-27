import { chromium, expect } from "@playwright/test";
import assert from "node:assert/strict";
import fs from "node:fs";
const base = process.argv[2] ?? "http://127.0.0.1:18081";
const browser = await chromium.launch({
  headless: true,
  executablePath: process.env.VV_CHROMIUM_PATH || undefined,
  args: ["--no-sandbox"],
});
fs.mkdirSync("/tmp/venue-rebuild-ui", { recursive: true });
try {
  const context = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
    }),
    page = await context.newPage();
  const errors = [],
    external = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("request", (r) => {
    if (new URL(r.url()).origin !== new URL(base).origin)
      external.push(r.url());
  });
  await page.goto(base);
  await expect(page).toHaveTitle("Nearby Café");
  await expect(
    page.getByRole("heading", { name: "Sign in to your workspace" }),
  ).toBeVisible();
  const logo = page.locator(".login-story img");
  await expect(logo).toHaveAttribute("alt", "Nearby Café");
  await expect
    .poll(() => logo.evaluate((i) => i.complete && i.naturalWidth > 0))
    .toBe(true);
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage5-login.png",
    fullPage: true,
  });
  await page.getByLabel("Username", { exact: true }).fill("testowner");
  await page.getByLabel("Password", { exact: true }).fill("Owner-Test-Only-42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Management", exact: true }),
  ).toBeVisible();
  await expect(page.locator(".workspace-header img")).toBeVisible();
  await expect(page.locator("body")).not.toContainText("TravelBean");
  for (const width of [1440, 768, 390]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.getByRole("button", { name: "Reports", exact: true }).click();
    await expect(
      page.getByRole("heading", { name: "Sales reports" }),
    ).toBeVisible();
    assert.ok(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      "Horizontal page overflow",
    );
    await page.screenshot({
      path: `/tmp/venue-rebuild-ui/stage5-reports-${width}.png`,
      fullPage: true,
    });
  }
  await page.getByRole("link", { name: "Register", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Current order" }),
  ).toBeVisible();
  assert.ok(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  );
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage5-register-mobile.png",
    fullPage: true,
  });
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await expect(page.locator(".mobile-brand img")).toBeVisible();
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage5-login-mobile.png",
    fullPage: true,
  });
  assert.deepEqual(errors, []);
  assert.deepEqual(external, []);
  console.log(
    "PASS Stage 5: supplied Nearby Café logo loads locally, updated title/branding, desktop/tablet/mobile layouts, Register/Management navigation, no external asset requests or JS errors",
  );
} finally {
  await browser.close();
}
