import { useEffect, useRef, useState } from "react";
import { api } from "../api";
import RecoveryNotice from "./RecoveryNotice";
import StaffPassword from "./StaffPassword";
import SaveRecoveryCode from "./SaveRecoveryCode";
import "./recovery-code.css";
export default function OwnerRecoveryCode({ user, token, expired, onPrepared }) {
  const [state, setState] = useState(null), [loading, setLoading] = useState(false);
  const [password, setPassword] = useState(""), [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false), [error, setError] = useState(null);
  const [code, setCode] = useState(""), [notice, setNotice] = useState(""), [uncertain, setUncertain] = useState(false);
  const generation = useRef(0), running = useRef(false), uncertainNotice = useRef(null);
  useEffect(() => { if (uncertain) uncertainNotice.current?.focus(); }, [uncertain]);
  async function load() {
    const current = generation.current; setLoading(true); setError(null); setConfirmed(false); setPassword("");
    try { const next = await api("management/recovery-code/status", { token }); if (current !== generation.current) return; setState(next); onPrepared?.(next.prepared); }
    catch (e) { if (current !== generation.current) return; setState(null); setError(e); if (e.status === 401) expired(); }
    finally { if (current === generation.current) setLoading(false); }
  }
  useEffect(() => { load(); return () => { generation.current += 1; }; }, [token]);
  useEffect(() => {
    if (!code) return; const current = generation.current;
    const check = async () => { try { await api("session", { token }); } catch (e) { if (current === generation.current && e.status === 401) { setCode(""); expired(); } } };
    window.addEventListener("focus", check); return () => window.removeEventListener("focus", check);
  }, [code, token]);
  async function generate(event) {
    event.preventDefault(); if (running.current || !state || loading || code) return; setError(null); setNotice("");
    if (!password || new TextEncoder().encode(password).length > 72 || password.includes("\0")) { setError(new Error("Enter your current Owner password.")); return; }
    if (state.prepared && !confirmed) { setError(new Error("Confirm that replacing the code will invalidate your previous code.")); return; }
    const current = generation.current, body = { owner_password: password, expected_version: state.version, confirm_replace: confirmed };
    running.current = true; setBusy(true); setPassword(""); setConfirmed(false);
    try {
      const result = await api("management/recovery-code/generate", { token, body }); if (current !== generation.current) return;
      if (!result?.prepared || !/^[A-F0-9]{8}(?:-[A-F0-9]{8}){7}$/.test(result.recovery_code) || !/^[a-f0-9]{64}$/.test(result.version)) throw new Error("The host returned an unreadable recovery result.");
      setState({ prepared: true, version: result.version }); setUncertain(false); setCode(result.recovery_code); onPrepared?.(true);
    } catch (e) {
      if (current !== generation.current) return; if (e.status === 401) { expired(); return; }
      if (![400, 403, 409, 422, 429].includes(e.status) || e.code === "INVALID_RESPONSE") { setUncertain(true); setState(null); }
      else { setError(e); if (e.code === "RECOVERY_CHANGED") setState(null); }
    } finally { running.current = false; if (current === generation.current) setBusy(false); }
  }
  return <section className="panel owner-recovery-code" aria-labelledby="owner-recovery-heading">
    <h2 id="owner-recovery-heading">Owner recovery code</h2>
    <p>Save a recovery code while you can still sign in. If you forget your Owner password, you can use this code to reset it.</p>
    <div className="recovery-note"><strong>Already used a code to set up the shop?</strong><p>That initial setup code only creates the first Owner account. Prepare a separate Owner recovery code here for future password resets.</p></div>
    <RecoveryNotice error={error} id="preparation-error" />
    {uncertain && <div ref={uncertainNotice} tabIndex={-1} role="alert" aria-atomic="true" className="error recovery-notice">We could not confirm the result. The code may have changed. Check its status, enter your current password and confirm a replacement to get a code you can save. This does not change your password.</div>}
    {notice && <p role="status" className="success">{notice}</p>}
    <p className="recovery-state" role="status" aria-live="polite" aria-atomic="true">{loading ? "Checking recovery-code status…" : state ? state.prepared ? "A recovery code is prepared. Keep your saved copy safe." : "No recovery code is prepared yet." : "Recovery-code status is unavailable."}</p>
    <button type="button" disabled={loading || busy || !!code} onClick={load}>Check code status</button>
    {state && <form onSubmit={generate} noValidate className="section-space" aria-busy={busy} aria-describedby={error ? "preparation-error" : undefined}><fieldset disabled={busy || loading || !!code}>
      <StaffPassword label="Current Owner password for recovery code" value={password} required autoComplete="current-password" aria-describedby="prepare-password-help" disabled={busy || loading || !!code} onChange={event => setPassword(event.target.value)} />
      <p id="prepare-password-help" className="recovery-hint">Enter the password you use to sign in now. This confirms that you are the Owner.</p>
      {state.prepared && <label className="recovery-check"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} /><span>I understand that replacing the code will make my previous recovery code stop working.</span></label>}
      <button className="primary">{busy ? "Preparing code…" : state.prepared ? "Replace recovery code" : "Generate recovery code"}</button>
    </fieldset></form>}
    {code && <SaveRecoveryCode code={code} username={user.username} onDone={() => { setCode(""); setNotice("Recovery code saved. Keep your offline copy private."); }} />}
  </section>;
}
