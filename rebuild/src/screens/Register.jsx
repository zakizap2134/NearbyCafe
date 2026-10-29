import { useEffect, useRef, useState } from "react";
import { api } from "../api";
import {
  money,
  variantLabel,
  ErrorNotice,
  Receipt,
  OrderSuccessModal,
  VoidSaleModal,
  Field,
} from "../components/workflow";
import {
  readDraft,
  saveDraft,
  enqueueDraft,
  readOrders,
  resolveReview,
  markOrderVoided,
  cents,
  cashString,
} from "../checkout-store";
import { startOrderSync } from "../order-sync";

export default function Register({ user, token, expired }) {
  const [menu, setMenu] = useState(null),
    [error, setError] = useState(null),
    [notice, setNotice] = useState(""),
    [loading, setLoading] = useState(true),
    [draft, setDraft] = useState(null),
    [search, setSearch] = useState(""),
    [review, setReview] = useState(false),
    [busy, setBusy] = useState(false),
    [ready, setReady] = useState(false),
    [orders, setOrders] = useState([]),
    [sale, setSale] = useState(null),
    [successSale, setSuccessSale] = useState(null),
    [voidTarget, setVoidTarget] = useState(null),
    [saving, setSaving] = useState(0),
    [historyLimit, setHistoryLimit] = useState(10);
  const draftRef = useRef(null),
    savedDraft = useRef(null),
    writes = useRef(Promise.resolve()),
    writeFailed = useRef(false),
    sending = useRef(false),
    // Keys submitted from this page session; only these open the success modal,
    // so replayed/restored confirmations after a reload never pop a blocking dialog.
    liveKeys = useRef(new Set()),
    sync = useRef(null);
  const basket = draft?.basket ?? [],
    cash = draft?.cash ?? "";
  const total = basket.reduce((n, l) => n + cents(l.price) * l.qty, 0),
    cashCents = cents(cash);
  async function refresh() {
    setLoading(true);
    try {
      setMenu(await api("catalogue", { token }));
    } catch (e) {
      setError(e);
      if (e.status === 401) expired();
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    let live = true;
    refresh();
    Promise.all([readDraft(user.id), readOrders(user.id)])
      .then(([d, list]) => {
        if (live) {
          savedDraft.current = d;
          draftRef.current = d;
          setDraft(d);
          setOrders(list);
          setReady(true);
        }
      })
      .catch((e) => {
        if (live) setError(e);
      });
    return () => {
      live = false;
    };
  }, [token, user.id]);
  useEffect(() => {
    if (!ready) return;
    const worker = startOrderSync({
      userId: user.id,
      token,
      onOrders: setOrders,
      onError: setError,
      onExpired: expired,
      onConfirmed: (s) => {
        if (s?.transaction_key && liveKeys.current.delete(s.transaction_key)) {
          setSuccessSale(s);
          setSale(null);
        } else {
          setSale(s);
        }
        setError(null);
        refresh();
      },
    });
    sync.current = worker;
    return () => {
      worker.stop();
      sync.current = null;
    };
  }, [token, user.id, ready]);
  useEffect(() => {
    const warn = (e) => {
      if (saving) {
        e.preventDefault();
        e.returnValue = "";
      }
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [saving]);
  function edit(changes) {
    if (!ready || busy || writeFailed.current) return;
    const next = { ...draftRef.current, ...changes };
    draftRef.current = next;
    setDraft(next);
    setReview(false);
    setSaving((n) => n + 1);
    writes.current = writes.current
      .then(async () => {
        if (writeFailed.current) return;
        const saved = await saveDraft({
          ...next,
          revision: savedDraft.current.revision,
        });
        savedDraft.current = saved;
      })
      .catch((e) => {
        writeFailed.current = true;
        setReady(false);
        setError(e);
      })
      .finally(() => setSaving((n) => n - 1));
  }
  function add(v) {
    setSale(null);
    const rows = draftRef.current.basket;
    const old = rows.find((r) => r.id === v.id);
    edit({
      basket: old
        ? rows.map((r) =>
            r.id === v.id ? { ...r, qty: Math.min(r.qty + 1, 100) } : r,
          )
        : [...rows, { ...v, qty: 1 }],
    });
  }
  function adjust(id, delta) {
    edit({
      basket: draftRef.current.basket
        .map((r) => (r.id === id ? { ...r, qty: r.qty + delta } : r))
        .filter((r) => r.qty > 0),
    });
  }
  function reloadSaleToBasket(targetSale) {
    if (!targetSale?.items?.length || !menu?.variants?.length) return;
    const rebuilt = [];
    for (const item of targetSale.items) {
      const v = menu.variants.find(
        (cand) => Number(cand.id) === Number(item.variant_id),
      );
      if (v) {
        rebuilt.push({
          ...v,
          qty: Math.min(Number(item.qty) || 1, 100),
        });
      }
    }
    if (rebuilt.length > 0) {
      edit({
        basket: rebuilt,
        cash: targetSale.cash_received ?? "",
      });
      setNotice(
        `Loaded ${rebuilt.length} item(s) from Sale #${targetSale.id} into Current Order for adjustment.`,
      );
    }
  }
  async function handleVoided(updatedSale, reloadToBasket) {
    setVoidTarget(null);
    setSale(updatedSale);
    if (successSale?.id === updatedSale.id) {
      setSuccessSale(null);
    }
    await markOrderVoided(user.id, updatedSale);
    setOrders(await readOrders(user.id));
    await refresh();
    if (reloadToBasket) {
      reloadSaleToBasket(updatedSale);
    } else {
      setNotice(`Sale #${updatedSale.id} has been voided.`);
    }
  }
  async function submit() {
    if (sending.current) return;
    sending.current = true;
    setBusy(true);
    setError(null);
    setNotice("");
    try {
      await writes.current;
      if (writeFailed.current)
        throw new Error(
          "Reload to resolve the draft conflict before charging.",
        );
      const d = savedDraft.current;
      const amount = d.basket.reduce((n, l) => n + cents(l.price) * l.qty, 0);
      if (!d.basket.length || cents(d.cash) === null || cents(d.cash) < amount)
        throw new Error("Check the basket and cash amount.");
      const result = await enqueueDraft(d);
      if (result.record?.key) liveKeys.current.add(result.record.key);
      savedDraft.current = result.draft;
      draftRef.current = result.draft;
      setDraft(result.draft);
      setReview(false);
      setOrders(await readOrders(user.id));
      sync.current?.wake();
    } catch (e) {
      setError(e);
    } finally {
      sending.current = false;
      setBusy(false);
    }
  }
  async function acknowledge(order) {
    setError(null);
    try {
      await resolveReview(user.id, order.key);
      setOrders(await readOrders(user.id));
      await refresh();
    } catch (e) {
      setError(e);
    }
  }
  const pending = orders.filter(
      (o) => o.state === "submitted" || o.state === "review",
    ),
    history = orders.filter(
      (o) => o.state === "confirmed" || o.state === "resolved",
    );
  const products =
    menu?.variants
      .filter((v) =>
        (v.product_name + " " + v.category)
          .toLowerCase()
          .includes(search.toLowerCase()),
      )
      .reduce((all, v) => {
        (all[v.product_id] ??= {
          name: v.product_name,
          category: v.category,
          image_filename: v.image_filename,
          variants: [],
        }).variants.push(v);
        return all;
      }, {}) ?? {};
  return (
    <>
      <div className="page-heading">
        <div>
          <p className="eyebrow">THE COUNTER</p>
          <h1>Register</h1>
          <p className="subtle">
            Choose a drink, select its size, and take a cash payment.
          </p>
        </div>
        <button
          onClick={() => {
            setError(null);
            refresh();
            sync.current?.wake();
          }}
          disabled={loading || busy}
        >
          Refresh menu
        </button>
      </div>
      <ErrorNotice error={error} />
      {notice && (
        <p className="success" role="status">
          {notice}
        </p>
      )}
      {!ready && error && (
        <button onClick={() => location.reload()}>Reload saved orders</button>
      )}
      <OrderSuccessModal
        sale={successSale}
        cashierName={user.display_name || user.username}
        onClose={() => setSuccessSale(null)}
        onVoid={(s) => {
          setSuccessSale(null);
          setVoidTarget(s);
        }}
      />
      <VoidSaleModal
        sale={voidTarget}
        user={user}
        token={token}
        allowReload={true}
        onClose={() => setVoidTarget(null)}
        onVoided={handleVoided}
      />
      {pending.length > 0 && (
        <section className="pending-banner" aria-label="Pending orders">
          <div className="section-heading">
            <h2>Pending orders ({pending.length})</h2>
            <button onClick={() => sync.current?.wake()}>
              Retry pending orders
            </button>
          </div>
          <p>
            Saved on this device. Submission retries automatically while
            Register is open. Stock is checked by the host; a saved order is not
            a completed sale. Do not collect cash again for a retry.
          </p>
          {pending.map((order) => (
            <article className="pending-order" key={order.key}>
              <h3>
                {order.state === "review"
                  ? "Order needs review"
                  : "Awaiting host confirmation"}
              </h3>
              <small>
                Order {order.key.slice(0, 8)} ·{" "}
                {new Date(order.created_at).toLocaleString()}
              </small>
              <ul>
                {order.labels.map((l) => (
                  <li key={l.variant_id}>
                    {l.qty} × {l.label} · {money(l.price)}
                  </li>
                ))}
              </ul>
              <p>Cash received: {money(order.body.cash_received)}</p>
              {order.state === "review" && (
                <>
                  <p>{order.error}</p>
                  {order.error_code === "RESTORE_REVIEW" && (
                    <p>
                      Ask the owner to check this order against restored sales,
                      physical stock and cash. A previous confirmation may
                      predate the restore; do not charge again until reconciled.
                    </p>
                  )}
                  <p>
                    Reconcile any cash already collected, then build a corrected
                    order if needed.
                  </p>
                  <button onClick={() => acknowledge(order)}>
                    Mark review complete
                  </button>
                </>
              )}
            </article>
          ))}
        </section>
      )}
      <div className="register-layout">
        <section>
          <div className="menu-heading">
            <h2>Menu</h2>
            {menu && (
              <span className="subtle">
                Updated {menu.server_time} · Manila
              </span>
            )}
          </div>
          <Field
            label="Find a drink"
            placeholder="Search name or category"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {loading && !menu ? (
            <p role="status">Loading menu…</p>
          ) : Object.keys(products).length === 0 ? (
            <section className="panel empty">
              <h2>
                {search ? "No matching drinks" : "No drinks available yet"}
              </h2>
              <p>
                {search
                  ? "Try another name or category."
                  : "The owner needs to add products, variants, recipes and ingredients."}
              </p>
            </section>
          ) : (
            <div className="drink-grid">
              {Object.entries(products).map(([id, p]) => (
                <article className="panel drink" key={id}>
                  {p.image_filename ? (
                    <div className="drink-card-top">
                      <div className="drink-media">
                        <img
                          src={`${import.meta.env.BASE_URL}uploads/products/${p.image_filename}`}
                          alt={p.name}
                          loading="lazy"
                          onError={(e) => {
                            const media = e.currentTarget.closest(".drink-media");
                            if (media) media.style.display = "none";
                          }}
                        />
                      </div>
                      <div className="drink-card-title">
                        <p className="eyebrow">{p.category}</p>
                        <h2>{p.name}</h2>
                      </div>
                    </div>
                  ) : (
                    <>
                      <p className="eyebrow">{p.category}</p>
                      <h2>{p.name}</h2>
                    </>
                  )}
                  <div className="variant-grid">
                    {p.variants.map((v) => (
                      <button
                        key={v.id}
                        aria-label={"Add " + variantLabel(v)}
                        disabled={busy || !ready || v.available_count < 1}
                        onClick={() => add(v)}
                      >
                        <strong>
                          {v.size} · {{ S: 12, M: 16, L: 22 }[v.size]} oz
                          {v.temperature === "NA"
                            ? ""
                            : " · " +
                              (v.temperature === "ICED" ? "Iced" : "Hot")}
                        </strong>
                        <span>{money(v.price)}</span>
                        <small>
                          {v.available_count > 0
                            ? v.available_count + " available"
                            : v.recipe_ready
                              ? "Out of stock"
                              : "Recipe needed"}
                        </small>
                      </button>
                    ))}
                  </div>
                </article>
              ))}
            </div>
          )}
        </section>
        <aside className="panel basket">
          <div className="section-heading">
            <h2>Current order</h2>
            <div className="button-row">
              {basket.length > 0 && (
                <button
                  type="button"
                  className="clear-order-btn"
                  disabled={busy || !ready}
                  onClick={() => edit({ basket: [], cash: "" })}
                >
                  Clear order
                </button>
              )}
              <span className="pill">Cash</span>
            </div>
          </div>
          {!basket.length ? (
            <p className="subtle basket-empty">Select a size to add a drink.</p>
          ) : (
            basket.map((line) => (
              <div className="basket-line" key={line.id}>
                <div>
                  <strong>{variantLabel(line)}</strong>
                  <p>{money(line.price)} each</p>
                </div>
                <div className="quantity-controls">
                  <button
                    aria-label={"Remove one " + variantLabel(line)}
                    onClick={() => adjust(line.id, -1)}
                    disabled={busy || !ready}
                  >
                    −
                  </button>
                  <span>{line.qty}</span>
                  <button
                    aria-label={"Add one " + variantLabel(line)}
                    onClick={() => adjust(line.id, 1)}
                    disabled={busy || !ready || line.qty >= 100}
                  >
                    +
                  </button>
                </div>
                <strong>
                  {money(cashString(cents(line.price) * line.qty))}
                </strong>
              </div>
            ))
          )}
          <div className="checkout-total">
            <span>Total</span>
            <strong>{money(cashString(total))}</strong>
          </div>
          <p className="subtle small">
            Includes 12% VAT. Pending orders do not reserve stock.
          </p>
          <Field
            label="Cash received"
            inputMode="decimal"
            placeholder="0.00"
            value={cash}
            onChange={(e) => edit({ cash: e.target.value })}
            disabled={busy || !ready}
          />
          <div className="change">
            <span>Change</span>
            <strong>
              {cashCents !== null && cashCents >= total
                ? money(cashString(cashCents - total))
                : "—"}
            </strong>
          </div>
          {cashCents !== null && cashCents < total && (
            <p className="small">Cash received is below the total.</p>
          )}
          <p className="subtle small" role="status">
            {saving
              ? "Saving draft…"
              : ready
                ? "Draft saved on this device."
                : "Loading saved draft…"}
          </p>
          <button
            className="primary full"
            disabled={
              busy ||
              !ready ||
              !basket.length ||
              cashCents === null ||
              cashCents < total
            }
            onClick={() => setReview(true)}
          >
            Charge
          </button>
          {review && (
            <section className="checkout-review" aria-label="Review payment">
              <h3>Confirm cash payment</h3>
              <p>
                Collect {money(cashString(total))} and return{" "}
                {money(cashString(cashCents - total))} change.
              </p>
              <p>
                If the host is unavailable, this order stays pending until the
                host accepts it.
              </p>
              <div className="button-row">
                <button onClick={() => setReview(false)} disabled={busy}>
                  Back
                </button>
                <button className="primary" onClick={submit} disabled={busy}>
                  Confirm sale
                </button>
              </div>
            </section>
          )}
          {busy && <p role="status">Saving this order…</p>}
        </aside>
      </div>
      {!successSale && (
        <Receipt
          sale={sale}
          onClose={() => setSale(null)}
          onVoid={(s) => setVoidTarget(s)}
          onReloadItems={reloadSaleToBasket}
        />
      )}
      <section className="panel section-space">
        <h2>Your orders on this device</h2>
        <p className="subtle small">
          History for this account and browser. Confirmed receipts remain stored
          on the host.
        </p>
        {!history.length ? (
          <p className="subtle">No completed or reviewed orders yet.</p>
        ) : (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Status</th>
                  <th>Cash</th>
                  <th>Details</th>
                </tr>
              </thead>
              <tbody>
                {history.slice(0, historyLimit).map((o) => (
                  <tr key={o.key}>
                    <td>{o.key.slice(0, 8)}</td>
                    <td>
                      {o.state === "confirmed" ? (
                        o.sale?.status === "voided" ? (
                          <span className="pill pill-voided">Voided</span>
                        ) : (
                          "Confirmed"
                        )
                      ) : o.error_code === "RESTORE_REVIEW" ? (
                        "Restore review completed"
                      ) : (
                        "Reviewed; no sale"
                      )}
                    </td>
                    <td>{money(o.body.cash_received)}</td>
                    <td>
                      {o.state === "confirmed" ? (
                        <div className="table-actions">
                          <button
                            type="button"
                            className="compact-btn"
                            onClick={() => setSale(o.sale)}
                          >
                            View receipt #{o.sale.id}
                          </button>
                          {o.sale?.status !== "voided" && (
                            <button
                              type="button"
                              className="danger compact-btn"
                              onClick={() => setVoidTarget(o.sale)}
                            >
                              Void #{o.sale.id}
                            </button>
                          )}
                        </div>
                      ) : (
                        o.error
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {history.length > historyLimit && (
          <button onClick={() => setHistoryLimit((n) => n + 20)}>
            Show more orders
          </button>
        )}
      </section>
    </>
  );
}
