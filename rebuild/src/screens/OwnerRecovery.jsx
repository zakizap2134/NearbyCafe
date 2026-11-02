import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { api } from "../api";
import Brand from "../components/Brand";
import StaffPassword, { staffPasswordError } from "../components/StaffPassword";
import "./owner-recovery.css";

export default function OwnerRecovery() {
  const navigate = useNavigate();
  const [step, setStep] = useState("request"); // 'request' | 'verify' | 'success'
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [fallbackCode, setFallbackCode] = useState(null);
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState("");

  async function handleSendCode(e) {
    if (e && e.preventDefault) e.preventDefault();
    setError(null);
    const cleanEmail = email.trim();
    if (!cleanEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cleanEmail)) {
      setError(new Error("Please enter a valid email address."));
      return;
    }
    setBusy(true);
    try {
      const res = await api("recovery/email-request", { body: { email: cleanEmail } });
      setStep("verify");
      if (res?.fallback_code) {
        setCode(res.fallback_code);
        setFallbackCode(res.fallback_code);
        setNotice("Email delivery server is not configured yet. Your verification code is ready below!");
      } else {
        setFallbackCode(null);
        setNotice(`We sent a 6-digit verification code to ${cleanEmail}. Check your inbox (and spam folder)!`);
      }
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  async function handleVerifyAndReset(e) {
    e.preventDefault();
    setError(null);
    const cleanCode = code.replace(/\s+/g, "");
    if (!/^[0-9]{6}$/.test(cleanCode)) {
      setError(new Error("Enter the 6-digit code sent to your email."));
      return;
    }
    const passwordError = staffPasswordError(password, confirmation);
    if (passwordError) {
      setError(new Error(passwordError));
      return;
    }
    setBusy(true);
    try {
      await api("recovery/email-reset", {
        body: {
          email: email.trim(),
          code: cleanCode,
          new_password: password,
          confirm_password: confirmation,
        },
      });
      setStep("success");
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
          <p>Your account, safely back in reach.</p>
        </div>
      </header>

      <div className="recovery-content">
        <section className="panel owner-recovery-card">
          <p className="recovery-eyebrow">PASSWORD RECOVERY</p>

          {step === "request" && (
            <>
              <h1>Forgot password?</h1>
              <p className="subtle">
                Enter your registered email address. We'll send you a 6-digit verification code to reset your password.
              </p>

              {error && (
                <div className="error recovery-notice" role="alert">
                  <strong>{error.message}</strong>
                  {error.code === "ACCOUNT_NOT_FOUND" && (
                    <p style={{ marginTop: "8px", fontSize: "0.85rem", color: "#5d4037" }}>
                      💡 If you recently set up or changed your account, verify your email spelling or sign in with your username to update your email under Management.
                    </p>
                  )}
                </div>
              )}

              <form onSubmit={handleSendCode} noValidate>
                <label htmlFor="recovery-email">Email address</label>
                <input
                  id="recovery-email"
                  type="email"
                  autoComplete="email"
                  required
                  placeholder="owner@example.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  disabled={busy}
                  autoFocus
                />

                <button
                  type="submit"
                  className="primary full"
                  disabled={busy}
                  style={{ marginTop: "16px" }}
                >
                  {busy ? "Sending code…" : "Send verification code"}
                </button>
              </form>

              <div style={{ marginTop: "24px" }}>
                <Link className="recovery-back" to="/login">
                  Back to sign in
                </Link>
              </div>
            </>
          )}

          {step === "verify" && (
            <>
              <div className="recovery-step-indicator">
                <span className="recovery-step-badge">2</span> Step 2: Enter code & set new password
              </div>

              <h1>Enter 6-digit code</h1>

              {notice && <div className="success recovery-notice">{notice}</div>}

              {fallbackCode && (
                <div
                  style={{
                    background: "#fef8f0",
                    border: "2px dashed #a93226",
                    borderRadius: "8px",
                    padding: "16px",
                    marginBottom: "20px",
                    textAlign: "center",
                  }}
                >
                  <span style={{ fontSize: "0.85rem", fontWeight: "700", color: "#8a2419", display: "block", marginBottom: "4px" }}>
                    ON-SCREEN VERIFICATION CODE
                  </span>
                  <div
                    style={{
                      fontSize: "2.2rem",
                      fontWeight: "800",
                      letterSpacing: "6px",
                      color: "#a93226",
                      fontFamily: "monospace",
                      margin: "8px 0",
                    }}
                  >
                    {fallbackCode}
                  </div>
                  <p style={{ fontSize: "0.85rem", color: "#7f6055", margin: 0 }}>
                    Email delivery (SMTP) is not yet configured, so your code was generated on-screen. We have pre-filled it below for you.
                  </p>
                </div>
              )}

              {error && (
                <div className="error recovery-notice" role="alert">
                  <strong>{error.message}</strong>
                </div>
              )}

              <form onSubmit={handleVerifyAndReset} noValidate>
                <label htmlFor="recovery-code">Verification code</label>
                <input
                  id="recovery-code"
                  className="recovery-code-input"
                  inputMode="numeric"
                  pattern="[0-9]*"
                  maxLength={6}
                  autoComplete="one-time-code"
                  placeholder="123456"
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
                  disabled={busy}
                  autoFocus
                />
                <p className="setup-hint" style={{ textAlign: "center", marginBottom: "16px" }}>
                  Code for <strong>{email}</strong> (valid for 15 minutes)
                </p>

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

                <p id="recovery-password-rules" style={{ fontSize: "0.85rem", color: "#7f6055", marginBottom: "16px" }}>
                  Choose a secure password with at least 12 characters.
                </p>

                <button
                  type="submit"
                  className="primary full"
                  disabled={busy}
                >
                  {busy ? "Resetting password…" : "Reset password & sign in"}
                </button>

                <div className="recovery-resend-row">
                  <button
                    type="button"
                    className="link-button"
                    onClick={() => {
                      setError(null);
                      setStep("request");
                    }}
                    disabled={busy}
                  >
                    Change email
                  </button>
                  <button
                    type="button"
                    className="link-button"
                    onClick={handleSendCode}
                    disabled={busy}
                  >
                    Resend code
                  </button>
                </div>
              </form>

              <div style={{ marginTop: "24px" }}>
                <Link className="recovery-back" to="/login">
                  Back to sign in
                </Link>
              </div>
            </>
          )}

          {step === "success" && (
            <div style={{ textAlign: "center", padding: "16px 0" }}>
              <h1 style={{ color: "#2e7d32", marginBottom: "12px" }}>Password Reset!</h1>
              <p style={{ fontSize: "1.05rem", lineHeight: "1.5", marginBottom: "24px" }}>
                Your password has been successfully updated. All active sessions have ended.
              </p>
              <button
                type="button"
                className="primary full"
                onClick={() => navigate("/login", { replace: true })}
              >
                Sign in with new password
              </button>
            </div>
          )}
        </section>

        <aside className="recovery-support panel">
          <section className="recovery-staff">
            <h2>Need help?</h2>
            <p>
              • <strong>Instant verification:</strong> If your café has Gmail SMTP configured, your 6-digit code arrives directly in your inbox within 1–2 minutes.
            </p>
            <p>
              • <strong>On-Screen Code Fallback:</strong> If email delivery is not yet configured, your 6-digit code is displayed right on your screen so you are never locked out.
            </p>
            <p>
              • <strong>Different email?</strong> Click <em>Change email</em> to try another address, or ask your shop administrator.
            </p>
            <p>
              • <strong>Staff / Baristas:</strong> You can also ask your Store Owner to reset your password directly in <strong>Management → Staff</strong>.
            </p>
          </section>
        </aside>
      </div>
    </main>
  );
}
