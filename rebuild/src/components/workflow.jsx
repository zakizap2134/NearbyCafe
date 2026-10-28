import { useEffect, useState } from "react";
import { api } from "../api";
import Brand from "./Brand";
export const money = (value) =>
  new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(
    Number(value ?? 0),
  );
export const variantLabel = (v, products = []) =>
  `${v.product_name ?? products.find((p) => Number(p.id) === Number(v.product_id))?.name ?? "Drink"} ${v.size}${v.temperature === "NA" ? "" : ` ${v.temperature}`}`;
export function ErrorNotice({ error }) {
  return (
    error && (
      <div className="error" role="alert">
        <strong>{error.message}</strong>
        {error.reference && <small>Support reference: {error.reference}</small>}
      </div>
    )
  );
}
export function Field({ label, children, ...props }) {
  return (
    <label>
      {label}
      {children ?? <input {...props} />}
    </label>
  );
}
export function Select({ label, children, ...props }) {
  return (
    <label>
      {label}
      <select aria-label={label} {...props}>
        {children}
      </select>
    </label>
  );
}
export function Receipt({ sale, onVoid, onReloadItems }) {
  if (!sale) return null;
  const isVoided = sale.status === "voided";
  return (
    <section
      className={`panel receipt${isVoided ? " receipt-voided" : ""}`}
      aria-label="Sale receipt"
    >
      <Brand compact />
      <div className="section-heading">
        <h2>Sale #{sale.id} confirmed</h2>
        <div className="button-row">
          {isVoided && <span className="pill pill-voided">VOIDED</span>}
          <span className="pill">Cash</span>
        </div>
      </div>
      <p className="subtle">
        {sale.sale_at} · Manila time
        {sale.cashier_name ? ` · Cashier: ${sale.cashier_name}` : ""}
        {sale.transaction_key
          ? ` · Ref ${sale.transaction_key.slice(0, 8)}`
          : ""}
      </p>
      {isVoided && (
        <div className="void-banner">
          <strong>Order Voided</strong>
          <p>
            Reason: {sale.void_reason || "Voided by owner"}
            {sale.voided_at ? ` · ${sale.voided_at}` : ""}
            {sale.voided_by_name ? ` · Authorized by ${sale.voided_by_name}` : ""}
          </p>
        </div>
      )}
      <div className="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th>Qty</th>
              <th>Gross</th>
              <th>Net</th>
              <th>VAT</th>
            </tr>
          </thead>
          <tbody>
            {sale.items.map((line, i) => (
              <tr key={i}>
                <td>{line.variant_label}</td>
                <td>{line.qty}</td>
                <td>{money(line.line_gross)}</td>
                <td>{money(line.line_net)}</td>
                <td>{money(line.line_vat)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <dl className="sale-totals">
        {[
          ["VATable Sales", sale.total_net],
          ["VAT (12%)", sale.total_vat],
          ["VAT-Exempt", "0"],
          ["Zero-Rated", "0"],
          ["Total", sale.total_gross],
          ["Cash received", sale.cash_received],
          ["Change", sale.change_due],
        ].map(([name, value]) => (
          <div key={name}>
            <dt>{name}</dt>
            <dd>{money(value)}</dd>
          </div>
        ))}
      </dl>
      <div className="button-row receipt-actions no-print">
        <button type="button" onClick={() => window.print()}>
          Print receipt
        </button>
        {!isVoided && onVoid && (
          <button
            type="button"
            className="danger"
            onClick={() => onVoid(sale)}
          >
            Void order #{sale.id}
          </button>
        )}
        {isVoided && onReloadItems && (
          <button type="button" onClick={() => onReloadItems(sale)}>
            Load items into current order
          </button>
        )}
      </div>
      <p className="subtle receipt-note">
        Project sale confirmation · Powered by VenueVue
      </p>
    </section>
  );
}
export function OrderSuccessModal({
  sale,
  cashierName,
  onClose,
  onPrint,
  onVoid,
}) {
  useEffect(() => {
    if (!sale) return;
    function onKey(e) {
      if (e.key === "Enter" || e.key === "Escape") {
        e.preventDefault();
        onClose();
      }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [sale, onClose]);
  if (!sale) return null;
  return (
    <div
      className="order-success-overlay no-print"
      role="dialog"
      aria-modal="false"
      aria-label="Order registered successfully"
    >
      <div className="panel order-success-dialog">
        <div className="order-success-header">
          <span className="order-success-badge" aria-hidden="true">
            ✓
          </span>
          <div>
            <p className="eyebrow">CHECKOUT COMPLETE</p>
            <h2>Order Registered Successfully</h2>
            <p className="subtle">
              Sale #{sale.id}
              {sale.transaction_key
                ? ` · Key ${sale.transaction_key.slice(0, 8)}`
                : ""}{" "}
              · {sale.sale_at} · {sale.cashier_name || cashierName || "Staff"}
            </p>
          </div>
        </div>
        <div className="order-success-cash-grid">
          <div className="order-cash-box">
            <span>Total Gross</span>
            <strong>{money(sale.total_gross)}</strong>
          </div>
          <div className="order-cash-box">
            <span>Cash Tendered</span>
            <strong>{money(sale.cash_received)}</strong>
          </div>
          <div className="order-cash-box highlight">
            <span>Change Due</span>
            <strong>{money(sale.change_due)}</strong>
          </div>
        </div>
        <div className="order-success-items">
          <h3>Items ({sale.items?.reduce((n, l) => n + Number(l.qty), 0) ?? 0})</h3>
          <ul>
            {sale.items?.map((line, i) => (
              <li key={i}>
                <span>
                  <strong>{line.qty}×</strong> {line.variant_label}
                </span>
                <strong>{money(line.line_gross)}</strong>
              </li>
            ))}
          </ul>
        </div>
        <div className="button-row order-success-actions">
          <button type="button" className="primary" onClick={onClose}>
            New Order (Enter)
          </button>
          <button type="button" onClick={onPrint}>
            Print / View Receipt
          </button>
          {onVoid && sale.status !== "voided" && (
            <button
              type="button"
              className="danger"
              onClick={() => onVoid(sale)}
            >
              Void / Modify Order
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
const VOID_PRESETS = [
  "Wrong customer selection",
  "Customer cancelled before brewing",
  "Spilled / Damaged during preparation",
  "Incorrect quantity or size entered",
];
export function VoidSaleModal({
  sale,
  user,
  token,
  allowReload = false,
  onClose,
  onVoided,
}) {
  const [reason, setReason] = useState("Wrong customer selection"),
    [restoreStock, setRestoreStock] = useState("1"),
    [ownerPassword, setOwnerPassword] = useState(""),
    [reloadToBasket, setReloadToBasket] = useState(allowReload),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(null);
  if (!sale) return null;
  const isOwner = user?.role === "OWNER";
  async function handleSubmit(e) {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      const body = {
        sale_id: sale.id,
        transaction_key: sale.transaction_key,
        reason: reason.trim(),
        restore_stock: restoreStock === "1",
        ...(ownerPassword ? { owner_password: ownerPassword } : {}),
      };
      const updated = await api("sales/void", { token, body });
      onVoided(updated, reloadToBasket);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div
      className="order-success-overlay no-print"
      role="dialog"
      aria-label={`Void sale #${sale.id}`}
    >
      <div className="panel order-success-dialog void-dialog">
        <div className="section-heading">
          <div>
            <p className="eyebrow">OWNER AUTHORIZATION</p>
            <h2>Void Order #{sale.id}</h2>
          </div>
          <span className="pill">{money(sale.total_gross)}</span>
        </div>
        <p className="subtle">
          Voiding marks this transaction as voided, excludes it from active shift
          sales, and records an immutable audit event.
        </p>
        <ErrorNotice error={error} />
        <form onSubmit={handleSubmit}>
          <fieldset disabled={busy}>
            <div className="void-presets">
              {VOID_PRESETS.map((preset) => (
                <button
                  type="button"
                  key={preset}
                  className={reason === preset ? "primary" : ""}
                  onClick={() => setReason(preset)}
                >
                  {preset}
                </button>
              ))}
            </div>
            <Field
              label="Void reason"
              required
              maxLength={200}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Explain why this order is being voided"
            />
            <Select
              label="Inventory handling (FEFO stock)"
              value={restoreStock}
              onChange={(e) => setRestoreStock(e.target.value)}
            >
              <option value="1">
                Return ingredients to stock (drink not prepared yet)
              </option>
              <option value="0">
                Keep stock deducted as waste (drink already prepared / spoiled)
              </option>
            </Select>
            {allowReload && (
              <label className="checkbox-row">
                <input
                  type="checkbox"
                  checked={reloadToBasket}
                  onChange={(e) => setReloadToBasket(e.target.checked)}
                />
                <span>
                  Load items back into Current Order to modify and re-ring
                </span>
              </label>
            )}
            <Field
              label={
                isOwner
                  ? "Owner password (optional confirmation)"
                  : "Owner password (required to authorize void)"
              }
              type="password"
              required={!isOwner}
              maxLength={72}
              value={ownerPassword}
              onChange={(e) => setOwnerPassword(e.target.value)}
              placeholder="Enter Owner password"
            />
            <div className="button-row">
              <button type="button" onClick={onClose} disabled={busy}>
                Cancel
              </button>
              <button type="submit" className="danger" disabled={busy}>
                {busy ? "Voiding order…" : `Confirm Void #${sale.id}`}
              </button>
            </div>
          </fieldset>
        </form>
      </div>
    </div>
  );
}
