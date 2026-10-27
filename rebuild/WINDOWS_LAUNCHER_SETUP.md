# Nearby Café — Windows setup and upgrade

Release: 23 October 2026. For the integrated repository use [the root guide](../README.md) and [current verification](../docs/VERIFICATION.md). These are developer installation steps. After installation, the Owner uses the desktop shortcut and the short Owner guide.

## What this update runs

The previous `START-DEV.bat` starts PHP's development server on 8081 and Vite on 5174, after someone starts the database. The daily launcher now serves the **built React frontend and PHP API together on port 5174**, through a dedicated Apache Windows service using XAMPP's installed Apache/PHP files. A local MySQL/MariaDB database is also required. Vite, Node and npm are not used for ordinary startup.

XAMPP's global Apache service is not required. The dedicated service has its own configuration, exposing only approved files in `public/`; it does not include XAMPP's default sites or phpMyAdmin aliases. Apache was selected to provide a supported Windows service with concurrent PHP requests and graceful service control. PHP's built-in server remains for development.

**Keep XAMPP installed.** This update hides the daily control-panel/command workflow; it does not bundle or replace PHP/MariaDB. A completely standalone runtime installer is a separate packaging task.

## A. Upgrade your existing installation

1. Finish sales on all devices and let requests finish. Keep the same browser profiles and all browser site data, including pending orders. Make a verified database backup and a private copy of the existing project folder. Do not put backups under `public/` or `htdocs`.
2. Close old PHP/Vite terminal windows from `START-DEV.bat`. If this launcher was previously installed, use **Stop Nearby Café**, then close its window. Leave any shared database service alone.
3. Extract the complete ZIP into a separate temporary folder. Open its `rebuild` folder. Copy its contents into **`C:\Projects\VenueVue\rebuild`**, replacing supplied source/build files. Do not create `rebuild\rebuild`. This package contains no `config\local.php`, database files or runtime storage records, so ordinary copying does not replace those. Do not use a mirror/delete operation.
4. Keep the existing **`config\local.php`**, all existing **`storage`** contents and the XAMPP database data directory. Keep any existing launcher configuration too. Confirm `public\index.html`, `public\assets`, `launcher\Install.ps1` and `launcher\icons\nearby-cafe.ico` are present. The production frontend is already built; no npm commands are needed.
5. While signed into the **Windows account the shop Owner will use**, double-click **`Setup-Nearby-Cafe.bat`**. Read its explanation and accept the one-time Windows administrator prompt; an authorized administrator can supply credentials. Setup remembers the original Windows user for the shortcut and daily service permissions.
6. Setup checks the existing configuration and XAMPP files. It registers a dedicated manual-start web service and reuses a verified matching database service. If the database was run manually from XAMPP, it registers a manual-start service pointing to that **same existing my.ini and data directory**. It never initializes or recreates database files, runs SQL installation, or starts services during setup.
7. When setup reports success, double-click **Nearby Café** on the desktop. Wait for **Ready**. The browser should open **`http://localhost:5174`**. Sign in with your existing Owner account. Check your catalogue, stock, sales, reports and pending orders before handover.
8. Complete the native Windows acceptance checklist below. Keep the backup until those checks pass. If setup stops with a reference code, read troubleshooting; do not delete accounts or database files to resolve it.

If MySQL is already running manually from XAMPP, the launcher reuses it after verifying its port and data directory. It will leave that manually started database running on Stop. For a cold-start acceptance test, first finish all work and stop the manual database through XAMPP's normal Stop button, then use the desktop shortcut. Future daily starts use the registered service.

No new migration is needed for the existing completed Stage 4/5 schema (version 4). An older rebuild Stage 1–3 database requires its documented `tools\migrate-stage4.php` developer migration and backup first. The original pre-rebuild POS schema is different; this launcher does not convert it.

## B. Fresh installation — developer only

1. Install the full Windows XAMPP distribution with matching Apache 2.4 and thread-safe PHP 8.2+, plus its MariaDB. Enable PDO MySQL, OpenSSL and JSON. Use the supported Visual C++ runtime required by that XAMPP build. This package does not download/install prerequisites or modify PHP extensions automatically.
2. Put `rebuild` outside `htdocs`, normally `C:\Projects\VenueVue\rebuild`. A local NTFS path with spaces is supported; avoid network shares and cloud-synced directories. Keep XAMPP at `C:\xampp` for the default installer.
3. The developer starts the existing XAMPP database for initial provisioning. In Command Prompt:

   ```bat
   cd /d C:\Projects\VenueVue\rebuild
   copy config\local.example.php config\local.php
   notepad config\local.php
   ```

   **Only copy the example when local.php does not already exist.** Enter the actual host, port, database username/password and a new dedicated rebuild database name. Do not assume the example blank root password applies to your computer.
