/**
 * Nearby Café Bidirectional Sync Bridge
 * ----------------------------------------------------------------------------
 * Autonomous headless browser daemon synchronizing Local MariaDB <-> Cloud API
 * in both directions using natural keys, outbox/inbox deduplication, and
 * periodic two-way snapshot reconciliation.
 */

import { spawnSync } from "child_process";
import fs from "fs";
import { createRequire } from "module";
import path from "path";
import { fileURLToPath, pathToFileURL } from "url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

async function loadChromium() {
  const candidates = [
    path.resolve(__dirname, "../../rebuild/node_modules/@playwright/test/index.js"),
    path.resolve(__dirname, "../../node_modules/@playwright/test/index.js"),
    path.resolve(__dirname, "../../rebuild/node_modules/playwright/index.mjs"),
    path.resolve(__dirname, "../../node_modules/playwright/index.mjs"),
  ];
  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) {
      const mod = await import(pathToFileURL(candidate).href);
      const pw = mod?.default || mod;
      if (pw?.chromium) {
        return pw.chromium;
      }
    }
  }
  const req = createRequire(import.meta.url);
  for (const pkgName of ["@playwright/test", "playwright", "playwright-core"]) {
    try {
      const pw = req(pkgName);
      if (pw?.chromium) {
        return pw.chromium;
      }
    } catch {}
  }
  throw new Error(
    "Playwright is not installed. Run 'npm --prefix rebuild ci' (or One-Click-Setup) on this device first.",
  );
}

const WORKER_SCRIPT = path.resolve(__dirname, "sync_local_worker.php");
const DEFAULT_PID_FILE = path.resolve(__dirname, "../../rebuild/storage/launcher/sync_bridge.pid");
const DEFAULT_LOG_FILE = path.resolve(__dirname, "../../rebuild/storage/launcher/sync_bridge.log");
const PID_FILE = process.env.VV_SYNC_PID_FILE || DEFAULT_PID_FILE;
const LOG_FILE = process.env.VV_SYNC_LOG_FILE || DEFAULT_LOG_FILE;

function appendLog(line) {
  try {
    fs.mkdirSync(path.dirname(LOG_FILE), { recursive: true });
    if (fs.existsSync(LOG_FILE) && fs.statSync(LOG_FILE).size > 1024 * 1024) {
      const backup = `${LOG_FILE}.1`;
      if (fs.existsSync(backup)) fs.unlinkSync(backup);
      fs.renameSync(LOG_FILE, backup);
    }
    fs.appendFileSync(LOG_FILE, line + "\n", "utf8");
  } catch {}
}

function logInfo(msg) {
  const line = `[${new Date().toISOString()}] ${msg}`;
  console.log(line);
  appendLog(line);
}

function logErr(msg) {
  const line = `[${new Date().toISOString()}] [ERROR] ${msg}`;
  console.error(line);
  appendLog(line);
}

function acquireInstanceLock() {
  if (fs.existsSync(PID_FILE)) {
    try {
      const existingPid = parseInt(fs.readFileSync(PID_FILE, "utf8").trim(), 10);
      if (existingPid && existingPid !== process.pid) {
        try {
          process.kill(existingPid, 0);
          logInfo(`[SYNC BRIDGE] Daemon already running (PID: ${existingPid}). Exiting duplicate.`);
          process.exit(0);
        } catch {
          // Stale PID file
        }
      }
    } catch {}
  }
  try {
    fs.mkdirSync(path.dirname(PID_FILE), { recursive: true });
    fs.writeFileSync(PID_FILE, String(process.pid), "utf8");
  } catch {}
}

let activeBrowser = null;

function releaseInstanceLock() {
  try {
    if (fs.existsSync(PID_FILE)) {
      const savedPid = parseInt(fs.readFileSync(PID_FILE, "utf8").trim(), 10);
      if (savedPid === process.pid) {
        fs.unlinkSync(PID_FILE);
      }
    }
  } catch {}
}

async function cleanShutdown() {
  releaseInstanceLock();
  if (activeBrowser) {
    try { await activeBrowser.close(); } catch {}
  }
  process.exit(0);
}

process.on("exit", releaseInstanceLock);
process.on("SIGINT", cleanShutdown);
process.on("SIGTERM", cleanShutdown);

