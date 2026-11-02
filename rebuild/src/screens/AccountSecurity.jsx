import { useEffect, useRef, useState } from "react";
import { api } from "../api";
import { Select, ErrorNotice } from "../components/workflow";
import StaffPassword, { staffPasswordError } from "../components/StaffPassword";
import OwnerRecoveryCode from "../components/OwnerRecoveryCode";
export default function AccountSecurity({ user, token, expired, onPrepared }) {
  const [staff, setStaff] = useState(null),
    [id, setId] = useState(""),
    [ownerPassword, setOwnerPassword] = useState(""),
    [password, setPassword] = useState(""),
    [confirm, setConfirm] = useState(""),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(false),
    [error, setError] = useState(null),
    [notice, setNotice] = useState(""),
    [ownerEmail, setOwnerEmail] = useState(user?.email || ""),
    [shopName, setShopName] = useState(user?.shop_name || "Nearby Café"),
    [profilePassword, setProfilePassword] = useState(""),
    [profileBusy, setProfileBusy] = useState(false),
    [profileError, setProfileError] = useState(null),
    [profileNotice, setProfileNotice] = useState("");
  const running = useRef(false);
  async function saveOwnerProfile(e) {
    e.preventDefault();
    if (profileBusy) return;
    setProfileError(null);
    setProfileNotice("");
    const trimmedEmail = ownerEmail.trim();
    if (
      !trimmedEmail ||
      trimmedEmail.length > 191 ||
      !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)
    ) {
      setProfileError(
        new Error("Enter a valid email address (for example, owner@example.com)."),
      );
      return;
    }
    if (!shopName.trim() || new TextEncoder().encode(shopName.trim()).length > 120) {
      setProfileError(new Error("Shop name must contain 1–120 bytes."));
      return;
    }
    if (
      !profilePassword ||
      new TextEncoder().encode(profilePassword).length > 72 ||
      profilePassword.includes("\0")
    ) {
      setProfileError(new Error("Enter your current owner password."));
      return;
    }
    setProfileBusy(true);
    try {
      const res = await api("management/owner-profile", {
        token,
        body: {
          email: trimmedEmail,
          shop_name: shopName.trim(),
          owner_password: profilePassword,
        },
      });
      if (res?.user?.email) setOwnerEmail(res.user.email);
      if (res?.user?.shop_name) setShopName(res.user.shop_name);
      setProfileNotice(
        "Owner recovery email and shop profile saved! You can now use Email Recovery on the Forgot password screen.",
      );
    } catch (err) {
      setProfileError(err);
      if (err.status === 401) expired();
    } finally {
      setProfileBusy(false);
      setProfilePassword("");
    }
  }
  async function load() {
    setLoading(true);
    setError(null);
    try {
      setStaff((await api("management/staff", { token })).staff);
    } catch (e) {
      setError(e);
      if (e.status === 401) expired();
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    load();
  }, [token]);
  async function reset(e) {
    e.preventDefault();
    if (running.current) return;
    setNotice("");
    setError(null);
    const validation = !id
      ? "Choose a Barista account."
      : staffPasswordError(password, confirm);
    if (validation) {
      setError(new Error(validation));
      return;
    }
    if (
      !ownerPassword ||
      new TextEncoder().encode(ownerPassword).length > 72 ||
      ownerPassword.includes("\0")
    ) {
      setError(
        new Error("Enter your current owner password."),
      );
      return;
    }
    running.current = true;
    setBusy(true);
    try {
      await api("management/reset-password", {
        token,
        body: {
          user_id: id,
          owner_password: ownerPassword,
          new_password: password,
          confirm_password: confirm,
        },
      });
      setNotice(
        "Password reset. All sessions for this Barista were signed out. Their saved orders remain on their device.",
      );
    } catch (e) {
      setError(e);
      if (e.status === 401) expired();
    } finally {
      running.current = false;
      setBusy(false);
      setOwnerPassword("");
      setPassword("");
      setConfirm("");
    }
  }
  return (
    <div className="editor-layout">
      <section className="panel">
        <h2>Reset a Barista password</h2>
        <p className="subtle small">
          Confirm your owner password to authorize the reset. Share the new
          password privately. Resetting signs the Barista out without deleting
          saved orders. An inactive account remains inactive; enable it under
          Staff when appropriate.
        </p>
        <ErrorNotice error={error} />
        {notice && (
          <p className="success" role="status">
            {notice}
          </p>
        )}
        {staff === null ? (
          <>
            <p role="status">
              {loading
                ? "Loading staff accounts…"
                : "Staff list unavailable. Retry when the host is reachable."}
            </p>
            <button disabled={loading} onClick={load}>
              Retry staff list
            </button>
          </>
        ) : staff.length === 0 ? (
          <p className="subtle">
            No Barista accounts yet. Open Management → Staff to create one.
          </p>
        ) : (
          <form onSubmit={reset} noValidate>
            <fieldset disabled={busy}>
              <Select
                label="Barista account"
                required
                value={id}
                onChange={(e) => {
                  setId(e.target.value);
                  setOwnerPassword("");
                  setPassword("");
                  setConfirm("");
                  setError(null);
                  setNotice("");
                }}
              >
                <option value="">Choose Barista</option>
                {staff.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.display_name} ({s.username})
                    {Number(s.is_active) ? "" : " · inactive"}
                  </option>
                ))}
              </Select>
              <StaffPassword
                label="Your owner password"
                disabled={busy}
                autoComplete="current-password"
                required
                value={ownerPassword}
                onChange={(e) => setOwnerPassword(e.target.value)}
              />
              <p className="subtle small">Use at least 12 characters.</p>
              <StaffPassword
                label="New Barista password"
                disabled={busy}
                autoComplete="new-password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
              <StaffPassword
                label="Confirm new Barista password"
                disabled={busy}
                autoComplete="new-password"
                required
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
              />
              <button className="primary">
                {busy ? "Resetting…" : "Reset Barista password"}
              </button>
            </fieldset>
          </form>
        )}
      </section>
      <OwnerRecoveryCode user={user} token={token} expired={expired} onPrepared={onPrepared} />
      <section className="panel" style={{ gridColumn: "1 / -1" }}>
        <h2>Owner recovery email & store profile</h2>
        <p className="subtle small">
          Link your email address so you can reset your Owner password online via{" "}
          <strong>Forgot password? → Reset password via Email</strong>.
        </p>
        <ErrorNotice error={profileError} />
        {profileNotice && (
          <p className="success" role="status">
            {profileNotice}
          </p>
        )}
        <form onSubmit={saveOwnerProfile} noValidate>
          <fieldset disabled={profileBusy}>
            <label>
              Café / Shop name
              <input
                type="text"
                required
                maxLength={120}
                value={shopName}
                onChange={(e) => setShopName(e.target.value)}
              />
            </label>
            <label>
              Owner recovery email address
              <input
                type="email"
                required
                maxLength={191}
                placeholder="owner@example.com"
                value={ownerEmail}
                onChange={(e) => setOwnerEmail(e.target.value)}
              />
            </label>
            <StaffPassword
              label="Your current owner password"
              disabled={profileBusy}
              autoComplete="current-password"
              required
              value={profilePassword}
              onChange={(e) => setProfilePassword(e.target.value)}
            />
            <button className="primary">
              {profileBusy ? "Saving…" : "Save recovery email"}
            </button>
          </fieldset>
        </form>
      </section>
    </div>
  );
}
