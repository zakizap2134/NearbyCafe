import { useEffect, useRef, useState } from "react";
import "./recovery-code.css";
export default function SaveRecoveryCode({ code, username, onDone, introduction = "Your new Owner recovery code is ready." }) {
  const dialog = useRef(null), heading = useRef(null), downloads = useRef(new Set()), alive = useRef(true);
  const [saved, setSaved] = useState(false), [feedback, setFeedback] = useState("");
  useEffect(() => {
    alive.current = true; dialog.current.showModal(); heading.current?.focus();
    return () => { alive.current = false; for (const url of downloads.current) URL.revokeObjectURL(url); downloads.current.clear(); };
  }, []);
  useEffect(() => {
    const warn = event => { if (!saved) { event.preventDefault(); event.returnValue = ""; } };
    window.addEventListener("beforeunload", warn); return () => window.removeEventListener("beforeunload", warn);
  }, [saved]);
  function keepFocus(event) {
    if (event.key !== "Tab") return;
    const controls = [...dialog.current.querySelectorAll("button:not(:disabled), input:not(:disabled), textarea:not(:disabled), a[href], [tabindex='0']")].filter(el => el.getClientRects().length);
    const first = controls[0], last = controls[controls.length - 1];
    if (!first) return;
    if (event.shiftKey && (document.activeElement === first || document.activeElement === heading.current)) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }
  async function copy() {
    try {
      if (!navigator.clipboard?.writeText) throw new Error();
      await navigator.clipboard.writeText(code);
      if (alive.current) setFeedback("Code copied. Paste it into a private document, or write it on paper. Check that all eight groups are saved.");
    } catch { if (alive.current) setFeedback("Copy is unavailable in this browser. Select the code, then use Ctrl+C (Windows), Command+C (Mac), or touch and hold to copy it manually. You can also write all eight groups on paper."); }
  }
  function download() {
    let url;
    try {
      const text = `NEARBY CAFÉ — KEEP THIS OWNER RECOVERY SHEET PRIVATE\n\nOwner username: ${username}\nOwner recovery code: ${code}\n\nWHAT THIS CODE IS FOR\nThis is your ongoing Owner recovery code. Use it if you forget your password.\nIt is NOT the initial setup code used to create the shop's first Owner account.\n\nHOW TO RESET YOUR PASSWORD\n1. Make sure the shop's host laptop and local Wi-Fi are running.\n2. Open the shop's usual sign-in page and choose Forgot password?.\n3. Enter the Owner username above, this recovery code, and your new password twice.\n4. Choose Reset Owner password once and wait for confirmation.\n5. Save the NEW replacement code, confirm that you saved it, then sign in.\n\nAFTER A RESET OR CODE REPLACEMENT\nThis sheet's old code stops working. Keep only the latest recovery sheet.\nUse at least 12 characters.\nIf the app says your password is too long, use fewer characters; accented\nletters and emoji may take more space.\n\nIF THE RESULT COULD NOT BE CONFIRMED\nThe password may already have changed. Try signing in with your NEW password.\nAfter successful sign-in, open Management > Account security and replace the code\nusing your current password. Save that replacement; a lost code cannot be shown again.\nIf you cannot sign in, contact the authorized host administrator or project support.\n\nKEEP IT SAFE\nKeep this sheet offline and separate from the shop laptop and backups.\nDo not share it in chat, email, screenshots or coursework.\nIf both the password and code are lost, contact authorized support.\nKeep the database intact; do not create another Owner or reinstall over shop data.\nBaristas should ask the Owner to reset their passwords in Account security.\n`;
      url = URL.createObjectURL(new Blob([text], { type: "text/plain;charset=utf-8" })); downloads.current.add(url);
      const link = document.createElement("a"); link.href = url; link.download = "NearbyCafe-owner-recovery.txt";
      document.body.append(link); link.click(); link.remove();
      setFeedback("Download requested. Look for NearbyCafe-owner-recovery.txt in your downloads and open it to check. If no file appears, copy the code or write all eight groups on paper.");
      setTimeout(() => { URL.revokeObjectURL(url); downloads.current.delete(url); }, 1000);
    } catch {
      if (url) { URL.revokeObjectURL(url); downloads.current.delete(url); }
      setFeedback("Download could not start. Select and copy the code, or write all eight groups on paper. Check your copy before marking it as saved.");
    }
  }
  return <dialog ref={dialog} className="recovery-code-dialog" aria-labelledby="save-recovery-title" aria-describedby="save-recovery-intro" onKeyDown={keepFocus}
    onCancel={event => { event.preventDefault(); if (saved) onDone(); }}>
    <p className="recovery-eyebrow">KEEP THIS SOMEWHERE PRIVATE</p>
    <h2 ref={heading} tabIndex={-1} id="save-recovery-title">Save your recovery code</h2>
    <p id="save-recovery-intro">{introduction} This is the only time it will be shown. Any previous recovery code no longer works.</p>
    <label htmlFor="prepared-recovery-code">Owner recovery code</label>
    <textarea id="prepared-recovery-code" value={code} readOnly rows={5} wrap="soft" aria-describedby="recovery-manual-help" spellCheck={false} autoComplete="off" onFocus={event => event.target.select()} />
    <p id="recovery-manual-help" className="recovery-hint">Save all eight groups. Select the code to copy it manually, or write it carefully on paper. Keep the letters and numbers in the same order.</p>
    <div className="button-row"><button type="button" onClick={copy}>Copy code</button><button type="button" onClick={download}>Download recovery sheet</button></div>
    <p className="recovery-feedback" role="status" aria-live="polite" aria-atomic="true">{feedback}</p>
    <div className="recovery-note"><strong>Recovery code ≠ setup code</strong><p>The setup code creates the first Owner account. This code helps you reset its password later. Store it offline, away from the shop laptop.</p></div>
    <label className="recovery-check"><input type="checkbox" checked={saved} onChange={event => setSaved(event.target.checked)} /><span>I saved this recovery code somewhere safe.</span></label>
    <p className="recovery-hint" id="recovery-save-help">Check your copy, then tick the box to enable Done.</p>
    <button type="button" className="primary" aria-describedby="recovery-save-help" disabled={!saved} onClick={onDone}>Done — hide code</button>
  </dialog>;
}
