import { useEffect, useMemo, useRef, useState } from "react";
import { api } from "../api";
import {
  money,
  variantLabel,
  productImageUrl,
  PAYMENT_METHODS,
  paymentMethodLabel,
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

function playRegisterChime() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    if (ctx.state === "suspended") {
      ctx.resume();
    }
    const now = ctx.currentTime;
    const playTone = (freq, start, duration, gainVal = 0.22) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = "sine";
      osc.frequency.setValueAtTime(freq, start);
      gain.gain.setValueAtTime(gainVal, start);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(start);
      osc.stop(start + duration);
    };
    // Dual-tone café register chime: 880 Hz (A5) followed by 1318.5 Hz (E6)
    playTone(880, now, 0.2, 0.18);
    playTone(1318.5, now + 0.08, 0.35, 0.22);
  } catch {
    // AudioContext blocked or not supported - fail silently
  }
}

function DrinkMedia({ name, category, imageFilename, variants }) {
  const [imgFailed, setImgFailed] = useState(false);

  useEffect(() => {
    setImgFailed(false);
  }, [imageFilename]);

  if (imageFilename && !imgFailed) {
    return (
      <div className="drink-media">
        <img
          src={productImageUrl(imageFilename)}
          alt={name}
          loading="lazy"
          onError={() => setImgFailed(true)}
        />
      </div>
    );
  }

  const catLower = String(category || "").toLowerCase();
  const nameLower = String(name || "").toLowerCase();
  const allIced =
    Array.isArray(variants) &&
    variants.length > 0 &&
    variants.every((v) => v.temperature === "ICED");
  const isColdStyle =
    allIced ||
    nameLower.includes("iced") ||
    nameLower.includes("cold") ||
    nameLower.includes("frappe") ||
    catLower.includes("matcha") ||
    catLower.includes("specialty");

  return (
    <div className="drink-media drink-media-placeholder" aria-hidden="true">
      {isColdStyle ? (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.65" strokeLinecap="round" strokeLinejoin="round">
          <path d="M14.5 3L13 8" />
          <path d="M6.5 8h11l-1.2 11.2a2 2 0 0 1-2 1.8H9.7a2 2 0 0 1-2-1.8L6.5 8z" />
          <path d="M5.5 8h13" />
          <path d="M7.3 13.5c1.5.7 3 .7 4.7 0s3.2-.7 4.7 0" />
        </svg>
      ) : (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.65" strokeLinecap="round" strokeLinejoin="round">
          <path d="M9 4c0 1.2-1 1.8-1 3" />
          <path d="M13 4c0 1.2-1 1.8-1 3" />
          <path d="M5 9.5h11v4.5a5.5 5.5 0 0 1-11 0V9.5z" />
          <path d="M16 10.5h1.5a2.5 2.5 0 0 1 0 5H16" />
          <path d="M4 20h13" />
        </svg>
      )}
    </div>
  );
}

