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
export function Receipt({ sale }) {
  if (!sale) return null;
  return (
    <section className="panel receipt" aria-label="Sale receipt">
      <Brand compact />
      <div className="section-heading">
        <h2>Sale #{sale.id} confirmed</h2>
        <span className="pill">Cash</span>
      </div>
      <p className="subtle">{sale.sale_at} · Manila time</p>
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
      <p className="subtle receipt-note">
        Project sale confirmation · Powered by VenueVue
      </p>
    </section>
  );
}
