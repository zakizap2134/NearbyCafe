import { useEffect, useRef, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { api } from "../api";
import Brand from "../components/Brand";
import StaffPassword, { staffPasswordError } from "../components/StaffPassword";
import SaveRecoveryCode from "../components/SaveRecoveryCode";
import RecoveryNotice from "../components/RecoveryNotice";
import "./owner-recovery.css";

function EmailResetRequestCard() {
  const [email, setEmail] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [sentMessage, setSentMessage] = useState("");

  async function handleRequest(e) {
    e.preventDefault();
    if (busy) return;
    setError(null);
    setSentMessage("");
    const trimmed = email.trim();
    if (!trimmed || trimmed.length > 191 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
      setError(new Error("Enter your registered email address (for example, owner@example.com)."));
      return;
    }
    setBusy(true);
    try {
      const res = await api("recovery/email-request", { body: { email: trimmed } });
      setSentMessage(
        res?.message ||
          "If an active account matches that email address, a password reset link has been sent. Please check your inbox and spam folder.",
      );
      setEmail("");
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="recovery-staff" aria-labelledby="email-recovery-title">
      <p className="recovery-eyebrow">ONLINE EMAIL RECOVERY</p>
      <h2 id="email-recovery-title">Reset password via Email</h2>
      <p>
        Enter the email address linked to your account. We will send you a 15-minute password reset link.
      </p>
      {error && <RecoveryNotice error={error} id="email-reset-error" />}
      {sentMessage && (
        <div className="success" role="status" style={{ marginBottom: "14px" }}>
          <p style={{ margin: 0 }}>{sentMessage}</p>
          <p style={{ margin: "8px 0 0", fontSize: "0.9rem" }}>
            <Link to="/reset-password" style={{ fontWeight: 700, color: "inherit" }}>
              Have your reset token? Click here to enter it manually →
            </Link>
          </p>
        </div>
      )}
      <form onSubmit={handleRequest} noValidate>
        <fieldset disabled={busy}>
          <label htmlFor="recovery-email-input" style={{ display: "block", fontWeight: 700, marginBottom: "6px" }}>
            Registered email address
          </label>
          <input
            id="recovery-email-input"
            type="email"
            autoComplete="email"
            placeholder="owner@example.com"
            required
            maxLength={191}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            style={{ width: "100%", marginBottom: "12px" }}
          />
          <button type="submit" className="primary full" disabled={busy}>
            {busy ? "Sending reset link…" : "Send password reset email"}
          </button>
        </fieldset>
      </form>
      <p style={{ marginTop: "10px", fontSize: "0.9rem" }}>
        <Link to="/reset-password" className="forgot-password-link" style={{ padding: "4px 0", minHeight: "auto" }}>
          Already have a reset token? Reset password here
        </Link>
      </p>
    </section>
  );
}

export function EmailTokenReset() {
  const [searchParams] = useSearchParams();
  const initialToken = searchParams.get("token") || "";
  const [token, setToken] = useState(initialToken);
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [doneUser, setDoneUser] = useState("");

  useEffect(() => {
    if (initialToken) setToken(initialToken);
  }, [initialToken]);

  async function submitTokenReset(e) {
    e.preventDefault();
    if (busy || doneUser) return;
    setError(null);
    const cleaned = token.trim().replaceAll("-", "");
    if (!/^[a-fA-F0-9]{64}$/.test(cleaned) || token.length > 100) {
      setError(new Error("Enter the 64-character reset token from your email."));
      return;
    }
    const invalid = staffPasswordError(password, confirmation);
    if (invalid) {
      setError(new Error(invalid));
      return;
    }
    setBusy(true);
    try {
      const res = await api("recovery/email-reset", {
        body: {
          token: token.trim(),
          new_password: password,
          confirm_password: confirmation,
        },
      });
      setPassword("");
      setConfirmation("");
      setDoneUser(res?.username || "your account");
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="owner-recovery-page">
      <header className="recovery-brand-bar">
        <Brand compact header />
        <div>
          <span className="recovery-eyebrow">NEARBY CAFÉ</span>
          <p>Complete your email password reset.</p>
        </div>
      </header>
      <div className="recovery-content">
        <section className="panel owner-recovery-card" aria-labelledby="token-reset-title">
          <p className="recovery-eyebrow">EMAIL PASSWORD RESET</p>
          <h1 id="token-reset-title" tabIndex={-1}>
            Choose a new password
          </h1>
          <p>
            Enter the reset token from your email and choose a new password (at least 12 characters).
          </p>
          <RecoveryNotice error={error} id="token-reset-error" />
          {doneUser ? (
            <div className="success" role="status">
              <strong>Password updated!</strong>
              <p>
                The password for <strong>{doneUser}</strong> has been reset and previous sessions have been signed out.
              </p>
              <Link className="primary full" to="/login" style={{ display: "inline-flex", justifyContent: "center", marginTop: "12px", textDecoration: "none" }}>
                Continue to Sign in
              </Link>
            </div>
          ) : (
            <form onSubmit={submitTokenReset} noValidate autoComplete="off" aria-busy={busy}>
              <fieldset disabled={busy}>
                <label htmlFor="reset-token-input">Reset token from email</label>
                <input
                  id="reset-token-input"
                  value={token}
                  onChange={(e) => setToken(e.target.value)}
                  required
                  autoComplete="off"
                  autoCapitalize="none"
                  spellCheck={false}
                  maxLength={100}
                  placeholder="Paste 64-character token from email"
                  style={{ width: "100%", marginBottom: "20px" }}
                />
                <StaffPassword
                  label="New password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={busy}
                  required
                  autoComplete="new-password"
                />
                <StaffPassword
                  label="Confirm new password"
                  value={confirmation}
                  onChange={(e) => setConfirmation(e.target.value)}
                  disabled={busy}
                  required
                  autoComplete="new-password"
                />
                <p id="token-password-rules">
                  Choose a password you can remember and enter it twice. Use at least 12 characters.
                </p>
                <button type="submit" className="primary full" disabled={busy}>
                  {busy ? "Saving new password…" : "Save new password"}
                </button>
              </fieldset>
            </form>
          )}
          <div style={{ display: "flex", gap: "16px", flexWrap: "wrap", marginTop: "12px" }}>
            <Link className="recovery-back" to="/forgot-password">
              Request another reset email
            </Link>
            <Link className="recovery-back" to="/login">
              Back to sign in
            </Link>
          </div>
        </section>
      </div>
    </main>
  );
}

export default function OwnerRecovery() {
  const navigate = useNavigate();
  const [username, setUsername] = useState(""), [code, setCode] = useState(""), [password, setPassword] = useState(""), [confirmation, setConfirmation] = useState("");
  const [busy, setBusy] = useState(false), [error, setError] = useState(null), [uncertain, setUncertain] = useState(false), [replacement, setReplacement] = useState("");
  const running = useRef(false), generation = useRef(0), uncertainNotice = useRef(null);
  useEffect(() => { if (uncertain) uncertainNotice.current?.focus(); }, [uncertain]);
  useEffect(() => { document.getElementById("owner-recovery-title")?.focus(); return () => { generation.current += 1; }; }, []);
  function clearSecrets() { setCode(""); setPassword(""); setConfirmation(""); }
  async function submit(event) {
    event.preventDefault(); if (running.current || uncertain || replacement) return; setError(null);
    if (!/^[a-zA-Z0-9][a-zA-Z0-9._@-]{2,63}$/.test(username.trim())) { setError(new Error("Enter your username (3–64 characters using letters, numbers, dots, @, underscores or hyphens).")); return; }
    if (!/^[a-fA-F0-9]{64}$/.test(code.trim().replaceAll("-", "")) || code.length > 100) { setError(new Error("Enter your current offline recovery code: 64 letters/numbers, with or without its hyphens.")); return; }
    const invalid = staffPasswordError(password, confirmation); if (invalid) { setError(new Error(invalid)); return; }
    const current = generation.current, body = { username: username.trim(), recovery_code: code.trim(), new_password: password, confirm_password: confirmation };
    running.current = true; setBusy(true); clearSecrets();
    try {
      const result = await api("recovery/owner", { body }); if (current !== generation.current) return;
      if (result?.reset !== true || result?.sessions_revoked !== true || !/^[A-F0-9]{8}(?:-[A-F0-9]{8}){7}$/.test(result.recovery_code)) throw new Error("Unreadable recovery confirmation");
      setReplacement(result.recovery_code);
    } catch (e) {
      if (current !== generation.current) return;
      const known = (e.status === 403 && ["RECOVERY_INVALID", "RECOVERY_ORIGIN"].includes(e.code)) || (e.status === 429 && e.code === "RECOVERY_LIMIT") || (e.status === 422 && e.code === "VALIDATION");
      if (known) setError(e); else setUncertain(true);
    } finally { running.current = false; if (current === generation.current) setBusy(false); }
  }
  return <main className="owner-recovery-page">
    <header className="recovery-brand-bar"><Brand compact header /><div><span className="recovery-eyebrow">NEARBY CAFÉ</span><p>Your account, safely back in reach.</p></div></header>
    <div className="recovery-content"><section className="panel owner-recovery-card" aria-labelledby="owner-recovery-title">
      <p className="recovery-eyebrow">ACCOUNT HELP</p><h1 id="owner-recovery-title" tabIndex={-1}>Forgot password?</h1><h2>Owner/Admin recovery</h2>
      <p>Reset your password via your registered Email (on the right) or use the offline recovery code you saved below.</p>
      <div className="recovery-note" id="recovery-code-help"><strong>Use your Owner recovery code</strong><p>This is the code saved from Account security or a previous password reset. The initial setup code used to create the first Owner account will not work here.</p></div>
      <RecoveryNotice error={error} id="owner-reset-error" />
      {uncertain ? <div ref={uncertainNotice} tabIndex={-1} className="error recovery-notice" role="alert" aria-atomic="true"><strong>The result could not be confirmed.</strong>
        <p>The password may already have changed. Try signing in with the new password you just entered.</p>
        <p>After successful sign-in, open <strong>Management → Account security</strong> to generate a replacement recovery code using your current password. The code from this attempt cannot be retrieved.</p>
        <p>Do not assume the old password or code still works. If you cannot sign in, contact authorized support and keep your existing code and shop data.</p>
      </div> : replacement ? <p role="status" className="success">Your Owner password has changed and previous Owner sessions have ended. Save the replacement recovery code, then sign in with your new password.</p> : <form onSubmit={submit} noValidate autoComplete="off" aria-busy={busy} aria-describedby={error ? "owner-reset-error" : undefined}><fieldset disabled={busy}>
        <label htmlFor="recovery-username">Username</label><input id="recovery-username" value={username} onChange={e => setUsername(e.target.value)} required autoComplete="username" autoCapitalize="none" spellCheck={false} maxLength={64} />
        <StaffPassword label="Current offline recovery code" value={code} onChange={e => setCode(e.target.value)} disabled={busy} required autoComplete="off" aria-describedby="recovery-code-help" maxLength={100} />
        <StaffPassword label="New password" value={password} onChange={e => setPassword(e.target.value)} disabled={busy} required autoComplete="off" aria-describedby="recovery-password-rules" />
        <StaffPassword label="Confirm new password" value={confirmation} onChange={e => setConfirmation(e.target.value)} disabled={busy} required autoComplete="off" aria-describedby="recovery-password-rules" />
        <p id="recovery-password-rules">Choose a password you can remember and enter it twice. Use at least 12 characters.</p>
        <button type="submit" className="primary full" disabled={busy}>{busy ? "Resetting password…" : "Reset Owner password"}</button>
      </fieldset></form>}
      {busy && <p role="status">Waiting for confirmation. This request will not retry automatically. If you leave now, its result may be unknown.</p>}
      <Link className="recovery-back" to="/login">Back to sign in</Link>
      {replacement && <SaveRecoveryCode introduction="Your Owner password has changed. Save this replacement code before returning to sign-in." code={replacement} username={username.trim()} onDone={() => { setReplacement(""); clearSecrets(); navigate("/login", { replace: true }); }} />}
    </section><aside className="recovery-support panel" aria-label="Other ways to get help">
      <EmailResetRequestCard />
      <section className="recovery-staff" aria-labelledby="barista-recovery-title"><h2 id="barista-recovery-title">Barista: contact the Owner</h2>
        <p>Ask your Owner/Admin to reset your password in <strong>Management → Account security</strong> (or use Email Reset above if your email is linked to your Barista account).</p>
        <p>Afterward, sign in using the same account, browser profile and shop address to return to your saved orders.</p>
      </section>
      <p><strong>Lost both your Owner password and recovery code?</strong> Use the Email Recovery box above, or contact the authorized host administrator. Keep the database intact.</p>
      <p>If you can still sign in, prepare or replace your recovery email and offline code in <strong>Management → Account security</strong>.</p>
    </aside></div>
  </main>;
}