4. For this new database only, run:

   ```bat
   "C:\xampp\php\php.exe" tools\install.php
   "C:\xampp\php\php.exe" tools\prepare-owner-setup.php
   "C:\xampp\php\php.exe" tools\preflight.php --allow-owner-setup
   ```

   Privately give the Owner the one-time setup code. It expires after 24 hours. This initial setup code is different from the ongoing Owner recovery code.
5. Run `Setup-Nearby-Cafe.bat` once as described in A, then double-click the desktop **Nearby Café** shortcut. Choose **Set up owner account** in the browser. The Owner enters their details, matching passwords and the setup code; no public role selector is provided.
6. Sign in, open **Management → Account security**, generate the ongoing recovery code, and store it safely. Create Baristas through Management. Complete a test sale using agreed disposable demonstration records before loading client data.

Registration is intentionally closed when an Owner was already provisioned. If someone removed an Owner row, this launcher does not reopen registration or wipe the audit trail. Preserve the data and use authorized support/recovery.

## C. Installation choices and LAN access

Default setup uses `C:\xampp`, HTTP port **5174**, and private LAN access. The application directory is detected from the script location. For a different XAMPP location or port, a developer can run this from PowerShell in `rebuild`:

```powershell
powershell.exe -NoProfile -STA -ExecutionPolicy Bypass -File .\launcher\Install.ps1 -XamppRoot "D:\Tools\xampp" -WebPort 5174
```

Add **`-LocalOnly`** if tablets must not connect. Add **`-KeepDatabaseRunning`** if the database also serves other applications, even when no existing Windows service was found. Existing external database services are preserved on Stop by default. A dedicated database service is stopped only if this launcher started the current process and the saved policy permits it.

Setup makes only its own services manual-start, configures them as Windows LocalService, grants required directory permissions, and grants the original Windows user query/start/stop rights for the selected services. Existing third-party database service accounts/startup settings are retained. It creates one inbound firewall rule for the selected Apache executable/port, **Private profile and LocalSubnet only**. It does not open the database port for tablets or change the network category. The developer must set the trusted shop Wi-Fi/hotspot to Private in Windows Settings and verify the correct host address.

Tablets on that same trusted network use **`http://HOST-IP:5174`**. The launcher displays a candidate tablet address; if the laptop has several adapters/VPNs, confirm the Wi-Fi/hotspot IPv4 address with `ipconfig`. Keep the laptop awake. No internet is needed after setup, but the host and local Wi-Fi must remain active. Plain local HTTP is not encrypted; HTTPS provisioning remains a separate deployment requirement if transport confidentiality is needed.

Preserve the exact prior scheme/host/port/browser profile while orders are pending. `localhost` and `127.0.0.1` have separate browser storage; do not switch between them casually. For a previous URL using `/venuevue/` on port 80, reconcile its pending orders before moving to this root URL. Do not run START-DEV and the daily launcher together on port 5174.

If the project is moved or its services are renamed, stop it first and ask the developer to reconfigure the installation and shortcut. Never copy stale service configuration from a different computer.

## D. Owner daily use

1. Double-click **Nearby Café** on the desktop (or `Start-Nearby-Cafe.bat` in the project).
2. Wait through **Checking setup → Starting database → Starting Nearby Café → Ready**. Already-running compatible services are reused. The browser opens only after readiness succeeds.
3. Use the application normally. **Open Nearby Café** reopens the browser. Repeated shortcut clicks reuse the same launcher/application.
4. Finish sales on all devices, then click **Stop Nearby Café** and confirm. Wait for **Stopped**. If a shared database remains running, that is expected. Closing only the launcher window leaves the shop running.
5. If a problem appears, read the next step and reference code. Use Retry after the cause is resolved, or give the code to the developer. Stop never force-kills a database or stops unrelated services.

## E. Troubleshooting

