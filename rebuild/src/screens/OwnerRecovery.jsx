import { useEffect, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { api } from "../api";
import Brand from "../components/Brand";
import StaffPassword, { staffPasswordError } from "../components/StaffPassword";
import SaveRecoveryCode from "../components/SaveRecoveryCode";
import RecoveryNotice from "../components/RecoveryNotice";
import "./owner-recovery.css";
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
      <p>Use the recovery code you saved to create a new Owner password. You do not need email. Keep the shop’s host laptop and Wi-Fi running.</p>
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
      <section className="recovery-staff" aria-labelledby="barista-recovery-title"><h2 id="barista-recovery-title">Barista: contact the Owner</h2>
        <p>Ask your Owner/Admin to reset your password in <strong>Management → Account security</strong>. Barista passwords can only be reset with Owner authorization.</p>
        <p>Afterward, sign in using the same account, browser profile and shop address to return to your saved orders.</p>
      </section>
      <p><strong>Lost both your Owner password and recovery code?</strong> Contact the authorized host administrator or project support. Keep the database intact. Creating another Owner or reinstalling is not a recovery method.</p>
      <p>If you can still sign in, prepare or replace your code in <strong>Management → Account security</strong>.</p>
    </aside></div>
  </main>;
}
