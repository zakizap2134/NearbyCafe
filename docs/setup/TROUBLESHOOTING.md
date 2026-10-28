# Troubleshooting

Run developer commands in the actual `rebuild` folder. Preserve data; setup errors are not a reason to reinstall the database.

**PowerShell**:

```powershell
Set-Location "C:\Projects\VenueVue\rebuild"
& "C:\xampp\php\php.exe" tools\preflight.php
```

**Command Prompt (cmd.exe)**:

```bat
cd /d C:\Projects\VenueVue\rebuild
"C:\xampp\php\php.exe" tools\preflight.php
```

Do not use `cd /d` in PowerShell; `/d` is a Command Prompt option. A quoted executable path in PowerShell needs `&` to execute it.

| Message / symptom | What to check |
|---|---|
| `CONFIG_MISSING` | Confirm existing `config/local.php` is present. For a new install only, copy the example and configure it. Never overwrite an existing private config with the example. |
| PHP parse error in config | Run `& "C:\xampp\php\php.exe" -l config\local.php`; repair PHP array syntax. Do not publish the file to get help. |
| `DB_UNAVAILABLE` | Confirm the configured database service is running and its host/port match the saved settings; check credentials privately. A direct preflight does not start MySQL. The configured Owner launcher should start/reuse it. Never delete InnoDB files or recreate the database. |
| `DB_BUSY` / `DB_IDENTITY` | Another instance or data directory may be in use. Have the developer inspect its executable, defaults file and port. Never start a second instance against the same files. |
| `PORT_BUSY` | Identify the listener; close the known old development server if appropriate. Do not kill unrelated programs. Preserve the app's existing origin. |
| `ASSETS_MISSING` | For Git source, run `npm ci` then `npm run build` in `rebuild`. For the complete package, verify that `public/index.html` and `public/assets` were copied. |
| `SERVICE_ACCESS` | Setup must have granted service rights to the intended Windows account. Rerun the one-time setup under that account with authorized administrator approval; daily launch should not need elevation. |
| `LAUNCHER_FAILED` | Check the safe reference codes in `storage/launcher/launcher.log`. Developer should inspect the failure locally, PHP preflight, service configuration and Apache syntax. Do not upload private config or raw database logs. |
| `OWNERSHIP_SAVE_FAILED` | Check free disk space and write access to `storage/launcher`. Services remain intact. See the failed-save recovery guidance in [installation](INSTALLATION.md#if-the-earlier-launcher-save-failed). |
| `OWNER_MISSING` / registration closed | Use authorized recovery/support for an existing shop. Prepare initial setup only for a genuinely new installation. |
| Schema mismatch | Back up and identify the actual rebuild schema before a documented migration. Do not import the old root POS schema into the rebuild. |
| Tablets: "Server connection failed" | Run `rebuild\tools\maintenance\CHECK-SHOP-NETWORK.bat` on the host (read-only: Private profile, firewall rule, listening port, tablet address). Then follow the router AP-isolation / DHCP-reservation steps in [the owner guide](OWNER_GUIDE.md#shop-wi-fi-keep-the-tablets-connected). |
| After a power cut / forced shutdown | Start Nearby Café, then run `rebuild\tools\maintenance\CHECK-DATABASE-AFTER-OUTAGE.bat` before taking orders. Never delete `ib_logfile*`/`ibdata1` or run `REPAIR TABLE`. See [BACKUP_RESTORE.md](../operations/BACKUP_RESTORE.md#after-a-power-cut). |

Inspect service state (PowerShell; read-only):

```powershell
Get-Service | Where-Object { $_.Name -match 'NearbyCafe|mysql|maria' } |
    Format-Table Status, Name, DisplayName -AutoSize
Get-NetTCPConnection -LocalPort 5174 -State Listen -ErrorAction SilentlyContinue |
    Select-Object LocalAddress, LocalPort, OwningProcess
```

Validate the generated dedicated Apache configuration (PowerShell):

```powershell
& "C:\xampp\apache\bin\httpd.exe" -t -f "C:\Projects\VenueVue\rebuild\config\nearby-httpd.conf"
```

## Confirmed Windows PowerShell 5.1 replacement issue

The earlier `Save-NearbyState` called `[IO.File]::Replace($tmp,$file,$null)`. On the reported host this raised **“The path is not of a legal form.”** The updated source uses:

```powershell
[IO.File]::Replace($tmp, $file, [System.Management.Automation.Language.NullString]::Value)
```

It still performs atomic replacement, removes temporary files on failure, and only publishes in-memory ownership after persistence succeeds. Native Windows 5.1 verification is pending; Linux PowerShell results are not a Windows compatibility certificate.

The developer can run these non-service tests on Windows PowerShell 5.1, from `rebuild`:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\launcher-persistence.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\launcher-orchestration.ps1
```

The filesystem test uses a temporary folder. The orchestration test simulates service adapters; it does not start or stop Windows services. Complete the native acceptance checklist as well.

## Old favicon

In Chrome or Edge press **Ctrl+F5**, close/reopen the tab, then restart the browser if needed. Visit `http://localhost:5174/favicon.ico?v=nearby1` to check the served file. A private window can distinguish caching, but uses separate storage and is not where existing pending orders live. **Do not clear site data or IndexedDB.** Native Chrome/Edge visual acceptance remains pending.