function resolvePhpBin() {
  if (process.env.VV_SYNC_PHP_BIN && fs.existsSync(process.env.VV_SYNC_PHP_BIN)) {
    return process.env.VV_SYNC_PHP_BIN;
  }
  const launcherCfgPath = path.resolve(__dirname, "../../rebuild/config/launcher.local.json");
  if (fs.existsSync(launcherCfgPath)) {
    try {
      const parsed = JSON.parse(fs.readFileSync(launcherCfgPath, "utf8"));
      if (parsed?.Php && fs.existsSync(String(parsed.Php))) {
        return String(parsed.Php);
      }
    } catch {}
  }
  const candidates = [
    "C:\\xampp\\php\\php.exe",
    "D:\\xampp\\php\\php.exe",
    "C:\\Program Files\\xampp\\php\\php.exe",
  ];
  for (const exe of candidates) {
    if (fs.existsSync(exe)) return exe;
  }
  return process.env.VV_SYNC_PHP_BIN || "php";
}

function loadConfig() {
  const cfg = {
    shop_id: "nearbycafe_intramuros_01",
    shop_name: "Nearby Café",
    cloud_url: "https://nearbycafe.infinityfreeapp.com",
    cloud_user: "",
    cloud_pass: "",
    batch_size: 25,
    poll_interval_seconds: 15,
    reconcile_every_cycles: 20,
    php_bin: resolvePhpBin(),
  };

  const cloudCfgPath = process.env.VV_SYNC_CLOUD_CONFIG || path.resolve(__dirname, "../../rebuild/config/cloud.local.json");
  if (fs.existsSync(cloudCfgPath)) {
    try {
      const parsed = JSON.parse(fs.readFileSync(cloudCfgPath, "utf8"));
      if (parsed.cloud_url) cfg.cloud_url = String(parsed.cloud_url).trim().replace(/\/+$/, "");
      if (parsed.cloud_user) cfg.cloud_user = String(parsed.cloud_user).trim();
      if (parsed.cloud_pass) cfg.cloud_pass = String(parsed.cloud_pass).trim();
      if (parsed.shop_id) cfg.shop_id = String(parsed.shop_id).trim();
      if (parsed.shop_name) cfg.shop_name = String(parsed.shop_name).trim();
    } catch (err) {
      logErr(`Could not parse cloud.local.json: ${err.message}`);
    }
  }

  if (process.env.VV_SYNC_CLOUD_URL) cfg.cloud_url = process.env.VV_SYNC_CLOUD_URL.trim().replace(/\/+$/, "");
  if (process.env.VV_SYNC_CLOUD_USER) cfg.cloud_user = process.env.VV_SYNC_CLOUD_USER.trim();
  if (process.env.VV_SYNC_CLOUD_PASS) cfg.cloud_pass = process.env.VV_SYNC_CLOUD_PASS.trim();
  if (process.env.VV_SYNC_SHOP_ID) cfg.shop_id = process.env.VV_SYNC_SHOP_ID.trim();

  for (const arg of process.argv.slice(2)) {
    if (arg.startsWith("--cloud-url=")) cfg.cloud_url = arg.slice(12).trim().replace(/\/+$/, "");
    if (arg.startsWith("--interval=")) {
      const n = parseInt(arg.slice(11), 10);
      if (Number.isFinite(n) && n >= 2) cfg.poll_interval_seconds = n;
    }
  }
  return cfg;
}

const CONFIG = loadConfig();

function runWorker(command, inputObj = {}, extraArgs = []) {
  const res = spawnSync(
    CONFIG.php_bin,
    [WORKER_SCRIPT, command, ...extraArgs],
    {
      input: JSON.stringify(inputObj),
      encoding: "utf8",
      maxBuffer: 32 * 1024 * 1024,
      env: process.env,
    },
  );
  if (res.error) {
    throw res.error;
  }
  if (res.status !== 0) {
    const errText = (res.stderr || res.stdout || `Worker exited with ${res.status}`).trim();
    throw new Error(`Worker '${command}' failed: ${errText}`);
  }
  const out = (res.stdout || "").trim();
  if (!out) {
    throw new Error(`Worker '${command}' returned empty output.`);
  }
  return JSON.parse(out);
}

function findBrowserExecutable() {
  const localApp = process.env.LOCALAPPDATA || "";
  const candidates = [
    "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe",
    "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
    localApp ? path.join(localApp, "Microsoft\\Edge\\Application\\msedge.exe") : "",
    "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
    "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
    localApp ? path.join(localApp, "Google\\Chrome\\Application\\chrome.exe") : "",
  ].filter(Boolean);
  for (const exe of candidates) {
    if (fs.existsSync(exe)) return exe;
  }
  return undefined;
}

