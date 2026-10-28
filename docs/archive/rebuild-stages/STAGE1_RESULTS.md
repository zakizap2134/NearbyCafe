# Stage 1 verification — October 18, 2026

Status: **foundation implementation gate passed in the isolated test environment.**

Tested with Node 24.19.0, PHP 8.3.6 / PDO MySQL, MariaDB 10.11.14, and Chromium 134 headless shell through Playwright. A clean `npm ci --ignore-scripts` and production build succeeded using the rebuild's own pinned lockfile. PHP syntax checks passed for every backend/tool file.

## Database/API checks passed

- Missing configuration, missing database and missing initial owner produce distinct preflight codes.
- New database installs from the authoritative Stage 1 schema. Rerunning installation preserves accounts. An unrelated/nonempty database is refused.
- Initial owner creation works exactly once. Staff creation requires valid current owner credentials.
- Owner and Barista can authenticate, retrieve their current session and access Register. Only Owner can access the Management endpoint. Client-supplied role values cannot elevate access.
- Unauthenticated requests, invalid HTTP methods, malformed JSON and oversized bodies receive controlled JSON errors.
- Development server refuses direct access to configuration, implementation, SQL, logs and CLI tools.
- Logout revokes the server token. Expired tokens and deactivated users cannot access protected endpoints.
- Failed login attempts trigger the username limit; valid credentials are still blocked until the limit window clears.
- Injecting an audit-table write failure causes login to fail without creating a session. The response contains a diagnostic reference but no raw SQL/exception details.
- A missing required table fails readiness/preflight clearly; restoring it restores readiness.
- Logs do not contain the test passwords or injected SQL exception text.

## Browser checks passed

- Invalid credentials show an error and retain the username.
- Owner login opens Management, exposes both role-appropriate navigation links, and survives reload.
- Owner can navigate to Register and sign out.
- Barista sees Register only. Direct navigation to Management redirects to Register.
- No JavaScript errors; no document overflow at 768px tablet or 390px mobile width.
- Desktop login/owner screens and tablet layout captured. Desktop login and owner screenshots visually reviewed.

## Not yet verified or implemented

- Actual Windows `.bat` execution, XAMPP Apache Alias installation, MySQL 8 and physical-tablet LAN operation require the user's environment. The automated database test used MariaDB, not MySQL 8.
- No old database was imported, changed or deleted. Tests recreated only `venuevue_rebuild_test` on a disposable server.
- Stage 2 catalogue, recipe, inventory and checkout work has not started. Stages 3–5 are also pending.
- Temporary text branding uses no official logo artwork. Client-specific recipes/prices/stock remain unconfigured.

Run the documented Windows installation and require all preflight checks to pass before using this checkpoint on the laptop. If it fails, preserve the diagnostic code/reference for troubleshooting.
