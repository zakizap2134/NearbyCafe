import { api } from "./api";
import { readOrders, finishOrder } from "./checkout-store";

// Account-scoped worker; server idempotency protects simultaneous tab retries.
export function startOrderSync({
  userId,
  token,
  onOrders,
  onError,
  onExpired,
  onConfirmed,
  interval = 5000,
}) {
  let stopped = false,
    running = false,
    timer = null,
    failures = 0,
    wakeRequested = false;
  async function tick() {
    if (stopped || running) return;
    running = true;
    clearTimeout(timer);
    try {
      const orders = await readOrders(userId);
      if (stopped) return;
      onOrders(orders);
      for (const order of [...orders]
        .reverse()
        .filter((o) => o.state === "submitted")) {
        if (stopped) break;
        try {
          const sale = await api("checkout", { token, body: order.body });
          await finishOrder(userId, order.key, {
            state: "confirmed",
            sale,
            error: null,
          });
          failures = 0;
          if (!stopped) onConfirmed(sale);
        } catch (e) {
          if (e.status === 401) {
            if (!stopped) onExpired();
            stopped = true;
            break;
          }
          if (
            [400, 403, 409, 413, 422].includes(e.status) &&
            e.code !== "INVALID_RESPONSE"
          ) {
            await finishOrder(userId, order.key, {
              state: "review",
              error: e.message,
              error_code: e.code,
            });
            failures = 0;
          } else {
            failures++;
            if (!stopped) onError(e);
            break;
          }
        }
      }
      if (!stopped) onOrders(await readOrders(userId));
    } catch (e) {
      failures++;
      if (!stopped) onError(e);
    } finally {
      running = false;
      if (!stopped)
        timer = setTimeout(
          tick,
          wakeRequested
            ? 0
            : Math.min(interval * 2 ** Math.min(failures, 4), 60000),
        );
      wakeRequested = false;
    }
  }
  const wake = () => {
    if (!stopped) {
      failures = 0;
      if (running) {
        wakeRequested = true;
        return;
      }
      void tick();
    }
  };
  window.addEventListener("online", wake);
  window.addEventListener("focus", wake);
  timer = setTimeout(tick, 0);
  return {
    wake,
    stop() {
      stopped = true;
      clearTimeout(timer);
      window.removeEventListener("online", wake);
      window.removeEventListener("focus", wake);
    },
  };
}
