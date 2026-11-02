import { useEffect, useRef, useState } from "react";
import { api } from "../api";
import { staffPasswordError } from "../password-policy.js";

function SecretField({
  id,
  label,
  value,
  onChange,
  disabled,
  help,
  code = false,
}) {
  const [visible, setVisible] = useState(false);
  useEffect(() => {
    if (disabled || !value) setVisible(false);
  }, [disabled, value]);
  return (
    <div className="setup-field">
      <label htmlFor={id}>{label}</label>
      <div className="setup-secret">
        <input
          id={id}
          type={visible && !disabled ? "text" : "password"}
          autoComplete={code ? "off" : "new-password"}
          autoCapitalize="none"
          spellCheck={false}
          required
          value={value}
          onChange={onChange}
          disabled={disabled}
          aria-describedby={help ? `${id}-help` : undefined}
        />
        <button
          type="button"
          onClick={() => setVisible(!visible)}
          aria-label={`${visible ? "Hide" : "Show"} ${label.toLowerCase()}`}
          aria-controls={id}
          aria-pressed={visible}
          disabled={disabled}
        >
          {visible ? "Hide" : "Show"}
        </button>
      </div>
      {help && (
        <p className="setup-hint" id={`${id}-help`}>
          {help}
        </p>
      )}
    </div>
  );
}

