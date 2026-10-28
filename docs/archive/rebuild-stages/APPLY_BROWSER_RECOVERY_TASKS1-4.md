> Historical task record. The complete Tasks 1–5 release instructions and results are in START_HERE.md and RECOVERY_TASK5_VERIFICATION.md.

# Apply the combined browser recovery Tasks 1–4 release

The source ZIP includes the entire rebuild application, compiled frontend, combined patch, synthetic design previews and verification results. You do not need the missing Task 1–3 downloads.

## Existing rebuild installation (recommended)

1. Finish active sales and stop the app/server. Back up your database and keep your existing rebuild folder, especially config/local.php. Preserve the browser profile and site data containing pending orders.
2. Extract the ZIP to a separate folder. Its `rebuild` directory is the application. Review it before replacing the running version.
3. Copy your existing config/local.php into the new rebuild/config directory. Keep the same database and deployment URL/port. Do not copy old source files over the new release.
4. Start MySQL and run from the new rebuild folder:

```bat
npm ci
npm run build
C:\xampp\php\php.exe tools\preflight.php
```

5. Start the app with your existing launch/deployment procedure. Sign in using your existing Owner account. Open Management → Account security and prepare a recovery code. Test recovery on a disposable installation before handing it to the client.

No database migration is required for this update to a completed Stage 4/5 rebuild. Do not run database installation again, delete accounts or create another Owner. Older Stage 1–3 rebuilds require their existing Stage 4 migration instructions; the original pre-rebuild VenueVue database is a different schema.

## Patch alternative (developers)

The combined patch targets Git baseline **9a43ca8**. In a clean matching checkout, run `git apply --check NearbyCafe_Recovery_Tasks1-4.patch`, then `git apply NearbyCafe_Recovery_Tasks1-4.patch`. It contains source/docs/tests; run npm ci and npm run build afterward. Do not blindly apply it over partially patched Tasks 1–3. Use the complete source ZIP or review conflicts against your own changes.

No production config, accounts, database dumps, recovery secrets or node_modules are included. Previews use synthetic data only. See BROWSER_RECOVERY_TASK4.md and tests/BROWSER_RECOVERY_TASK4_RESULTS.txt for scope and limitations. CLI recovery remains available; Task 5 is excluded.
