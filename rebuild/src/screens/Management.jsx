import { useEffect, useRef, useState } from "react";
import { api, getHostEpoch } from "../api";
import {
  Reports,
  History,
  Staff,
  BackupRestore,
  StockOperations,
  containerStatus,
  download,
} from "./Stage4";
import AccountSecurity from "./AccountSecurity";
import { transactionKey } from "../checkout-store";
import {
  money,
  variantLabel,
  productImageUrl,
  ErrorNotice,
  Field,
  Select,
  Receipt,
  VoidSaleModal,
} from "../components/workflow";

function Active({ value, onChange }) {
  return (
    <Select
      label="Status"
      value={value}
      onChange={(e) => onChange(Number(e.target.value))}
    >
      <option value="1">Active</option>
      <option value="0">Inactive</option>
    </Select>
  );
}
function Table({ headers, children }) {
  return (
    <div className="table-scroll">
      <table>
        <thead>
          <tr>
            {headers.map((h) => (
              <th key={h}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}
const emptyIngredient = {
  name: "",
  unit: "mL",
  minimum_stock: "0",
  opened_shelf_life_hours: "",
  is_active: 1,
};
const emptyProduct = {
  name: "",
  category: "Coffee",
  image_filename: null,
  image_data: "",
  remove_image: 0,
  is_active: 1,
};
const emptyVariant = {
  product_id: "",
  size: "M",
  temperature: "HOT",
  sku: "",
  price: "",
  is_active: 1,
};

function cleanCodeChars(str) {
  return String(str || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toUpperCase()
    .replace(/[^A-Z0-9\s-]/g, " ")
    .trim();
}

function twoCharCode(text, fallback = "XX") {
  const cleaned = cleanCodeChars(text);
  if (!cleaned) return fallback;
  const words = cleaned.split(/[\s-]+/).filter(Boolean);
  if (words.length >= 2) {
    return (words[0][0] + words[1][0]).slice(0, 2);
  }
  const word = words[0];
  if (word.length <= 2) return word.padEnd(2, "X");
  const consonants = word.slice(1).replace(/[AEIOU]/g, "");
  return word[0] + (consonants[0] || word[1]);
}

function threeCharBrand(shopName) {
  const cleaned = cleanCodeChars(shopName || "Nearby Cafe");
  const words = cleaned.split(/[\s-]+/).filter(Boolean);
  if (words.length >= 3) {
    return (words[0][0] + words[1][0] + words[2][0]).slice(0, 3);
  }
  if (words.length === 2) {
    const w1 = words[0].replace(/[AEIOU]/g, "");
    const secondChar = w1.length >= 2 ? w1[1] : words[0][1] || "X";
    return (words[0][0] + secondChar + words[1][0]).slice(0, 3);
  }
  if (words.length === 1 && words[0].length >= 3) {
    return words[0].slice(0, 3);
  }
  return "NBC";
}

function generateVariantSku(productObj, size, temperature, existingVariants = [], currentId = null, shopName = "Nearby Café") {
  if (!productObj) return "";
  const brandCode = threeCharBrand(shopName);
  const catCode = twoCharCode(productObj.category, "CF");
  const prodCode = twoCharCode(productObj.name, "DR");
  const sizeOz = { S: "12", M: "16", L: "22" }[size] || "16";
  const tempCode = { HOT: "HT", ICED: "IC", NA: "NA" }[temperature] || "HT";
  const baseSku = `${brandCode}-${catCode}-${prodCode}-${sizeOz}-${tempCode}`;

  const taken = new Set(
    (existingVariants || [])
      .filter((v) => String(v.id) !== String(currentId || ""))
      .map((v) => String(v.sku || "").toUpperCase()),
  );
  if (!taken.has(baseSku)) return baseSku;
  let seq = 2;
  while (taken.has(`${baseSku}-${String(seq).padStart(2, "0")}`) && seq < 99) {
    seq++;
  }
  return `${baseSku}-${String(seq).padStart(2, "0")}`;
}
const emptyDelivery = {
  ingredient_id: "",
  container_count: "1",
  quantity_per_container: "",
  expiration_date: "",
  reference: "",
  supplier: "",
  lot_reference: "",
};

function generateDeliveryCodes(ingredientObj, existingBatches = [], serverTime = "") {
  const rawDate = (serverTime || new Date().toISOString()).slice(0, 10).replace(/-/g, "");
  const ingCode = ingredientObj ? twoCharCode(ingredientObj.name, "IN") : "IN";
  const prefix = `DEL-${rawDate}-${ingCode}`;
  const taken = new Set(
    (existingBatches || []).map((b) => String(b.reference || "").toUpperCase()),
  );
  let seq = 1;
  while (taken.has(`${prefix}-${String(seq).padStart(2, "0")}`) && seq < 99) {
    seq++;
  }
  const suffix = `${rawDate}-${ingCode}-${String(seq).padStart(2, "0")}`;
  return {
    reference: `DEL-${suffix}`,
    lot_reference: `LOT-${suffix}`,
  };
}

function addDaysToIsoDate(baseDateStr, days) {
  const clean = (baseDateStr || new Date().toISOString()).slice(0, 10);
  const [y, m, d] = clean.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days));
  return dt.toISOString().slice(0, 10);
}

export default function Management({ user, token, expired }) {
  const [recoveryPrepared, setRecoveryPrepared] = useState(Boolean(user?.email));
  useEffect(() => {
    setRecoveryPrepared(Boolean(user?.email));
  }, [user?.email]);
  const [data, setData] = useState(null),
    [tab, setTab] = useState("Overview"),
    [error, setError] = useState(null),
    [notice, setNotice] = useState(""),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(true),
    [receipt, setReceipt] = useState(null),
    [voidTarget, setVoidTarget] = useState(null),
    [presetStockOp, setPresetStockOp] = useState(null);
  const [ingredient, setIngredient] = useState(emptyIngredient),
    [product, setProduct] = useState(emptyProduct),
    [variant, setVariant] = useState(emptyVariant),
    [skuCustom, setSkuCustom] = useState(false),
    [recipeVariant, setRecipeVariant] = useState(""),
    [copySourceVariant, setCopySourceVariant] = useState(""),
    [rows, setRows] = useState([]),
    [delivery, setDelivery] = useState(emptyDelivery);
  const sending = useRef(false),
    deliveryAttempt = useRef(null),
    productImageInputRef = useRef(null);
  const deliveryStorage = `venuevue.rebuild.delivery.${user.id}`;
  useEffect(() => {
    try {
      const saved = localStorage.getItem(deliveryStorage);
      if (saved) {
        const attempt = JSON.parse(saved);
        deliveryAttempt.current = attempt;
        setDelivery(JSON.parse(attempt.signature));
        setNotice(
          "A delivery is awaiting confirmation. Retry its original details from Stock.",
        );
      }
    } catch {
      setError(
        new Error(
          "Saved delivery could not be read. Restore browser storage before receiving stock.",
        ),
      );
    }
  }, [deliveryStorage]);
  async function refresh() {
    setLoading(true);
    try {
      setData(await api("management", { token }));
      return true;
    } catch (e) {
      setError(e);
      if (e.status === 401) expired();
      return false;
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    refresh();
  }, [token]);
  async function save(action, values, reset) {
    if (sending.current) return;
    sending.current = true;
    setBusy(true);
    setError(null);
    setNotice("");
    try {
      const result = await api("management/save", {
        token,
        body: { action, ...values },
      });
      reset?.();
      setNotice(
        result.duplicate
          ? "Previously received delivery confirmed. No stock added twice."
          : "Saved successfully.",
      );
      await refresh();
    } catch (e) {
      setError(e);
      if (
        action === "receive" &&
        [400, 403, 409, 422].includes(e.status) &&
        e.code !== "INVALID_RESPONSE"
      ) {
        try {
          localStorage.removeItem(deliveryStorage);
          deliveryAttempt.current = null;
        } catch {
          /* Keep the original request until storage works. */
        }
      }
      if (e.status === 401) expired();
    } finally {
      sending.current = false;
      setBusy(false);
    }
  }
  function chooseRecipe(id) {
    setRecipeVariant(id);
    setRows(
      data.recipes
        .filter((r) => Number(r.variant_id) === Number(id))
        .map((r) => ({ ingredient_id: String(r.ingredient_id), qty: r.qty })),
    );
    const targetVar = (data?.variants ?? []).find(
      (v) => Number(v.id) === Number(id),
    );
    const variantsWithRecipeIds = new Set(
      (data?.recipes ?? []).map((r) => Number(r.variant_id)),
    );
    const siblingWithRecipe = (data?.variants ?? []).find(
      (v) =>
        Number(v.id) !== Number(id) &&
        variantsWithRecipeIds.has(Number(v.id)) &&
        Number(v.product_id) === Number(targetVar?.product_id),
    );
    const anyWithRecipe = (data?.variants ?? []).find(
      (v) =>
        Number(v.id) !== Number(id) && variantsWithRecipeIds.has(Number(v.id)),
    );
    setCopySourceVariant(
      siblingWithRecipe
        ? String(siblingWithRecipe.id)
        : anyWithRecipe
          ? String(anyWithRecipe.id)
          : "",
    );
  }
  function copyRecipeFromVariant(sourceVariantId, scaleBySize = false) {
    if (!sourceVariantId || !recipeVariant) return;
    const sourceRows = (data?.recipes ?? []).filter(
      (r) => Number(r.variant_id) === Number(sourceVariantId),
    );
    if (!sourceRows.length) return;
    const sizeOunces = { S: 12, M: 16, L: 22 };
    const targetVar = (data?.variants ?? []).find(
      (v) => Number(v.id) === Number(recipeVariant),
    );
    const sourceVar = (data?.variants ?? []).find(
      (v) => Number(v.id) === Number(sourceVariantId),
    );
    const targetOz = sizeOunces[targetVar?.size] || 16;
    const sourceOz = sizeOunces[sourceVar?.size] || 16;
    const ratio = scaleBySize && sourceOz > 0 ? targetOz / sourceOz : 1;
    const ingMap = new Map(
      (data?.ingredients ?? []).map((i) => [Number(i.id), i]),
    );
    const copied = sourceRows.map((r) => {
      const ing = ingMap.get(Number(r.ingredient_id));
      const unit = String(ing?.unit || "").toLowerCase();
      const rawQty = Number(r.qty) || 0;
      let nextQty = rawQty;
      if (scaleBySize && ratio !== 1 && unit !== "units") {
        const scaled = rawQty * ratio;
        nextQty =
          scaled >= 5 ? Math.round(scaled) : Math.round(scaled * 100) / 100;
      }
      return {
        ingredient_id: String(r.ingredient_id),
        qty: String(nextQty),
      };
    });
    setRows(copied);
    setNotice(
      scaleBySize && ratio !== 1
        ? `Copied ${copied.length} ingredient(s) from ${sourceVar?.sku || "source"} and scaled ${sourceOz}oz → ${targetOz}oz. Click Save recipe to confirm.`
        : `Copied ${copied.length} ingredient(s) from ${sourceVar?.sku || "source"}. Click Save recipe to confirm.`,
    );
  }
  async function remove(kind, id, reset) {
    if (
      sending.current ||
      !window.confirm(
        `Delete this unused ${kind}? Records with history must be made inactive instead.`,
      )
    )
      return;
    sending.current = true;
    setBusy(true);
    setError(null);
    setNotice("");
    try {
      await api("management/delete", { token, body: { kind, id } });
      reset();
      await refresh();
      setNotice("Unused record deleted.");
    } catch (e) {
      setError(e);
      if (e.status === 401) expired();
    } finally {
      sending.current = false;
      setBusy(false);
    }
  }
  function receive(e) {
    e.preventDefault();
    const signature = JSON.stringify(delivery);
    if (
      deliveryAttempt.current &&
      deliveryAttempt.current.signature !== signature
    ) {
      setError(
        new Error(
          "Resolve the previous delivery by retrying its original details before changing this form.",
        ),
      );
      return;
    }
    deliveryAttempt.current ??= {
      signature,
      key: transactionKey(),
      epoch: getHostEpoch(),
    };
    try {
      localStorage.setItem(
        deliveryStorage,
        JSON.stringify(deliveryAttempt.current),
      );
    } catch {
      setError(
        new Error(
          "Cannot save the delivery request on this device. Enable browser storage before receiving stock.",
        ),
      );
      return;
    }
    save(
      "receive",
      {
        ...delivery,
        delivery_key: deliveryAttempt.current.key,
        expected_epoch: deliveryAttempt.current.epoch,
      },
      () => {
        localStorage.removeItem(deliveryStorage);
        deliveryAttempt.current = null;
        setDelivery(emptyDelivery);
      },
    );
  }
  async function showReceipt(id) {
    setError(null);
    try {
      setReceipt(await api("sales/receipt", { token, query: { id } }));
    } catch (e) {
      setError(e);
      if (e.status === 401) expired();
    }
  }
  function openQuickWaste(c, b) {
    const st = containerStatus(c, b, data?.server_time);
    setPresetStockOp({
      kind: "waste",
      id: String(c.id),
      quantity: String(c.remaining_qty ?? ""),
      expected_remaining: String(c.remaining_qty ?? ""),
      reason: st.defaultReason || "Expired date",
    });
    setNotice("");
    setError(null);
    setTab("Stock operations");
  }
  function openQuickAdjust(c) {
    setPresetStockOp({
      kind: "adjust",
      id: String(c.id),
      quantity: String(c.remaining_qty ?? ""),
      expected_remaining: String(c.remaining_qty ?? ""),
      reason: "Physical shift count adjustment",
    });
    setNotice("");
    setError(null);
    setTab("Stock operations");
  }
  function openQuickBatchEdit(b) {
    setPresetStockOp({
      kind: "batch",
      id: String(b.id),
      expiration_date: b.expiration_date ?? "",
      supplier: b.supplier ?? "",
      lot_reference: b.lot_reference ?? "",
      quarantined: b.quarantined ?? 0,
      reason: "Batch metadata / expiry correction",
    });
    setNotice("");
    setError(null);
    setTab("Stock operations");
  }
  function openQuickReceiveStock(ingredientId) {
    const ingObj = (data?.ingredients ?? []).find(
      (x) => String(x.id) === String(ingredientId),
    );
    const autoCodes = ingObj
      ? generateDeliveryCodes(ingObj, data?.batches, data?.server_time)
      : { reference: "", lot_reference: "" };
    setDelivery({
      ...emptyDelivery,
      ingredient_id: String(ingredientId),
      reference: autoCodes.reference,
      lot_reference: autoCodes.lot_reference,
    });
    setNotice("");
    setError(null);
    setTab("Stock");
  }
  function openIngredientExpiredWaste(ingredientId) {
    const batchesForIng = (data?.batches ?? []).filter(
      (b) => Number(b.ingredient_id) === Number(ingredientId),
    );
    const batchMap = new Map(batchesForIng.map((b) => [Number(b.id), b]));
    const target = (data?.containers ?? []).find((c) => {
      const b = batchMap.get(Number(c.batch_id));
      return b && containerStatus(c, b, data?.server_time).isWasteDue;
    });
    if (target) {
      openQuickWaste(target, batchMap.get(Number(target.batch_id)));
    } else {
      setNotice("");
      setError(null);
      setTab("Stock operations");
    }
  }
  function handleProductImageSelect(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    if (!/^image\/(jpeg|jpg|png|webp)$/i.test(file.type)) {
      setError(new Error("Choose a JPG, PNG or WebP image."));
      e.target.value = "";
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      setError(new Error("Selected image is too large (max 10 MB)."));
      e.target.value = "";
      return;
    }
    setError(null);
    const reader = new FileReader();
    reader.onload = () => {
      const dataUrl = typeof reader.result === "string" ? reader.result : "";
      if (!dataUrl) return;
      const img = new Image();
      img.onload = () => {
        try {
          const maxDim = 600;
          let width = img.naturalWidth || img.width || maxDim;
          let height = img.naturalHeight || img.height || maxDim;
          if (width > maxDim || height > maxDim || file.size > 1500 * 1024) {
            if (width >= height) {
              height = Math.max(1, Math.round((height * maxDim) / width));
              width = maxDim;
            } else {
              width = Math.max(1, Math.round((width * maxDim) / height));
              height = maxDim;
            }
            const canvas = document.createElement("canvas");
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext("2d");
            if (ctx) {
              ctx.drawImage(img, 0, 0, width, height);
              const outMime =
                file.type === "image/png" ? "image/png" : "image/jpeg";
              const compressed = canvas.toDataURL(outMime, 0.86);
              if (
                compressed &&
                compressed.startsWith("data:image/") &&
                compressed.length < 2.5 * 1024 * 1024
              ) {
                setProduct((prev) => ({
                  ...prev,
                  image_data: compressed,
                  remove_image: 0,
                }));
                return;
              }
            }
          }
        } catch {
          /* Fall back to raw dataUrl below */
        }
        if (file.size > 2 * 1024 * 1024) {
          setError(new Error("Product image must be 2 MB or smaller."));
          return;
        }
        setProduct((prev) => ({
          ...prev,
          image_data: dataUrl,
          remove_image: 0,
        }));
      };
      img.onerror = () => {
        setError(new Error("Selected image could not be decoded."));
      };
      img.src = dataUrl;
    };
    reader.readAsDataURL(file);
  }
  const input = (obj, set, key) => ({
    value: obj[key] ?? "",
    onChange: (e) => set({ ...obj, [key]: e.target.value }),
  });
  const ingredients = data?.ingredients ?? [];
  return (
    <>
      <div className="page-heading">
        <div>
          <p className="eyebrow">YOUR SHOP, AT A GLANCE</p>
          <h1>Management</h1>
          <p className="subtle">
            Your menu, stock, sales and team — all in one place.
          </p>
        </div>
        <button
          disabled={busy || loading}
          onClick={() => {
            setError(null);
            refresh();
          }}
        >
          Refresh records
        </button>
      </div>
      {recoveryPrepared === false && tab !== "Account security" && <section className="panel section-space" aria-label="Prepare Owner recovery"><h2>Protect your Owner account</h2><p>Set up your recovery email and Gmail SMTP in Account security so you can reset your password anytime.</p><button className="primary" onClick={() => setTab("Account security")}>Set up email recovery</button></section>}
      <nav className="tabs" aria-label="Management sections">
        {[
          "Overview",
          "Ingredients",
          "Catalogue",
          "Recipes",
          "Stock",
          "Stock operations",
          "Reports",
          "Audit",
          "Staff",
          "Backup & restore",
          "Account security",
        ].map((t) => (
          <button
            key={t}
            aria-current={tab === t ? "page" : undefined}
            onClick={() => {
              setTab(t);
              setNotice("");
              setError(null);
            }}
          >
            {t}
          </button>
        ))}
      </nav>
      <ErrorNotice error={error} />
      {notice && (
        <p className="success" role="status">
          {notice}
        </p>
      )}
      <VoidSaleModal
        sale={voidTarget}
        user={user}
        token={token}
        onClose={() => setVoidTarget(null)}
        onVoided={async (updated) => {
          setVoidTarget(null);
          setReceipt(updated);
          setNotice(`Sale #${updated.id} has been voided.`);
          await refresh();
        }}
      />
      {loading && !data ? (
        <p role="status">Loading shop records…</p>
      ) : !data ? (
        <p className="subtle">Use Refresh records to try again.</p>
      ) : (
        <>
          {tab === "Stock operations" && (
            <StockOperations
              user={user}
              data={data}
              token={token}
              expired={expired}
              refresh={refresh}
              presetOp={presetStockOp}
              onPresetConsumed={() => setPresetStockOp(null)}
            />
          )}
          {tab === "Reports" && <Reports token={token} expired={expired} />}
          {tab === "Audit" && <History token={token} expired={expired} />}
          {tab === "Staff" && (
            <Staff
              token={token}
              expired={expired}
              onResetPassword={() => setTab("Account security")}
            />
          )}
          {tab === "Backup & restore" && (
            <BackupRestore token={token} expired={expired} />
          )}
          {tab === "Account security" && (
            <AccountSecurity user={user} token={token} expired={expired} onPrepared={setRecoveryPrepared} />
          )}
          {tab === "Overview" && (
            <>
              <div className="section-heading">
                <p className="subtle">{data.sales.date} · Manila time</p>
                <div className="button-row">
                  <button
                    type="button"
                    className="primary compact-btn"
                    disabled={busy}
                    onClick={async () => {
                      setBusy(true);
                      setError(null);
                      setNotice("");
                      try {
                        const file = await api("management/csv", {
                          token,
                          query: {
                            from: data.sales.date,
                            to: data.sales.date,
                            format: "xlsx_structured",
                            include_voided: "1",
                          },
                          timeout: 60000,
                        });
                        download(
                          file.filename,
                          file.content,
                          file.mime ||
                            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                          file.encoding || "base64",
                        );
                        setNotice(
                          `Downloaded today's Z-Reading Excel workbook (${file.filename}).`,
                        );
                      } catch (e) {
                        setError(e);
                        if (e.status === 401) expired();
                      } finally {
                        setBusy(false);
                      }
                    }}
                  >
                    Download Today’s Z-Reading (.xlsx)
                  </button>
                  <button
                    type="button"
                    className="compact-btn"
                    onClick={() => {
                      setNotice("");
                      setError(null);
                      setTab("Reports");
                    }}
                  >
                    Open Reports →
                  </button>
                </div>
              </div>
              <div className="stats">
                {[
                  ["Sales", data.sales.totals.count],
                  ["Gross sales", money(data.sales.totals.gross)],
                  ["VATable sales", money(data.sales.totals.net)],
                  ["VAT (12%)", money(data.sales.totals.vat)],
                ].map(([name, value]) => (
                  <section className="panel stat" key={name}>
                    <p>{name}</p>
                    <strong>{value}</strong>
                  </section>
                ))}
              </div>
              <section className="panel">
                <div className="section-heading">
                  <h2>Stock alerts</h2>
                  {ingredients.some(
                    (i) => Number(i.is_active) && Number(i.waste_due) > 0,
                  ) && (
                    <button
                      type="button"
                      className="compact-btn"
                      onClick={() => {
                        setNotice("");
                        setError(null);
                        setTab("Stock operations");
                      }}
                    >
                      Manage expired stock →
                    </button>
                  )}
                </div>
                {ingredients.filter(
                  (i) =>
                    Number(i.is_active) &&
                    (Number(i.eligible_stock) <= Number(i.minimum_stock) ||
                      Number(i.waste_due) > 0),
                ).length === 0 ? (
                  <p className="subtle">No stock alerts.</p>
                ) : (
                  <ul className="alert-list">
                    {ingredients
                      .filter(
                        (i) =>
                          Number(i.is_active) &&
                          (Number(i.eligible_stock) <=
                            Number(i.minimum_stock) ||
                            Number(i.waste_due) > 0),
                      )
                      .map((i) => (
                        <li key={i.id} className="alert-action-row">
                          <div>
                            <strong>{i.name}</strong> · {i.eligible_stock}{" "}
                            {i.unit} eligible (min {i.minimum_stock} {i.unit})
                            {Number(i.waste_due) > 0 && (
                              <span>
                                {" "}
                                ·{" "}
                                <span className="stock-badge danger">
                                  EXPIRED / USE-BY
                                </span>{" "}
                                {i.waste_due} {i.unit} set aside for waste
                                recording.
                              </span>
                            )}
                          </div>
                          <div className="button-row" style={{ marginTop: 0 }}>
                            {Number(i.eligible_stock) <=
                              Number(i.minimum_stock) && (
                              <button
                                type="button"
                                className="compact-btn"
                                onClick={() => openQuickReceiveStock(i.id)}
                              >
                                Receive stock →
                              </button>
                            )}
                            {Number(i.waste_due) > 0 && (
                              <button
                                type="button"
                                className="danger compact-btn"
                                onClick={() => openIngredientExpiredWaste(i.id)}
                              >
                                Record waste
                              </button>
                            )}
                          </div>
                        </li>
                      ))}
                  </ul>
                )}
              </section>
              <section className="panel section-space">
                <h2>Today’s latest sales</h2>
                {!data.sales.recent.length ? (
                  <p className="subtle">No confirmed sales today.</p>
                ) : (
                  <Table
                    headers={[
                      "Sale",
                      "Time",
                      "Status",
                      "Gross",
                      "Net",
                      "VAT",
                      "Details",
                    ]}
                  >
                    {data.sales.recent.map((s) => (
                      <tr key={s.id}>
                        <td>#{s.id}</td>
                        <td>{s.sale_at}</td>
                        <td>
                          {s.status === "voided" ? (
                            <span className="pill pill-voided">VOIDED</span>
                          ) : (
                            "Completed"
                          )}
                        </td>
                        <td>{money(s.total_gross)}</td>
                        <td>{money(s.total_net)}</td>
                        <td>{money(s.total_vat)}</td>
                        <td>
                          <div className="button-row">
                            <button onClick={() => showReceipt(s.id)}>
                              View sale #{s.id}
                            </button>
                            {s.status !== "voided" && (
                              <button
                                className="danger"
                                onClick={() => setVoidTarget(s)}
                              >
                                Void #{s.id}
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </Table>
                )}
              </section>
              {receipt && (
                <Receipt
                  sale={receipt}
                  onClose={() => setReceipt(null)}
                  onVoid={(s) => setVoidTarget(s)}
                />
              )}
            </>
          )}
          {tab === "Ingredients" && (
            <div className="editor-layout">
              <section className="panel">
                <h2>{ingredient.id ? "Edit ingredient" : "Add ingredient"}</h2>
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    save("ingredient", ingredient, () =>
                      setIngredient(emptyIngredient),
                    );
                  }}
                >
                  <fieldset disabled={busy}>
                    <Field
                      label="Ingredient name"
                      required
                      maxLength={100}
                      {...input(ingredient, setIngredient, "name")}
                    />
                    <Select
                      label="Base unit"
                      disabled={!!ingredient.id}
                      {...input(ingredient, setIngredient, "unit")}
                    >
                      <option value="mL">mL</option>
                      <option value="g">g</option>
                      <option value="units">units</option>
                    </Select>
                    <Field
                      label="Low-stock threshold"
                      required
                      inputMode="decimal"
                      {...input(ingredient, setIngredient, "minimum_stock")}
                    />
                    <Field
                      label="Opened shelf life (hours; blank if not applicable)"
                      type="number"
                      min="1"
                      max="87600"
                      {...input(
                        ingredient,
                        setIngredient,
                        "opened_shelf_life_hours",
                      )}
                    />
                    <p className="subtle small">
                      Starting guide: fresh milk 48 h; plant milk 72 h; syrup
                      720 h; beans 168 h. Existing opened containers keep their
                      recorded use-by.
                    </p>
                    <Active
                      value={ingredient.is_active}
                      onChange={(is_active) =>
                        setIngredient({ ...ingredient, is_active })
                      }
                    />
                    <div className="button-row">
                      <button className="primary">Save ingredient</button>
                      {ingredient.id && (
                        <button
                          type="button"
                          onClick={() =>
                            remove("ingredient", ingredient.id, () =>
                              setIngredient(emptyIngredient),
                            )
                          }
                        >
                          Delete unused ingredient
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => setIngredient(emptyIngredient)}
                      >
                        New ingredient
                      </button>
                    </div>
                  </fieldset>
                </form>
              </section>
              <section className="panel">
                <h2>Ingredient stock</h2>
                {!ingredients.length ? (
                  <p className="subtle">
                    Add your first ingredient, then receive stock.
                  </p>
                ) : (
                  <Table
                    headers={[
                      "Ingredient",
                      "On hand",
                      "Eligible",
                      "Low at",
                      "Opened life",
                      "Status",
                      "Edit",
                    ]}
                  >
                    {ingredients.map((i) => (
                      <tr key={i.id}>
                        <td>
                          {i.name}
                          <small>{i.unit}</small>
                        </td>
                        <td>{i.current_stock}</td>
                        <td>{i.eligible_stock}</td>
                        <td>{i.minimum_stock}</td>
                        <td>{i.opened_shelf_life_hours ?? "—"}</td>
                        <td>{Number(i.is_active) ? "Active" : "Inactive"}</td>
                        <td>
                          <button
                            disabled={busy}
                            onClick={() =>
                              setIngredient({
                                ...i,
                                opened_shelf_life_hours:
                                  i.opened_shelf_life_hours ?? "",
                              })
                            }
                          >
                            Edit {i.name}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </Table>
                )}
              </section>
            </div>
          )}
          {tab === "Catalogue" && (
            <>
              <div className="editor-layout">
                <section className="panel">
                  <h2>{product.id ? "Edit product" : "Add product"}</h2>
                  <form
                    onSubmit={(e) => {
                      e.preventDefault();
                      save("product", product, () => {
                        setProduct(emptyProduct);
                        if (productImageInputRef.current) {
                          productImageInputRef.current.value = "";
                        }
                      });
                    }}
                  >
                    <fieldset disabled={busy}>
                      <Field
                        label="Product name"
                        required
                        maxLength={120}
                        {...input(product, setProduct, "name")}
                      />
                      <Field
                        label="Category"
                        required
                        maxLength={60}
                        {...input(product, setProduct, "category")}
                      />
                      <div className="quick-preset-row">
                        <span className="preset-caption">
                          Quick categories (1-click):
                        </span>
                        <div className="quick-chip-group">
                          {Array.from(
                            new Set([
                              "Coffee",
                              "Espresso",
                              "Matcha",
                              "Specialty",
                              "Non-Coffee",
                              "Pastry",
                              ...(data.products || [])
                                .map((p) => p.category)
                                .filter(Boolean),
                            ]),
                          ).map((cat) => (
                            <button
                              key={cat}
                              type="button"
                              className={`compact-btn quick-chip${product.category === cat ? " active" : ""}`}
                              onClick={() =>
                                setProduct({ ...product, category: cat })
                              }
                            >
                              {cat}
                            </button>
                          ))}
                        </div>
                      </div>
                      <Field label="Choose Image (Optional · JPG, PNG, WebP)">
                        <input
                          ref={productImageInputRef}
                          type="file"
                          accept="image/jpeg,image/png,image/webp"
                          onChange={handleProductImageSelect}
                        />
                      </Field>
                      {(product.image_data ||
                        (product.image_filename && !product.remove_image)) && (
                        <div className="product-image-preview">
                          <img
                            src={
                              product.image_data ||
                              productImageUrl(product.image_filename)
                            }
                            alt={product.name || "Product preview"}
                          />
                          <button
                            type="button"
                            onClick={() => {
                              setProduct({
                                ...product,
                                image_data: "",
                                image_filename: null,
                                remove_image: 1,
                              });
                              if (productImageInputRef.current) {
                                productImageInputRef.current.value = "";
                              }
                            }}
                          >
                            Remove Image
                          </button>
                        </div>
                      )}
                      <Active
                        value={product.is_active}
                        onChange={(is_active) =>
                          setProduct({ ...product, is_active })
                        }
                      />
                      <div className="button-row">
                        <button className="primary">Save product</button>
                        {product.id && (
                          <button
                            type="button"
                            onClick={() =>
                              remove("product", product.id, () => {
                                setProduct(emptyProduct);
                                if (productImageInputRef.current) {
                                  productImageInputRef.current.value = "";
                                }
                              })
                            }
                          >
                            Delete unused product
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => {
                            setProduct(emptyProduct);
                            if (productImageInputRef.current) {
                              productImageInputRef.current.value = "";
                            }
                          }}
                        >
                          New product
                        </button>
                      </div>
                    </fieldset>
                  </form>
                </section>
                <section className="panel">
                  <h2>Products</h2>
                  {!data.products.length ? (
                    <p className="subtle">No products yet.</p>
                  ) : (
                    <Table headers={["Product", "Category", "Status", "Edit"]}>
                      {data.products.map((p) => (
                        <tr key={p.id}>
                          <td>
                            <div className="product-table-cell">
                              {p.image_filename ? (
                                <img
                                  className="product-table-thumb"
                                  src={productImageUrl(p.image_filename)}
                                  alt={p.name}
                                  loading="lazy"
                                />
                              ) : (
                                <span
                                  className="product-table-thumb fallback"
                                  aria-hidden="true"
                                >
                                  {p.name.slice(0, 2).toUpperCase()}
                                </span>
                              )}
                              <span>{p.name}</span>
                            </div>
                          </td>
                          <td>{p.category}</td>
                          <td>{Number(p.is_active) ? "Active" : "Inactive"}</td>
                          <td>
                            <button
                              disabled={busy}
                              onClick={() => {
                                setProduct({
                                  ...p,
                                  image_data: "",
                                  remove_image: 0,
                                });
                                if (productImageInputRef.current) {
                                  productImageInputRef.current.value = "";
                                }
                              }}
                            >
                              Edit {p.name}
                            </button>
                          </td>
                        </tr>
                      ))}
                    </Table>
                  )}
                </section>
              </div>
              <div className="editor-layout section-space">
                <section className="panel">
                  <h2>{variant.id ? "Edit variant" : "Add variant"}</h2>
                  <form
                    onSubmit={(e) => {
                      e.preventDefault();
                      save("variant", variant, () => {
                        setVariant(emptyVariant);
                        setSkuCustom(false);
                      });
                    }}
                  >
                    <fieldset disabled={busy}>
                      <Select
                        label="Variant product"
                        required
                        disabled={!!variant.id}
                        value={variant.product_id}
                        onChange={(e) => {
                          const pid = e.target.value;
                          const prodObj = data.products.find((p) => String(p.id) === String(pid));
                          const nextSku =
                            !skuCustom && prodObj
                              ? generateVariantSku(
                                  prodObj,
                                  variant.size,
                                  variant.temperature,
                                  data.variants,
                                  variant.id,
                                  user?.shop_name,
                                )
                              : variant.sku;
                          setVariant({ ...variant, product_id: pid, sku: nextSku });
                        }}
                      >
                        <option value="">Choose product</option>
                        {data.products.map((p) => (
                          <option key={p.id} value={p.id}>
                            {p.name}
                          </option>
                        ))}
                      </Select>
                      <div className="quick-preset-row">
                        <span className="preset-caption">
                          Quick size & temp combo (1-click):
                        </span>
                        <div className="quick-chip-group">
                          {[
                            ["12oz Hot", "S", "HOT"],
                            ["12oz Iced", "S", "ICED"],
                            ["16oz Hot", "M", "HOT"],
                            ["16oz Iced", "M", "ICED"],
                            ["22oz Hot", "L", "HOT"],
                            ["22oz Iced", "L", "ICED"],
                            ["Single / NA", "M", "NA"],
                          ].map(([label, sz, tmp]) => {
                            const isActive =
                              variant.size === sz &&
                              variant.temperature === tmp;
                            return (
                              <button
                                key={label}
                                type="button"
                                className={`compact-btn quick-chip${isActive ? " active" : ""}`}
                                onClick={() => {
                                  const prodObj = data.products.find(
                                    (p) =>
                                      String(p.id) ===
                                      String(variant.product_id),
                                  );
                                  const nextSku =
                                    !skuCustom && prodObj
                                      ? generateVariantSku(
                                          prodObj,
                                          sz,
                                          tmp,
                                          data.variants,
                                          variant.id,
                                          user?.shop_name,
                                        )
                                      : variant.sku;
                                  setVariant({
                                    ...variant,
                                    size: sz,
                                    temperature: tmp,
                                    sku: nextSku,
                                  });
                                }}
                              >
                                {label}
                              </button>
                            );
                          })}
                        </div>
                      </div>
                      <div className="form-grid">
                        <Select
                          label="Size"
                          value={variant.size}
                          onChange={(e) => {
                            const nextSize = e.target.value;
                            const prodObj = data.products.find(
                              (p) => String(p.id) === String(variant.product_id),
                            );
                            const nextSku =
                              !skuCustom && prodObj
                                ? generateVariantSku(
                                    prodObj,
                                    nextSize,
                                    variant.temperature,
                                    data.variants,
                                    variant.id,
                                    user?.shop_name,
                                  )
                                : variant.sku;
                            setVariant({ ...variant, size: nextSize, sku: nextSku });
                          }}
                        >
                          <option value="S">S · 12 oz</option>
                          <option value="M">M · 16 oz</option>
                          <option value="L">L · 22 oz</option>
                        </Select>
                        <Select
                          label="Temperature"
                          value={variant.temperature}
                          onChange={(e) => {
                            const nextTemp = e.target.value;
                            const prodObj = data.products.find(
                              (p) => String(p.id) === String(variant.product_id),
                            );
                            const nextSku =
                              !skuCustom && prodObj
                                ? generateVariantSku(
                                    prodObj,
                                    variant.size,
                                    nextTemp,
                                    data.variants,
                                    variant.id,
                                    user?.shop_name,
                                  )
                                : variant.sku;
                            setVariant({
                              ...variant,
                              temperature: nextTemp,
                              sku: nextSku,
                            });
                          }}
                        >
                          <option value="HOT">Hot</option>
                          <option value="ICED">Iced</option>
                          <option value="NA">Not applicable</option>
                        </Select>
                      </div>
                      <div className="sku-field-group">
                        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: "8px", marginBottom: "4px" }}>
                          <span style={{ fontWeight: 600 }}>SKU (Auto-generated · Editable)</span>
                          <button
                            type="button"
                            className="compact-btn"
                            disabled={!variant.product_id || busy}
                            onClick={() => {
                              const prodObj = data.products.find(
                                (p) => String(p.id) === String(variant.product_id),
                              );
                              if (!prodObj) return;
                              setSkuCustom(false);
                              setVariant({
                                ...variant,
                                sku: generateVariantSku(
                                  prodObj,
                                  variant.size,
                                  variant.temperature,
                                  data.variants,
                                  variant.id,
                                  user?.shop_name,
                                ),
                              });
                            }}
                          >
                            Auto-generate SKU
                          </button>
                        </div>
                        <input
                          aria-label="SKU"
                          required
                          maxLength={64}
                          pattern="[A-Za-z0-9._\-]+"
                          placeholder="e.g. NRC-ES-SL-16-IC"
                          value={variant.sku}
                          onChange={(e) => {
                            setSkuCustom(true);
                            setVariant({ ...variant, sku: e.target.value.toUpperCase() });
                          }}
                        />
                        <p className="subtle small" style={{ marginTop: "4px" }}>
                          Format: Store-Category-Product-Oz-Temp (e.g. <code>NRC-ES-SL-16-IC</code>). You can customize this code before saving.
                        </p>
                      </div>
                      <Field
                        label="VAT-inclusive price"
                        required
                        inputMode="decimal"
                        {...input(variant, setVariant, "price")}
                      />
                      <div className="quick-preset-row">
                        <span className="preset-caption">
                          Quick prices (1-click):
                        </span>
                        <div className="quick-chip-group">
                          {[
                            "100.00",
                            "110.00",
                            "120.00",
                            "140.00",
                            "150.00",
                            "160.00",
                            "180.00",
                            "200.00",
                          ].map((pVal) => (
                            <button
                              key={pVal}
                              type="button"
                              className={`compact-btn quick-chip${Number(variant.price) === Number(pVal) && variant.price !== "" ? " active" : ""}`}
                              onClick={() =>
                                setVariant({ ...variant, price: pVal })
                              }
                            >
                              ₱{parseInt(pVal, 10)}
                            </button>
                          ))}
                        </div>
                      </div>
                      <Active
                        value={variant.is_active}
                        onChange={(is_active) =>
                          setVariant({ ...variant, is_active })
                        }
                      />
                      <div className="button-row">
                        <button className="primary">Save variant</button>
                        {variant.id && (
                          <button
                            type="button"
                            onClick={() =>
                              remove("variant", variant.id, () => {
                                setVariant(emptyVariant);
                                setSkuCustom(false);
                              })
                            }
                          >
                            Delete unused variant
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => {
                            setVariant(emptyVariant);
                            setSkuCustom(false);
                          }}
                        >
                          New variant
                        </button>
                      </div>
                    </fieldset>
                  </form>
                </section>
                <section className="panel">
                  <h2>Sizes & temperatures</h2>
                  {!data.variants.length ? (
                    <p className="subtle">
                      Create a variant for each offered size and temperature,
                      then add its recipe.
                    </p>
                  ) : (
                    <Table
                      headers={["Variant", "SKU", "Price", "Status", "Edit"]}
                    >
                      {data.variants.map((v) => (
                        <tr key={v.id}>
                          <td>{variantLabel(v, data.products)}</td>
                          <td>{v.sku}</td>
                          <td>{money(v.price)}</td>
                          <td>{Number(v.is_active) ? "Active" : "Inactive"}</td>
                          <td>
                            <button
                              disabled={busy}
                              onClick={() => {
                                setVariant(v);
                                setSkuCustom(true);
                              }}
                            >
                              Edit {v.sku}
                            </button>
                          </td>
                        </tr>
                      ))}
                    </Table>
                  )}
                </section>
              </div>
            </>
          )}
          {tab === "Recipes" && (
            <section className="panel">
              <h2>Recipe per variant</h2>
              <p className="subtle">
                Enter exact base-unit quantities for one drink. Include cups,
                lids, ice and straws where needed. A variant with no recipe
                cannot be sold.
              </p>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  if (
                    !rows.length &&
                    !window.confirm(
                      "Clear this recipe? This variant will become unavailable for sale.",
                    )
                  )
                    return;
                  save("recipe", { variant_id: recipeVariant, rows });
                }}
              >
                <fieldset disabled={busy}>
                  <Select
                    label="Recipe variant"
                    required
                    value={recipeVariant}
                    onChange={(e) => chooseRecipe(e.target.value)}
                  >
                    <option value="">Choose variant</option>
                    {data.variants.map((v) => (
                      <option key={v.id} value={v.id}>
                        {variantLabel(v, data.products)} · {v.sku}
                      </option>
                    ))}
                  </Select>
                  {recipeVariant &&
                    (() => {
                      const variantsWithRecipes = data.variants.filter(
                        (v) =>
                          Number(v.id) !== Number(recipeVariant) &&
                          data.recipes.some(
                            (r) => Number(r.variant_id) === Number(v.id),
                          ),
                      );
                      if (!variantsWithRecipes.length) return null;
                      const sizeOunces = { S: 12, M: 16, L: 22 };
                      const targetVar = data.variants.find(
                        (v) => Number(v.id) === Number(recipeVariant),
                      );
                      const sourceVar = data.variants.find(
                        (v) => Number(v.id) === Number(copySourceVariant),
                      );
                      const targetOz = sizeOunces[targetVar?.size] || 16;
                      const sourceOz = sizeOunces[sourceVar?.size] || 16;
                      return (
                        <div className="recipe-copy-helper">
                          <div className="recipe-copy-controls">
                            <Select
                              label="Copy recipe from another variant (1-click)"
                              value={copySourceVariant}
                              onChange={(e) =>
                                setCopySourceVariant(e.target.value)
                              }
                            >
                              <option value="">Choose source variant</option>
                              {variantsWithRecipes.map((v) => (
                                <option key={v.id} value={v.id}>
                                  {variantLabel(v, data.products)} · {v.sku}
                                </option>
                              ))}
                            </Select>
                          </div>
                          <div className="button-row" style={{ marginTop: 0 }}>
                            <button
                              type="button"
                              className="compact-btn"
                              disabled={!copySourceVariant}
                              onClick={() =>
                                copyRecipeFromVariant(copySourceVariant, false)
                              }
                            >
                              Copy exact quantities
                            </button>
                            {sourceVar && targetOz !== sourceOz && (
                              <button
                                type="button"
                                className="compact-btn"
                                disabled={!copySourceVariant}
                                onClick={() =>
                                  copyRecipeFromVariant(copySourceVariant, true)
                                }
                              >
                                Copy & scale ({sourceOz}oz → {targetOz}oz)
                              </button>
                            )}
                          </div>
                        </div>
                      );
                    })()}
                  {rows.map((r, index) => {
                    const rowIng = ingredients.find(
                      (i) => String(i.id) === String(r.ingredient_id),
                    );
                    const isUnitBased =
                      String(rowIng?.unit || "").toLowerCase() === "units";
                    const quickRecipeQtys = isUnitBased
                      ? ["1", "2", "3"]
                      : ["15", "20", "30", "40", "150", "200", "250"];
                    return (
                      <div className="recipe-row-block" key={index}>
                        <div className="recipe-row">
                          <Select
                            label={`Ingredient ${index + 1}`}
                            required
                            value={r.ingredient_id}
                            onChange={(e) => {
                              const nextIngId = e.target.value;
                              const nextIng = ingredients.find(
                                (i) => String(i.id) === String(nextIngId),
                              );
                              const defaultQty =
                                !r.qty &&
                                String(nextIng?.unit || "").toLowerCase() ===
                                  "units"
                                  ? "1"
                                  : r.qty;
                              setRows(
                                rows.map((x, j) =>
                                  j === index
                                    ? {
                                        ...x,
                                        ingredient_id: nextIngId,
                                        qty: defaultQty,
                                      }
                                    : x,
                                ),
                              );
                            }}
                          >
                            <option value="">Choose ingredient</option>
                            {ingredients.map((i) => (
                              <option
                                key={i.id}
                                value={i.id}
                                disabled={!Number(i.is_active)}
                              >
                                {i.name} ({i.unit})
                                {Number(i.is_active) ? "" : " · inactive"}
                              </option>
                            ))}
                          </Select>
                          <Field
                            label={`Quantity ${index + 1}${rowIng ? ` (${rowIng.unit})` : ""}`}
                            required
                            inputMode="decimal"
                            value={r.qty}
                            onChange={(e) =>
                              setRows(
                                rows.map((x, j) =>
                                  j === index
                                    ? { ...x, qty: e.target.value }
                                    : x,
                                ),
                              )
                            }
                          />
                          <button
                            type="button"
                            aria-label={`Remove ingredient ${index + 1}`}
                            onClick={() =>
                              setRows(rows.filter((_, j) => j !== index))
                            }
                          >
                            Remove
                          </button>
                        </div>
                        <div className="quick-preset-row recipe-qty-presets">
                          <span className="preset-caption">
                            Quick {rowIng?.unit || "qty"}:
                          </span>
                          <div className="quick-chip-group">
                            {quickRecipeQtys.map((qVal) => (
                              <button
                                key={qVal}
                                type="button"
                                className={`compact-btn quick-chip${Number(r.qty) === Number(qVal) && r.qty !== "" ? " active" : ""}`}
                                onClick={() =>
                                  setRows(
                                    rows.map((x, j) =>
                                      j === index ? { ...x, qty: qVal } : x,
                                    ),
                                  )
                                }
                              >
                                {qVal} {rowIng?.unit || ""}
                              </button>
                            ))}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                  <div className="button-row">
                    <button
                      type="button"
                      disabled={!recipeVariant || rows.length >= 100}
                      onClick={() =>
                        setRows([...rows, { ingredient_id: "", qty: "" }])
                      }
                    >
                      Add recipe row
                    </button>
                    <button className="primary" disabled={!recipeVariant}>
                      Save recipe
                    </button>
                  </div>
                </fieldset>
              </form>
            </section>
          )}
          {tab === "Stock" && (
            <>
              <section className="panel">
                <h2>Receive stock</h2>
                <p className="subtle">
                  Each delivery and expiration stays separate. Enter individual
                  container sizes so opening one carton does not start the
                  shelf-life clock for the others.
                </p>
                <form onSubmit={receive}>
                  <fieldset disabled={busy}>
                    <div className="form-grid">
                      <Select
                        label="Delivery ingredient"
                        required
                        value={delivery.ingredient_id}
                        onChange={(e) => {
                          const ingId = e.target.value;
                          const ingObj = ingredients.find(
                            (i) => String(i.id) === String(ingId),
                          );
                          const codes = ingObj
                            ? generateDeliveryCodes(
                                ingObj,
                                data.batches,
                                data.server_time,
                              )
                            : { reference: "", lot_reference: "" };
                          setDelivery({
                            ...delivery,
                            ingredient_id: ingId,
                            reference: codes.reference,
                            lot_reference: codes.lot_reference,
                          });
                        }}
                      >
                        <option value="">Choose ingredient</option>
                        {ingredients
                          .filter((i) => Number(i.is_active))
                          .map((i) => (
                            <option key={i.id} value={i.id}>
                              {i.name} ({i.unit})
                            </option>
                          ))}
                      </Select>
                      <div>
                        <div
                          style={{
                            display: "flex",
                            justifyContent: "space-between",
                            alignItems: "center",
                            gap: "8px",
                            marginBottom: "4px",
                          }}
                        >
                          <span style={{ fontWeight: 600, fontSize: "13px" }}>
                            Delivery reference (Auto · Editable)
                          </span>
                          <button
                            type="button"
                            className="compact-btn"
                            disabled={!delivery.ingredient_id || busy}
                            onClick={() => {
                              const ingObj = ingredients.find(
                                (i) =>
                                  String(i.id) ===
                                  String(delivery.ingredient_id),
                              );
                              const codes = generateDeliveryCodes(
                                ingObj,
                                data.batches,
                                data.server_time,
                              );
                              setDelivery({
                                ...delivery,
                                reference: codes.reference,
                                lot_reference: codes.lot_reference,
                              });
                            }}
                          >
                            Auto-generate codes
                          </button>
                        </div>
                        <input
                          aria-label="Delivery reference"
                          required
                          maxLength={120}
                          placeholder="e.g. DEL-20261103-AE-01"
                          value={delivery.reference}
                          onChange={(e) =>
                            setDelivery({
                              ...delivery,
                              reference: e.target.value,
                            })
                          }
                        />
                      </div>
                      <Field
                        label="Container count"
                        type="number"
                        min="1"
                        max="1000"
                        required
                        {...input(delivery, setDelivery, "container_count")}
                      />
                      <div>
                        <Field
                          label="Quantity per container (base units)"
                          required
                          inputMode="decimal"
                          {...input(
                            delivery,
                            setDelivery,
                            "quantity_per_container",
                          )}
                        />
                        <div className="quick-preset-row compact-preset">
                          <div className="quick-chip-group">
                            {["250", "500", "1000", "2000", "5000"].map((q) => (
                              <button
                                key={q}
                                type="button"
                                className={`compact-btn quick-chip${String(delivery.quantity_per_container) === q ? " active" : ""}`}
                                onClick={() =>
                                  setDelivery({
                                    ...delivery,
                                    quantity_per_container: q,
                                  })
                                }
                              >
                                {q}
                              </button>
                            ))}
                          </div>
                        </div>
                      </div>
                      <div>
                        <Field
                          label="Expiration date"
                          type="date"
                          required={
                            ingredients.find(
                              (i) =>
                                String(i.id) === String(delivery.ingredient_id),
                            )?.opened_shelf_life_hours != null
                          }
                          {...input(delivery, setDelivery, "expiration_date")}
                        />
                        <div className="quick-preset-row compact-preset">
                          <div className="quick-chip-group">
                            {[
                              ["+7 Days", 7],
                              ["+14 Days", 14],
                              ["+1 Month", 30],
                              ["+6 Months", 180],
                              ["+1 Year", 365],
                            ].map(([lbl, days]) => {
                              const targetDate = addDaysToIsoDate(
                                data.server_time,
                                days,
                              );
                              return (
                                <button
                                  key={lbl}
                                  type="button"
                                  className={`compact-btn quick-chip${delivery.expiration_date === targetDate ? " active" : ""}`}
                                  onClick={() =>
                                    setDelivery({
                                      ...delivery,
                                      expiration_date: targetDate,
                                    })
                                  }
                                >
                                  {lbl}
                                </button>
                              );
                            })}
                          </div>
                        </div>
                      </div>
                      <Field
                        label="Supplier (optional)"
                        maxLength={120}
                        {...input(delivery, setDelivery, "supplier")}
                      />
                      <Field
                        label="Lot reference (optional)"
                        maxLength={120}
                        placeholder="e.g. LOT-20261103-AE-01"
                        {...input(delivery, setDelivery, "lot_reference")}
                      />
                    </div>
                    <button className="primary">Receive delivery</button>
                  </fieldset>
                </form>
              </section>
              <section className="panel section-space">
                <h2>Stock batches</h2>
                {!data.batches.length ? (
                  <p className="subtle">No deliveries yet.</p>
                ) : (
                  <Table
                    headers={[
                      "Batch / delivery",
                      "Ingredient",
                      "Expires",
                      "Remaining",
                      "Sealed",
                      "Opened",
                      "Containers",
                    ]}
                  >
                    {data.batches.map((b) => {
                      const todayDate = (data.server_time || "").slice(0, 10);
                      const batchContainers = data.containers.filter(
                        (c) => Number(c.batch_id) === Number(b.id),
                      );
                      const hasWasteDue = batchContainers.some(
                        (c) =>
                          containerStatus(c, b, data.server_time).isWasteDue,
                      );
                      const batchExpired =
                        Number(b.remaining_qty) > 0 &&
                        b.expiration_date &&
                        todayDate &&
                        b.expiration_date < todayDate;
                      const batchExpiresToday =
                        Number(b.remaining_qty) > 0 &&
                        b.expiration_date &&
                        todayDate &&
                        b.expiration_date === todayDate;
                      return (
                        <tr key={b.id}>
                          <td>
                            #{b.id} · {b.reference}
                            <small>
                              {b.supplier} {b.lot_reference}
                            </small>
                            <div style={{ marginTop: "6px" }}>
                              <button
                                type="button"
                                className="compact-btn"
                                onClick={() => openQuickBatchEdit(b)}
                              >
                                Edit batch
                              </button>
                            </div>
                          </td>
                          <td>
                            {b.ingredient_name} ({b.unit})
                          </td>
                          <td>
                            <div>{b.expiration_date ?? "No expiry"}</div>
                            {Number(b.quarantined) ? (
                              <span className="stock-badge warn">
                                QUARANTINED
                              </span>
                            ) : batchExpired ? (
                              <span className="stock-badge danger">
                                EXPIRED
                              </span>
                            ) : batchExpiresToday ? (
                              <span className="stock-badge amber">
                                EXPIRES TODAY
                              </span>
                            ) : null}
                          </td>
                          <td>{b.remaining_qty}</td>
                          <td>{b.qty_sealed}</td>
                          <td>{b.qty_opened}</td>
                          <td>
                            <details open={hasWasteDue}>
                              <summary>
                                View containers ({batchContainers.length})
                              </summary>
                              {batchContainers.map((c) => {
                                const st = containerStatus(
                                  c,
                                  b,
                                  data.server_time,
                                );
                                return (
                                  <div className="container-detail" key={c.id}>
                                    <div className="container-detail-head">
                                      <strong>
                                        #{c.id}: {c.remaining_qty} {b.unit}
                                      </strong>
                                      <span
                                        className={`stock-badge ${st.tone}`}
                                      >
                                        {st.label}
                                      </span>
                                    </div>
                                    {c.opened_at && (
                                      <small>Opened {c.opened_at}</small>
                                    )}
                                    {Number(c.remaining_qty) > 0 && (
                                      <div className="container-detail-actions">
                                        <button
                                          type="button"
                                          className={
                                            st.isWasteDue
                                              ? "danger compact-btn"
                                              : "compact-btn"
                                          }
                                          onClick={() => openQuickWaste(c, b)}
                                        >
                                          {st.isWasteDue
                                            ? "Dispose expired"
                                            : "Record waste"}
                                        </button>
                                        <button
                                          type="button"
                                          className="compact-btn"
                                          onClick={() => openQuickAdjust(c)}
                                        >
                                          Adjust qty
                                        </button>
                                      </div>
                                    )}
                                  </div>
                                );
                              })}
                            </details>
                          </td>
                        </tr>
                      );
                    })}
                  </Table>
                )}
              </section>
              <section className="panel section-space">
                <h2>Latest stock movements</h2>
                {!data.movements.length ? (
                  <p className="subtle">No stock movements yet.</p>
                ) : (
                  <Table
                    headers={[
                      "Time",
                      "Ingredient",
                      "Type",
                      "Quantity",
                      "By",
                      "Reference",
                    ]}
                  >
                    {data.movements.map((m) => (
                      <tr key={m.id}>
                        <td>{m.created_at}</td>
                        <td>{m.ingredient_name}</td>
                        <td>{m.type}</td>
                        <td>{m.quantity_delta}</td>
                        <td>{m.actor}</td>
                        <td>{m.reason}</td>
                      </tr>
                    ))}
                  </Table>
                )}
              </section>
            </>
          )}
        </>
      )}
    </>
  );
}
