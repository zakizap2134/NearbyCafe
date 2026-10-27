import { useEffect, useRef } from "react";
export default function RecoveryNotice({ error, id }) {
  const notice = useRef(null);
  useEffect(() => { if (error) notice.current?.focus(); }, [error]);
  if (!error) return null;
  return <div ref={notice} id={id} className="error recovery-notice" role="alert" aria-atomic="true" tabIndex={-1}>
    <strong>Please check before continuing</strong>
    <p>{error.code === "CREDENTIAL_INVALID" ? "That current Owner password is not correct. Check it and try again." : error.message}</p>
    {error.reference && <small>Give this reference to support if you need help: {error.reference}</small>}
  </div>;
}
