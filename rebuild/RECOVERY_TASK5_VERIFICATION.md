# Nearby Café — recovery Tasks 1–5 verification

Release date: **21 October 2026**. Includes the complete rebuild Stages 1–5, original account/theme Tasks 1–7, and browser recovery Tasks 1–5. The attached Tasks 1–4 ZIP was recovered after workspace maintenance; recorded Task 5 changes were restored and verified again. No push or merge was performed.

**Result: all requested automated regression, recovery and accessibility suites passed.** Windows/device/client acceptance remains pending.

## Evidence and environment

Tests use a disposable `venuevue_rebuild_test` database on an isolated MariaDB 10.11.14 server, PHP 8.3.6 with multiple server workers, Node 24.19.0, pinned Vite 8.3.0, and Chromium headless through Playwright. No client database or production accounts were used. Build, PHP syntax, integration and browser results are recorded in `tests/RECOVERY_TASK5_RESULTS.txt`.

| Area | Checked behavior |
|---|---|
| Owner preparation | No-auth and Barista access denied; inactive Owner denied; current-password reauthentication; five-attempt/shared-CLI limits; prepared/version status only. |
| Reset validation | Generic responses for unknown usernames, Baristas, inactive Owners, wrong/malformed/missing/used codes; replaced codes invalidated; client role/id cannot retarget recovery. |
| Password rules | Byte-length and confirmation validation, oversize Unicode and NUL rejection, successful reset/sign-in at 12 and 72 UTF-8 bytes. |
| Transactions | Injected final audit failures roll back password, recovery code, sessions and security state; preparation and audit commit atomically. |
| Concurrency | Eight resets yield one success; eight replacements yield one rotation; CLI/web and reset/replacement races serialize; browser duplicate clicks send one mutation. |
| Sessions | Recovered Owner sessions revoked; staff session retained; staff reset remains Owner-authorized; inactive staff remain inactive. |
| Lost responses | Committed reset with lost reply supports new-password sign-in and re-preparation; committed replacement with lost reply shows uncertainty and requires reauthentication/replacement. |
| Origin/privacy | Actual Vite proxy checks reset and preparation origins; method/JSON/body guards and no-store/no-referrer headers; no plaintext recovery secrets in tested storage, logs or URLs. |
| Browser recovery | Clickable dedicated flow, validation/loading/errors, no auto-authentication, one-time display, acknowledgement, Copy/Download success and fallback, cleared sensitive inputs. |
| Pending orders | Owner order survives reset/reload/same-account sign-in and reconciles using its original key with one deduction; Barista pending order survives an authorized staff reset. |
| POS regression | Catalogue/recipes, cash sale, duplicates, rollback, FEFO/opened/expired stock, stored VAT, receipt, reports/CSV, encrypted backup/restore, restore epoch and retained credentials. |
| Layout/accessibility | Recovery widths 1440/768/390/320px, 200% text reflow, labels, focus, announcements, touch targets, modal keyboard behavior, contrast and long-code wrapping. |

The real PHP/API/browser tests use generated disposable accounts and codes. Separate accessibility and clipboard-failure scenarios use synthetic responses. Authentication tokens remain in session storage as designed; passwords and recovery codes are not persisted there. User-requested clipboard copies/downloaded recovery sheets intentionally contain the displayed code and must remain private. A download-request message does not prove a file was retained.

## Task 5 changes

- Corrected outdated backend Owner-target denial and preflight messages directing Owners exclusively to server-console recovery; authorization/transaction behavior unchanged.
- Reconciled Windows fresh-install/upgrade and operating instructions with browser recovery; CLI support retained.
- Updated old browser test selectors for the dedicated Forgot password route and scoped live-status messages; waited for completed navigation/sign-out to avoid test races.
- Added missing boundary, inactive-account, proxy preparation, cross-operation concurrency, lost replacement-response, Owner pending-order and browser secret-handling coverage.
- Added selectable `regression`/`recovery` test scopes to allow separate fresh disposable runs; default `all` retains combined execution.

No new database migration is required for existing completed Stage 4/5 rebuilds. Schema version remains 4. Existing Stage 1–3 rebuilds use their existing Stage 4 migration; the original pre-rebuild POS schema is incompatible. Preserve config/local.php, accounts, database, browser site data and deployment origin. Checkout, reporting, backup/restore, queue implementations and the original logo were checked unchanged from baseline `9a43ca8`.

## Remaining manual acceptance — not claimed as passed

- Actual Windows/XAMPP installation and upgrade, Apache Alias/Authorization forwarding, permissions and firewall.
- Real iOS/Safari and Android/tablet browsers; clipboard/download permissions, physical keyboard/touch and screen readers.
- Multiple real devices, shop Wi-Fi interruptions, host restarts, operational load and client recovery drill.
- Client UAT/sign-off and backup/restore rehearsal on a separate disposable Windows database.

Automated accessibility checks are not a WCAG certification. Normal operation needs the local host and Wi-Fi; there is no service-worker cache for host-independent startup. Plain LAN HTTP is unencrypted. These are existing deployment constraints.

## Reproduce the automated suites

Use an isolated Linux test host: the harness uses POSIX process groups and PHP server workers. This is not the Windows acceptance procedure. Install pinned dependencies (`npm ci`), build (`npm run build`), provide compatible PHP/MySQL command-line tools, Python 3 and Playwright Chromium. Set `PHP`, `MYSQL`, `VV_TEST_DB_PORT` for a disposable database server; the harness recreates its named test database and must not target a client server. Optional `VV_TEST_START_DB=1` starts a dedicated instance using `MYSQLD`, `MYSQL_BASE` and separately initialized `MYSQL_DATA`. Set `VV_CHROMIUM_PATH` for a custom browser executable.

From `rebuild`, run sequentially:

```sh
VV_TEST_SCOPE=regression VV_BROWSER_TEST=1 VV_RELEASE_TEST=1 python -u tests/integration.py
VV_TEST_SCOPE=recovery VV_BROWSER_RECOVERY_TEST=1 VV_RECOVERY_PREPARATION_TEST=1 VV_RECOVERY_UI=1 python -u tests/integration.py
python tests/run-recovery-preview.py
```

Private runtime logs, test databases, generated test codes/screenshots, configuration, node_modules and .git are excluded from the release. Test source contains disposable fixture values only. The ZIP contains compiled assets; the optional cumulative patch requires rebuilding and targets baseline `9a43ca8`. See START_HERE.md before installing.