export default function Register({ user, token, expired }) {
  const [menu, setMenu] = useState(null),
    [error, setError] = useState(null),
    [notice, setNotice] = useState(""),
    [loading, setLoading] = useState(true),
    [draft, setDraft] = useState(null),
    [search, setSearch] = useState(""),
    [categoryFilter, setCategoryFilter] = useState("ALL"),
    [review, setReview] = useState(false),
    [busy, setBusy] = useState(false),
    [ready, setReady] = useState(false),
    [orders, setOrders] = useState([]),
    [sale, setSale] = useState(null),
    [successSale, setSuccessSale] = useState(null),
    [voidTarget, setVoidTarget] = useState(null),
    [saving, setSaving] = useState(0),
    [historyLimit, setHistoryLimit] = useState(10),
    [showStockAlertDetails, setShowStockAlertDetails] = useState(false),
    [chimeEnabled, setChimeEnabled] = useState(() => {
      try {
        return localStorage.getItem("venuevue_chime_enabled") !== "0";
      } catch {
        return true;
      }
    });
  const draftRef = useRef(null),
    savedDraft = useRef(null),
    writes = useRef(Promise.resolve()),
    writeFailed = useRef(false),
    sending = useRef(false),
    liveKeys = useRef(new Set()),
    sync = useRef(null),
    searchInputRef = useRef(null),
    reviewRef = useRef(null),
    chimeRef = useRef(chimeEnabled);

  useEffect(() => {
    chimeRef.current = chimeEnabled;
  }, [chimeEnabled]);

  useEffect(() => {
    if (review) {
      reviewRef.current?.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }, [review]);

  function toggleChime() {
    setChimeEnabled((prev) => {
      const next = !prev;
      try {
        localStorage.setItem("venuevue_chime_enabled", next ? "1" : "0");
      } catch {}
      if (next) {
        playRegisterChime();
      }
      return next;
    });
  }

  const basket = draft?.basket ?? [],
    cash = draft?.cash ?? "",
    customerName = draft?.customer_name ?? "",
    paymentMethod = (draft?.payment_method || "CASH").toUpperCase(),
    paymentReference = draft?.payment_reference ?? "";
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
          if (chimeRef.current) {
            playRegisterChime();
          }
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
  useEffect(() => {
    function onKeyDown(e) {
      if (successSale || voidTarget) return;
      const tag = (e.target?.tagName || "").toUpperCase();
      const inInput =
        tag === "INPUT" ||
        tag === "TEXTAREA" ||
        tag === "SELECT" ||
        e.target?.isContentEditable;
      if (e.key === "/" && !inInput && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        searchInputRef.current?.focus();
        searchInputRef.current?.select();
      } else if (
        e.altKey &&
        (e.key === "e" || e.key === "E") &&
        draftRef.current?.basket?.length
      ) {
        e.preventDefault();
        const curTotal = draftRef.current.basket.reduce(
          (n, l) => n + cents(l.price) * l.qty,
          0,
        );
        if (curTotal > 0) {
          edit({ cash: cashString(curTotal) });
        }
      }
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [successSale, voidTarget, ready, busy]);
  function edit(changes) {
    if (!ready || busy || writeFailed.current) return;
    const next = { ...draftRef.current, ...changes };
    if (
      changes.basket &&
      changes.cash === undefined &&
      String(next.payment_method || "CASH").toUpperCase() !== "CASH"
    ) {
      const nextTotal = next.basket.reduce(
        (n, l) => n + cents(l.price) * l.qty,
        0,
      );
      next.cash = nextTotal > 0 ? cashString(nextTotal) : "";
    }
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
  function repeatLastOrder() {
    const last = history.find((o) => o.state === "confirmed" && !o.voided);
    if (!last || !menu?.variants?.length) return;
    const rawItems = last.body?.items || last.labels || [];
    const rebuilt = [];
    for (const item of rawItems) {
      const v = menu.variants.find(
        (cand) => Number(cand.id) === Number(item.variant_id),
      );
      if (v && Number(v.available_count) > 0) {
        rebuilt.push({
          ...v,
          qty: Math.min(Number(item.qty) || 1, Number(v.available_count), 100),
        });
      }
    }
    if (rebuilt.length > 0) {
      setError(null);
      edit({ basket: rebuilt, cash: "" });
      setNotice(
        `Repeated ${rebuilt.length} item(s) from Sale #${last.sale_id || "last order"} into Current order.`,
      );
    } else {
      setError(
        new Error("Items from the last order are currently out of stock."),
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
  // Memoized: typing cash or a cup note re-renders the screen, but the menu
  // grouping only depends on the catalogue, category chip and search text.
  const pending = useMemo(
      () =>
        (orders ?? []).filter(
          (o) => o && (o.state === "submitted" || o.state === "review"),
        ),
      [orders],
    ),
    history = useMemo(
      () =>
        (orders ?? []).filter(
          (o) => o && (o.state === "confirmed" || o.state === "resolved"),
        ),
      [orders],
    );
  const allVariants = useMemo(
    () => (Array.isArray(menu?.variants) ? menu.variants : []),
    [menu],
  );
  const lowStockAlerts = useMemo(
    () => (Array.isArray(menu?.low_stock_alerts) ? menu.low_stock_alerts : []),
    [menu],
  );
  const menuCategories = useMemo(
    () =>
      Array.from(new Set(allVariants.map((v) => v?.category).filter(Boolean))),
    [allVariants],
  );
  const products = useMemo(() => {
    const needle = search.toLowerCase();
    return allVariants
      .filter((v) => {
        if (categoryFilter !== "ALL" && v?.category !== categoryFilter) {
          return false;
        }
        return `${v?.product_name ?? ""} ${v?.category ?? ""}`
          .toLowerCase()
          .includes(needle);
      })
      .reduce((all, v) => {
        const pid = v.product_id ?? v.id;
        const entry = (all[pid] ??= {
          name: v.product_name ?? "Drink",
          category: v.category ?? "",
          image_filename: v.image_filename || null,
          variants: [],
        });
        if (!entry.image_filename && v.image_filename) {
          entry.image_filename = v.image_filename;
        }
        entry.variants.push(v);
        return all;
      }, {});
  }, [allVariants, categoryFilter, search]);
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
        onPrint={() => {
          const target = successSale;
          setSuccessSale(null);
          setSale(target);
          setTimeout(() => window.print(), 250);
        }}
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
            <div className="button-row" style={{ alignItems: "center" }}>
              <button
                type="button"
                className={`compact-btn sound-toggle-btn${chimeEnabled ? " sound-active" : ""}`}
                onClick={toggleChime}
                title={chimeEnabled ? "Register chime is on (Click to mute)" : "Register chime is muted (Click to unmute)"}
                aria-label={chimeEnabled ? "Chime enabled" : "Chime muted"}
              >
                <span className={`chime-dot ${chimeEnabled ? "active" : ""}`} aria-hidden="true" />
                <span>{chimeEnabled ? "Chime on" : "Chime muted"}</span>
              </button>
              {menu && (
                <span className="subtle">
                  Updated {menu.server_time} · Manila
                </span>
              )}
            </div>
          </div>
          {lowStockAlerts.length > 0 && (
            <section className="register-stock-banner no-print" aria-label="Low stock alerts" role="status">
              <div className="register-stock-banner-header">
                <div className="register-stock-banner-title">
                  <span className="register-stock-pill" aria-hidden="true">Low Stock</span>
                  <strong>{lowStockAlerts.length} ingredient{lowStockAlerts.length === 1 ? "" : "s"} running low</strong>
                </div>
                <button
                  type="button"
                  className="compact-btn register-stock-toggle-btn"
                  onClick={() => setShowStockAlertDetails((prev) => !prev)}
                  aria-expanded={showStockAlertDetails}
                >
                  {showStockAlertDetails ? "Hide details" : "View ingredients"}
                </button>
              </div>
              {showStockAlertDetails && (
                <div className="register-stock-list">
                  {lowStockAlerts.map((item) => (
                    <div key={item.id} className={`register-stock-item ${item.is_out_of_stock ? "out" : "warning"}`}>
                      <div className="stock-item-info">
                        <span className="stock-item-name">{item.name}</span>
                        <span className="stock-item-qty">
                          <strong>{Number(item.eligible_stock).toLocaleString()} {item.unit}</strong> left
                          <small> (min threshold: {Number(item.minimum_stock).toLocaleString()} {item.unit})</small>
                        </span>
                      </div>
                      <span className={`stock-item-badge ${item.is_out_of_stock ? "danger" : "warning"}`}>
                        {item.is_out_of_stock ? "Out of stock" : "Low stock"}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </section>
          )}
          <Field
            label="Find a drink (Press / to search)"
            placeholder="Search name or category"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          >
            <input
              ref={searchInputRef}
              aria-label="Find a drink"
              placeholder="Search name or category (Press / to focus, Esc to clear)"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Escape") {
                  setSearch("");
                  e.currentTarget.blur();
                }
              }}
            />
          </Field>
          {menuCategories.length > 0 && (
            <div className="quick-preset-row register-category-row">
              <div className="quick-chip-group">
                <button
                  type="button"
                  className={`compact-btn quick-chip${categoryFilter === "ALL" ? " active" : ""}`}
                  onClick={() => setCategoryFilter("ALL")}
                >
                  All drinks
                </button>
                {menuCategories.map((cat) => (
                  <button
                    key={cat}
                    type="button"
                    className={`compact-btn quick-chip${categoryFilter === cat ? " active" : ""}`}
                    onClick={() => setCategoryFilter(cat)}
                  >
                    {cat}
                  </button>
                ))}
                {(search || categoryFilter !== "ALL") && (
                  <button
                    type="button"
                    className="compact-btn"
                    onClick={() => {
                      setSearch("");
                      setCategoryFilter("ALL");
                    }}
                  >
                    Reset filter
                  </button>
                )}
              </div>
            </div>
          )}
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
                  <div className="drink-card-top">
                    <DrinkMedia
                      name={p.name}
                      category={p.category}
                      imageFilename={p.image_filename}
                      variants={p.variants}
                    />
                    <div className="drink-card-title">
                      <p className="eyebrow">{p.category}</p>
                      <h2>{p.name}</h2>
                    </div>
                  </div>
                  <div className="variant-grid">
                    {p.variants.map((v) => {
                      const isOut = v.available_count < 1;
                      const isLow = v.available_count > 0 && (v.available_count <= 5 || v.has_low_ingredient);
                      return (
                        <button
                          key={v.id}
                          aria-label={"Add " + variantLabel(v)}
                          disabled={busy || !ready || isOut}
                          onClick={() => add(v)}
                          className={`variant-card-btn${isOut ? " is-out" : isLow ? " is-low" : ""}`}
                        >
                          <strong>
                            {v.size} · {{ S: 12, M: 16, L: 22 }[v.size]} oz
                            {v.temperature === "NA"
                              ? ""
                              : " · " +
                                (v.temperature === "ICED" ? "Iced" : "Hot")}
                          </strong>
                          <span>{money(v.price)}</span>
                          <small className={`variant-stock-badge ${isOut ? "badge-out" : isLow ? "badge-low" : "badge-ok"}`}>
                            {isOut
                              ? (v.recipe_ready ? "Out of stock" : "Recipe needed")
                              : isLow
                                ? (v.has_low_ingredient && v.available_count > 5
                                    ? `Low ingredient (${v.available_count} left)`
                                    : `Low: ${v.available_count} left`)
                                : `${v.available_count} available`}
                          </small>
                        </button>
                      );
                    })}
                  </div>
                </article>
              ))}
            </div>
          )}
        </section>
        <aside className="panel basket">
          <div className="basket-header">
            <div className="basket-title-row">
              <h2>Current order</h2>
              <div className="basket-badges">
                {basket.length > 0 && (
                  <span className="basket-count-pill">
                    {basket.reduce((sum, item) => sum + item.qty, 0)}{" "}
                    {basket.reduce((sum, item) => sum + item.qty, 0) === 1 ? "cup" : "cups"}
                  </span>
                )}
                <span className="pill basket-method-pill">{paymentMethodLabel(paymentMethod)}</span>
              </div>
            </div>
            {(history.some((o) => o.state === "confirmed" && !o.voided) || basket.length > 0) && (
              <div className="basket-quick-actions">
                {history.some((o) => o.state === "confirmed" && !o.voided) && (
                  <button
                    type="button"
                    className="compact-btn"
                    disabled={busy || !ready}
                    onClick={repeatLastOrder}
                  >
                    Repeat last order
                  </button>
                )}
                {basket.length > 0 && (
                  <button
                    type="button"
                    className="clear-order-btn"
                    disabled={busy || !ready}
                    onClick={() =>
                      edit({
                        basket: [],
                        cash: "",
                        customer_name: "",
                        payment_method: "CASH",
                        payment_reference: "",
                      })
                    }
                  >
                    Clear order
                  </button>
                )}
              </div>
            )}
          </div>
          {!basket.length ? (
            <div className="basket-empty-state">
              <span className="basket-empty-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M5 9h11v4.5a5.5 5.5 0 0 1-11 0V9z" />
                  <path d="M16 10h1.5a2.5 2.5 0 0 1 0 5H16" />
                  <path d="M4 19.5h13" />
                </svg>
              </span>
              <p className="subtle basket-empty">Select a size to add a drink.</p>
            </div>
          ) : (
            <div className="basket-items-scroll">
              {basket.map((line) => {
                const currentVariant = menu?.variants?.find((cand) => Number(cand.id) === Number(line.id));
                const hasLowIng = Boolean(currentVariant?.has_low_ingredient ?? line.has_low_ingredient);
                const maxAvail = currentVariant?.available_count ?? line.available_count;
                const isOut = maxAvail !== undefined && maxAvail < 1;
                const exceedsAvail = maxAvail !== undefined && line.qty > maxAvail;
                return (
                  <div className="basket-line" key={line.id}>
                    <div className="basket-line-info">
                      <div className="basket-line-title">
                        <strong>{variantLabel(line)}</strong>
                        {isOut ? (
                          <span className="basket-alert-tag danger">Out of stock</span>
                        ) : exceedsAvail ? (
                          <span className="basket-alert-tag danger">Exceeds stock ({maxAvail} left)</span>
                        ) : hasLowIng ? (
                          <span className="basket-alert-tag warning">Low ingredient stock</span>
                        ) : null}
                      </div>
                      <p>{money(line.price)} each</p>
                    </div>
                    <div className="basket-line-controls">
                      <div className="quantity-controls">
                        <button
                          type="button"
                          aria-label={"Remove one " + variantLabel(line)}
                          onClick={() => adjust(line.id, -1)}
                          disabled={busy || !ready}
                        >
                          −
                        </button>
                        <span>{line.qty}</span>
                        <button
                          type="button"
                          aria-label={"Add one " + variantLabel(line)}
                          onClick={() => adjust(line.id, 1)}
                          disabled={busy || !ready || line.qty >= 100}
                        >
                          +
                        </button>
                      </div>
                      <strong className="basket-line-subtotal">
                        {money(cashString(cents(line.price) * line.qty))}
                      </strong>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
          <div className="basket-total-card">
            <div className="checkout-total">
              <span>Total</span>
              <strong>{money(cashString(total))}</strong>
            </div>
            <p className="subtle small basket-vat-note">
              Includes 12% VAT. Pending orders do not reserve stock.
            </p>
          </div>
          <div className="basket-payment-card">
            <Field
              label="Customer name / Cup note (optional)"
              maxLength={100}
              placeholder="e.g. Sarah / Table 4 / Less sweet"
              value={customerName}
              onChange={(e) => edit({ customer_name: e.target.value })}
              disabled={busy || !ready}
            />
            <div className="basket-divider" aria-hidden="true" />
            <div className="quick-preset-row payment-method-row">
              <span className="preset-caption">Payment method (1-click):</span>
              <div className="quick-chip-group">
                {PAYMENT_METHODS.map((pm) => {
                  const isActive = paymentMethod === pm.code;
                  return (
                    <button
                      key={pm.code}
                      type="button"
                      disabled={busy || !ready}
                      className={`compact-btn quick-chip${isActive ? " active" : ""}`}
                      onClick={() => {
                        const nextChanges = { payment_method: pm.code };
                        if (pm.code !== "CASH" && total > 0) {
                          nextChanges.cash = cashString(total);
                        } else if (pm.code === "CASH") {
                          nextChanges.payment_reference = "";
                        }
                        edit(nextChanges);
                      }}
                    >
                      {pm.label}
                    </button>
                  );
                })}
              </div>
            </div>
            {paymentMethod !== "CASH" && (
              <Field
                label={`${paymentMethodLabel(paymentMethod)} reference # (optional · last 4–13 digits)`}
                maxLength={80}
                placeholder={`e.g. ${paymentMethod === "GCASH" ? "GCash" : paymentMethod === "MAYA" ? "Maya" : "QR / Terminal"} Ref #904218`}
                value={paymentReference}
                onChange={(e) => edit({ payment_reference: e.target.value })}
                disabled={busy || !ready}
              />
            )}
            <Field
              label={
                paymentMethod === "CASH"
                  ? "Cash received"
                  : `${paymentMethodLabel(paymentMethod)} amount received`
              }
              inputMode="decimal"
              placeholder="0.00"
              value={cash}
              onChange={(e) => edit({ cash: e.target.value })}
              disabled={busy || !ready}
            />
            {total > 0 && (
              <div className="cash-preset-row">
                <button
                  type="button"
                  className="compact-btn"
                  disabled={busy || !ready}
                  title="Shortcut: Alt + E"
                  onClick={() => edit({ cash: cashString(total) })}
                >
                  Exact
                </button>
                {[100, 200, 500, 1000].map((amt) => (
                  <button
                    key={amt}
                    type="button"
                    className="compact-btn"
                    disabled={busy || !ready}
                    onClick={() => edit({ cash: String(amt) })}
                  >
                    ₱{amt}
                  </button>
                ))}
              </div>
            )}
            <div
              className={`change${
                total > 0 && cashCents !== null && cashCents >= total
                  ? " is-ready"
                  : cashCents !== null && cashCents < total
                    ? " is-short"
                    : ""
              }`}
            >
              <span>Change</span>
              <strong>
                {cashCents !== null && cashCents >= total
                  ? money(cashString(cashCents - total))
                  : "—"}
              </strong>
            </div>
            {cashCents !== null && cashCents < total && (
              <p className="small basket-short-warning">Cash received is below the total.</p>
            )}
          </div>
          <div className="basket-cta-footer">
            <button
              className="primary full charge-cta-btn"
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
            <p className="subtle small basket-draft-status" role="status">
              {saving
                ? "Saving draft…"
                : ready
                  ? "Draft saved on this device."
                  : "Loading saved draft…"}
            </p>
          </div>
          {review && (
            <section
              ref={reviewRef}
              className="checkout-review"
              aria-label="Review payment"
            >
              <h3>Confirm {paymentMethodLabel(paymentMethod)} payment</h3>
              <p>
                {paymentMethod === "CASH" ? (
                  <>
                    Collect {money(cashString(total))} and return{" "}
                    {money(cashString(cashCents - total))} change.
                  </>
                ) : (
                  <>
                    Verify {paymentMethodLabel(paymentMethod)} payment of{" "}
                    <strong>{money(cashString(total))}</strong>
                    {paymentReference.trim()
                      ? ` (Ref #${paymentReference.trim()})`
                      : ""}
                    {cashCents > total
                      ? ` · Change: ${money(cashString(cashCents - total))}`
                      : "."}
                  </>
                )}
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
          onPrint={() => window.print()}
          onVoid={(s) => setVoidTarget(s)}
          onReloadItems={reloadSaleToBasket}
        />
      )}
      <section className="panel section-space no-print">
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
                  <th>Total</th>
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
                    <td>{money(o.sale?.total_gross ?? o.body?.cash_received)}</td>
                    <td>
                      {o.state === "confirmed" && o.sale ? (
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
