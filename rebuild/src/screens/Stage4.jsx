import { useEffect, useRef, useState } from "react";
import { api, getHostEpoch } from "../api";
import StaffPassword, { staffPasswordError } from "../components/StaffPassword";
import { transactionKey } from "../checkout-store";
import {
  ErrorNotice,
  Field,
  Select,
  Receipt,
  VoidSaleModal,
  money,
  paymentMethodLabel,
} from "../components/workflow";

const today = () =>
  new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());

function manilaDateOffset(daysOffset) {
  const base = today();
  const [y, m, d] = base.split("-").map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + daysOffset));
  return dt.toISOString().slice(0, 10);
}

function manilaStartOfMonth() {
  return `${today().slice(0, 8)}01`;
}

export function download(filename, content, type, encoding = "utf8") {
  let blobPart = content;
  if (encoding === "base64") {
    const bin = atob(content);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) {
      bytes[i] = bin.charCodeAt(i);
    }
    blobPart = bytes;
  }
  const url = URL.createObjectURL(new Blob([blobPart], { type }));
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}
function useAction(expired) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(null),
    [notice, setNotice] = useState("");
  const running = useRef(false);
  async function run(work) {
    if (running.current) return;
    running.current = true;
    setBusy(true);
    setError(null);
    setNotice("");
    try {
      await work(setNotice);
    } catch (e) {
      setError(e);
      if (e.status === 401) expired();
    } finally {
      running.current = false;
      setBusy(false);
    }
  }
  return {
    busy,
    run,
    error,
    setError,
    notice,
    feedback: (
      <>
        <ErrorNotice error={error} />
        {notice && (
          <p className="success" role="status">
            {notice}
          </p>
        )}
      </>
    ),
  };
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
function Pager({ page, total, busy, onPage }) {
  return (
    <div className="button-row pager-bar">
      <button disabled={busy || page <= 1} onClick={() => onPage(page - 1)}>
        Previous page
      </button>
      <span className="pager-status">
        Page {page} · {total} records
      </span>
      <button
        disabled={busy || page * 50 >= total}
        onClick={() => onPage(page + 1)}
      >
        Next page
      </button>
    </div>
  );
}
function Dates({ range, setRange, onQuickRange }) {
  const t = today();
  const yest = manilaDateOffset(-1);
  const last7 = manilaDateOffset(-6);
  const monthStart = manilaStartOfMonth();
  const last30 = manilaDateOffset(-29);
  const presets = [
    ["Today (Z-Reading)", t, t],
    ["Yesterday", yest, yest],
    ["Last 7 Days", last7, t],
    ["This Month", monthStart, t],
    ["Last 30 Days", last30, t],
  ];
  return (
    <>
      <div className="quick-preset-row">
        <span className="preset-caption">Quick period (1-click load):</span>
        <div className="quick-chip-group">
          {presets.map(([label, from, to]) => {
            const active = range.from === from && range.to === to;
            return (
              <button
                key={label}
                type="button"
                className={`compact-btn quick-chip${active ? " active" : ""}`}
                onClick={() => {
                  const next = { from, to };
                  setRange(next);
                  if (onQuickRange) onQuickRange(next);
                }}
              >
                {label}
              </button>
            );
          })}
        </div>
      </div>
      <div className="form-grid">
        <Field
          label="From date"
          type="date"
          required
          value={range.from}
          onChange={(e) => setRange({ ...range, from: e.target.value })}
        />
        <Field
          label="Through date"
          type="date"
          required
          value={range.to}
          onChange={(e) => setRange({ ...range, to: e.target.value })}
        />
      </div>
    </>
  );
}

