# Integration verification — 23 October 2026

Scope: the complete launcher source integrated onto GitHub baseline `ac70e70e667c26d10c58056bbcaa8c7f4d28bd37`, with the ownership-persistence correction and repository/documentation changes. Tests used isolated temporary databases and synthetic accounts. No Windows client database, configuration or service was accessed.

## Executed checks

| Check | Result | Evidence |
|---|---|---|
| Clean dependency install and production build | Passed; Vite 8.3.5, 43 modules | [build output](../verification/build.txt) |
| Dependency audit | Vite 8.3.5; zero known vulnerabilities at verification | [audit JSON](../verification/dependency-audit.json) |
| PHP syntax | All application/tool/test PHP files passed | [lint output](../verification/php-lint.txt) |
| Full application integration/browser suite | **58 PASS groups**, scope `all` | [regression output](../verification/regression.txt) |
| PHP/JavaScript password-policy parity | Shared boundary cases passed, including Unicode/combining marks, NUL, confirmation and 72-byte limit | [password output](../verification/password-policy.txt) |
| Service orchestration | **13 PASS groups**, simulated adapters | [orchestration output](../verification/orchestration.txt) |
| Ownership persistence | **3 PASS groups**, real temporary filesystem, Linux PowerShell | [persistence output](../verification/persistence.txt) |
| Production Apache and actual Vite/browser integration | **8 PASS groups**, Linux Apache with production configuration adapted for Linux modules | [Apache output](../verification/apache.txt) |
| Logo/favicon/local assets | **3 PASS groups**; unchanged original logo, ICO sizes, local PNG/SVG and 6.62:1 button contrast | [asset output](../verification/assets.txt) |
| Repository exclusions, source reconciliation, links and staged tree | See final repository check output | [repository output](../verification/repository.txt) |

These counts describe assertion groups, not individual assertions or a development-completion percentage. Earlier `rebuild/tests/*RESULTS*` files are historical package evidence; the reports above were rerun for this integration.

The full suite covers the recipe → stock-in → cash sale milestone, exact remaining stock, VAT/receipt totals, failed-sale rollback, repeated/concurrent submissions, FEFO/opened stock, frozen sale-time expiration boundaries, Owner/Barista restrictions, browser setup, reauthentication limits, single-use recovery, audit-failure rollback, code-replacement/reset races, session revocation, lost responses, pending-order reload/reconciliation, actual Vite same-origin checks, reports/CSV and encrypted backup/restore. Invalid password/reset paths preserve records. Browser tests use synthetic data.

Persistence tests exercise first creation, existing-file replacement using `NullString.Value`, paths with spaces and failure cleanup. Injected save failures leave prior in-memory ownership intact, do not claim new ownership, do not open the browser prematurely and do not stop running services. Orchestration tests also cover shared services, identity changes/PID reuse, startup timeouts, ports and shutdown failures. They do **not** prove real Windows SCM/ACL behavior.

Production-server tests verify root index, private-file denial, readiness, Owner/Barista sign-in, recovery preparation, exactly-once stock deduction/VAT, local favicon requests in Apache and Vite, safe diagnostic filtering and graceful Apache shutdown without changing the database snapshot.

## Test environment

- Linux x86-64; Node.js **24.19.0**, npm **11.9.0**.
- PHP CLI/mod_php **8.3.6**, MariaDB **10.11.14**, InnoDB.
- Apache **2.4.58** (Ubuntu package), isolated configuration and temporary data.
- PowerShell **7.4.2 on Linux**; Chromium headless browser through the pinned Playwright dependency.
- PHP extensions loaded: mysqlnd, PDO, PDO MySQL; OpenSSL/JSON/PCRE available.

User-reported Windows evidence before this integration: PHP **8.2.12**, MariaDB **10.4.32**, schema 4 preflight passed; Apache configuration syntax was OK; database service was running and web service initially stopped. Windows PowerShell **5.1.26100.9711** then failed in `File.Replace`. The correction has **not yet been reported as passing on that Windows host**.

## Reproducing checks

From `rebuild`, with appropriate tools installed:

```powershell
npm ci
npm run build
$env:PHP = "C:\xampp\php\php.exe"
node tests/password-policy.mjs
Get-ChildItem app,public,tools,tests -Filter *.php -Recurse | ForEach-Object {
    & $env:PHP -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed" }
}
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\launcher-persistence.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\launcher-orchestration.ps1
```

`python tests/launcher-assets.py` requires Pillow. The full database suite is **destructive to its explicitly named disposable test database**. Use only a separately initialized temporary MariaDB instance/data directory. Read the fixture before running it. Never supply the shop's database data directory or live port.

For the full Linux fixture, `tests/integration.py` was run with `VV_TEST_SCOPE=all`, `VV_TEST_START_DB=1`, `VV_TEST_DB_PORT=3307`, and all of `VV_BROWSER_TEST`, `VV_RELEASE_TEST`, `VV_BROWSER_RECOVERY_TEST`, `VV_RECOVERY_PREPARATION_TEST`, `VV_RECOVERY_UI`, `VV_PASSWORD_POLICY_TEST` set to `1`. `PHP`, `MYSQL`, `MYSQLD`, `MYSQL_BASE`, `MYSQL_DATA` and `VV_CHROMIUM_PATH` pointed to isolated runtimes and a freshly initialized temporary directory. Test ports were checked free first. The fixture shuts down its own processes.

`tests/launcher-apache-linux.py` initializes its own temporary database and refuses occupied test ports. It requires `VV_TEST_RUNTIME_USR`, `VV_TEST_APACHE_ROOT`, `PHP`, `PWSH` and `VV_CHROMIUM_PATH` as documented at its head. It adapts Windows Apache paths/module directives for Linux; it is not a native Windows deployment test.

## Remaining acceptance

- [ ] Run filesystem and orchestration tests under **Windows PowerShell 5.1** on the target host; verify the exact reported `File.Replace` failure is resolved.
- [ ] Real Windows service setup, LocalService filesystem ACLs, original-user service rights and scoped firewall rule; daily startup without UAC.
- [ ] Cold start, already-running database/app, repeated clicks and shared-service preservation; no duplicate database instance.
- [ ] Real port conflicts, missing executables/config/build, database timeout and write-permission failure; readable messages and safe service state.
- [ ] Graceful shutdown, start after a failed save, paths with spaces and actual client-data preservation after an approved backup/upgrade.
- [ ] Native Chrome and Edge, physical tablet Wi-Fi/hotspot access, host sleep behavior, and reconnect with the same browser origin.
- [ ] Windows desktop/WPF at 100%, 125%, 150% and 200% scaling; keyboard focus/readability and client approval of the full wordmark at 16px.
- [ ] Client acceptance of products, recipes, units, shelf lives, VAT workflow, reports and restore procedure.
- [ ] Native MySQL 8 compatibility if deploying MySQL instead of the tested MariaDB.

No Windows/device/client check is marked passed by inference from Linux. No BIR certification, public-network hardening, legacy-root-database conversion or standalone runtime installer is included.
