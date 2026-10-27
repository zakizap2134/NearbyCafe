import React, { useEffect, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import {
  HashRouter,
  Link,
  NavLink,
  Navigate,
  Route,
  Routes,
  useNavigate,
} from "react-router-dom";
import { api, readToken, storeToken } from "./api";
import "./style.css";
import Register from "./screens/Register";
import Management from "./screens/Management";
import Brand from "./components/Brand";
import OwnerSetup from "./screens/OwnerSetup";
import OwnerRecovery from "./screens/OwnerRecovery";

function ErrorMessage({ error }) {
  return (
    error && (
      <div className="error" role="alert">
        <strong>{error.message}</strong>
        {error.reference && <small>Support reference: {error.reference}</small>}
      </div>
    )
  );
}
function App() {
  const navigate = useNavigate();
  const endedMessage =
    "Your session has ended. Sign in again. Your pending orders remain saved on this device.";
  const [sessionNotice, setSessionNotice] = useState("");
  const [token, setToken] = useState(readToken),
    [user, setUser] = useState(null),
    [boot, setBoot] = useState(true),
    [error, setError] = useState(null),
    [attempt, setAttempt] = useState(0);
  const activeToken = useRef(token);
  activeToken.current = token;
  useEffect(() => {
    const controller = new AbortController();
    let live = true;
    setBoot(true);
    setError(null);
    (async () => {
      try {
        await api("health", { signal: controller.signal });
        if (token) {
          const data = await api("session", {
            token,
            signal: controller.signal,
          });
          if (live) setUser(data.user);
        }
      } catch (e) {
        if (!live) return;
        if (e.status === 401) {
          setSessionNotice(endedMessage);
          storeToken("");
          setToken("");
          setUser(null);
        } else if (e.name !== "AbortError") setError(e);
      } finally {
        if (live) setBoot(false);
      }
    })();
    return () => {
      live = false;
      controller.abort();
    };
  }, [token, attempt]);
  const signedIn = (data) => {
    setSessionNotice("");
    setBoot(true);
    navigate(data.user.role === "OWNER" ? "/management" : "/register", {
      replace: true,
    });
    activeToken.current = data.token;
    storeToken(data.token);
    setToken(data.token);
    setUser(data.user);
  };
  const expired = (message = endedMessage) => {
    // Ignore a late 401 from a component belonging to an older signed-in account.
    if (activeToken.current !== token) return;
    setSessionNotice(message);
    activeToken.current = "";
    storeToken("");
    setToken("");
    setUser(null);
  };
  const signOut = async () => {
    try {
      await api("logout", { token, body: {} });
      expired(
        "You’re signed out. Your pending orders remain saved on this device.",
      );
    } catch (e) {
      if (e.status === 401) expired();
      else throw e;
    }
  };
  if (boot)
    return (
      <div className="loading-screen">
        <Brand />
        <p role="status">Connecting to your local workspace…</p>
      </div>
    );
  if (error)
    return (
      <div className="loading-screen">
        <Brand />
        <section className="panel connection">
          <h1>Let’s reconnect</h1>
          <ErrorMessage error={error} />
          <button className="primary" onClick={() => setAttempt(attempt + 1)}>
            Try again
          </button>
          <p>
            On the host laptop, run the preflight check if this message
            continues.
          </p>
        </section>
      </div>
    );
  if (!user)
    return <Routes><Route path="/forgot-password" element={<OwnerRecovery />} /><Route path="*" element={<Login onSuccess={signedIn} sessionNotice={sessionNotice} />} /></Routes>;
  return (
    <Shell user={user} signOut={signOut}>
      <Routes>
        <Route
          path="/register"
          element={<Register user={user} token={token} expired={expired} />}
        />
        <Route
          path="/management"
          element={
            user.role === "OWNER" ? (
              <Management user={user} token={token} expired={expired} />
            ) : (
              <Navigate to="/register" replace />
            )
          }
        />
        <Route
          path="*"
          element={
            <Navigate
              to={user.role === "OWNER" ? "/management" : "/register"}
              replace
            />
          }
        />
      </Routes>
    </Shell>
  );
}
function Login({ onSuccess, sessionNotice }) {
  const submitting = useRef(false);
  const [passwordVisible, setPasswordVisible] = useState(false);
  const [name, setName] = useState(""),
    [password, setPassword] = useState(""),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(null);
  const [setup, setSetup] = useState(null),
    [setupOpen, setSetupOpen] = useState(false),
    [setupLoading, setSetupLoading] = useState(true),
    [setupError, setSetupError] = useState(null),
    [setupAttempt, setSetupAttempt] = useState(0),
    [notice, setNotice] = useState("");
  useEffect(() => {
    let live = true;
    const controller = new AbortController();
    setSetupLoading(true);
    setSetupError(null);
    const timer = setTimeout(() => {
      if (live)
        setSetupError(
          new Error("Setup check timed out. Check the host and retry."),
        );
      controller.abort();
    }, 12000);
    api("setup/status", { signal: controller.signal })
      .then((data) => {
        if (!live) return;
        setSetup(data);
        if (!data.needed) setSetupOpen(false);
      })
      .catch((failure) => {
        if (live && failure.name !== "AbortError") setSetupError(failure);
      })
      .finally(() => {
        clearTimeout(timer);
        if (live) setSetupLoading(false);
      });
    return () => {
      live = false;
      clearTimeout(timer);
      controller.abort();
    };
  }, [setupAttempt]);
  async function submit(e) {
    e.preventDefault();
    if (submitting.current) return;
    setError(null);
    if (!/^[a-zA-Z0-9][a-zA-Z0-9._@-]{2,63}$/.test(name.trim())) {
      setError(
        new Error(
          "Enter your username (3–64 characters using letters, numbers, dots, @, underscores or hyphens).",
        ),
      );
      return;
    }
    if (
      !password ||
      new TextEncoder().encode(password).length > 72 ||
      password.includes("\0")
    ) {
      setError(new Error("Enter your password."));
      return;
    }
    submitting.current = true;
    setPasswordVisible(false);
    setBusy(true);
    try {
      onSuccess(
        await api("login", { body: { username: name.trim(), password } }),
      );
    } catch (e) {
      setError(e);
      if (e.code === "INVALID_CREDENTIALS") setPassword("");
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  }
  return (
    <div className={`login-layout${setupOpen ? " owner-setup-layout" : ""}`}>
      <aside className="login-story">
        <Brand />
        <div>
          <p className="eyebrow">GOOD COFFEE. GREAT COMPANY.</p>
          <h1>
            Good coffee,
            <br />
            always nearby.
          </h1>
          <p>
            Your daily workspace for coffee, customers and everything behind the
            counter.
          </p>
        </div>
        <div className="story-footer">
          <span className="status-dot" /> Local network · Powered by VenueVue
        </div>
      </aside>
      <main className="login-main">
        <div className="mobile-brand">
          <Brand />
        </div>
        <section className="login-card">
          {setupOpen ? (
            <>
              <OwnerSetup
                ready={setup?.ready}
                refreshing={setupLoading}
                onRefresh={() => setSetupAttempt((value) => value + 1)}
                onCancel={() => {
                  setSetupOpen(false);
                  setSetupAttempt((value) => value + 1);
                }}
                onComplete={(username) => {
                  setName(username);
                  setPassword("");
                  setError(null);
                  setSetup({ needed: false, ready: false });
                  setSetupOpen(false);
                  setNotice(
                    "Your owner account is ready. Sign in with the username and password you just created.",
                  );
                }}
              />
              <ErrorMessage error={setupError} />
            </>
          ) : (
            <>
              <p className="eyebrow">WELCOME BACK</p>
              <h2>Sign in to your workspace</h2>
              <p className="subtle">
                Sign in with your Owner/Admin or Barista account.
              </p>
              {(notice || sessionNotice) && (
                <div className="success" role="status">
                  {notice || sessionNotice}
                </div>
              )}
              <form onSubmit={submit} noValidate>
                <label>
                  Username
                  <input
                    autoComplete="username"
                    required
                    minLength={3}
                    maxLength={64}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    disabled={busy}
                    autoFocus
                  />
                </label>
                <div className="setup-field">
                  <label htmlFor="login-password">Password</label>
                  <div className="setup-secret">
                    <input
                      id="login-password"
                      type={passwordVisible ? "text" : "password"}
                      autoComplete="current-password"
                      required
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      disabled={busy}
                    />
                    <button
                      type="button"
                      disabled={busy}
                      aria-controls="login-password"
                      aria-pressed={passwordVisible}
                      aria-label={
                        passwordVisible ? "Hide password" : "Show password"
                      }
                      onClick={() => setPasswordVisible(!passwordVisible)}
                    >
                      {passwordVisible ? "Hide" : "Show"}
                    </button>
                  </div>
                </div>
                <ErrorMessage error={error} />
                <button className="primary full" disabled={busy}>
                  {busy ? "Signing in…" : "Sign in"}
                </button>
              </form>
              {setupLoading && (
                <p className="setup-hint" role="status">
                  Checking first-time setup…
                </p>
              )}
              {setup?.needed && (
                <div className="setup-entry">
                  <p>First time here? Create your shop’s owner account.</p>
                  <button
                    className="full"
                    type="button"
                    disabled={busy || setupLoading}
                    onClick={() => {
                      setNotice("");
                      setSetupOpen(true);
                    }}
                  >
                    Set up owner account
                  </button>
                </div>
              )}
              {setupError && (
                <div className="setup-entry">
                  <ErrorMessage error={setupError} />
                  <button
                    type="button"
                    disabled={setupLoading}
                    onClick={() => setSetupAttempt((value) => value + 1)}
                  >
                    Retry setup check
                  </button>
                </div>
              )}
              {!busy && <Link className="forgot-password-link" to="/forgot-password">Forgot password?</Link>}
              <p className="login-help">
                Need access? Ask your shop owner.
                <br />
                Your host laptop and shop Wi-Fi must be running.
              </p>
            </>
          )}
        </section>
        <footer>
          Nearby Café <span>·</span> VenueVue
        </footer>
      </main>
    </div>
  );
}
function Shell({ user, signOut, children }) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(null);
  async function leave() {
    setBusy(true);
    setError(null);
    try {
      await signOut();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="app-layout">
      <header className="workspace-header">
        <Brand compact header />
        <div className="header-workspace">
          <div className="header-identity"><strong>Nearby Café</strong><span>VenueVue · Local-network POS</span></div>
          <nav aria-label="Workspace navigation">
            <NavLink to="/register">Register</NavLink>
            {user.role === "OWNER" && <NavLink to="/management">Management</NavLink>}
          </nav>
        </div>
        <div className="header-account">
          <span className="role">{user.role === "OWNER" ? "Owner / Admin" : "Barista"}</span>
          <div className="account">
            <span>{user.display_name}</span>
            <button onClick={leave} disabled={busy}>{busy ? "Signing out…" : "Sign out"}</button>
          </div>
        </div>
      </header>
      <div className="app-content">
        {error && (
          <div className="page-error">
            <ErrorMessage error={error} />
          </div>
        )}
        <main className="workspace">{children}</main>
        <footer className="app-footer">
          Nearby Café · Powered by VenueVue <span>Local-network POS</span>
        </footer>
      </div>
    </div>
  );
}
createRoot(document.getElementById("root")).render(
  <HashRouter>
    <App />
  </HashRouter>,
);