| Message/reference | Developer next step |
|---|---|
| CONFIG_MISSING / CONFIG_INVALID | Check the existing local.php and launcher.local.json; run PHP lint on local.php if it was edited. Finish setup rather than replacing credentials with examples. |
| PHP_MISSING / DB_BINARY_MISSING / REQUIRED_SETUP_FILE_MISSING | Check XAMPP's installed location and required files; rerun developer setup with the correct XamppRoot. |
| ASSETS_MISSING | Copy the complete supplied public frontend and launcher tools/icons; or rebuild source as a developer. |
| PORT_BUSY | Close the known old Vite/PHP server or identify the actual listener. The launcher will not kill it. Preserve the old origin where possible. |
| DB_BUSY | Wait for a database already starting. If a different mysqld process exists, inspect its installation/datadir; never launch a second one against that directory. |
| DB_IDENTITY / DATABASE_DIRECTORY_MISMATCH | Verify the configured port and `@@datadir` belong to this installation before proceeding. |
| DATABASE_SERVICE_CONFIG_UNVERIFIED | The existing service does not explicitly use the expected my.ini, or has overriding arguments. Developer must verify/reconfigure that service deliberately; setup does not guess. |
| DATABASE_INCLUDES_REQUIRE_MANUAL_SETUP | The my.ini includes additional files; the simple installer deliberately refuses ambiguous configurations. Have the developer resolve the actual effective settings. |
| DB_ACCESS_DENIED / DB_UNAVAILABLE / DB_TIMEOUT | Check saved database credentials, service state, my.ini and database health. Wait/retry for slow startup; never delete InnoDB files or recreate client data. |
| OWNER_MISSING | For a genuinely fresh empty workspace prepare initial setup. Existing/provisioned workspaces require authorized recovery/support. |
| SCHEMA_MISSING / SCHEMA_MISMATCH | Back up and inspect the schema. Apply only the documented older-rebuild migration when appropriate. |
| SERVICE_ACCESS | Rerun setup while logged into the intended Owner Windows account. The administrator may need to inspect corporate PowerShell/CIM/service policy. Daily startup does not elevate itself. |
| SERVICE_CHANGED / WEB_SERVICE_CONFIG_UNVERIFIED | Inspect the service executable and Apache registry ConfigArgs. Do not point the launcher at a generic shared Apache service. |
| APACHE_CONFIGURATION_FAILED | Developer: run `C:\xampp\apache\bin\httpd.exe -t -f "C:\Projects\VenueVue\rebuild\config\nearby-httpd.conf"` locally and repair the reported dependency/configuration issue. |
| STOP_INCOMPLETE | Wait for graceful shutdown, then retry. Inspect the named service; never use taskkill on MariaDB. |
| SETUP_FAILED / partial installation | Setup may have registered a service before a later failure. Correct the cause and rerun setup; it recognizes its exact dedicated service names. Do not delete the data directory. |

Technical diagnostics are under `storage\launcher\launcher.log` and `apache-status.log`; they contain timestamps and safe codes only. The Apache pipe discards raw messages, URLs and headers. Normal access logging is disabled. Existing application diagnostics remain under storage. Do not publish configuration, database logs or recovery materials.

## F. Favicon and desktop icon

The complete original Nearby Café logo is preserved. The ICO includes 16, 24, 32, 48, 64, 128 and 256 pixel images; PNG alternatives are local. The browser tab title is **Nearby Café**. The icon is available in development and production without internet.

In Chrome or Edge, first press **Ctrl+F5**, then close and reopen the app tab if the previous favicon remains. Check `http://localhost:5174/favicon.ico?v=nearby1` directly or try a private window to distinguish caching. Browser favicon caches may take another restart to refresh. **Do not clear site data/IndexedDB**, because it can contain pending orders. Future icon updates should change the favicon version query in index.html and rebuild.

The full wordmark is naturally very small at 16px; it has not been cropped or redesigned. Client approval of the tiny icon and native scaling checks remains required. A simplified mark would need separate approval/artwork.

## G. Windows/device acceptance before handover

Use a disposable shop installation/database for failure tests. Check off actual results; these are not claimed as already passed on Windows.

- [ ] Windows 10/11 + the actual XAMPP PHP 8.2.12 / MariaDB 10.4.32 combination: setup, LocalService filesystem permissions and standard-user startup without daily UAC.
- [ ] Database stopped, already running as a service, and already running from XAMPP: one process/datadir; shared database remains running after Stop.
- [ ] Cold start, repeated clicks, closing/reopening launcher, app already running; no duplicate listeners.
- [ ] Occupied port, missing PHP/database executable/config/build, unavailable database and startup timeout: readable guidance, no automatic reset or unrelated termination.
- [ ] Finish sales and Stop: graceful web/database shutdown, preservation of existing shared services, unchanged account/stock/sales records.
- [ ] Chrome **and** Edge: logo favicon/title, Owner and Barista sign-in, test sale/VAT/receipt, pending-order resume, reports/CSV and backup download.
- [ ] Desktop/launcher at 100%, 125%, 150% and 200% Windows scaling: logo proportions, readable buttons/status icons, keyboard focus and scrolling on a small screen.
- [ ] Actual tablet on shop Wi-Fi/hotspot: connection, private firewall rule, correct address, sales and reconnect behavior.

## Developer commands and optional patch

Keep `START-DEV.bat` for development. Node.js 22.12+ is needed only for `npm ci` / `npm run build`; Python/Pillow/CairoSVG are needed only if regenerating icon assets. Neither is needed for daily Owner startup.

The integrated complete ZIP needs no earlier patches. The Git branch delivery and exact baseline are documented in [publishing instructions](../docs/PUBLISHING.md). Source Git checkouts require `npm ci` and `npm run build`; the complete ZIP includes the build.

Sources for deployment choices: [Apache on Windows](https://httpd.apache.org/docs/2.4/platform/windows.html), [PHP with Apache on Windows](https://www.php.net/manual/en/install.windows.apache2.php), [MariaDB server options](https://mariadb.com/docs/server/server-management/starting-and-stopping-mariadb/mariadbd-options), [Apache Windows service implementation](https://github.com/apache/httpd/blob/2.4.x/server/mpm/winnt/service.c).