export function Reports({ token, expired }) {
  const action = useAction(expired),
    [range, setRange] = useState({ from: today(), to: today() }),
    [data, setData] = useState(null),
    [receipt, setReceipt] = useState(null),
    [voidTarget, setVoidTarget] = useState(null),
    [csvFormat, setCsvFormat] = useState("xlsx_structured"),
    [csvIncludeVoided, setCsvIncludeVoided] = useState(true);
  const load = (page = 1, customRange = null) =>
    action.run(async () => {
      const r = customRange ?? range;
      setData(
        await api("management/reports", { token, query: { ...r, page } }),
      );
      setReceipt(null);
    });
  useEffect(() => {
    load();
  }, [token]);
  return (
    <section className="panel">
      {" "}
      <h2>Sales reports</h2>
      <p className="subtle">
        Manila time · Stored sale totals, including VAT at the time of sale.
        Select one day for a daily summary (Z-reading). This summary does not
        close the register.
      </p>
      {action.feedback}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          load();
        }}
      >
        <fieldset disabled={action.busy}>
          <Dates
            range={range}
            setRange={setRange}
            onQuickRange={(next) => load(1, next)}
          />
          <button className="primary">Load report</button>
        </fieldset>
      </form>
      {action.busy && <p role="status">Loading report…</p>}
      {data && (
        <>
          <p className="subtle">
            Showing {data.from} through {data.to}
          </p>
          <div className="stats">
            {[
              ["Sales", data.totals.count],
              ["Gross", money(data.totals.gross)],
              [
                "Cash in drawer",
                money(data.totals.cash_drawer_gross ?? data.totals.gross),
              ],
              ["Online / E-Wallet", money(data.totals.online_gross ?? 0)],
              ["VATable sales", money(data.totals.net)],
              ["VAT remittance", money(data.vat_remittance)],
            ].map(([label, value]) => (
              <div className="panel stat" key={label}>
                <p>{label}</p>
                <strong>{value}</strong>
              </div>
            ))}
          </div>
          <div className="csv-export-bar">
            <div className="csv-export-controls">
              <label className="csv-format-field">
                <span>Spreadsheet / CSV export structure</span>
                <select
                  aria-label="CSV export structure"
                  value={csvFormat}
                  disabled={action.busy}
                  onChange={(e) => setCsvFormat(e.target.value)}
                >
                  <option value="xlsx_structured">
                    Styled Excel Workbook (.xlsx) — Auto-Sized Columns, Colors,
                    Borders & Currency (₱)
                  </option>
                  <option value="structured">
                    Full Structured CSV (.csv) — Plain Text with All 6 Sections
                  </option>
                  <option value="line_items">
                    Line-Item Transactions Only (.csv) — with 5-Part SKU & Cash
                  </option>
                  <option value="daily_z">
                    Daily Z-Reading Summary Table Only (.csv)
                  </option>
                  <option value="products">
                    Product & 5-Part SKU Performance Table Only (.csv)
                  </option>
                </select>
              </label>
              {(csvFormat === "xlsx_structured" ||
                csvFormat === "structured") && (
                <label className="checkbox-row csv-checkbox">
                  <input
                    type="checkbox"
                    checked={csvIncludeVoided}
                    disabled={action.busy}
                    onChange={(e) => setCsvIncludeVoided(e.target.checked)}
                  />
                  <span>Include voided transactions audit section</span>
                </label>
              )}
            </div>
            <button
              className="primary"
              disabled={action.busy}
              onClick={() =>
                action.run(async (notice) => {
                  const file = await api("management/csv", {
                    token,
                    query: {
                      from: data.from,
                      to: data.to,
                      format: csvFormat,
                      include_voided: csvIncludeVoided ? "1" : "0",
                    },
                    timeout: 60000,
                  });
                  download(
                    file.filename,
                    file.content,
                    file.mime || "text/csv;charset=utf-8",
                    file.encoding || "utf8",
                  );
                  notice(
                    `Downloaded ${file.filename} (${
                      csvFormat === "xlsx_structured"
                        ? "styled Excel workbook with auto-fitted columns & currency"
                        : csvFormat === "structured"
                          ? "multi-section structured CSV"
                          : csvFormat === "daily_z"
                            ? "daily Z-reading summary"
                            : csvFormat === "products"
                              ? "product & SKU performance"
                              : "line-item transactions with totals"
                    }).`,
                  );
                })
              }
            >
              {csvFormat === "xlsx_structured"
                ? "Download Excel (.xlsx)"
                : "Download sales CSV"}
            </button>
          </div>
          <h3>Daily summary / Z-reading</h3>
          {!data.daily.length ? (
            <p>No sales in this period.</p>
          ) : (
            <Table headers={["Date", "Sales", "Gross", "Net", "VAT (12%)"]}>
              {data.daily.map((d) => (
                <tr key={d.date}>
                  <td>{d.date}</td>
                  <td>{d.count}</td>
                  <td>{money(d.gross)}</td>
                  <td>{money(d.net)}</td>
                  <td>{money(d.vat)}</td>
                </tr>
              ))}
            </Table>
          )}
          <h3>Transactions</h3>
          <VoidSaleModal
            sale={voidTarget}
            user={{ role: "OWNER" }}
            token={token}
            onClose={() => setVoidTarget(null)}
            onVoided={async (updated) => {
              setVoidTarget(null);
              await load(data.page);
              setReceipt(updated);
            }}
          />
          <Table
            headers={[
              "Sale",
              "Time",
              "Cashier",
              "Payment",
              "Status",
              "Gross",
              "Net",
              "VAT",
              "Details",
            ]}
          >
            {data.sales.map((s) => (
              <tr key={s.id}>
                <td>#{s.id}</td>
                <td>{s.sale_at}</td>
                <td>{s.cashier}</td>
                <td>
                  <span className="pill">
                    {paymentMethodLabel(s.payment_method)}
                  </span>
                  {s.payment_reference && (
                    <small>Ref #{s.payment_reference}</small>
                  )}
                </td>
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
                    <button
                      disabled={action.busy}
                      onClick={() =>
                        action.run(async () =>
                          setReceipt(
                            await api("sales/receipt", {
                              token,
                              query: { id: s.id },
                            }),
                          ),
                        )
                      }
                    >
                      View sale #{s.id}
                    </button>
                    {s.status !== "voided" && (
                      <button
                        className="danger"
                        disabled={action.busy}
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
          <Pager
            busy={action.busy}
            page={data.page}
            total={data.totals.count}
            onPage={(p) =>
              action.run(async () =>
                setData(
                  await api("management/reports", {
                    token,
                    query: { from: data.from, to: data.to, page: p },
                  }),
                ),
              )
            }
          />
          <Receipt
            sale={receipt}
            onClose={() => setReceipt(null)}
            onVoid={(s) => setVoidTarget(s)}
          />
        </>
      )}
    </section>
  );
}

export function History({ token, expired, stock = false }) {
  const action = useAction(expired),
    [range, setRange] = useState({ from: today(), to: today() }),
    [filter, setFilter] = useState(""),
    [data, setData] = useState(null),
    [applied, setApplied] = useState(null);
  function load(page = 1, params = null) {
    const query = params ?? { ...range, [stock ? "type" : "action"]: filter };
    action.run(async () => {
      setData(
        await api(stock ? "management/movements" : "management/audit", {
          token,
          query: { ...query, page },
        }),
      );
      setApplied(query);
    });
  }
  useEffect(() => {
    load();
  }, [token, stock]);
  return (
    <section className="panel section-space">
      <h2>{stock ? "Stock movement history" : "Audit history"}</h2>
      {action.feedback}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          load();
        }}
      >
        <fieldset disabled={action.busy}>
          <Dates
            range={range}
            setRange={setRange}
            onQuickRange={(next) =>
              load(1, { ...next, [stock ? "type" : "action"]: filter })
            }
          />
          {stock ? (
            <>
              <div className="quick-preset-row">
                <span className="preset-caption">
                  Quick movement filter (1-click load):
                </span>
                <div className="quick-chip-group">
                  {[
                    ["All movements", ""],
                    ["Deliveries (RECEIVE)", "RECEIVE"],
                    ["Sales (SALE)", "SALE"],
                    ["Waste (WASTE)", "WASTE"],
                    ["Adjustments (ADJUSTMENT)", "ADJUSTMENT"],
                  ].map(([label, val]) => (
                    <button
                      key={label}
                      type="button"
                      className={`compact-btn quick-chip${filter === val ? " active" : ""}`}
                      onClick={() => {
                        setFilter(val);
                        load(1, { ...range, type: val });
                      }}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              </div>
              <Select
                label="Movement type"
                value={filter}
                onChange={(e) => setFilter(e.target.value)}
              >
                <option value="">All types</option>
                {["RECEIVE", "SALE", "WASTE", "ADJUSTMENT"].map((t) => (
                  <option key={t}>{t}</option>
                ))}
              </Select>
            </>
          ) : (
            <>
              <div className="quick-preset-row">
                <span className="preset-caption">
                  Quick audit filter (1-click load):
                </span>
                <div className="quick-chip-group">
                  {[
                    ["All actions", ""],
                    ["Sales & Voids", "SALE"],
                    ["Logins", "LOGIN"],
                    ["Stock & Waste", "STOCK"],
                    ["Staff", "STAFF"],
                    ["Catalogue", "SAVED"],
                    ["Backups", "BACKUP"],
                  ].map(([label, val]) => (
                    <button
                      key={label}
                      type="button"
                      className={`compact-btn quick-chip${filter.toUpperCase() === val && (val !== "" || filter === "") ? " active" : ""}`}
                      onClick={() => {
                        setFilter(val);
                        load(1, { ...range, action: val });
                      }}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              </div>
              <Field
                label="Action contains"
                maxLength={64}
                value={filter}
                onChange={(e) => setFilter(e.target.value)}
              />
            </>
          )}
          <button>Load history</button>
        </fieldset>
      </form>
      {action.busy && <p role="status">Loading history…</p>}
      {data && (
        <>
          <p className="subtle">
            {data.from} through {data.to} · Manila time
          </p>
          {!data?.rows?.length ? (
            <p>No matching records.</p>
          ) : (
            <Table
              headers={
                stock
                  ? [
                      "Time",
                      "By",
                      "Ingredient",
                      "Type",
                      "Delta",
                      "Batch / container",
                      "Reason",
                    ]
                  : ["Time", "By", "Action", "Reference", "Details"]
              }
            >
              {data.rows.map((r) => (
                <tr key={r.id}>
                  <td>{r.created_at}</td>
                  <td>{r.actor ?? "System"}</td>
                  {stock ? (
                    <>
                      <td>{r.ingredient_name}</td>
                      <td>{r.type}</td>
                      <td>
                        {r.quantity_delta} {r.unit}
                      </td>
                      <td>
                        #{r.batch_id} / #{r.container_id}
                      </td>
                      <td>{r.reason}</td>
                    </>
                  ) : (
                    <>
                      <td>{r.action}</td>
                      <td>{r.request_id}</td>
                      <td>
                        {r.details ? (
                          <details>
                            <summary>View details</summary>
                            <pre className="audit-json">
                              {JSON.stringify(JSON.parse(r.details), null, 2)}
                            </pre>
                          </details>
                        ) : (
                          "—"
                        )}
                      </td>
                    </>
                  )}
                </tr>
              ))}
            </Table>
          )}
          <Pager
            busy={action.busy}
            page={data.page}
            total={data.total}
            onPage={(p) => load(p, applied)}
          />
        </>
      )}
    </section>
  );
}

export function Staff({ token, expired, onResetPassword }) {
  const blank = {
      username: "",
      display_name: "",
      email: "",
      password: "",
      confirm_password: "",
      owner_password: "",
      is_active: 1,
    },
    [form, setForm] = useState(blank),
    [usernameCustom, setUsernameCustom] = useState(false),
    [generatedPwd, setGeneratedPwd] = useState(""),
    [copiedNotice, setCopiedNotice] = useState(""),
    [staff, setStaff] = useState(null),
    action = useAction(expired);
  const refresh = async () =>
    setStaff((await api("management/staff", { token })).staff);
  useEffect(() => {
    action.run(refresh);
  }, [token]);
  function suggestStaffUsername(displayName) {
    const clean = String(displayName || "")
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9\s]/g, "")
      .split(/\s+/)
      .filter(Boolean);
    if (!clean.length) return "";
    const first = clean[0].slice(0, 20);
    return `barista_${first}`;
  }
  function generateTempStaffPassword() {
    const randDigits = String(1000 + Math.floor(Math.random() * 9000));
    const namePart =
      String(form.display_name || "Staff")
        .replace(/[^A-Za-z]/g, "")
        .slice(0, 6) || "Barista";
    const capPart =
      namePart.charAt(0).toUpperCase() + namePart.slice(1).toLowerCase();
    const pwd = `Cafe!${capPart}${randDigits}#`;
    setForm((f) => ({
      ...f,
      password: pwd,
      confirm_password: pwd,
    }));
    setGeneratedPwd(pwd);
    setCopiedNotice("");
  }
  const field = (key) => ({
    value: form[key],
    onChange: (e) => {
      const value = e.target.value;
      setForm((f) => {
        const next = { ...f, [key]: value };
        if (key === "username") {
          setUsernameCustom(true);
        } else if (key === "display_name" && !f.id && !usernameCustom) {
          next.username = suggestStaffUsername(value);
        }
        return next;
      });
    },
  });
  return (
    <section className="panel">
      <h2>Barista accounts</h2>
      <p className="subtle">
        Baristas have Register access only. Saving an existing account signs
        that staff member out. Inactive accounts cannot sign in. Saved orders
        stay on their device.
      </p>
      {action.feedback}
      <form
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          action.run(async (notice) => {
            if (
              !/^[a-zA-Z0-9][a-zA-Z0-9._@-]{2,63}$/.test(form.username.trim())
            )
              throw new Error(
                "Username must be 3–64 characters, start with a letter or number, and use only letters, numbers, dots, @, underscores or hyphens.",
              );
            if (
              !form.display_name.trim() ||
              new TextEncoder().encode(form.display_name.trim()).length > 100
            )
              throw new Error("Display name must contain 1–100 bytes.");
            if (
              form.email?.trim() &&
              (form.email.trim().length > 191 ||
                !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()))
            )
              throw new Error(
                "Enter a valid staff email address (or leave it blank).",
              );
            const validation =
              !form.id &&
              staffPasswordError(form.password, form.confirm_password);
            if (validation) throw new Error(validation);
            if (
              !form.owner_password ||
              new TextEncoder().encode(form.owner_password).length > 72 ||
              form.owner_password.includes("\0")
            )
              throw new Error(
                "Enter your current owner password.",
              );
            try {
              const body = form;
              await api("management/staff-save", {
                token,
                body: {
                  ...body,
                  username: body.username.trim(),
                  display_name: body.display_name.trim(),
                  email: body.email?.trim() || "",
                },
              });
              setForm(blank);
              notice(
                form.id
                  ? "Staff account saved. This Barista was signed out; saved orders remain on their device."
                  : "Staff account saved. Share the username and initial password privately with the Barista.",
              );
              try {
                await refresh();
              } catch (e) {
                setStaff(null);
                if (e.status === 401) expired();
                else
                  action.setError(
                    new Error(
                      "The account was saved, but the list could not refresh. Use Refresh staff list; do not create the account again.",
                    ),
                  );
              }
            } catch (e) {
              if (e.code === "DUPLICATE")
                e.message =
                  "That username is already in use. Choose a different username.";
              throw e;
            } finally {
              setForm((f) => ({
                ...f,
                password: "",
                confirm_password: "",
                owner_password: "",
              }));
            }
          });
        }}
      >
        <fieldset disabled={action.busy}>
          <h3>{form.id ? "Edit Barista" : "Create Barista"}</h3>
          <Field
            label="Staff username"
            required
            autoComplete="off"
            {...field("username")}
          />
          <Field
            label="Staff display name"
            required
            maxLength={100}
            {...field("display_name")}
          />
          <Field
            label="Staff email (optional, for email password reset)"
            type="email"
            maxLength={191}
            placeholder="barista@example.com"
            {...field("email")}
          />
          {!form.id && (
            <>
              <div
                style={{
                  display: "flex",
                  justifyContent: "space-between",
                  alignItems: "center",
                  flexWrap: "wrap",
                  gap: "8px",
                  marginBottom: "6px",
                }}
              >
                <p className="subtle small" style={{ margin: 0 }}>
                  Use at least 12 characters.
                </p>
                <div className="button-row" style={{ marginTop: 0 }}>
                  <button
                    type="button"
                    className="compact-btn"
                    disabled={action.busy}
                    onClick={generateTempStaffPassword}
                  >
                    Generate strong temp password
                  </button>
                  {generatedPwd && (
                    <button
                      type="button"
                      className="compact-btn"
                      onClick={() => {
                        const text = `Username: ${form.username || suggestStaffUsername(form.display_name)}\nPassword: ${generatedPwd}`;
                        navigator.clipboard?.writeText(text);
                        setCopiedNotice("Copied credentials!");
                      }}
                    >
                      {copiedNotice || `Copy (${generatedPwd})`}
                    </button>
                  )}
                </div>
              </div>
              <StaffPassword
                label="Initial staff password"
                required
                autoComplete="new-password"
                disabled={action.busy}
                {...field("password")}
              />
              <StaffPassword
                label="Confirm initial staff password"
                required
                autoComplete="new-password"
                disabled={action.busy}
                {...field("confirm_password")}
              />
            </>
          )}
          <Select label="Staff status" {...field("is_active")}>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </Select>
          <StaffPassword
            label="Your owner password"
            disabled={action.busy}
            required
            autoComplete="current-password"
            {...field("owner_password")}
          />
          <div className="button-row">
            <button className="primary">
              {action.busy ? "Working…" : "Save staff account"}
            </button>
            <button
              type="button"
              onClick={() => {
                action.setError(null);
                setForm(blank);
              }}
            >
              {form.id ? "Cancel editing" : "Clear form"}
            </button>
          </div>
        </fieldset>
      </form>
      <div className="button-row section-space">
        <button disabled={action.busy} onClick={() => action.run(refresh)}>
          Refresh staff list
        </button>
        <button disabled={action.busy} onClick={onResetPassword}>
          Reset a staff password
        </button>
      </div>
      {action.busy && !action.notice && <p role="status">Working…</p>}
      {staff === null ? (
        <p className="subtle">
          {action.busy
            ? "Loading staff accounts…"
            : "Staff list unavailable. Use Refresh staff list to try again."}
        </p>
      ) : !staff.length ? (
        <p>No Barista accounts yet.</p>
      ) : (
        <Table headers={["Name", "Username", "Email", "Status", "Edit"]}>
          {staff.map((s) => (
            <tr key={s.id}>
              <td>{s.display_name}</td>
              <td>{s.username}</td>
              <td>{s.email || "—"}</td>
              <td>{Number(s.is_active) ? "Active" : "Inactive"}</td>
              <td>
                <button
                  disabled={action.busy}
                  onClick={() => {
                    action.setError(null);
                    setForm({ ...blank, ...s, email: s.email || "" });
                  }}
                >
                  Edit {s.username}
                </button>
              </td>
            </tr>
          ))}
        </Table>
      )}
    </section>
  );
}

export function BackupRestore({ token, expired }) {
  const action = useAction(expired),
    [ownerPassword, setOwnerPassword] = useState(""),
    [password, setPassword] = useState(""),
    [file, setFile] = useState(null),
    [filename, setFilename] = useState(""),
    [preview, setPreview] = useState(null),
    [confirmation, setConfirmation] = useState("");
  function invalidate() {
    setPreview(null);
    setConfirmation("");
  }
  const body = () => ({
    owner_password: ownerPassword,
    backup_password: password,
    file,
  });
  async function chooseFile(e) {
    invalidate();
    setFile(null);
    setFilename("");
    const chosen = e.target.files[0];
    if (!chosen) return;
    try {
      if (chosen.size > 12 * 1024 * 1024)
        throw new Error("The backup file must be smaller than 12 MiB.");
      setFile(JSON.parse(await chosen.text()));
      setFilename(chosen.name);
    } catch (error) {
      action.setError(error);
    }
  }
  return (
    <section className="panel">
      <h2>Backup & restore</h2>
      <p>
        Download an encrypted backup before changing hosts or restoring records.
        Store its passphrase separately; it cannot be recovered from this file.
      </p>
      {action.feedback}
      <fieldset disabled={action.busy}>
        <Field
          label="Current owner password"
          type="password"
          autoComplete="current-password"
          required
          value={ownerPassword}
          onChange={(e) => {
            setOwnerPassword(e.target.value);
            invalidate();
          }}
        />
        <Field
          label="Backup passphrase (12–128 bytes)"
          type="password"
          autoComplete="off"
          minLength={12}
          required
          value={password}
          onChange={(e) => {
            setPassword(e.target.value);
            invalidate();
          }}
        />
        <button
          className="primary"
          disabled={!ownerPassword || password.length < 12}
          onClick={() =>
            action.run(async (notice) => {
              invalidate();
              const result = await api("management/backup", {
                token,
                body: body(),
                timeout: 180000,
              });
              download(
                result.filename,
                JSON.stringify(result.file),
                "application/json",
              );
              notice(
                "Encrypted backup downloaded. Keep the file and its passphrase safe.",
              );
            })
          }
        >
          Download encrypted backup
        </button>
        <h3>Restore an earlier backup</h3>
        <p className="subtle">
          Restoring replaces catalogue, stock, sales, staff and audit records.
          All sessions end. Your current owner password and recovery code are
          retained. Staff passwords return to their backed-up values. Older
          pending orders and stock requests require owner review.
        </p>
        <Field
          label="Encrypted backup file"
          type="file"
          accept=".vvbackup,application/json"
          onChange={chooseFile}
        />
        {filename && <p>Selected: {filename}</p>}
        <button
          disabled={!file || !ownerPassword || password.length < 12}
          onClick={() =>
            action.run(async () => {
              setPreview(null);
              setConfirmation("");
              setPreview(
                await api("management/restore-preview", {
                  token,
                  body: body(),
                  timeout: 180000,
                }),
              );
            })
          }
        >
          Preview restore
        </button>
        {preview && (
          <div className="section-space">
            <h3>Review replacement</h3>
            <p>Backup created {preview.created_at} · Manila time</p>
            <Table headers={["Records", "Current", "From backup"]}>
              {Object.keys(preview.backup_counts).map((t) => (
                <tr key={t}>
                  <td>{t.replaceAll("_", " ")}</td>
                  <td>{preview.current_counts[t]}</td>
                  <td>{preview.backup_counts[t]}</td>
                </tr>
              ))}
            </Table>
            <p>
              Stop sales on every device. Download a current backup first.
              Changes made after this preview require a new preview. Once
              restored, reconcile physical stock, cash and pending orders before
              reopening.
            </p>
            <Field
              label="Type RESTORE to replace current records"
              autoComplete="off"
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
            />
            <button
              className="danger"
              disabled={confirmation !== "RESTORE"}
              onClick={() =>
                action.run(async () => {
                  await api("management/restore", {
                    token,
                    body: { ...body(), ...preview, confirmation },
                    timeout: 180000,
                  });
                  setPassword("");
                  setOwnerPassword("");
                  setFile(null);
                  setPreview(null);
                  window.alert(
                    "Restore completed. Sign in again and review older pending orders before resuming sales.",
                  );
                  window.location.reload();
                })
              }
            >
              Replace records and sign out
            </button>
          </div>
        )}
      </fieldset>
      {action.busy && (
        <p role="status">
          Working on the host. Keep this page open. If confirmation is
          interrupted, sign in again and check Audit history before retrying.
        </p>
      )}
    </section>
  );
}

const WASTE_PRESETS = [
  { key: "Expired date", label: "Expired date" },
  { key: "Past opened use-by", label: "Past opened use-by" },
  { key: "Spoiled / Curdled", label: "Spoiled / Curdled" },
  { key: "Spilled / Dropped", label: "Spilled / Dropped" },
  { key: "Calibration / Recipe waste", label: "Calibration / Recipe waste" },
];

export function containerStatus(c, batch, serverTime) {
  const todayDate = (serverTime || "").slice(0, 10);
  const rem = Number(c?.remaining_qty ?? 0);
  if (rem <= 0) {
    return {
      code: "EMPTY",
      label: "Empty",
      tone: "neutral",
      isWasteDue: false,
      sortRank: 5,
      defaultReason: "",
    };
  }
  if (Number(batch?.quarantined)) {
    return {
      code: "QUARANTINED",
      label: "QUARANTINED",
      tone: "warn",
      isWasteDue: false,
      sortRank: 3,
      defaultReason: "Quarantined batch disposal",
    };
  }
  if (batch?.expiration_date && todayDate && batch.expiration_date < todayDate) {
    return {
      code: "EXPIRED",
      label: "EXPIRED",
      tone: "danger",
      isWasteDue: true,
      sortRank: 0,
      defaultReason: `Expired date (${batch.expiration_date})`,
    };
  }
  if (c?.use_by && serverTime && c.use_by <= serverTime) {
    return {
      code: "PAST_USE_BY",
      label: "PAST USE-BY",
      tone: "danger",
      isWasteDue: true,
      sortRank: 1,
      defaultReason: `Past opened use-by (${c.use_by})`,
    };
  }
  if (batch?.expiration_date && todayDate && batch.expiration_date === todayDate) {
    return {
      code: "EXPIRES_TODAY",
      label: "EXPIRES TODAY",
      tone: "amber",
      isWasteDue: false,
      sortRank: 2,
      defaultReason: `End-of-day expiry (${batch.expiration_date})`,
    };
  }
  if (c?.opened_at) {
    return {
      code: "OPENED",
      label: c.use_by ? `OPENED · Use by ${c.use_by.slice(0, 16)}` : "OPENED",
      tone: "info",
      isWasteDue: false,
      sortRank: 3,
      defaultReason: "",
    };
  }
  return {
    code: "SEALED",
    label: "SEALED",
    tone: "ok",
    isWasteDue: false,
    sortRank: 4,
    defaultReason: "",
  };
}

export function StockOperations({
  user,
  data,
  token,
  expired,
  refresh,
  presetOp = null,
  onPresetConsumed = null,
}) {
  const blank = {
    kind: "waste",
    id: "",
    quantity: "",
    reason: "",
    expiration_date: "",
    supplier: "",
    lot_reference: "",
    quarantined: 0,
    expected_remaining: "",
  };
  const [form, setForm] = useState(blank),
    [pending, setPending] = useState(null),
    action = useAction(expired),
    storage = `venuevue.rebuild.stock-operation.${user.id}`;
  useEffect(() => {
    try {
      const saved = JSON.parse(localStorage.getItem(storage) || "null");
      if (saved) {
        setPending(saved);
        setForm(saved);
      }
    } catch {
      action.setError(
        new Error(
          "Cannot read saved stock operation. Restore browser storage before continuing.",
        ),
      );
    }
  }, [storage]);
  useEffect(() => {
    if (!presetOp) return;
    if (!pending) {
      setForm({ ...blank, ...presetOp });
    }
    onPresetConsumed?.();
  }, [presetOp]);
  const batchById = new Map(
    (data.batches ?? []).map((b) => [Number(b.id), b]),
  );
  const decoratedContainers = (data.containers ?? [])
    .map((c) => {
      const b = batchById.get(Number(c.batch_id));
      const status = containerStatus(c, b, data.server_time);
      return { c, b, status };
    })
    .sort(
      (a, b) =>
        a.status.sortRank - b.status.sortRank || Number(b.c.id) - Number(a.c.id),
    );
  const expiredContainers = decoratedContainers.filter(
    (row) => row.status.isWasteDue,
  );
  const selected = (data?.containers ?? []).find(
      (c) => String(c.id) === String(form.id),
    ),
    batch =
      form.kind === "batch"
        ? (data?.batches ?? []).find((b) => String(b.id) === String(form.id))
        : batchById.get(Number(selected?.batch_id)),
    selectedStatus = selected
      ? containerStatus(selected, batch, data?.server_time)
      : null;
  function select(id) {
    if (form.kind === "batch") {
      const b = (data?.batches ?? []).find((b) => String(b.id) === id);
      setForm({
        ...form,
        id,
        expiration_date: b?.expiration_date ?? "",
        supplier: b?.supplier ?? "",
        lot_reference: b?.lot_reference ?? "",
        quarantined: b?.quarantined ?? 0,
      });
    } else {
      const c = (data?.containers ?? []).find((c) => String(c.id) === id);
      const b = c ? batchById.get(Number(c.batch_id)) : null;
      const st = c ? containerStatus(c, b, data?.server_time) : null;
      setForm({
        ...form,
        id,
        quantity:
          form.kind === "adjust" || (form.kind === "waste" && st?.isWasteDue)
            ? (c?.remaining_qty ?? "")
            : "",
        expected_remaining: c?.remaining_qty ?? "",
        reason:
          form.kind === "waste" && st?.defaultReason && !form.reason
            ? st.defaultReason
            : form.reason,
      });
    }
  }
  function quickSelectWaste(c, b, st) {
    if (pending || action.busy) return;
    setForm({
      ...blank,
      kind: "waste",
      id: String(c.id),
      quantity: String(c.remaining_qty ?? ""),
      expected_remaining: String(c.remaining_qty ?? ""),
      reason: st.defaultReason || "Expired date",
    });
  }
  function applyPresetReason(presetKey) {
    let text = presetKey;
    if (presetKey === "Expired date" && batch?.expiration_date) {
      text = `Expired date (${batch.expiration_date})`;
    } else if (presetKey === "Past opened use-by" && selected?.use_by) {
      text = `Past opened use-by (${selected.use_by})`;
    }
    setForm({ ...form, reason: text });
  }
  const field = (key) => ({
    value: form[key] ?? "",
    onChange: (e) => setForm({ ...form, [key]: e.target.value }),
  });
  async function submit(customRequest = null) {
    let request =
      customRequest ??
      pending ?? {
        ...form,
        operation_key: transactionKey(),
        expected_epoch: getHostEpoch(),
      };
    localStorage.setItem(storage, JSON.stringify(request));
    setPending(request);
    try {
      const result = await api("management/inventory", {
        token,
        body: request,
      });
      localStorage.removeItem(storage);
      setPending(null);
      setForm(blank);
      await refresh();
      if (request.kind === "clear_expired") {
        return result.duplicate
          ? "Previously saved expired-stock write-off confirmed."
          : `Wrote off ${result.cleared_count ?? 0} expired/past-use-by container(s) to waste.`;
      }
      return result.duplicate
        ? "Previously saved operation confirmed."
        : "Stock operation saved.";
    } catch (e) {
      if (
        [400, 403, 404, 409, 422].includes(e.status) &&
        e.code !== "INVALID_RESPONSE"
      ) {
        localStorage.removeItem(storage);
        setPending(null);
        await refresh();
      }
      throw e;
    }
  }
  function handleClearAllExpired() {
    if (pending || action.busy || !expiredContainers.length) return;
    if (
      !window.confirm(
        `Write off all ${expiredContainers.length} expired or past use-by container(s) to waste? This records an audited WASTE movement for each container.`,
      )
    ) {
      return;
    }
    const req = {
      kind: "clear_expired",
      reason: "Shift audit: Write off expired or past use-by stock",
      operation_key: transactionKey(),
      expected_epoch: getHostEpoch(),
    };
    action.run(async (notice) => notice(await submit(req)));
  }
  return (
    <>
      {expiredContainers.length > 0 && (
        <section
          className="panel expired-queue-panel"
          aria-label="Expired or past use-by containers"
        >
          <div className="section-heading">
            <div>
              <p className="eyebrow">PERISHABLE STOCK CHECK</p>
              <h2>
                Expired & past use-by containers ({expiredContainers.length})
              </h2>
            </div>
            <button
              type="button"
              className="danger"
              disabled={action.busy || !!pending}
              onClick={handleClearAllExpired}
            >
              Write off all expired ({expiredContainers.length})
            </button>
          </div>
          <p className="subtle">
            These containers are automatically blocked from Register sales.
            Click <strong>Select for waste</strong> to review one container, or{" "}
            <strong>Write off all expired</strong> during shift checks.
          </p>
          <ul className="expired-queue-list">
            {expiredContainers.map(({ c, b, status }) => (
              <li key={c.id} className="expired-queue-row">
                <div className="expired-queue-info">
                  <span className={`stock-badge ${status.tone}`}>
                    {status.label}
                  </span>
                  <strong>
                    #{c.id} · {b?.ingredient_name}
                  </strong>
                  <span>
                    {c.remaining_qty} {b?.unit} remaining · Batch #{c.batch_id}
                    {b?.expiration_date ? ` · Exp ${b.expiration_date}` : ""}
                    {c.use_by ? ` · Use by ${c.use_by}` : ""}
                  </span>
                </div>
                <button
                  type="button"
                  className="compact-btn"
                  disabled={action.busy || !!pending}
                  onClick={() => quickSelectWaste(c, b, status)}
                >
                  Select for waste
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}
      <section className="panel">
        <h2>Waste & stock adjustments</h2>
        <p className="subtle">
          Count in the ingredient’s base unit. Adjustments set the container’s
          actual remaining quantity; use Stock to receive a new delivery.
          Existing opening times stay unchanged.
        </p>
        {action.feedback}
        {pending && (
          <p className="warning">
            A saved operation is awaiting confirmation. Retry its original
            details before making another adjustment.
          </p>
        )}
        <form
          onSubmit={(e) => {
            e.preventDefault();
            action.run(async (notice) => notice(await submit()));
          }}
        >
          <fieldset disabled={action.busy}>
            <fieldset disabled={!!pending}>
              <Select
                label="Operation"
                value={form.kind === "clear_expired" ? "waste" : form.kind}
                onChange={(e) => setForm({ ...blank, kind: e.target.value })}
              >
                <option value="waste">Record waste</option>
                <option value="adjust">Correct counted quantity</option>
                <option value="batch">Correct batch details</option>
              </Select>
              <Select
                label={form.kind === "batch" ? "Batch" : "Container"}
                required={form.kind !== "clear_expired"}
                value={form.id ?? ""}
                onChange={(e) => select(e.target.value)}
              >
                <option value="">
                  Choose {form.kind === "batch" ? "batch" : "container"}
                </option>
                {form.kind === "batch"
                  ? (data?.batches ?? []).map((b) => (
                      <option key={b.id} value={b.id}>
                        #{b.id} · {b.ingredient_name} · {b.reference}
                      </option>
                    ))
                  : decoratedContainers
                      .filter(
                        ({ c }) =>
                          form.kind !== "waste" ||
                          Number(c.remaining_qty) > 0 ||
                          String(c.id) === String(form.id),
                      )
                      .map(({ c, b, status }) => (
                        <option key={c.id} value={c.id}>
                          [{status.code === "OPENED" ? "OPENED" : status.label}] #
                          {c.id} · {b?.ingredient_name} · {c.remaining_qty}{" "}
                          {b?.unit} · batch #{c.batch_id}
                        </option>
                      ))}
              </Select>
              {batch && (
                <p className="subtle">
                  {selectedStatus && (
                    <>
                      <span className={`stock-badge ${selectedStatus.tone}`}>
                        {selectedStatus.label}
                      </span>{" "}
                    </>
                  )}
                  Batch #{batch.id} · expires{" "}
                  {batch.expiration_date ?? "not applicable"} ·{" "}
                  {Number(batch.quarantined)
                    ? "Quarantined"
                    : "Not quarantined"}
                  {selected?.opened_at &&
                    ` · opened ${selected.opened_at} · use by ${selected.use_by ?? "not applicable"}`}
                </p>
              )}
              {form.kind === "batch" ? (
                <>
                  <Field
                    label="Corrected expiration date"
                    type="date"
                    {...field("expiration_date")}
                  />
                  <Field
                    label="Supplier"
                    maxLength={120}
                    {...field("supplier")}
                  />
                  <Field
                    label="Lot reference"
                    maxLength={120}
                    {...field("lot_reference")}
                  />
                  <Select label="Batch eligibility" {...field("quarantined")}>
                    <option value="0">Allow if dates are eligible</option>
                    <option value="1">Quarantine — block sales</option>
                  </Select>
                </>
              ) : (
                <>
                  <div className="qty-label-row">
                    <span>
                      Count checked against: {form.expected_remaining || "—"}{" "}
                      {batch?.unit}
                    </span>
                    {form.kind === "waste" &&
                      selected &&
                      Number(selected.remaining_qty) > 0 &&
                      String(form.quantity) !== String(selected.remaining_qty) && (
                        <button
                          type="button"
                          className="compact-btn"
                          onClick={() =>
                            setForm({
                              ...form,
                              quantity: String(selected.remaining_qty),
                            })
                          }
                        >
                          Use full remaining ({selected.remaining_qty}{" "}
                          {batch?.unit})
                        </button>
                      )}
                  </div>
                  <Field
                    label={
                      form.kind === "waste"
                        ? "Waste quantity"
                        : "Actual remaining quantity"
                    }
                    required={form.kind !== "clear_expired"}
                    inputMode="decimal"
                    {...field("quantity")}
                  />
                  {form.kind === "adjust" && selected && (
                    <div className="quick-preset-row">
                      <span className="preset-caption">
                        Quick count delta (1-click):
                      </span>
                      <div className="quick-chip-group">
                        {[-50, -10, -5, -1, 1, 5, 10, 50].map((delta) => (
                          <button
                            key={delta}
                            type="button"
                            className="compact-btn quick-chip"
                            onClick={() => {
                              const cur =
                                Number(
                                  form.quantity !== ""
                                    ? form.quantity
                                    : selected.remaining_qty,
                                ) || 0;
                              const maxCap = Number(selected.received_qty) || 999999;
                              const next = Math.max(
                                0,
                                Math.min(maxCap, Math.round((cur + delta) * 1000) / 1000),
                              );
                              setForm({ ...form, quantity: String(next) });
                            }}
                          >
                            {delta > 0 ? `+${delta}` : delta} {batch?.unit || ""}
                          </button>
                        ))}
                        <button
                          type="button"
                          className="compact-btn"
                          onClick={() =>
                            setForm({
                              ...form,
                              quantity: String(selected.remaining_qty ?? "0"),
                            })
                          }
                        >
                          Reset to {selected.remaining_qty} {batch?.unit || ""}
                        </button>
                      </div>
                    </div>
                  )}
                </>
              )}
              {form.kind === "waste" && (
                <div className="waste-presets-block">
                  <span className="preset-caption">Quick waste reason:</span>
                  <div className="void-presets">
                    {WASTE_PRESETS.map((p) => {
                      const active = (form.reason ?? "")
                        .toLowerCase()
                        .startsWith(p.key.toLowerCase());
                      return (
                        <button
                          type="button"
                          key={p.key}
                          className={active ? "primary" : ""}
                          onClick={() => applyPresetReason(p.key)}
                        >
                          {p.label}
                        </button>
                      );
                    })}
                  </div>
                </div>
              )}
              {form.kind === "adjust" && (
                <div className="waste-presets-block">
                  <span className="preset-caption">
                    Quick adjustment reason (1-click):
                  </span>
                  <div className="void-presets">
                    {[
                      "End-of-shift physical count",
                      "Spillage / calibration loss",
                      "Unrecorded bar prep",
                      "Delivery count correction",
                    ].map((rText) => (
                      <button
                        type="button"
                        key={rText}
                        className={form.reason === rText ? "primary" : ""}
                        onClick={() => setForm({ ...form, reason: rText })}
                      >
                        {rText}
                      </button>
                    ))}
                  </div>
                </div>
              )}
              {form.kind === "batch" && (
                <div className="waste-presets-block">
                  <span className="preset-caption">
                    Quick batch correction reason (1-click):
                  </span>
                  <div className="void-presets">
                    {[
                      "Corrected supplier expiry date",
                      "Quarantine inspection",
                      "Updated lot / supplier details",
                    ].map((rText) => (
                      <button
                        type="button"
                        key={rText}
                        className={form.reason === rText ? "primary" : ""}
                        onClick={() => setForm({ ...form, reason: rText })}
                      >
                        {rText}
                      </button>
                    ))}
                  </div>
                </div>
              )}
              <Field
                label="Reason for stock operation"
                required
                maxLength={180}
                {...field("reason")}
              />
            </fieldset>
            <button className="primary">
              {pending ? "Retry saved operation" : "Save stock operation"}
            </button>
          </fieldset>
        </form>
        {!(data?.batches?.length) && (
          <p>No batches yet. Receive a delivery from Stock first.</p>
        )}
      </section>
      <History token={token} expired={expired} stock />
    </>
  );
}
