import "./recovery-help.css";
function OwnerSteps() {
  return (
    <>
      <h3>Owner/Admin: reset a forgotten password</h3>
      <ol>
        <li>
          Use the shop’s host laptop, where VenueVue and its database are
          installed. Start MySQL in XAMPP.
        </li>
        <li>
          Open the project’s <strong>rebuild</strong> folder and double-click{" "}
          <strong>RECOVER-OWNER.bat</strong>.
        </li>
        <li>
          In the private console, enter your owner username, current offline
          recovery code, and your new password twice.
        </li>
        <li>
          Wait for “Owner password changed”. Write down the replacement recovery
          code and keep it offline before closing the window.
        </li>
        <li>
          Return here and sign in with the new password. All previous Owner
          sessions are signed out.
        </li>
      </ol>
      <p>
        The code and passwords are entered on the host console, where input is
        visible. Do not enter the recovery code into the sign-in form or share
        it in chat. No email or internet service is used.
      </p>
      <p>
        Use at least 12 characters.
      </p>
    </>
  );
}
function MissingCode() {
  return (
    <details>
      <summary>Lost the code, or recovery did not work?</summary>
      <ul>
        <li>
          <strong>Password still known:</strong> use PREPARE-OWNER-RECOVERY.bat
          on the host to issue a replacement code. The previous code stops
          working.
        </li>
        <li>
          <strong>Both password and code lost:</strong> contact the authorized
          host administrator or project support. Keep the database intact. Do
          not register another owner, delete accounts or reinstall over the
          shop’s data.
        </li>
        <li>
          <strong>Passwords do not match:</strong> run recovery again and enter
          the same new password twice. Validation failure does not change the
          password or consume the code.
        </li>
        <li>
          <strong>Too many credential attempts:</strong> wait 15 minutes before
          trying again. Changing devices does not bypass owner recovery limits.
        </li>
        <li>
          <strong>Window closed before recording the replacement:</strong> try
          signing in with the new password. If it works, use
          PREPARE-OWNER-RECOVERY.bat to issue another code. If it does not, keep
          the existing code and ask support to check the displayed error
          reference.
        </li>
      </ul>
    </details>
  );
}
export default function RecoveryHelp({ owner = false }) {
  const content = (
    <div className="recovery-guide">
      {owner ? (
        <>
          <h3>Prepare recovery while you can sign in</h3>
          <ol>
            <li>
              On the host laptop, start MySQL in XAMPP and open the project’s{" "}
              <strong>rebuild</strong> folder.
            </li>
            <li>
              Double-click <strong>PREPARE-OWNER-RECOVERY.bat</strong>. Enter
              your owner username and current password in the private console.
            </li>
            <li>
              Record the printed code offline, separately from the laptop and
              backups. Each successful run replaces the previous code; the app
              cannot display an old code again.
            </li>
          </ol>
        </>
      ) : (
        <>
          <h3>Barista: ask your shop owner</h3>
          <p>
            The owner can reset your password in{" "}
            <strong>Management → Account security</strong>. Sign in again using
            the same account, browser profile and shop address to return to your
            saved orders.
          </p>
        </>
      )}
      <OwnerSteps />
      <MissingCode />
      <p>
        Recovery does not delete sales, stock, drafts or pending orders. Keep
        using the same account, browser profile and shop address. Do not clear
        browser data or recreate unanswered orders.
      </p>
      <p>
        For the host walkthrough, open <strong>OWNER-RECOVERY-GUIDE.txt</strong>{" "}
        in the rebuild folder. This page provides instructions only; it cannot
        reset an Owner password.
      </p>
    </div>
  );
  return owner ? (
    content
  ) : (
    <details className="recovery-help">
      <summary>Forgot your password?</summary>
      {content}
    </details>
  );
}
