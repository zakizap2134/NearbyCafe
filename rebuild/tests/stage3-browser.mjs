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
const owner = await api("login", {
  username: "testowner",
  password: "Owner-Test-Only-42",
});
const save = async (action, values) =>
  (await api("management/save", { action, ...values }, owner.token)).id;
const milk = await save("ingredient", {
  name: "Reliability milk",
  unit: "mL",
  minimum_stock: "100",
  opened_shelf_life_hours: 48,
});
const product = await save("product", {
  name: "Reliability Latte",
  category: "Coffee",
});
const variant = await save("variant", {
  product_id: product,
  size: "M",
  temperature: "HOT",
  sku: "RELIABILITY-M",
  price: "50.00",
});
await save("recipe", {
  variant_id: variant,
  rows: [{ ingredient_id: milk, qty: "100" }],
});
await save("receive", {
  ingredient_id: milk,
  delivery_key: crypto.randomUUID(),
  container_count: 5,
  quantity_per_container: "1000",
  expiration_date: "2099-12-31",
  reference: "Reliability stock",
});
const browser = await chromium.launch({
  headless: true,
  executablePath: process.env.VV_CHROMIUM_PATH || undefined,
  args: ["--no-sandbox"],
});
const errors = [];
try {
  const context = await browser.newContext({
      viewport: { width: 1280, height: 960 },
    }),
    page = await context.newPage();
  context.on("page", (p) => p.on("pageerror", (e) => errors.push(e.message)));
  page.on("pageerror", (e) => errors.push(e.message));
  async function click(p, name) {
    await p.getByRole("button", { name, exact: true }).click();
  }
  async function fill(p, label, value) {
    await p.getByLabel(label, { exact: true }).fill(value);
  }
  async function login(
    p,
    name = "testbarista",
    password = "Staff-Test-Only-42",
  ) {
    await fill(p, "Username", name);
    await fill(p, "Password", password);
    await click(p, "Sign in");
  }
  async function order(p) {
    await click(p, "Add Reliability Latte M HOT");
    await fill(p, "Cash received", "100");
    await expect(
      p.getByText("Draft saved on this device.", { exact: true }),
    ).toBeVisible();
    await click(p, "Charge");
    await click(p, "Confirm sale");
  }
  async function queue(p) {
    return p.evaluate(
      () =>
        new Promise((resolve, reject) => {
          // Observe only: the application owns schema upgrades.
          const r = indexedDB.open("venuevue-rebuild-checkout");
          r.onsuccess = () => {
            const db = r.result;
            if(!db.objectStoreNames.contains('queue')){db.close();resolve([]);return;}
            const tx = db.transaction("queue");
            const q = tx.objectStore("queue").getAll();
            q.onsuccess = () => resolve(q.result);
            tx.oncomplete = () => db.close();
          };
          r.onerror = () => reject(r.error);
        }),
    );
  }
  await page.goto(base);
  await login(page);
  await click(page, "Add Reliability Latte M HOT");
  await fill(page, "Cash received", "100");
  await expect(
    page.getByText("Draft saved on this device.", { exact: true }),
  ).toBeVisible();
  await page.reload();
  await expect(page.getByLabel("Cash received", { exact: true })).toHaveValue(
    "100",
  );
  await expect(
    page.getByRole("button", { name: "Add one Reliability Latte M HOT" }),
  ).toBeVisible();
  let available = false;
  await context.route("**/api/index.php?route=checkout", (route) =>
    available ? route.continue() : route.abort("failed"),
  );
  await click(page, "Charge");
  await click(page, "Confirm sale");
  await expect(
    page.getByRole("heading", { name: "Pending orders (1)", exact: true }),
  ).toBeVisible();
  await order(page);
  await expect(
    page.getByRole("heading", { name: "Pending orders (2)", exact: true }),
  ).toBeVisible();
  const pending = await queue(page);
  assert.equal(pending.length, 2);
  assert.ok(pending.every((o) => o.state === "submitted"));
  const keys = pending.map((o) => o.key).sort();
  await click(page, "Sign out");
  await login(page, "testowner", "Owner-Test-Only-42");
  await page.getByRole("link", { name: "Register", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Current order" }),
  ).toBeVisible();
  await expect(page.getByLabel("Pending orders")).toHaveCount(0);
  await expect(page.getByLabel("Cash received", { exact: true })).toHaveValue(
    "",
  );
  await click(page, "Sign out");
  await login(page);
  await expect(
    page.getByRole("heading", { name: "Pending orders (2)", exact: true }),
  ).toBeVisible();
  const second = await context.newPage();
  await second.goto(base);
  await login(second);
  await expect(
    second.getByRole("heading", { name: "Pending orders (2)", exact: true }),
  ).toBeVisible();
  available = true;
  await Promise.all([
    page.evaluate(() => window.dispatchEvent(new Event("online"))),
    second.evaluate(() => window.dispatchEvent(new Event("online"))),
  ]);
  await expect
    .poll(
      async () =>
        (await queue(page)).filter((o) => o.state === "confirmed").length,
      { timeout: 15000 },
    )
    .toBe(2);
  assert.deepEqual((await queue(page)).map((o) => o.key).sort(), keys);
  const state = await api("management", undefined, owner.token);
  assert.equal(
    state.ingredients.find((i) => Number(i.id) === milk).current_stock,
    "4800.000",
  );
  await second.close();
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Your orders on this device" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: /View receipt #/ }),
  ).toHaveCount(2);
  console.log(
    "PASS browser Stage 3: persisted draft, two queued orders, account isolation and simultaneous-tab automatic reconnection without duplicate stock deductions",
  );

  // A known rejection becomes review and stays terminal after later menu corrections.
  available = false;
  await order(page);
  await expect(
    page.getByRole("heading", { name: "Pending orders (1)", exact: true }),
  ).toBeVisible();
  await save("variant", {
    id: variant,
    product_id: product,
    size: "M",
    temperature: "HOT",
    sku: "RELIABILITY-M",
    price: "60.00",
  });
  available = true;
  await page.evaluate(() => window.dispatchEvent(new Event("online")));
  await expect(
    page.getByRole("heading", { name: "Order needs review" }),
  ).toBeVisible();
  await expect(page.getByLabel("Pending orders")).toContainText(
    "Price changed",
  );
  await click(page, "Mark review complete");
  await expect(page.getByLabel("Pending orders")).toHaveCount(0);
  await page.reload();
  await expect(
    page.getByText("Reviewed; no sale", { exact: true }),
  ).toBeVisible();
  // Another tab cannot overwrite a draft which has already been edited elsewhere.
  const other = await context.newPage();
  await other.goto(base);
  await login(other);
  await expect(
    other.getByText("Draft saved on this device.", { exact: true }),
  ).toBeVisible();
  await click(page, "Add Reliability Latte M HOT");
  await expect(
    page.getByText("Draft saved on this device.", { exact: true }),
  ).toBeVisible();
  await click(other, "Add Reliability Latte M HOT");
  await expect(other.getByRole("alert")).toContainText(
    "changed in another tab",
  );
  await expect(
    other.getByRole("button", { name: "Charge", exact: true }),
  ).toBeDisabled();
  await other.close();
  await page.screenshot({
    path: "/tmp/venue-rebuild-ui/stage3-register.png",
    fullPage: true,
  });
  console.log(
    "PASS browser Stage 3: stale-price review retained across reload and competing draft edit blocked",
  );

  // Owner uses the actual reset screen while a Barista has a queued order.
  available = false;
  await fill(page, "Cash received", "100");
  await click(page, "Charge");
  await click(page, "Confirm sale");
  await expect(
    page.getByRole("heading", { name: "Pending orders (1)", exact: true }),
  ).toBeVisible();
  const ownerContext = await browser.newContext(),
    ownerPage = await ownerContext.newPage();
  await ownerPage.goto(base);
  await login(ownerPage, "testowner", "Owner-Test-Only-42");
  await click(ownerPage, "Account security");
  await ownerPage
    .getByLabel("Barista account", { exact: true })
    .selectOption({ label: "Test Barista (testbarista)" });
  await fill(ownerPage, "Your owner password", "Owner-Test-Only-42");
  await fill(ownerPage, "New Barista password", "Staff-Browser-Reset-42");
  await fill(
    ownerPage,
    "Confirm new Barista password",
    "Staff-Browser-Reset-42",
  );
  await click(ownerPage, "Reset Barista password");
  await expect(ownerPage.getByRole("status").filter({hasText:"Password reset"})).toContainText("Password reset");
  await click(page, "Refresh menu");
  await expect(
    page.getByRole("heading", { name: "Sign in to your workspace" }),
  ).toBeVisible();
  await login(page, "testbarista", "Staff-Browser-Reset-42");
  await expect(
    page.getByRole("heading", { name: "Pending orders (1)", exact: true }),
  ).toBeVisible();
  available = true;
  await page.evaluate(() => window.dispatchEvent(new Event("online")));
  await expect
    .poll(
      async () =>
        (await queue(page)).filter((o) => o.state === "confirmed").length,
      { timeout: 15000 },
    )
    .toBe(3);
  await ownerPage.screenshot({
    path: "/tmp/venue-rebuild-ui/stage3-security.png",
    fullPage: true,
  });
  const staff = (
    await api("management/staff", undefined, owner.token)
  ).staff.find((s) => s.username === "testbarista");
  await api(
    "management/reset-password",
    {
      user_id: staff.id,
      owner_password: "Owner-Test-Only-42",
      new_password: "Staff-Test-Only-42",
      confirm_password: "Staff-Test-Only-42",
    },
    owner.token,
  );
  assert.deepEqual(errors, []);
  console.log(
    "PASS browser Stage 3: owner password-reset UI revokes staff session; queued order survives and confirms after same-account sign-in",
  );

  // Upgrade actual Stage 2 IndexedDB data; no pending record is discarded.
  const legacyContext = await browser.newContext(),
    legacy = await legacyContext.newPage();
  await legacy.goto(base);
  const importedKey = crypto.randomUUID();
  await legacy.evaluate(
    ({ key, uid, variant }) =>
      new Promise((resolve, reject) => {
        const r = indexedDB.open("venuevue-rebuild-checkout", 1);
        r.onupgradeneeded = () =>
          r.result.createObjectStore("orders", { keyPath: "user_id" });
        r.onsuccess = () => {
          const db = r.result;
          const tx = db.transaction("orders", "readwrite");
          tx.objectStore("orders").put({
            user_id: uid,
            state: "submitted",
            labels: [
              {
                variant_id: variant,
                label: "Imported Reliability Latte",
                qty: 1,
                price: "60.00",
              },
            ],
            body: {
              transaction_key: key,
              cash_received: "100.00",
              items: [{ variant_id: variant, qty: 1, expected_price: "60.00" }],
            },
          });
          tx.oncomplete = () => {
            db.close();
            resolve();
          };
          tx.onerror = () => reject(tx.error);
        };
      }),
    { key: importedKey, uid: Number(staff.id), variant },
  );
  await login(legacy);
  await expect(
    legacy.getByText("Draft saved on this device.", { exact: true }),
  ).toBeVisible();
  await expect
    .poll(
      async () =>
        (await queue(legacy)).find((o) => o.key === importedKey)?.state,
      { timeout: 15000 },
    )
    .toBe("confirmed");
  console.log(
    "PASS browser Stage 3: Stage 2 pending order migrates to queue v2 and reconciles with its original transaction key",
  );
  const blockedContext = await browser.newContext();
  await blockedContext.addInitScript(() =>
    Object.defineProperty(window, "indexedDB", {
      get() {
        throw new Error("Storage deliberately blocked");
      },
    }),
  );
  const blocked = await blockedContext.newPage();
  let submitted = 0;
  blocked.on("request", (r) => {
    if (r.url().includes("route=checkout")) submitted++;
  });
  await blocked.goto(base);
  await login(blocked);
  await expect(blocked.getByRole("alert")).toContainText(
    "Storage deliberately blocked",
  );
  await expect(
    blocked.getByRole("button", { name: "Charge", exact: true }),
  ).toBeDisabled();
  assert.equal(submitted, 0);
  console.log(
    "PASS browser Stage 3: blocked device storage prevents unsafe checkout submission",
  );
} finally {
  await browser.close();
}
