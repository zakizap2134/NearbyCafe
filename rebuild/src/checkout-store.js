import { getHostEpoch } from "./api";
const database = "venuevue-rebuild-checkout";
function open() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(database, 2);
    request.onupgradeneeded = () => {
      const db = request.result;
      const queue = db.createObjectStore("queue", { keyPath: "key" });
      queue.createIndex("user_id", "user_id");
      db.createObjectStore("drafts", { keyPath: "user_id" });
      if (db.objectStoreNames.contains("orders")) {
        const cursor = request.transaction.objectStore("orders").openCursor();
        cursor.onsuccess = () => {
          const c = cursor.result;
          if (c) {
            const r = c.value;
            queue.put({
              ...r,
              key: r.body.transaction_key,
              created_at: new Date().toISOString(),
              updated_at: new Date().toISOString(),
            });
            c.continue();
          }
        };
      }
    };
    request.onsuccess = () => {
      request.result.onversionchange = () => request.result.close();
      resolve(request.result);
    };
    request.onerror = () =>
      reject(
        new Error(
          "Device storage is unavailable. No new checkout was submitted.",
        ),
      );
    request.onblocked = () =>
      reject(
        new Error(
          "Close older VenueVue tabs, then reload to upgrade device storage.",
        ),
      );
  });
}
async function run(stores, mode, perform) {
  const db = await open();
  try {
    return await new Promise((resolve, reject) => {
      const tx = db.transaction(stores, mode);
      let value = null,
        reason =
          "Device storage could not be saved. Keep this page open and retry.";
      const finish = (v) => {
        value = v;
      };
      const abort = (message) => {
        reason = message;
        tx.abort();
      };
      tx.oncomplete = () => resolve(value);
      tx.onabort = () => reject(new Error(reason));
      tx.onerror = () => reject(new Error(reason));
      try {
        perform(tx, finish, abort);
      } catch (e) {
        reason = e.message;
        tx.abort();
      }
    });
  } finally {
    db.close();
  }
}
export function transactionKey() {
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 15) | 64;
  b[8] = (b[8] & 63) | 128;
  const s = Array.from(b, (x) => x.toString(16).padStart(2, "0")).join("");
  return [
    s.slice(0, 8),
    s.slice(8, 12),
    s.slice(12, 16),
    s.slice(16, 20),
    s.slice(20),
  ].join("-");
}
const freshDraft = (userId) => ({
  user_id: Number(userId),
  key: transactionKey(),
  revision: 0,
  expected_epoch: getHostEpoch(),
  basket: [],
  cash: "",
});
export function readDraft(userId) {
  return run(["drafts"], "readwrite", (tx, finish) => {
    const store = tx.objectStore("drafts");
    const r = store.get(Number(userId));
    r.onsuccess = () => {
      const resetEmpty =
        r.result &&
        !r.result.basket.length &&
        !r.result.cash &&
        getHostEpoch() &&
        r.result.expected_epoch !== getHostEpoch();
      const draft = !r.result || resetEmpty ? freshDraft(userId) : r.result;
      if (!r.result || resetEmpty) store.put(draft);
      finish(draft);
    };
  });
}
export function saveDraft(draft) {
  return run(["drafts"], "readwrite", (tx, finish, abort) => {
    const store = tx.objectStore("drafts");
    const r = store.get(draft.user_id);
    r.onsuccess = () => {
      if (r.result?.key !== draft.key || r.result.revision !== draft.revision) {
        abort(
          "This account’s draft changed in another tab. Reload before editing or charging.",
        );
        return;
      }
      const saved = { ...draft, revision: draft.revision + 1 };
      store.put(saved);
      finish(saved);
    };
  });
}
export function readOrders(userId) {
  return run(["queue"], "readonly", (tx, finish) => {
    const r = tx.objectStore("queue").index("user_id").getAll(Number(userId));
    r.onsuccess = () =>
      finish(r.result.sort((a, b) => b.created_at.localeCompare(a.created_at)));
  });
}
export function enqueueDraft(draft) {
  return run(["drafts", "queue"], "readwrite", (tx, finish, abort) => {
    const drafts = tx.objectStore("drafts"),
      queue = tx.objectStore("queue");
    const r = drafts.get(draft.user_id);
    r.onsuccess = () => {
      if (r.result?.key !== draft.key || r.result.revision !== draft.revision) {
        abort(
          "This draft changed in another tab. Reload; no new order was submitted.",
        );
        return;
      }
      if (!draft.basket.length || cents(draft.cash) === null) {
        abort("Check the basket and cash amount.");
        return;
      }
      const record = {
        key: draft.key,
        user_id: draft.user_id,
        state: "submitted",
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        labels: draft.basket.map((l) => ({
          variant_id: l.id,
          label:
            l.product_name +
            " " +
            l.size +
            (l.temperature === "NA" ? "" : " " + l.temperature),
          qty: l.qty,
          price: l.price,
        })),
        body: {
          expected_epoch: draft.expected_epoch,
          transaction_key: draft.key,
          cash_received: cashString(cents(draft.cash)),
          items: draft.basket.map((l) => ({
            variant_id: l.id,
            qty: l.qty,
            expected_price: l.price,
          })),
        },
      };
      queue.add(record);
      const next = freshDraft(draft.user_id);
      drafts.put(next);
      finish({ record, draft: next });
    };
  });
}
export function finishOrder(userId, key, outcome) {
  return run(["queue"], "readwrite", (tx, finish, abort) => {
    const store = tx.objectStore("queue");
    const r = store.get(key);
    r.onsuccess = () => {
      const old = r.result;
      if (!old || old.user_id !== Number(userId)) {
        abort("Saved order not found for this account.");
        return;
      }
      // Late responses cannot downgrade a confirmed receipt.
      if (
        old.state === "confirmed" ||
        (old.state === "resolved" && outcome.state !== "confirmed")
      ) {
        finish(old);
        return;
      }
      const saved = {
        ...old,
        ...outcome,
        key: old.key,
        user_id: old.user_id,
        body: old.body,
        labels: old.labels,
        updated_at: new Date().toISOString(),
      };
      store.put(saved);
      finish(saved);
    };
  });
}
export function resolveReview(userId, key) {
  return run(["queue"], "readwrite", (tx, finish, abort) => {
    const store = tx.objectStore("queue");
    const r = store.get(key);
    r.onsuccess = () => {
      const old = r.result;
      if (!old || old.user_id !== Number(userId) || old.state !== "review") {
        abort("This order changed. Refresh its status before reviewing.");
        return;
      }
      const saved = {
        ...old,
        state: "resolved",
        updated_at: new Date().toISOString(),
      };
      store.put(saved);
      finish(saved);
    };
  });
}
export function cents(value) {
  const match = String(value).match(/^(\d{1,7})(?:\.(\d{1,2}))?$/);
  return match
    ? Number(match[1]) * 100 + Number((match[2] ?? "").padEnd(2, "0"))
    : null;
}
export function cashString(value) {
  return Math.floor(value / 100) + "." + String(value % 100).padStart(2, "0");
}
