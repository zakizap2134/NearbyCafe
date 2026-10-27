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
  const ctx = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
  });
  const page = await ctx.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto(base);
  await page
    .getByRole("heading", { name: "Sign in to your workspace" })
    .waitFor();
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/login.png",
    fullPage: true,
  });
  await page.getByLabel("Username", { exact: true }).fill("testowner");
  await page.getByLabel("Password", { exact: true }).fill("wrong");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.getByRole("alert")).toContainText(
    "Username or password is incorrect",
  );
  await expect(page.getByLabel("Username", { exact: true })).toHaveValue(
    "testowner",
  );
  await page.getByLabel("Password", { exact: true }).fill("Owner-Test-Only-42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page
    .getByRole("heading", { name: "Management", exact: true })
    .waitFor();
  await page.getByRole("heading", { name: "Today’s latest sales" }).waitFor();
  assert.equal(
    await page.getByRole("link", { name: "Management", exact: true }).count(),
    1,
  );
  assert.equal(
    await page.getByRole("link", { name: "Register", exact: true }).count(),
    1,
  );
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/owner.png",
    fullPage: true,
  });
  await page.reload();
  await page
    .getByRole("heading", { name: "Management", exact: true })
    .waitFor();
  await page.getByRole("link", { name: "Register", exact: true }).click();
  await page.getByRole("heading", { name: "Current order" }).waitFor();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await page
    .getByRole("heading", { name: "Sign in to your workspace" })
    .waitFor();
  await page.getByLabel("Username", { exact: true }).fill("testbarista");
  await page.getByLabel("Password", { exact: true }).fill("Staff-Test-Only-42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await page.getByRole("heading", { name: "Register", exact: true }).waitFor();
  assert.equal(
    await page.getByRole("link", { name: "Management", exact: true }).count(),
    0,
  );
  await page.goto(base + "/#/management");
  await page.waitForURL("**/#/register");
  await page.setViewportSize({ width: 768, height: 1024 });
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/barista-tablet.png",
    fullPage: true,
  });
  assert.equal(
    await page.evaluate(
      () => document.documentElement.scrollWidth > innerWidth,
    ),
    false,
  );
  await page.setViewportSize({ width: 390, height: 844 });
  assert.equal(
    await page.evaluate(
      () => document.documentElement.scrollWidth > innerWidth,
    ),
    false,
  );
  assert.deepEqual(errors, []);
  console.log(
    "PASS browser: invalid login feedback, owner login, both owner routes, refresh persistence, logout, barista-only navigation/direct-route redirect, tablet/mobile layout and no JS errors",
  );
} finally {
  await browser.close();
}
