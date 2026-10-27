import { useEffect, useId, useState } from "react";
import "./staff-password.css";
export default function StaffPassword({ label, value, disabled, ...props }) {
  const id = useId(),
    [visible, setVisible] = useState(false);
  useEffect(() => {
    if (disabled || !value) setVisible(false);
  }, [disabled, value]);
  return (
    <div className="staff-password">
      <label htmlFor={id}>{label}</label>
      <div className="staff-password-controls">
        <input
          {...props}
          id={id}
          type={visible && !disabled ? "text" : "password"}
          value={value}
          disabled={disabled}
          autoCapitalize="none"
          spellCheck={false}
        />
        <button
          type="button"
          disabled={disabled}
          aria-controls={id}
          aria-pressed={visible}
          aria-label={`${visible ? "Hide" : "Show"} ${label.toLowerCase()}`}
          onClick={() => setVisible(!visible)}
        >
          {visible ? "Hide" : "Show"}
        </button>
      </div>
    </div>
  );
}
export { staffPasswordError } from "../password-policy.js";
