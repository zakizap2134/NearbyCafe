# Combined release verification — Tasks 1–7

Release review date: October 20, 2026. Scope: the complete Nearby Café `rebuild` application, Stages 1–5 with Tasks 1–7. This is an application release candidate for the team's Windows/device acceptance, not a claim of client sign-off or production deployment.

## Source provenance

The release starts from local repository baseline `57552fa` (Stage 5 plus the muted Task 6 theme). Available Task 1 changes and the recorded account/UI requirements were consolidated in an isolated `feature/tasks1-7-release` worktree. Earlier separate Task 4/5 output folders were unavailable in this resumed workspace; those additions were reconstructed and verified together. The existing transaction, inventory, VAT and backup/restore implementation was retained. No push or merge was performed.

## Automated checks

See `tests/RELEASE_RESULTS.txt` for the passing output from the combined integration run. The test harness uses only the disposable `venuevue_rebuild_test` database; it must never be pointed at a client's database server/data directory.

| Area | Verification |
|---|---|
| Initial setup | Code hash/expiry, missing/malformed code attempts, per-address/global limits, ignored proxy spoofing, audit-failure rollback, concurrent browser/CLI creation, server-assigned OWNER role, no automatic login and closure after provisioning |
| Sign-in and roles | Invalid-input/credential feedback, throttling, network-error handling, Owner/Barista landing, reload, logout, inactive/session-expired denial, direct protected API denial |
| Staff and recovery | Password confirmation, owner reauthentication, save-versus-refresh feedback, deactivation/session revocation, reset of inactive account without activation, reactivation, offline recovery hash/limits/rotation and atomic failure rollback |
| Core sale | Recipe → replenishment → cash sale → receipt → exact stock and totals: ₱420 gross, ₱375 net, ₱45 VAT, ₱80 change |
| Inventory | FEFO, opened-before-sealed allocation, expiry-today eligibility, exact use-by/midnight boundaries, grouped ingredient requirements, stock invariants, waste and authorized adjustments |
| Reliability | Eight concurrent duplicate submissions, competing last-stock sales, late-write rollback, persisted drafts/queues, reconnect replay, stale-price review, reset/logout queue preservation and browser storage failure |
| Management | Catalogue/variant/recipe CRUD, stock-in, real reports and audit, protected history, gross/net/VAT CSV totals, migration preservation |
| Backup/restore | Encrypted download/upload, wrong passphrase/tamper rejection, invalid-record validation, stale preview, rollback at final audit, atomic replacement, session revocation and pre-restore request review |
| Interface | Nearby Café local logo, two-role navigation, keyboard recovery help, 1440/768/390 viewport checks, no unexpected horizontal overflow or JavaScript errors in the exercised flows |
| Build and packaging | Production Vite build, PHP syntax checks, Windows launcher static checks, ZIP integrity and exclusion of private configuration, credentials, logs and dependencies |

Test runtime: Linux, PHP 8.3.6, MariaDB 10.11.14/InnoDB, Node 24.19.0, npm 11.9.0 and Playwright Chromium. API concurrency checks use multiple PHP workers. Synthetic test credentials are fixtures, not installed accounts.

A first run encountered MariaDB engine/table errors with its disposable data files on the workspace-mounted filesystem. The run was repeated with the disposable data directory under `/tmp`; no application transaction code was changed to work around that environment issue. Browser test assertions were corrected to match the existing username message and Register link semantics.

## Reproduce

Install dependencies with `npm ci`, build with `npm run build`, and provision a separate disposable database server. Install a compatible Playwright browser or set `VV_CHROMIUM_PATH`. From `rebuild` on a suitable test host:

```sh
VV_TEST_DB_PORT=3307 VV_BROWSER_TEST=1 VV_RELEASE_TEST=1 python tests/integration.py
```

Use `PHP` and `MYSQL` to select executables. The optional `VV_TEST_START_DB=1` path also requires `MYSQLD`, `MYSQL_BASE` and a dedicated `MYSQL_DATA` directory. The harness drops and recreates its named test database. New account-screen screenshots are generated locally for verification and are not included in the release ZIP; the earlier Task 6 preview files are retained.

## Remaining acceptance on your installation

1. Run the appropriate fresh-install or existing-database instructions in `START_HERE.md` on actual Windows/XAMPP. Double-click all launchers; these were statically checked here, not executed by Windows.
2. Test your real tablet/phone, browser, LAN address, Wi-Fi reconnection and host restart. The automated responsive checks used Chromium viewports, not physical devices or Safari.
3. Verify the configured MySQL variant and database permissions. This run used MariaDB; native MySQL execution was not performed here.
4. Use agreed client recipes/prices/stock and reconcile a sample cash sale, stock deduction, VAT report and CSV. Validate backup/restore on a disposable copy before relying on it operationally.
5. Record team/client acceptance using `ACCEPTANCE.md`. The package does not declare the project 100% accepted.
