# Account recovery and password resets

## Prepare your Owner recovery code

Sign in as the active Owner/Admin. Open **Management → Account security** and choose **Generate / replace recovery code**. Enter your current password. If a code already exists, explicitly confirm replacement: the previous code will stop working.

Copy, download or carefully write down all eight groups of the displayed code. Keep it privately, separately from the shop computer. Confirm that you saved it before closing the display. The application stores only its hash and cannot show it again. The setup code used to create the first Owner is different: it cannot reset an Owner password.

If the response is lost, sign in and generate a replacement again. Never assume an unconfirmed response left the old code valid.

## Forgot your Owner password?

Choose **Forgot password?** on sign-in. Enter your username, current offline recovery code, new password and matching confirmation. Use at least 12 characters. Very long passwords are rejected; accented letters and emoji may take more space. After a confirmed reset, save the replacement recovery code, acknowledge it, then sign in manually with the new password. The old code is invalid and all previous Owner sessions are revoked.

If the result could not be confirmed, try signing in with the new password. After successful sign-in, use Account security to generate a replacement code. An interrupted response does not mean the password stayed unchanged. Do not repeatedly submit the same reset.

Incorrect credentials are limited by the server; wait for the displayed retry period. Baristas must contact the Owner for a reset. If you lose both your Owner password and recovery code, contact authorized support. Preserve the database and accounts; do not reinstall or create a replacement Owner.

## Optional server-console support

Existing support commands remain available from the `rebuild` folder on the trusted host:

```bat
C:\xampp\php\php.exe tools\recovery-code.php
C:\xampp\php\php.exe tools\recover-owner.php
```

The first prepares/replaces a code with the current Owner password; the second resets using the current code. Input is visible; use a private console. Browser and CLI use compatible codes and shared limits. Keep the host clock correct. Database restores can restore older credentials: follow BACKUP_RESTORE.md and rotate recovery credentials afterward.

## Owner resets a Barista password

1. Open **Management → Account security**.
2. Select a Barista account.
3. Enter your current owner password, the new staff password and its confirmation.
4. Choose **Reset Barista password** and share the new password privately.

The server checks the Owner role and current owner password again. A reset cannot target an Owner, promote staff, or reactivate an inactive account. Password update, staff-session revocation and the audit event `BARISTA_PASSWORD_RESET:<user ID>` are atomic. Five incorrect owner-password checks trigger the fifteen-minute reauthentication limit.

Resetting a password does not erase the staff member's sales, browser drafts or queued orders. Their next protected request requires sign-in again. They must use the **same account, browser profile and site address** to recover their local orders. Register then retries saved submissions using their original transaction keys. Do not recreate an unanswered order or collect the same cash twice.

## Operational checks

- Keep the host clock/date correct; the application uses Asia/Manila time.
- Store local credentials and recovery codes privately. Application diagnostic logs omit passwords, session tokens and recovery codes.
- Use Management → Backup & restore for encrypted manual backups. The detailed procedure and host fallback are in [BACKUP_RESTORE.md](BACKUP_RESTORE.md).
- Test the documented recovery on a disposable installation before client handover. Windows/XAMPP and physical-device acceptance still require local validation.
