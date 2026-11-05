/**
 * VenueVue InfinityFree Cloud Sync Bridge
 * ----------------------------------------------------------------------------
 * Autonomous Edge daemon that bridges Local MariaDB Outbox with InfinityFree
 * Cloud Hosting. Natively navigates InfinityFree's /aes.js security layer
 * via headless browser session.
 */

import { execSync } from "child_process";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";
import pkg from "file:///C:/Projects/NearbyCafe/rebuild/node_modules/@playwright/test/index.js";
const { chromium } = pkg;

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const CONFIG = {
  shop_id: "nearbycafe_intramuros_01",
  shop_name: "Nearby Café (Legaspi St., Intramuros)",
  cloud_url: "https://nearbycafe.infinityfreeapp.com",
  cloud_user: "zakizap",
  cloud_pass: "callmepapaton2x",
  batch_size: 25,
  poll_interval_seconds: 15,
  php_bin: "C:\\xampp\\php\\php.exe",
  mysql_bin: "C:\\xampp\\mysql\\bin\\mysql.exe",
  db_user: "root",
  db_pass: "",
  db_name: "venuevue_rebuild",
};

function runLocalSql(query) {
  try {
    const cmd = `"${CONFIG.mysql_bin}" -u${CONFIG.db_user} -D${CONFIG.db_name} -sN`;
    const res = execSync(cmd, { input: query, encoding: "utf8", stdio: ["pipe", "pipe", "ignore"] });
    return res.trim();
  } catch (err) {
    return "";
  }
}

function getPendingOutbox(limit = 25) {
  const q = `SELECT id, entity_type, transaction_key, payload, created_at FROM sync_outbox WHERE status = 'PENDING' ORDER BY id ASC LIMIT ${limit};`;
  const raw = runLocalSql(q);
  if (!raw) return [];

  const rows = [];
  const lines = raw.split("\n");
  for (const line of lines) {
    if (!line.trim()) continue;
    const parts = line.split("\t");
    if (parts.length >= 5) {
      let payload = {};
      try { payload = JSON.parse(parts[3]); } catch (e) { payload = { raw: parts[3] }; }
      rows.push({
        id: parseInt(parts[0], 10),
        entity_type: parts[1],
        transaction_key: parts[2],
        payload,
        created_at: parts[4],
      });
    }
  }
  return rows;
}

function markOutboxSynced(ids) {
  if (!ids || ids.length === 0) return;
  const idList = ids.join(",");
  runLocalSql(`UPDATE sync_outbox SET status = 'SYNCED', synced_at = NOW() WHERE id IN (${idList});`);
  runLocalSql(`UPDATE sync_meta SET meta_value = NOW() WHERE meta_key = 'last_successful_sync_at';`);
}

function updateLocalStatus(status) {
  runLocalSql(`UPDATE sync_meta SET meta_value = '${status}' WHERE meta_key = 'sync_status';`);
}