export default function OwnerSetup({
  ready,
  requiresCode = false,
  onComplete,
  onCancel,
  onRefresh,
  refreshing,
}) {
  const [fields, setFields] = useState({
    shop_name: "Nearby Café",
    display_name: "",
    username: "",
    email: "",
    password: "",
    confirm_password: "",
    setup_code: "",
  });
  const running = useRef(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [closed, setClosed] = useState(false);
  const update = (key) => (event) => {
    const value = event.target.value;
    setFields((current) => ({ ...current, [key]: value }));
  };
  async function submit(event) {
    event.preventDefault();
    if (running.current || closed || !ready) return;
    setError(null);
    const bytes = (value) => new TextEncoder().encode(value).length;
    let message;
    if (!fields.shop_name.trim() || bytes(fields.shop_name.trim()) > 120)
      message = "Shop name must contain 1–120 bytes.";
    else if (!fields.display_name.trim() || bytes(fields.display_name.trim()) > 100)
      message = "Display name must contain 1–100 bytes.";
    else if (!/^[a-zA-Z0-9][a-zA-Z0-9._@-]{2,63}$/.test(fields.username.trim()))
      message =
        "Username must be 3–64 characters, start with a letter or number, and use only letters, numbers, dots, @, underscores or hyphens.";
    else if (
      !requiresCode &&
      (!fields.email.trim() ||
        fields.email.trim().length > 191 ||
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(fields.email.trim()))
    )
      message =
        "Enter a valid email address for password recovery (for example, owner@example.com).";
    else if (staffPasswordError(fields.password, fields.confirm_password))
      message = staffPasswordError(fields.password, fields.confirm_password);
    else if (
      requiresCode &&
      !/^[A-Fa-f0-9]{64}$/.test(fields.setup_code.trim().replaceAll("-", ""))
    )
      message =
        "Enter the complete setup code supplied by your development team.";
    if (message) {
      setError({ message });
      return;
    }
    running.current = true;
    setBusy(true);
    try {
      const payload = {
        shop_name: fields.shop_name.trim(),
        display_name: fields.display_name.trim(),
        username: fields.username.trim(),
        password: fields.password,
        confirm_password: fields.confirm_password,
      };
      if (fields.email.trim()) payload.email = fields.email.trim();
      if (requiresCode || fields.setup_code.trim()) {
        payload.setup_code = fields.setup_code.trim();
      }
      const data = await api("setup/owner", { body: payload });
      onComplete(data.username, data);
    } catch (failure) {
      setError(failure);
      if (failure.code === "OWNER_EXISTS") setClosed(true);
    } finally {
      running.current = false;
      setFields((current) => ({
        ...current,
        password: "",
        confirm_password: "",
        setup_code: "",
      }));
      setBusy(false);
    }
  }
  return (
    <>
      <p className="eyebrow">WELCOME TO NEARBY CAFÉ</p>
      <h2>{requiresCode ? "Set up owner account" : "Set up your store & owner account"}</h2>
      <p className="subtle">
        Create the first Owner / Admin account for this workspace. Once created, public registration automatically closes.
      </p>
      {!ready && (
        <div className="setup-notice" role="status">
          <p>
            Your development team needs to prepare a setup code before you can
            register.
          </p>
          <button type="button" onClick={onRefresh} disabled={refreshing}>
            {refreshing ? "Checking…" : "Check setup again"}
          </button>
        </div>
      )}
      <form onSubmit={submit} noValidate aria-busy={busy}>
        {!requiresCode && (
          <label htmlFor="setup-shop-name">
            Café / Shop name
            <input
              id="setup-shop-name"
              aria-label="Café / Shop name"
              required
              maxLength={120}
              value={fields.shop_name}
              onChange={update("shop_name")}
              disabled={busy}
            />
          </label>
        )}
        <label htmlFor="setup-display">
          Display name
          <input
            id="setup-display"
            aria-label="Display name"
            autoComplete="name"
            required
            maxLength={100}
            value={fields.display_name}
            onChange={update("display_name")}
            disabled={busy}
            autoFocus
            aria-describedby="setup-display-help"
          />
          <span className="setup-hint" id="setup-display-help">
            The name shown in your workspace. Up to 100 bytes.
          </span>
        </label>
        <label htmlFor="setup-username">
          Username
          <input
            id="setup-username"
            aria-label="Username"
            autoComplete="username"
            autoCapitalize="none"
            spellCheck={false}
            required
            maxLength={64}
            value={fields.username}
            onChange={update("username")}
            disabled={busy}
            aria-describedby="setup-username-help"
          />
          <span className="setup-hint" id="setup-username-help">
            3–64 characters. Start with a letter or number. Letters, numbers,
            dots, @, underscores and hyphens are allowed. Sign-in is
            case-insensitive.
          </span>
        </label>
        {!requiresCode && (
          <label htmlFor="setup-email">
            Owner email address
            <input
              id="setup-email"
              type="email"
              aria-label="Owner email address"
              autoComplete="email"
              autoCapitalize="none"
              spellCheck={false}
              required
              maxLength={191}
              placeholder="owner@example.com"
              value={fields.email}
              onChange={update("email")}
              disabled={busy}
              aria-describedby="setup-email-help"
            />
            <span className="setup-hint" id="setup-email-help">
              Used for online password reset if you ever forget your password.
            </span>
          </label>
        )}
        <SecretField
          id="setup-password"
          label="Password"
          value={fields.password}
          onChange={update("password")}
          disabled={busy}
          help="Use at least 12 characters. Choose a password you do not use elsewhere."
        />
        <SecretField
          id="setup-confirm"
          label="Confirm password"
          value={fields.confirm_password}
          onChange={update("confirm_password")}
          disabled={busy}
        />
        {requiresCode && (
          <SecretField
            id="setup-code"
            label="One-time setup code"
            value={fields.setup_code}
            onChange={update("setup_code")}
            disabled={busy}
            code
            help="Paste the code your development team gave you. Hyphens are optional. This code is for first-time setup only."
          />
        )}
        {error && (
          <div className="error" role="alert">
            <strong>{error.message}</strong>
            {error.reference && (
              <small>Support reference: {error.reference}</small>
            )}
            {error.code === "NETWORK" && (
              <p>
                If registration may have finished, return to sign-in and try
                your new account before retrying.
              </p>
            )}
          </div>
        )}
        <button className="primary full" disabled={busy || !ready || closed}>
          {busy ? "Creating owner account…" : "Create owner account"}
        </button>
        <button
          className="full setup-back"
          type="button"
          onClick={onCancel}
          disabled={busy}
        >
          Back to sign in
        </button>
      </form>
      <p className="login-help">
        Barista accounts can be created by the Owner inside Management → Staff after setup.
      </p>
    </>
  );
}
