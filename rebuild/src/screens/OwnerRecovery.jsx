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
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState("");

  const hasMinLength = password.length >= 12;
  const passwordsMatch = password.length > 0 && password === confirmation;

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
      await api("recovery/email-request", { body: { email: cleanEmail } });
      setStep("verify");
      setCode("");
      setNotice(`We sent a 6-digit verification code to ${cleanEmail}. Check your inbox (and spam folder)!`);
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
        <div className="recovery-brand-wrap">
          <Brand compact header />
          <div className="recovery-brand-text">
            <strong>Nearby Café</strong>
            <span>VenueVue · Account Recovery</span>
          </div>
        </div>
        <Link className="recovery-header-back-link" to="/login">
          ← Back to sign in
        </Link>
      </header>

      <div className="recovery-content">
        <section className="panel owner-recovery-card">
          <div className="recovery-progress-bar" aria-label="Recovery progress">
            <div className={`recovery-step-pill ${step === "request" ? "active" : "done"}`}>
              <span className="step-num">{step === "request" ? "1" : "✓"}</span>
              <span className="step-label">1. Request Code</span>
            </div>
            <div className="step-connector"></div>
            <div className={`recovery-step-pill ${step === "verify" ? "active" : step === "success" ? "done" : "idle"}`}>
              <span className="step-num">{step === "success" ? "✓" : "2"}</span>
              <span className="step-label">2. Verify & Reset</span>
            </div>
            <div className="step-connector"></div>
            <div className={`recovery-step-pill ${step === "success" ? "active" : "idle"}`}>
              <span className="step-num">{step === "success" ? "✓" : "3"}</span>
              <span className="step-label">3. Completed</span>
            </div>
          </div>

          {step === "request" && (
            <>
              <p className="recovery-eyebrow">STEP 1 OF 2 · ACCOUNT IDENTIFICATION</p>
              <h1>Forgot password?</h1>
              <p className="subtle">
                Enter your registered email address. We'll send a secure 6-digit verification code to your inbox to reset your password.
              </p>

              {error && (
                <div className="error recovery-notice" role="alert">
                  <strong>{error.message}</strong>
                  {error.code === "ACCOUNT_NOT_FOUND" && (
                    <p style={{ marginTop: "8px", fontSize: "0.85rem", color: "#5d4037" }}>
                      💡 Check your email spelling. If you haven't set up your recovery email yet, sign in with your username and visit Management → Account security.
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
                  spellCheck={false}
                  required
                  placeholder="owner@example.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value.replace(/\s+/g, ""))}
                  onBlur={() => setEmail((prev) => prev.trim())}
                  disabled={busy}
                  autoFocus
                />

                <button
                  type="submit"
                  className="primary full"
                  disabled={busy}
                  style={{ marginTop: "18px" }}
                >
                  {busy ? "Sending code…" : "Send verification code →"}
                </button>
              </form>

              <div style={{ marginTop: "24px", textAlign: "center" }}>
                <Link className="recovery-back" to="/login">
                  Return to sign in
                </Link>
              </div>
            </>
          )}

          {step === "verify" && (
            <>
              <p className="recovery-eyebrow">STEP 2 OF 2 · CODE VERIFICATION & NEW PASSWORD</p>
              <h1>Enter 6-digit code</h1>

              {notice && <div className="success recovery-notice">{notice}</div>}

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
                  spellCheck={false}
                  placeholder="123456"
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
                  disabled={busy}
                  autoFocus
                />
                <p className="setup-hint" style={{ textAlign: "center", marginBottom: "18px" }}>
                  Code sent to <strong>{email}</strong> (valid for 15 minutes)
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

                <div className="recovery-pw-requirements">
                  <div className={`req-item ${hasMinLength ? "met" : ""}`}>
                    <span className="req-icon">{hasMinLength ? "✓" : "○"}</span> At least 12 characters
                  </div>
                  <div className={`req-item ${passwordsMatch ? "met" : ""}`}>
                    <span className="req-icon">{passwordsMatch ? "✓" : "○"}</span> Passwords match
                  </div>
                </div>

                <button
                  type="submit"
                  className="primary full"
                  disabled={busy || !hasMinLength || !passwordsMatch || code.length < 6}
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
                    ← Change email
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

              <div style={{ marginTop: "24px", textAlign: "center" }}>
                <Link className="recovery-back" to="/login">
                  Return to sign in
                </Link>
              </div>
            </>
          )}

          {step === "success" && (
            <div className="recovery-success-card">
              <div className="recovery-success-icon-wrap" aria-hidden="true">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                  <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
              </div>
              <h1>Password Reset Complete!</h1>
              <p className="subtle" style={{ fontSize: "1.05rem", lineHeight: "1.6", marginBottom: "24px" }}>
                Your account password has been safely updated. All previous active sessions have ended for security.
              </p>
              <button
                type="button"
                className="primary full"
                onClick={() => navigate("/login", { replace: true })}
              >
                Sign in with new password →
              </button>
            </div>
          )}
        </section>

        <aside className="recovery-support panel">
          <section className="recovery-staff">
            <h2>Need assistance?</h2>
            <p>
              • <strong>Check your Spam / Junk folder:</strong> Verification codes typically arrive in your inbox within 1–2 minutes.
            </p>
            <p>
              • <strong>Private & secure:</strong> Verification codes are sent strictly to your private email address and are never displayed on-screen.
            </p>
            <p>
              • <strong>Different email?</strong> Click <em>Change email</em> if your account was set up using a different email address.
            </p>
            <p>
              • <strong>Baristas / Staff:</strong> You can also request the Store Owner to reset your password directly under <strong>Management → Staff</strong>.
            </p>
          </section>
        </aside>
      </div>
    </main>
  );
}
