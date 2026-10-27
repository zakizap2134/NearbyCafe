# Nearby Café password policy update

Use at least 12 characters.

New passwords require at least 12 Unicode code points, at most 72 UTF-8 bytes, no NUL characters, and an exactly matching confirmation. Letters with combining marks and joined emoji may contain multiple code points; this is code-point counting, not visual-symbol counting. The app never trims, normalizes or truncates passwords.

Oversized passwords display:
“This password is too long. Use fewer characters; accented letters and emoji may take more space.”

## What changed

- PHP `validate_new_password` / `new_password` enforce the policy for browser setup, staff creation/reset, browser recovery, and all four account/recovery CLI tools.
- Frontend setup, staff and recovery screens share `src/password-policy.js`, re-exported by the existing StaffPassword component. PHP PCRE `/us` and JavaScript `Array.from` count the same Unicode code points. Invalid Unicode is rejected.
- Staff creation and password reset requests now include required `confirm_password`; the server rejects missing or mismatching confirmation even if client validation is bypassed.
- Sign-in and current-password reauthentication retain their existing rules. A previously accepted password with fewer than 12 code points still works. Automatic bcrypt cost rehashing at sign-in hashes the verified password directly, without applying the new-password rules.
- Instructions and downloadable recovery sheets use character-based guidance. Backup encryption passphrases are separate from account passwords and retain their existing policy.
- No database migration, bulk hash replacement, session invalidation or stock/sales changes. Successful authorized resets still revoke the affected account's sessions as before.

## Windows/XAMPP upgrade

1. Close the running app servers and browser tabs. Keep a private backup of your current project folder and database.
2. Extract the complete source ZIP to a separate folder. It contains the complete application; earlier patches are not required.
3. Copy the updated `rebuild` files into `C:\Projects\VenueVue\rebuild`. Preserve your existing `config\local.php` and `storage` contents. The ZIP deliberately excludes private configuration, logs and setup credentials. Do not reinstall or clear the database.
4. In Command Prompt:

   ```bat
   cd /d C:\Projects\VenueVue\rebuild
   npm ci
   npm run build
   "C:\xampp\php\php.exe" tools\preflight.php
   ```

5. Start the app with its existing launcher/server configuration and reload all browser tabs so the frontend and backend are updated together. Old tabs do not send the newly required staff confirmation field and should be reloaded.
6. Sign in using your existing account. Test the new policy with a disposable staff account before changing a real account's password. Confirm normal register/report use.

The ZIP already includes built frontend assets. Building locally above verifies your installed Node setup and regenerates them. PHP's existing PCRE Unicode support is used, so **mbstring is not required** and no new XAMPP extension setting is needed. Preflight checks Unicode counting support.

`tools\support-reset-owner.php` is included for authorized host support when both password and recovery code are lost. Run only on the private host console; it is blocked over HTTP. See the support instructions supplied with this release. Its password entry is visible, and a successful operation revokes Owner sessions and replaces the recovery code. It is not a public sign-up route.

## Optional patch

The optional patch is an alternative to copying the full source. It targets `NearbyCafe_Header_Update_Complete_Source.zip` (the complete cream-header release), including the existing browser recovery Tasks 1–5. It also includes the updated support reset tool. It is not a patch for the original Stages 1–5 source alone.

From the folder containing `rebuild`, run `git apply --check NearbyCafe_Password_Policy.patch`, then `git apply NearbyCafe_Password_Policy.patch`. If Git reports conflicting local changes or an already-existing support tool, use the complete source with a careful file comparison; do not force it. Then run the build/preflight steps above. Compiled frontend assets are supplied in the full ZIP and regenerated when using the patch.

## Reproducing automated checks

From `rebuild`:

```bat
set PHP=C:\xampp\php\php.exe
node tests\password-policy.mjs
npm run build
```

Database/browser checks use `tests/integration.py` with `VV_PASSWORD_POLICY_TEST=1`, `VV_RELEASE_TEST=1`, `VV_BROWSER_TEST=1`, `VV_BROWSER_RECOVERY_TEST=1`, `VV_RECOVERY_PREPARATION_TEST=1`, and `VV_RECOVERY_UI=1`, plus the existing documented isolated database/runtime configuration. They delete/recreate `venuevue_rebuild_test`; run only against a disposable test server, never the shop database. Support-tool rollback checks are in `tests/support-password-reset.py` and require an isolated local MariaDB runtime as documented in that script.

See `PASSWORD_POLICY_VERIFICATION.md` for this release's results and remaining Windows/device acceptance checks.
