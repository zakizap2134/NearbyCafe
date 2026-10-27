import { useEffect, useRef, useState } from "react";
import { api, getHostEpoch } from "../api";
import {
  Reports,
  History,
  Staff,
  BackupRestore,
  StockOperations,
} from "./Stage4";
import AccountSecurity from "./AccountSecurity";
import { transactionKey } from "../checkout-store";
import {
  money,
  variantLabel,
  ErrorNotice,
  Field,
  Select,
  Receipt,
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
const emptyProduct = { name: "", category: "Coffee", is_active: 1 };
const emptyVariant = {
  product_id: "",
  size: "M",
  temperature: "HOT",
  sku: "",
  price: "",
  is_active: 1,
};
const emptyDelivery = {
  ingredient_id: "",
  container_count: "1",
  quantity_per_container: "",
  expiration_date: "",
  reference: "",
  supplier: "",
  lot_reference: "",
};

export default function Management({ user, token, expired }) {
  const [recoveryPrepared, setRecoveryPrepared] = useState(null);
  useEffect(() => {
    let live = true;
    api("management/recovery-code/status", { token }).then(state => { if (live) setRecoveryPrepared(state.prepared); }).catch(e => { if (live && e.status === 401) expired(); });
    return () => { live = false; };
  }, [token]);
  const [data, setData] = useState(null),
    [tab, setTab] = useState("Overview"),
    [error, setError] = useState(null),
    [notice, setNotice] = useState(""),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(true),
    [receipt, setReceipt] = useState(null);
  const [ingredient, setIngredient] = useState(emptyIngredient),
    [product, setProduct] = useState(emptyProduct),
    [variant, setVariant] = useState(emptyVariant),
    [recipeVariant, setRecipeVariant] = useState(""),
    [rows, setRows] = useState([]),
    [delivery, setDelivery] = useState(emptyDelivery);
  const sending = useRef(false),
    deliveryAttempt = useRef(null);
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
      {recoveryPrepared === false && tab !== "Account security" && <section className="panel section-space" aria-label="Prepare Owner recovery"><h2>Protect your Owner account</h2><p>Prepare an offline recovery code in Account security while you can sign in.</p><button className="primary" onClick={() => setTab("Account security")}>Prepare recovery code</button></section>}
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
              <p className="subtle">{data.sales.date} · Manila time</p>
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
                <h2>Stock alerts</h2>
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
                        <li key={i.id}>
                          <strong>{i.name}</strong> · {i.eligible_stock}{" "}
                          {i.unit} eligible
                          {Number(i.waste_due) > 0 && (
                            <span>
                              {" "}
                              · {i.waste_due} {i.unit} expired or past use-by;
                              set aside for waste recording.
                            </span>
                          )}
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
                    headers={["Sale", "Time", "Gross", "Net", "VAT", "Details"]}
                  >
                    {data.sales.recent.map((s) => (
                      <tr key={s.id}>
                        <td>#{s.id}</td>
                        <td>{s.sale_at}</td>
                        <td>{money(s.total_gross)}</td>
                        <td>{money(s.total_net)}</td>
                        <td>{money(s.total_vat)}</td>
                        <td>
                          <button onClick={() => showReceipt(s.id)}>
                            View sale #{s.id}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </Table>
                )}
              </section>
              {receipt && <Receipt sale={receipt} />}
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
                      save("product", product, () => setProduct(emptyProduct));
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
                              remove("product", product.id, () =>
                                setProduct(emptyProduct),
                              )
                            }
                          >
                            Delete unused product
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => setProduct(emptyProduct)}
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
                          <td>{p.name}</td>
                          <td>{p.category}</td>
                          <td>{Number(p.is_active) ? "Active" : "Inactive"}</td>
                          <td>
                            <button
                              disabled={busy}
                              onClick={() => setProduct(p)}
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
                      save("variant", variant, () => setVariant(emptyVariant));
                    }}
                  >
                    <fieldset disabled={busy}>
                      <Select
                        label="Variant product"
                        required
                        disabled={!!variant.id}
                        {...input(variant, setVariant, "product_id")}
                      >
                        <option value="">Choose product</option>
                        {data.products.map((p) => (
                          <option key={p.id} value={p.id}>
                            {p.name}
                          </option>
                        ))}
                      </Select>
                      <div className="form-grid">
                        <Select
                          label="Size"
                          {...input(variant, setVariant, "size")}
                        >
                          <option value="S">S · 12 oz</option>
                          <option value="M">M · 16 oz</option>
                          <option value="L">L · 22 oz</option>
                        </Select>
                        <Select
                          label="Temperature"
                          {...input(variant, setVariant, "temperature")}
                        >
                          <option value="HOT">Hot</option>
                          <option value="ICED">Iced</option>
                          <option value="NA">Not applicable</option>
                        </Select>
                      </div>
                      <Field
                        label="SKU"
                        required
                        maxLength={64}
                        pattern="[A-Za-z0-9._\\-]+"
                        {...input(variant, setVariant, "sku")}
                      />
                      <Field
                        label="VAT-inclusive price"
                        required
                        inputMode="decimal"
                        {...input(variant, setVariant, "price")}
                      />
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
                              remove("variant", variant.id, () =>
                                setVariant(emptyVariant),
                              )
                            }
                          >
                            Delete unused variant
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => setVariant(emptyVariant)}
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
                              onClick={() => setVariant(v)}
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
                  {rows.map((r, index) => (
                    <div className="recipe-row" key={index}>
                      <Select
                        label={`Ingredient ${index + 1}`}
                        required
                        value={r.ingredient_id}
                        onChange={(e) =>
                          setRows(
                            rows.map((x, j) =>
                              j === index
                                ? { ...x, ingredient_id: e.target.value }
                                : x,
                            ),
                          )
                        }
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
                        label={`Quantity ${index + 1}`}
                        required
                        inputMode="decimal"
                        value={r.qty}
                        onChange={(e) =>
                          setRows(
                            rows.map((x, j) =>
                              j === index ? { ...x, qty: e.target.value } : x,
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
                  ))}
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
                        {...input(delivery, setDelivery, "ingredient_id")}
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
                      <Field
                        label="Delivery reference"
                        required
                        maxLength={120}
                        {...input(delivery, setDelivery, "reference")}
                      />
                      <Field
                        label="Container count"
                        type="number"
                        min="1"
                        max="1000"
                        required
                        {...input(delivery, setDelivery, "container_count")}
                      />
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
                      <Field
                        label="Supplier (optional)"
                        maxLength={120}
                        {...input(delivery, setDelivery, "supplier")}
                      />
                      <Field
                        label="Lot reference (optional)"
                        maxLength={120}
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
                    {data.batches.map((b) => (
                      <tr key={b.id}>
                        <td>
                          #{b.id} · {b.reference}
                          <small>
                            {b.supplier} {b.lot_reference}
                          </small>
                        </td>
                        <td>
                          {b.ingredient_name} ({b.unit})
                        </td>
                        <td>{b.expiration_date ?? "No expiry"}</td>
                        <td>{b.remaining_qty}</td>
                        <td>{b.qty_sealed}</td>
                        <td>{b.qty_opened}</td>
                        <td>
                          <details>
                            <summary>View containers</summary>
                            {data.containers
                              .filter(
                                (c) => Number(c.batch_id) === Number(b.id),
                              )
                              .map((c) => (
                                <p className="container-detail" key={c.id}>
                                  #{c.id}: {c.remaining_qty} {b.unit}
                                  <br />
                                  {c.opened_at
                                    ? `Opened ${c.opened_at}`
                                    : "Sealed"}
                                  <br />
                                  {c.use_by ? `Use by ${c.use_by}` : ""}
                                </p>
                              ))}
                          </details>
                        </td>
                      </tr>
                    ))}
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