function formatSqlDate(dt = new Date()) {
  const pad = (n) => String(n).padStart(2, "0");
  return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())} ${pad(dt.getHours())}:${pad(dt.getMinutes())}:${pad(dt.getSeconds())}`;
}

async function main() {
  const args = process.argv.slice(2);
  const isOnce = args.includes("--once");
  const forceReconcile = args.includes("--reconcile");
  if (!isOnce) {
    acquireInstanceLock();
  }

  logInfo(`Starting bidirectional sync bridge -> ${CONFIG.cloud_url} (shop: ${CONFIG.shop_id})`);

  const chromium = await loadChromium();
  const exePath = findBrowserExecutable();
  const launchOpts = { headless: true };
  if (exePath) launchOpts.executablePath = exePath;

  const browser = await chromium.launch(launchOpts);
  activeBrowser = browser;
  const context = await browser.newContext();
  const page = await context.newPage();

  let pageReady = false;
  let sessionToken = null;
  let lastOnlineState = null;
  let cycleCount = 0;
  let consecutiveFailures = 0;

  async function ensurePageLoaded(forceReload = false) {
    if (!forceReload && pageReady && page.url().startsWith(CONFIG.cloud_url)) {
      return;
    }
    const resp = await page.goto(CONFIG.cloud_url + "/", { waitUntil: "domcontentloaded", timeout: 25000 });
    if (!resp) {
      throw new Error("No HTTP response from cloud target.");
    }
    await page.waitForTimeout(300);
    pageReady = page.url().startsWith(CONFIG.cloud_url);
  }

  async function fetchApiJson(route, method = "GET", bodyObj = null) {
    return await page.evaluate(
      async ({ route, method, bodyObj, token }) => {
        const headers = {};
        if (method === "POST") headers["Content-Type"] = "application/json";
        if (token) {
          headers["Authorization"] = `Bearer ${token}`;
          headers["X-Session-Token"] = token;
        }
        const opts = { method, headers, credentials: "same-origin" };
        if (method === "POST") opts.body = JSON.stringify(bodyObj ?? {});
        const res = await fetch(`api/index.php?route=${route}`, opts);
        const text = await res.text();
        try {
          return { status: res.status, json: JSON.parse(text), isHtml: false };
        } catch {
          return { status: res.status, json: null, isHtml: text.includes("<html") || text.includes("/aes.js"), raw: text.slice(0, 200) };
        }
      },
      { route, method, bodyObj, token: sessionToken },
    );
  }

  async function authenticate() {
    if (!CONFIG.cloud_user || !CONFIG.cloud_pass) {
      throw new Error(
        "Cloud sync is not linked on this device yet. Run sync-engine\\Configure_Cloud_Account.bat to save your Cloud URL, username, and password in rebuild/config/cloud.local.json.",
      );
    }
    await ensurePageLoaded();
    let res = await fetchApiJson("login", "POST", {
      username: CONFIG.cloud_user,
      password: CONFIG.cloud_pass,
    });
    if (res.isHtml) {
      await ensurePageLoaded(true);
      res = await fetchApiJson("login", "POST", {
        username: CONFIG.cloud_user,
        password: CONFIG.cloud_pass,
      });
    }
    const token = res?.json?.data?.token;
    if (!res?.json?.ok || typeof token !== "string" || token.length < 32) {
      const msg = res?.json?.error?.message || res?.raw || `HTTP ${res?.status}`;
      throw new Error(`Cloud login failed: ${msg}`);
    }
    sessionToken = token;
    await page.evaluate((t) => {
      try { sessionStorage.setItem("venuevue.rebuild.session", t); } catch {}
    }, token);
    logInfo(`Authenticated cloud session as ${CONFIG.cloud_user} (role: ${res.json.data.user?.role || "?"}).`);
  }

  async function ensureAuthenticated() {
    if (!sessionToken) {
      await authenticate();
      return;
    }
    const check = await fetchApiJson("session", "GET");
    if (check.isHtml) {
      await ensurePageLoaded(true);
      sessionToken = null;
      await authenticate();
      return;
    }
    if (check.status === 401 || !check?.json?.ok || check?.json?.data?.user?.role !== "OWNER") {
      sessionToken = null;
      await authenticate();
    }
  }

  async function callCloudApi(route, { method = "GET", body = null, retry = true } = {}) {
    await ensurePageLoaded();
    let res = await fetchApiJson(route, method, body);
    if (res.isHtml && retry) {
      logInfo("Cloud anti-bot challenge refreshed; reloading session...");
      await ensurePageLoaded(true);
      sessionToken = null;
      await authenticate();
      res = await fetchApiJson(route, method, body);
    }
    if ((res.status === 401 || res.status === 403) && retry) {
      sessionToken = null;
      await authenticate();
      res = await fetchApiJson(route, method, body);
    }
    if (!res?.json?.ok) {
      const msg = res?.json?.error?.message || res?.raw || `HTTP ${res?.status}`;
      throw new Error(`Cloud API '${route}' failed (${res?.status}): ${msg}`);
    }
    return res.json;
  }

  async function downloadProductImage(filename) {
    const res = await page.evaluate(async (file) => {
      const r = await fetch(`api/index.php?route=product/image&file=${encodeURIComponent(file)}`, {
        credentials: "same-origin",
      });
      if (!r.ok) return { ok: false, status: r.status };
      const buf = await r.arrayBuffer();
      const bytes = new Uint8Array(buf);
      let bin = "";
      for (let i = 0; i < bytes.length; i += 8192) {
        bin += String.fromCharCode(...bytes.subarray(i, i + 8192));
      }
      return { ok: true, base64: btoa(bin) };
    }, filename);
    if (res.ok && res.base64) {
      runWorker("save-image", { filename, base64: res.base64 });
      return true;
    }
    return false;
  }

  async function pushLocalOutbox() {
    let pushedCount = 0;
    let failedCount = 0;
    for (let batchIdx = 0; batchIdx < 5; batchIdx++) {
      const pending = runWorker("pending", {}, [`--limit=${CONFIG.batch_size}`]);
      const events = pending.events || [];
      if (events.length === 0) break;

      const eventIdByKey = new Map();
      for (const ev of events) {
        eventIdByKey.set(ev.transaction_key, ev.id);
      }

      const pushRes = await callCloudApi("sync/push", {
        method: "POST",
        body: { shop_id: CONFIG.shop_id, events },
      });
      const results = pushRes.data?.results || [];
      const syncedIds = [];
      const failures = [];
      for (const item of results) {
        const outboxId = eventIdByKey.get(item.transaction_key);
        if (!outboxId) continue;
        if (item.ok) {
          syncedIds.push(outboxId);
        } else {
          failures.push({ id: outboxId, error: item.error || "Unknown push failure" });
        }
      }
      if (syncedIds.length > 0) {
        runWorker("mark-synced", { ids: syncedIds });
        pushedCount += syncedIds.length;
      }
      if (failures.length > 0) {
        runWorker("mark-failed", { failures });
        failedCount += failures.length;
        logErr(`Push failures: ${JSON.stringify(failures)}`);
      }
      if (syncedIds.length === 0) break;
    }
    return { pushedCount, failedCount };
  }

  async function pullCloudOutbox() {
    let appliedCount = 0;
    let skippedCount = 0;
    let failedCount = 0;
    for (let batchIdx = 0; batchIdx < 5; batchIdx++) {
      const pullRes = await callCloudApi(`sync/pull&limit=${CONFIG.batch_size}`);
      const events = pullRes.data?.events || [];
      if (events.length === 0) break;

      const applyRes = runWorker("apply-events", { shop_id: "cloud-main", events });
      const ackIds = applyRes.acked_ids || [];
      const failed = applyRes.failed || [];

      if (ackIds.length > 0 || failed.length > 0) {
        await callCloudApi("sync/ack", {
          method: "POST",
          body: { acked_ids: ackIds, failed },
        });
      }
      appliedCount += applyRes.applied || 0;
      skippedCount += applyRes.skipped || 0;
      failedCount += failed.length;
      if (failed.length > 0) {
        logErr(`Pull apply failures: ${JSON.stringify(failed)}`);
      }
      if (ackIds.length === 0) break;
    }
    return { appliedCount, skippedCount, failedCount };
  }

  async function runReconcile() {
    let totalPulledMeta = 0;
    let totalPushed = 0;
    let totalPulledSales = 0;
    let totalImages = 0;

    for (let pass = 0; pass < 4; pass++) {
      const snapRes = await callCloudApi("sync/reconcile");
      const diff = runWorker("diff-reconcile", { cloud: snapRes.data });
      totalPulledMeta += diff.applied_locally || 0;

      if (diff.errors && diff.errors.length > 0) {
        logErr(`Reconcile local warnings: ${JSON.stringify(diff.errors)}`);
      }

      const pushEvents = diff.push_events || [];
      for (let i = 0; i < pushEvents.length; i += 10) {
        const chunk = pushEvents.slice(i, i + 10);
        const res = await callCloudApi("sync/push", {
          method: "POST",
          body: { shop_id: CONFIG.shop_id, events: chunk },
        });
        for (const r of res.data?.results || []) {
          if (r.ok) {
            totalPushed++;
          } else {
            logErr(`Reconcile push error (${r.transaction_key}): ${r.error}`);
          }
        }
      }

      const pullKeys = diff.pull_sale_keys || [];
      if (pullKeys.length > 0) {
        const expRes = await callCloudApi("sync/export-sales", {
          method: "POST",
          body: { transaction_keys: pullKeys },
        });
        const saleEvents = expRes.data?.events || [];
        if (saleEvents.length > 0) {
          const applyRes = runWorker("apply-events", { shop_id: "cloud-reconcile", events: saleEvents });
          totalPulledSales += applyRes.applied || 0;
          if (applyRes.failed && applyRes.failed.length > 0) {
            logErr(`Reconcile sale pull failures: ${JSON.stringify(applyRes.failed)}`);
          }
        }
      }

      for (const fname of diff.download_images || []) {
        try {
          if (await downloadProductImage(fname)) totalImages++;
        } catch (err) {
          logErr(`Image download failed (${fname}): ${err.message}`);
        }
      }

      if ((diff.remaining_pull_sales || 0) === 0 && (diff.remaining_push_sales || 0) === 0) {
        break;
      }
    }

    if (totalPulledMeta + totalPushed + totalPulledSales + totalImages > 0) {
      logInfo(
        `[RECONCILE] Local meta applied: ${totalPulledMeta}, Cloud pushed: ${totalPushed}, Cloud sales imported: ${totalPulledSales}, Images downloaded: ${totalImages}`,
      );
    }
    return { totalPulledMeta, totalPushed, totalPulledSales, totalImages };
  }

  async function syncCycle() {
    cycleCount++;
    const nowSql = formatSqlDate();
    try {
      await ensurePageLoaded();
      const health = await fetchApiJson("health", "GET");
      if (health.isHtml) {
        await ensurePageLoaded(true);
      }
      const h = health.isHtml ? await fetchApiJson("health", "GET") : health;
      if (!h?.json?.ok) {
        throw new Error(h?.raw || `Health HTTP ${h?.status || "?"}`);
      }

      await ensureAuthenticated();

      const push = await pushLocalOutbox();
      const pull = await pullCloudOutbox();

      let rec = null;
      if (forceReconcile || cycleCount === 1 || cycleCount % CONFIG.reconcile_every_cycles === 0) {
        rec = await runReconcile();
      }

      const hasError = push.failedCount > 0 || pull.failedCount > 0;
      const metaUpdates = {
        sync_status: "ONLINE",
        last_sync_attempt_at: nowSql,
        sync_last_error: hasError ? "Some sync events deferred; inspect sync_bridge.log." : "",
      };
      if (!hasError) {
        consecutiveFailures = 0;
        metaUpdates.last_sync_at = nowSql;
        metaUpdates.last_successful_sync_at = nowSql;
        metaUpdates.consecutive_failures = "0";
      }
      const status = runWorker("status-meta", { updates: metaUpdates });

      if (lastOnlineState !== true || push.pushedCount > 0 || pull.appliedCount > 0 || hasError) {
        logInfo(
          `[ONLINE] Pushed=${push.pushedCount} Pulled=${pull.appliedCount} (Skipped=${pull.skippedCount}) Pending=${status.pending} Failed=${status.failed}`,
        );
      }
      lastOnlineState = true;
      return { ok: true, push, pull, rec, status };
    } catch (err) {
      pageReady = false;
      consecutiveFailures++;
      try {
        runWorker("status-meta", {
          updates: {
            sync_status: "OFFLINE",
            last_sync_attempt_at: nowSql,
            consecutive_failures: String(consecutiveFailures),
            sync_last_error: String(err.message || err).slice(0, 500),
          },
        });
      } catch {}
      if (lastOnlineState !== false || cycleCount % 10 === 1) {
        logErr(`[OFFLINE] Cloud unreachable or session error: ${err.message}`);
      }
      lastOnlineState = false;
      return { ok: false, error: err.message };
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
  logErr(`Fatal bridge error: ${err.stack || err.message}`);
  process.exit(1);
});