async function main() {
  const isOnce = process.argv.includes("--once");
  console.log("=".repeat(75));
  console.log("VENUEVUE · INFINITYFREE LIVE CLOUD SYNC BRIDGE");
  console.log(`Target Cloud: ${CONFIG.cloud_url}`);
  console.log(`Local Shop ID: ${CONFIG.shop_id}`);
  console.log(`Mode: ${isOnce ? "Single Cycle" : `Continuous Loop (${CONFIG.poll_interval_seconds}s interval)`}`);
  console.log("=".repeat(75) + "\n");

  console.log("Launching headless Edge session to establish InfinityFree security handshake...");
  const browser = await chromium.launch({
    headless: true,
    executablePath: "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe",
  });
  const context = await browser.newContext();
  const page = await context.newPage();

  let isConnected = false;

  async function checkConnection() {
    try {
      const resp = await page.goto(CONFIG.cloud_url, { waitUntil: "domcontentloaded", timeout: 12000 });
      if (resp && resp.status() === 200) {
        // Ping API Health directly from browser context
        const health = await page.evaluate(async () => {
          try {
            const r = await fetch("api/index.php?route=health");
            return { ok: r.ok, status: r.status, json: await r.json() };
          } catch (e) {
            return { ok: false, error: e.message };
          }
        });
        return { online: health.ok, data: health.json };
      }
      return { online: false, error: `HTTP ${resp ? resp.status() : "none"}` };
    } catch (err) {
      return { online: false, error: err.message };
    }
  }

  async function ensureAuthenticated() {
    const isAuth = await page.evaluate(() => {
      const tok = sessionStorage.getItem("venuevue.rebuild.session");
      return !!tok;
    });

    if (!isAuth) {
      console.log("Authenticating bridge session on InfinityFree as " + CONFIG.cloud_user + "...");
      await page.goto(CONFIG.cloud_url + "/#/login", { waitUntil: "domcontentloaded", timeout: 25000 });
      await page.waitForTimeout(2000);
      const userInput = page.locator('input[autocomplete="username"]');
      if (await userInput.isVisible()) {
        await userInput.fill(CONFIG.cloud_user);
        await page.locator('#login-password').fill(CONFIG.cloud_pass);
        await page.click('button.primary.full, button[type="submit"]');
        await page.waitForSelector(".workspace-header", { timeout: 20000 });
        console.log("Authenticated bridge session successfully!");
      }
    }
  }

  async function pullDownstreamCatalogue() {
    try {
      const token = await page.evaluate(() => sessionStorage.getItem("venuevue.rebuild.session"));
      if (!token) return;

      const cloudPayload = await page.evaluate(async (tok) => {
        try {
          const res = await fetch("api/index.php?route=management", {
            headers: {
              "Authorization": `Bearer ${tok}`,
              "X-Session-Token": tok
            }
          });
          return await res.json();
        } catch (e) {
          return null;
        }
      }, token);

      if (cloudPayload && cloudPayload.ok && cloudPayload.data) {
        const tmpJson = path.join(__dirname, "last_cloud_catalogue.json");
        fs.writeFileSync(tmpJson, JSON.stringify(cloudPayload, null, 2), "utf8");
        const phpScript = path.join(__dirname, "sync_cloud_to_local.php");
        execSync(`"${CONFIG.php_bin}" "${phpScript}" "${tmpJson}"`, { stdio: "pipe" });
        console.log(`[SYNC DOWN] Pulled & Synchronized ${cloudPayload.data.products?.length || 0} products & ${cloudPayload.data.recipes?.length || 0} recipes from Cloud to Local.`);
      }
    } catch (e) {
      console.log("[SYNC DOWN] Note: " + e.message);
    }
  }

  async function syncCycle() {
    const timeStr = new Date().toLocaleTimeString();
    const pendingCount = parseInt(runLocalSql("SELECT COUNT(*) FROM sync_outbox WHERE status = 'PENDING';") || "0", 10);

    const conn = await checkConnection();

    if (!conn.online) {
      updateLocalStatus("OFFLINE");
      console.log(`[${timeStr}] [OFFLINE] Internet or InfinityFree unreachable (${conn.error || "Timeout"}).`);
      console.log(`           Counter is 100% operational. Outbox queue holding ${pendingCount} pending events.\n`);
      return;
    }

    updateLocalStatus("ONLINE");

    // Authenticate and pull latest cloud recipes/catalogue down to local
    await ensureAuthenticated();
    await pullDownstreamCatalogue();

    if (pendingCount === 0) {
      console.log(`[${timeStr}] [ONLINE - IDLE] InfinityFree Live & In Sync (Epoch: ${conn.data?.data?.epoch?.slice(0, 8)}...).`);
      console.log(`           Outbox is clean (0 pending events).\n`);
      return;
    }

    console.log(`[${timeStr}] [ONLINE - SYNCING] InfinityFree Live! Found ${pendingCount} pending events to sync...`);

    const batch = getPendingOutbox(CONFIG.batch_size);
    if (batch.length === 0) return;

    // Send batch through authenticated browser session
    const syncResult = await page.evaluate(async (records) => {
      const token = sessionStorage.getItem("venuevue.rebuild.session");
      const syncedIds = [];
      const failedIds = [];

      for (const rec of records) {
        if (rec.entity_type === "SALE" && rec.payload) {
          try {
            const r = await fetch("api/index.php?route=checkout", {
              method: "POST",
              headers: {
                "Content-Type": "application/json",
                "Authorization": `Bearer ${token}`,
                "X-Session-Token": token
              },
              body: JSON.stringify(rec.payload)
            });
            const resJson = await r.json().catch(() => null);
            if (r.ok || (resJson && resJson.ok)) {
              syncedIds.push(rec.id);
            } else {
              failedIds.push({ id: rec.id, error: resJson?.error?.message || `HTTP ${r.status}` });
            }
          } catch (e) {
            failedIds.push({ id: rec.id, error: e.message });
          }
        }
      }

      return { ok: syncedIds.length > 0, syncedIds, failedIds };
    }, batch);

    if (syncResult.syncedIds && syncResult.syncedIds.length > 0) {
      markOutboxSynced(syncResult.syncedIds);
      console.log(`[${timeStr}] [SYNC SUCCESS] Pushed ${syncResult.syncedIds.length} live sales to InfinityFree Cloud!`);
      console.log(`           Transactions registered in live cloud database.\n`);
    }
    if (syncResult.failedIds && syncResult.failedIds.length > 0) {
      console.log(`[${timeStr}] [SYNC NOTICE] ${syncResult.failedIds.length} records deferred:`, syncResult.failedIds);
    }
  }

  try {
    do {
      await syncCycle();
      if (isOnce) break;
      await new Promise((r) => setTimeout(r, CONFIG.poll_interval_seconds * 1000));
    } while (true);
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error("Bridge failure:", err);
  process.exit(1);
});
