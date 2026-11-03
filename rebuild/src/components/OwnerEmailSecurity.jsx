import { useEffect, useState } from "react";
import { api } from "../api";
import StaffPassword from "./StaffPassword";

function maskEmail(raw) {
  if (!raw || typeof raw !== "string" || !raw.includes("@")) return "";
  const [local, domain] = raw.trim().split("@");
  if (!local || !domain) return raw;
  if (local.length <= 2) return `${local[0]}***@${domain}`;
  const stars = "*".repeat(Math.min(Math.max(local.length - 2, 3), 6));
  return `${local[0]}${stars}${local[local.length - 1]}@${domain}`;
}

export default function OwnerEmailSecurity({ user, token, expired, onPrepared }) {
  // Profile state
  const [shopName, setShopName] = useState(user?.shop_name || "Nearby Café");
  const [email, setEmail] = useState("");
  const [registeredEmail, setRegisteredEmail] = useState(user?.email || "");
  const [ownerPassword, setOwnerPassword] = useState("");
  const [profileBusy, setProfileBusy] = useState(false);
  const [profileError, setProfileError] = useState(null);
  const [profileNotice, setProfileNotice] = useState("");

  // Hidden Admin SMTP Unlock (click heading 5 times or ?smtp=1 in URL)
  const [showSmtpAdmin, setShowSmtpAdmin] = useState(() => {
    const h = window.location.hash || "";
    const s = window.location.search || "";
    return h.includes("smtp=1") || s.includes("smtp=1");
  });
  const [adminClicks, setAdminClicks] = useState(0);

  // SMTP state
  const [smtpStatus, setSmtpStatus] = useState(null);
  const [smtpUsername, setSmtpUsername] = useState("");
  const [smtpPassword, setSmtpPassword] = useState("");
  const [smtpHost, setSmtpHost] = useState("smtp.gmail.com");
  const [smtpPort, setSmtpPort] = useState(587);
  const [smtpEncryption, setSmtpEncryption] = useState("tls");
  const [smtpFromName, setSmtpFromName] = useState("Nearby Café");
  const [smtpOwnerPassword, setSmtpOwnerPassword] = useState("");
  const [showAdvancedSmtp, setShowAdvancedSmtp] = useState(false);
  const [smtpBusy, setSmtpBusy] = useState(false);
  const [smtpError, setSmtpError] = useState(null);
  const [smtpNotice, setSmtpNotice] = useState("");

  useEffect(() => {
    if (!token) return;
    loadSmtpStatus();
  }, [token]);

  async function loadSmtpStatus() {
    try {
      const res = await api("management/smtp-status", { token });
      setSmtpStatus(res);
      if (res.host) setSmtpHost(res.host);
      if (res.port) setSmtpPort(res.port);
      if (res.encryption) setSmtpEncryption(res.encryption);
      if (res.from_name) setSmtpFromName(res.from_name);
    } catch (err) {
      if (err.status === 401) expired();
    }
  }

  function handleSecretHeadingClick() {
    const next = adminClicks + 1;
    if (next >= 5) {
      setShowSmtpAdmin((prev) => !prev);
      setAdminClicks(0);
    } else {
      setAdminClicks(next);
    }
  }

  async function handleProfileSave(e) {
    e.preventDefault();
    setProfileError(null);
    setProfileNotice("");

    const cleanEmail = email.trim() || registeredEmail.trim();
    if (!cleanEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cleanEmail)) {
      setProfileError(new Error("Enter a valid email address (for example, owner@example.com)."));
      return;
    }
    if (!ownerPassword) {
      setProfileError(new Error("Enter your current owner password to authorize this update."));
      return;
    }

    setProfileBusy(true);
    try {
      const res = await api("management/owner-profile", {
        token,
        body: {
          email: cleanEmail,
          shop_name: shopName.trim(),
          owner_password: ownerPassword,
        },
      });
      setProfileNotice("Recovery email and store profile updated successfully.");
      setOwnerPassword("");
      const savedEmail = res?.user?.email || cleanEmail;
      setEmail("");
      setRegisteredEmail(savedEmail);
      onPrepared?.(Boolean(savedEmail));
    } catch (err) {
      setProfileError(err);
      if (err.status === 401) expired();
    } finally {
      setProfileBusy(false);
    }
  }

  async function handleSmtpSave(e) {
    e.preventDefault();
    setSmtpError(null);
    setSmtpNotice("");

    const cleanUser = smtpUsername.trim() || (smtpStatus?.username || "").trim();
    if (!cleanUser || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cleanUser)) {
      setSmtpError(new Error("Enter a valid Gmail or SMTP email address."));
      return;
    }
    if (!smtpPassword && !smtpStatus?.has_password) {
      setSmtpError(new Error("Enter your 16-character Google App Password."));
      return;
    }
    if (!smtpOwnerPassword) {
      setSmtpError(new Error("Enter your current owner password to authorize email settings."));
      return;
    }

    setSmtpBusy(true);
    try {
      const res = await api("management/smtp-save", {
        token,
        body: {
          enabled: true,
          username: cleanUser,
          password: smtpPassword,
          host: smtpHost.trim(),
          port: Number(smtpPort) || 587,
          encryption: smtpEncryption,
          from_name: smtpFromName.trim(),
          from_email: cleanUser,
          owner_password: smtpOwnerPassword,
        },
      });
      setSmtpNotice(res.message || "Connected to Gmail SMTP successfully! A test email has been sent.");
      setSmtpUsername("");
      setSmtpPassword("");
      setSmtpOwnerPassword("");
      await loadSmtpStatus();
    } catch (err) {
      setSmtpError(err);
      if (err.status === 401) expired();
    } finally {
      setSmtpBusy(false);
    }
  }

  async function handleSmtpDisable() {
    if (!window.confirm("Turn off online email delivery? Password reset emails will be paused until re-enabled.")) {
      return;
    }
    const pwd = window.prompt("Enter your current Owner password to confirm turning off email delivery:");
    if (!pwd) return;

    setSmtpBusy(true);
    setSmtpError(null);
    setSmtpNotice("");
    try {
      const res = await api("management/smtp-save", {
        token,
        body: {
          enabled: false,
          owner_password: pwd,
        },
      });
      setSmtpNotice(res.message || "Email delivery disabled.");
      await loadSmtpStatus();
    } catch (err) {
      setSmtpError(err);
      if (err.status === 401) expired();
    } finally {
      setSmtpBusy(false);
    }
  }

  const isSmtpActive = Boolean(smtpStatus?.enabled && smtpStatus?.configured);

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: "24px" }}>
      {/* 1. Recovery Email & Profile */}
      <section className="panel">
        <h2
          onClick={handleSecretHeadingClick}
          style={{ userSelect: "none", cursor: "default" }}
          title="Owner Recovery Email & Store Profile"
        >
          Owner Recovery Email & Store Profile
        </h2>
        <p className="subtle small">
          Your recovery email receives 6-digit verification codes whenever you click <strong>Forgot password?</strong> on the login screen.
        </p>

        {profileError && (
          <div className="error" role="alert" style={{ marginBottom: "16px" }}>
            <strong>{profileError.message}</strong>
          </div>
        )}

        {profileNotice && (
          <p className="success" role="status" style={{ marginBottom: "16px" }}>
            {profileNotice}
          </p>
        )}

        <div
          style={{
            background: "var(--recovery-surface, #fdf6f0)",
            border: "1px solid #ebdcd0",
            borderRadius: "10px",
            padding: "16px",
            marginBottom: "20px",
          }}
        >
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "8px" }}>
            <span style={{ fontSize: "0.85rem", fontWeight: "700", color: "#7f6055" }}>
              CURRENT REGISTERED EMAIL
            </span>
            <span
              style={{
                fontSize: "0.75rem",
                background: registeredEmail ? "#2e7d32" : "#c62828",
                color: "#fff",
                padding: "2px 8px",
                borderRadius: "10px",
                fontWeight: "700",
              }}
            >
              {registeredEmail ? "REGISTERED" : "NOT SET"}
            </span>
          </div>
          <div style={{ fontSize: "1.15rem", fontWeight: "700", color: "#2d1810", wordBreak: "break-all" }}>
            {registeredEmail ? maskEmail(registeredEmail) : "No email registered yet"}
          </div>
        </div>

        <form onSubmit={handleProfileSave} noValidate>
          <fieldset disabled={profileBusy}>
            <label htmlFor="owner-email-input">
              {registeredEmail ? "New recovery email (optional)" : "Recovery email"}
              <input
                id="owner-email-input"
                type="email"
                autoComplete="off"
                required={!registeredEmail}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder={
                  registeredEmail
                    ? "Enter a new email address to replace current"
                    : "owner@example.com"
                }
              />
              <span className="setup-hint" style={{ fontSize: "0.8rem", color: "#7f6055", display: "block", marginTop: "4px" }}>
                {registeredEmail
                  ? "Leave blank to keep your current registered email, or enter a new email to replace it."
                  : "Must be an email address you can access to receive reset codes."}
              </span>
            </label>

            <label htmlFor="owner-shop-name" style={{ marginTop: "12px", display: "block" }}>
              Café / Store name
              <input
                id="owner-shop-name"
                type="text"
                required
                maxLength={120}
                value={shopName}
                onChange={(e) => setShopName(e.target.value)}
              />
            </label>

            <div style={{ marginTop: "12px" }}>
              <StaffPassword
                label="Confirm current owner password"
                disabled={profileBusy}
                autoComplete="current-password"
                required
                value={ownerPassword}
                onChange={(e) => setOwnerPassword(e.target.value)}
              />
              <span className="setup-hint" style={{ fontSize: "0.8rem", color: "#7f6055", display: "block", marginTop: "4px" }}>
                Required to authorize changes to your store profile.
              </span>
            </div>

            <button className="primary" style={{ marginTop: "16px" }} disabled={profileBusy}>
              {profileBusy ? "Saving changes…" : "Save profile & recovery email"}
            </button>
          </fieldset>
        </form>
      </section>

      {/* 2. Hidden Admin Gmail / SMTP Setup (Unlocked by clicking heading 5 times) */}
      {showSmtpAdmin && (
        <section className="panel" style={{ border: "1px dashed #c89d7c" }}>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: "12px" }}>
            <div>
              <p className="eyebrow">SYSTEM ADMIN · HIDDEN PANEL</p>
              <h2>Email Delivery & Gmail SMTP Setup</h2>
              <p className="subtle small">
                Connect your Gmail account so the system can dispatch real 6-digit verification codes to your phone and email inbox.
              </p>
            </div>
            <div style={{ display: "flex", flexDirection: "column", alignItems: "flex-end", gap: "6px" }}>
              <span
                style={{
                  fontSize: "0.75rem",
                  background: isSmtpActive ? "#2e7d32" : "#6c757d",
                  color: "#fff",
                  padding: "4px 10px",
                  borderRadius: "12px",
                  fontWeight: "700",
                  whiteSpace: "nowrap",
                }}
              >
                {isSmtpActive ? "🟢 SMTP ACTIVE" : "⚪ SMTP NOT CONFIGURED"}
              </span>
              <button
                type="button"
                className="compact-btn"
                onClick={() => setShowSmtpAdmin(false)}
                style={{ fontSize: "0.75rem" }}
              >
                Hide admin panel
              </button>
            </div>
          </div>

          {smtpError && (
            <div className="error" role="alert" style={{ marginBottom: "16px" }}>
              <strong>{smtpError.message}</strong>
            </div>
          )}

          {smtpNotice && (
            <div className="success" role="status" style={{ marginBottom: "16px", padding: "12px", borderRadius: "8px" }}>
              <strong>{smtpNotice}</strong>
            </div>
          )}

          <div
            style={{
              background: "#fff9f4",
              border: "1px solid #fed7aa",
              borderRadius: "8px",
              padding: "14px",
              fontSize: "0.85rem",
              color: "#7c2d12",
              marginBottom: "18px",
            }}
          >
            <strong>💡 How to connect your Gmail in 1 minute:</strong>
            <ol style={{ margin: "6px 0 0 18px", padding: 0, lineHeight: "1.5" }}>
              <li>Turn on <strong>2-Step Verification</strong> in your Google Account.</li>
              <li>
                Go to Google Account Security → <strong>App passwords</strong> (or visit{" "}
                <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noreferrer" style={{ textDecoration: "underline" }}>
                  myaccount.google.com/apppasswords
                </a>
                ).
              </li>
              <li>Create an App password named <em>Nearby Café</em> and copy the 16-character code.</li>
              <li>Paste your Gmail address and 16-character App password below, then click <strong>Connect & Test Email Delivery</strong>.</li>
            </ol>
          </div>

          <form onSubmit={handleSmtpSave} noValidate>
            <fieldset disabled={smtpBusy}>
              <label htmlFor="smtp-username">
                Sender Gmail Address {smtpStatus?.username ? `(Current: ${maskEmail(smtpStatus.username)})` : ""}
                <input
                  id="smtp-username"
                  type="email"
                  autoComplete="off"
                  required={!smtpStatus?.username}
                  value={smtpUsername}
                  onChange={(e) => setSmtpUsername(e.target.value)}
                  placeholder={
                    smtpStatus?.username
                      ? `${maskEmail(smtpStatus.username)} (Leave blank to keep existing)`
                      : "yourname@gmail.com"
                  }
                />
              </label>

              <div style={{ marginTop: "12px" }}>
                <label htmlFor="smtp-password">
                  Google 16-Character App Password
                  <input
                    id="smtp-password"
                    type="password"
                    required={!smtpStatus?.has_password}
                    value={smtpPassword}
                    onChange={(e) => setSmtpPassword(e.target.value)}
                    placeholder={smtpStatus?.has_password ? "•••••••••••••••• (Leave blank to keep existing)" : "abcd efgh ijkl mnop"}
                    autoComplete="off"
                  />
                </label>
              </div>

              <div style={{ marginTop: "12px" }}>
                <button
                  type="button"
                  className="link-button"
                  onClick={() => setShowAdvancedSmtp(!showAdvancedSmtp)}
                  style={{ fontSize: "0.85rem", padding: "4px 0", textDecoration: "underline" }}
                >
                  {showAdvancedSmtp ? "▲ Hide advanced SMTP settings" : "▼ Show advanced SMTP settings (Host, Port, TLS)"}
                </button>
              </div>

              {showAdvancedSmtp && (
                <div
                  style={{
                    background: "#f8f9fa",
                    border: "1px solid #dee2e6",
                    borderRadius: "8px",
                    padding: "14px",
                    marginTop: "8px",
                    marginBottom: "12px",
                  }}
                >
                  <div style={{ display: "grid", gridTemplateColumns: "2fr 1fr 1fr", gap: "10px" }}>
                    <label htmlFor="smtp-host" style={{ margin: 0 }}>
                      SMTP Host
                      <input
                        id="smtp-host"
                        type="text"
                        value={smtpHost}
                        onChange={(e) => setSmtpHost(e.target.value)}
                      />
                    </label>
                    <label htmlFor="smtp-port" style={{ margin: 0 }}>
                      Port
                      <input
                        id="smtp-port"
                        type="number"
                        value={smtpPort}
                        onChange={(e) => setSmtpPort(e.target.value)}
                      />
                    </label>
                    <label htmlFor="smtp-encryption" style={{ margin: 0 }}>
                      Security
                      <select
                        id="smtp-encryption"
                        value={smtpEncryption}
                        onChange={(e) => setSmtpEncryption(e.target.value)}
                        style={{ padding: "8px", borderRadius: "6px", width: "100%" }}
                      >
                        <option value="tls">TLS (Port 587)</option>
                        <option value="ssl">SSL (Port 465)</option>
                      </select>
                    </label>
                  </div>
                  <label htmlFor="smtp-from-name" style={{ marginTop: "10px", display: "block" }}>
                    Sender Display Name
                    <input
                      id="smtp-from-name"
                      type="text"
                      value={smtpFromName}
                      onChange={(e) => setSmtpFromName(e.target.value)}
                    />
                  </label>
                </div>
              )}

              <div style={{ marginTop: "14px" }}>
                <StaffPassword
                  label="Confirm current owner password"
                  disabled={smtpBusy}
                  autoComplete="current-password"
                  required
                  value={smtpOwnerPassword}
                  onChange={(e) => setSmtpOwnerPassword(e.target.value)}
                />
                <span className="setup-hint" style={{ fontSize: "0.8rem", color: "#7f6055", display: "block", marginTop: "4px" }}>
                  Confirm your password to authorize updating email delivery settings.
                </span>
              </div>

              <div style={{ display: "flex", gap: "12px", alignItems: "center", marginTop: "18px" }}>
                <button className="primary" disabled={smtpBusy}>
                  {smtpBusy ? "Testing & saving…" : "Connect & Test Email Delivery"}
                </button>
                {isSmtpActive && (
                  <button
                    type="button"
                    className="secondary"
                    disabled={smtpBusy}
                    onClick={handleSmtpDisable}
                    style={{ color: "#c62828" }}
                  >
                    Turn off email delivery
                  </button>
                )}
              </div>
            </fieldset>
          </form>
        </section>
      )}
    </div>
  );
}
