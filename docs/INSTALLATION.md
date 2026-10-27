# Windows installation and safe upgrades

Start from the [root README](../README.md). Commands below are **PowerShell**, unless marked otherwise. The examples use `C:\Projects\VenueVue\rebuild`. Substitute your actual application folder consistently.

## Fresh installation only

1. Install full XAMPP with matching Apache 2.4, thread-safe PHP 8.2+ and MariaDB. Keep it installed. Enable PDO MySQL, OpenSSL and JSON; the application uses PCRE for Unicode counting without requiring mbstring. Start MySQL through XAMPP for developer provisioning.
2. Extract the complete delivered ZIP into a temporary folder. Its `source` folder is the repository root. Copy `source\rebuild` to `C:\Projects\VenueVue\rebuild`. A Git clone instead already contains `rebuild`; do not nest it again. The expected path is `C:\Projects\VenueVue\rebuild\tools\preflight.php`.
3. Open PowerShell and configure the new installation. This guard refuses to overwrite existing configuration:

   ```powershell
   Set-Location "C:\Projects\VenueVue\rebuild"
   if (Test-Path .\config\local.php) { throw "Configuration already exists. Use upgrade instructions." }
   Copy-Item .\config\local.example.php .\config\local.php
   notepad .\config\local.php
   ```

   Enter the real database host/port/user/password and a **new dedicated database name**. The example is a template, not supplied credentials. Keep `local.php` private. Check syntax:

   ```powershell
   & "C:\xampp\php\php.exe" -l config\local.php
   ```

4. **Only for the genuinely new installation**, initialize the schema and prepare Owner registration:

   ```powershell
   & "C:\xampp\php\php.exe" tools\install.php
   & "C:\xampp\php\php.exe" tools\prepare-owner-setup.php
   & "C:\xampp\php\php.exe" tools\preflight.php --allow-owner-setup
   ```

   The developer needs database creation/table privileges for provisioning. Privately give the Owner the generated initial setup code (24-hour validity). Do not paste it into Git, chat screenshots or logs. If a database already contains shop records, stop and use the upgrade/recovery path. Never erase data to get past an install error.
5. **Git clone/GitHub source ZIP only:** install dependencies and build. Skip this for the provided complete ZIP with built assets:

   ```powershell
   npm ci
   npm run build
   ```

6. While signed into the Windows account the Owner will use, run:

   ```powershell
   .\Setup-Nearby-Cafe.bat
   ```

   An authorized administrator accepts the one-time elevation. Setup registers a dedicated manual-start Apache service, reuses a verified compatible database service or registers one for the existing XAMPP database, sets scoped permissions/firewall rules and creates the desktop shortcut. It does not initialize the database or start a second instance. For a shared database, read the `-KeepDatabaseRunning` option in [detailed setup](../rebuild/WINDOWS_LAUNCHER_SETUP.md).
7. Double-click **Nearby Café** on the desktop. Wait for Ready. Choose **Set up owner account** in the browser, enter the initial setup code and matching new passwords, then sign in. If setup is already closed, use the existing Owner or authorized recovery; deleting the Owner is not a setup procedure.
8. Open **Management → Account security**, generate the ongoing recovery code, save it offline and acknowledge saving it. This is different from the initial setup code. Create Baristas through Management. Complete the [acceptance checks](VERIFICATION.md#remaining-acceptance) before client handover.

## Upgrade an existing rebuild

1. Finish active sales on every device and resolve pending orders where possible. Make a verified backup and a private copy of the existing folder. Preserve browser profiles and IndexedDB. A CSV export alone is not a complete backup.
2. Use **Stop Nearby Café**; close the launcher after stopping. For a development host, close its PHP/Vite terminal windows. Do not stop a shared database used elsewhere without coordination.
3. Extract the complete ZIP separately. Copy the **contents** of `source\rebuild` into your existing `C:\Projects\VenueVue\rebuild`, replacing supplied source/build files. Do not copy the `source` wrapper into the application, do not create `rebuild\rebuild`, and do not use `/MIR` or delete the old folder.
4. Keep the existing `config\local.php`, `config\launcher.local.json`, `config\nearby-httpd.conf`, all runtime storage and the actual database data directory. None of these private files is shipped. Preserve accounts, hashes, stock, sales and pending browser data. No client database is accessed by this delivery.
5. This integration requires **no new migration for schema 4**. If still on rebuild Stages 1–3, back up first and have the developer run the existing `tools\migrate-stage4.php` after reviewing it. The original root VenueVue database has a different schema and no provided automatic conversion; keep it separate.
6. For Git source, run `npm ci` / `npm run build`. For the complete ZIP, built assets are supplied. Do not apply earlier stage/task patches on top.
7. If the launcher was never installed, run `Setup-Nearby-Cafe.bat` once. For an existing working launcher installation, this source-only ownership fix does not require service reinstallation. Keep the path and service configuration unchanged. If the path or Windows account changes, the developer must rerun setup deliberately.
8. Start the desktop shortcut. Sign in using the existing account, check stock and recent sales, and run preflight:

   ```powershell
   & "C:\xampp\php\php.exe" tools\preflight.php
   ```

   Verify receipt/VAT, pending-order reconciliation, reports, exports and backup access before handover. Retain the backup until acceptance passes.

**Origin preservation:** keep the exact previous scheme/host/port and browser profile. Default launcher URL is `http://localhost:5174`, matching the development frontend. A tablet's IP-based URL has its own storage. Switching to `127.0.0.1`, another port or HTTPS does not transfer pending orders. Reconcile first; do not clear site data as a troubleshooting shortcut.

## If the earlier launcher save failed

The updated launcher preserves atomic replacement using the PowerShell `NullString` argument and cleans temporary files. A failed ownership save leaves services intact and does not claim ownership in memory. A service started before such a failure may remain running but unowned; a later launch treats it as shared and will not stop it. The developer should inspect the exact dedicated service and stop it gracefully through Windows Services only when no one is using it, then perform a fresh launch. Do not edit ownership JSON to claim another process, kill MariaDB, or delete database files.
