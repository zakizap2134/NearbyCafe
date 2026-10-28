# Windows launcher verification

Release date: 23 October 2026. Baseline: `NearbyCafe_Password_Policy_Complete_Source.zip` (SHA-256 `daba70af510954b21443022ed526b1ca482968b67571f2b1e06d1c0978c9d91b`). The full source package includes all prior stages/tasks and compiled frontend assets. No push or merge was performed.

## Results and limits

| Area | Result | Evidence / scope |
|---|---|---|
| Full application regression | PASS | `tests/LAUNCHER_REGRESSION_RESULTS.txt`: all requested integration/browser checks passed on disposable MariaDB. Checkout, exact stock deductions, rollback, FEFO/opened stock, duplicate submissions, pending orders, roles, reports, CSV, backup/restore, browser recovery and Unicode password policy. |
| Launcher start/stop orchestration | PASS with simulated service adapters | `tests/LAUNCHER_ORCHESTRATION_RESULTS.txt`: cold start, duplicate starts, shared service preservation, process identity changes, missing prerequisites, occupied ports, wrong database identity, incomplete setup, SQL/HTTP/service timeouts and safe shutdown failures. PowerShell 7.4.2 on Linux; this is not native Windows SCM execution. |
| Real Apache + PHP integration | PASS on Linux fixture | `tests/LAUNCHER_APACHE_RESULTS.txt`: generated access rules, root index, private files/stray PHP blocked, instance/epoch readiness, real sign-in, bearer forwarding, same-origin recovery preparation, sale VAT/stock/idempotency and graceful server shutdown with unchanged records. |
| Production and development favicon | PASS in automated Chromium | Actual Apache production build and actual Vite dev server: title, local ICO/PNG requests and sign-in. Native Chrome and Edge tab rendering/cache behavior remain pending. |
| PHP syntax | PASS | `tests/LAUNCHER_PHP_LINT.txt`: all 35 PHP files parse. |
| Logo, icon files and contrast | PASS assets; native visual acceptance pending | `tests/LAUNCHER_ASSET_RESULTS.txt`: original logo hash unchanged, proportional whole-canvas PNG conversion, 16/24/32/48/64/128/256 ICO frames, local status/action assets, white button text contrast 6.62:1. Icon-size contact sheet visually inspected. |
| WPF/PowerShell source | PASS parser/XML checks | All launcher scripts parse; XAML is well formed, has visible text, wrapping and scrolling. WPF itself has not been executed on Windows here. |
| Windows/XAMPP, physical tablets | PENDING | Use the checklist in WINDOWS_LAUNCHER_SETUP.md. No claim of completed Windows client acceptance. |

Runtime used for actual backend/browser tests: isolated PHP CLI 8.3, MariaDB 10.11, Apache 2.4.58 with mod_php 8.3, Node 24 and Playwright Chromium on Linux. Apache's Windows-specific module paths, PHP DLL loading, thread directives and service integration were adapted only in the disposable Linux fixture. Access-control rules, application routes and generated configuration structure were exercised from the actual launcher config generator. This does not prove Windows SCM permissions, WPF rendering, native PHP 8.2.12/MariaDB 10.4.32 compatibility, firewall configuration or installed browser behavior.

## Confirmed defects corrected during verification

- Apache requires the message placeholder in ErrorLogFormat. A piped PHP logger now receives raw events and writes only timestamp, severity and an AH error code; it never persists raw URLs, headers or credential-bearing messages.
- Default-deny file rules blocked the site-root directory-index request. The generated config now explicitly allows only the root request for approved client addresses; private files, listings and stray PHP remain denied in real HTTP tests.
- Apache Windows services store configuration arguments separately in registry `Parameters\ConfigArgs`. Setup and daily service validation check these arguments as well as the saved binary command.
- The old browser regression expected the prior `Nearby Café · VenueVue` title. The assertion was updated to the requested `Nearby Café` title.

## Preservation review

Application PHP domain logic, React workflows, database schema/migrations, authentication rules, password hashes and the original logo source are unchanged from the supplied baseline. Launcher setup does not issue SQL installation, migration, restore or reset commands and does not write `config/local.php` or existing database files. Readiness/preflight are read-only at the database level; failed CLI checks may write existing safe application diagnostics.

The test fixture verified database snapshots around readiness and graceful Apache shutdown. The regression suite uses synthetic accounts/orders and disposable databases. No client database was accessed. Existing browser-local pending orders remain tied to their original origin/profile; port 5174 and the localhost daily URL were retained.

The standard-user launcher delegates to Windows services registered during developer installation. Stop checks saved service command, PID and creation time before sending the supported SCM stop request. It does not use force-kill, recursively stop service dependencies or stop a pre-existing external database. A new dedicated database service may be stopped if launcher-owned; use `-KeepDatabaseRunning` during setup when it also serves other applications.

## Reproduce automated checks

Use development dependencies only; none of these test tools is required for the Owner.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tests\launcher-orchestration.ps1
```

On a development machine with Python/Pillow:

```text
python tests/launcher-assets.py
```

The full app suite is `tests/integration.py`; use its documented disposable database variables and enable VV_TEST_SCOPE=all, VV_BROWSER_TEST=1, VV_RELEASE_TEST=1, VV_BROWSER_RECOVERY_TEST=1, VV_RECOVERY_PREPARATION_TEST=1, VV_RECOVERY_UI=1 and VV_PASSWORD_POLICY_TEST=1. Never target a client MySQL data directory.

`tests/launcher-apache-linux.py` is the additional Linux-only integration fixture. Supply absolute environment paths: `VV_TEST_RUNTIME_USR` (disposable-test MariaDB/PHP usr tree), `VV_TEST_APACHE_ROOT` (extracted Apache root), `PHP` (CLI with PDO MySQL enabled), `PWSH`, and `VV_CHROMIUM_PATH`. Run `npm ci` beforehand. It refuses occupied ports 3310, 18091 and 18575, creates a temporary database directory and temporary app path with spaces, and uses supported graceful shutdown. Its synthetic fixture credentials are test data, not shipped application accounts.

## Remaining native acceptance

Complete the Windows setup/standard-user permission, stopped/running/manual database, duplicate shortcut, port-conflict, missing-file, graceful shutdown, shared-service, same-account pending-order, actual Chrome/Edge, 100/125/150/200% scaling and real tablet checklist in WINDOWS_LAUNCHER_SETUP.md before handover.

The complete original wordmark is naturally tiny at 16px. Its colors and canvas were preserved as requested; no invented simplified replacement was used. Client approval of small-icon recognition remains open.

XAMPP runtimes remain external prerequisites. No standalone PHP/MariaDB bundling, service auto-start at boot, HTTPS provisioning or public internet deployment is included. No dependency upgrades were made as part of this launcher update.
