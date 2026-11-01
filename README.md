# Nearby Café a VenueVue Project by CTRL+Stay

A locally hosted point-of-sale and recipe-driven inventory application for **CTRL + STAY · COSC 75 / Software Engineering II**. React, Vite, Tailwind CSS and React Router provide the interface; PHP/PDO and MySQL/MariaDB handle accounts, sales and inventory.

**The current application is in `rebuild/`.** This integration includes rebuild Stages 1–5, account/theme Tasks 1–7, browser recovery Tasks 1–5, the Unicode password policy and Windows launcher. Earlier patches are unnecessary. The old root application has been retired; see [source reconciliation](docs/academic/INTEGRATION.md) and the full [documentation map](docs/README.md).

## Documentation Map

- **Setup & Deployment (`docs/setup/`)**: [Installation & Upgrades](docs/setup/INSTALLATION.md) · [Windows Launcher Setup](docs/setup/WINDOWS_LAUNCHER_SETUP.md) · [Owner Guide & Wi-Fi Hardening](docs/setup/OWNER_GUIDE.md) · [Troubleshooting](docs/setup/TROUBLESHOOTING.md) · [Start Here](docs/setup/START_HERE.md)
- **Operations & Recovery (`docs/operations/`)**: [Backup, Restore & Outage Recovery](docs/operations/BACKUP_RESTORE.md) · [Account Recovery](docs/operations/RECOVERY.md) · [Handover](docs/operations/HANDOVER.md) · [Demo Script](docs/operations/DEMO_SCRIPT.md) · [UAT Acceptance](docs/operations/ACCEPTANCE.md)
- **Academic & Verification (`docs/academic/`)**: [Verification Report](docs/academic/VERIFICATION.md) · [Integration Review](docs/academic/INTEGRATION.md) · [Publishing Guide](docs/academic/PUBLISHING.md) 
- **Historical Rebuild Archive (`docs/archive/rebuild-stages/`)**: [Full Index](docs/README.md#4-historical-rebuild-archive-docsarchiverebuild-stages)

## What it does

- **Owner/Admin** is one role: Register plus Management for products, variants, explicit recipes, ingredients, delivery batches, waste/count adjustments, staff, audit history, reports, CSV and manual encrypted backup/restore.
- **Barista** uses Register and their own pending orders. Management access is enforced by the backend.
- Cash checkout records the cashier, change, stored VAT-inclusive gross/net/VAT amounts, and atomic recipe deductions. Eligible batches follow expiration rules and opened-container use-by times.
- Pending orders survive reloads and reconnect for confirmation or review. A pending order is not a confirmed sale. Sign back into the same account and use the same browser profile and origin.
- Daily operation does not need internet, but **the host laptop and local network must remain available**. A fresh page load requires the host; this is not a service-worker-cached offline app.

## Requirements and evidence

| Requirement | Supported target / evidence |
|---|---|
| Host | Windows 10/11, local NTFS installation outside `htdocs`; native launcher acceptance remains pending |
| XAMPP | Full matching Apache 2.4 + thread-safe PHP 8.2+ + MariaDB installation; keep XAMPP installed |
| PHP | PDO MySQL, OpenSSL, JSON; PCRE Unicode counting is used, so mbstring is not required |
| Database | InnoDB; MariaDB 10.4+ target. MySQL 8 compatibility is expected but not verified in this integration |
| User's reported host | PHP 8.2.12, MariaDB 10.4.32, Windows PowerShell 5.1; preflight and Apache syntax passed before the reported launcher replacement failure |
| Developers only | Node.js 22.12+ and npm; pinned dependency versions and `package-lock.json` are retained |
| Automated verification | Linux PHP 8.3, MariaDB 10.11, Chromium and PowerShell 7.4; see [verification](docs/academic/VERIFICATION.md) for exact scope and limitations |

This is **not** a standalone bundled-runtime installer. Node is unnecessary for daily Owner startup once the frontend is built. No default accounts are shipped. VAT display is not a claim of BIR certification.

## Installation & File Locations Reference

Before installing or running Nearby Café (VenueVue), use this reference table to locate all key installers, configuration files, and runtime folders:

| Item / Component | Recommended Windows Location | Purpose |
|---|---|---|
| **XAMPP Runtime** | `C:\xampp\` | Provides Apache (`apache\bin\httpd.exe`), PHP 8.2+ (`php\php.exe`), and MariaDB (`mysql\bin\mysqld.exe`). Keep installed. |
| **Project Repository Root** | `C:\Projects\NearbyCafe\` | Main Git repository / extracted project folder (placed outside `C:\xampp\htdocs`). |
| **Application Root (`rebuild`)** | `C:\Projects\NearbyCafe\rebuild\` | Active VenueVue POS & Inventory application directory. |
| **1-Click Client Installer** | `C:\Projects\NearbyCafe\rebuild\One-Click-Setup.bat` | **Main installer.** Auto-configures `local.php`, provisions the database, registers services, generates the Owner Setup Code, and opens the browser. |
| **1-Click Uninstaller / Reset** | `C:\Projects\NearbyCafe\rebuild\Uninstall-Nearby-Cafe.bat` | Stops and removes services, deletes Desktop shortcuts/configs, and optionally drops `venuevue_rebuild` for a clean test. |
| **Desktop App Shortcut** | `Desktop\Nearby Café.lnk` | Daily launcher created by setup (`Start` / `Open` / `Stop` shop services without XAMPP Control Panel). |
| **One-Time Owner Setup Code** | `Desktop\NEARBY_CAFE_OWNER_SETUP_CODE.txt` | Generated during fresh 1-Click Setup (valid 24 hours). Delete after creating your Owner account. |
| **Daily Launcher Batch** | `C:\Projects\NearbyCafe\rebuild\Start-Nearby-Cafe.bat` | Alternative to the Desktop shortcut to open the Nearby Café launcher window. |
| **Local Database Config** | `C:\Projects\NearbyCafe\rebuild\config\local.php` | Auto-generated local MariaDB connection settings (`127.0.0.1:3306`, database `venuevue_rebuild`). |
| **Launcher Service Config** | `C:\Projects\NearbyCafe\rebuild\config\launcher.local.json` | Auto-generated Windows service & port configuration (`http://localhost:5174`). |
| **Scoped Apache Config** | `C:\Projects\NearbyCafe\rebuild\config\nearby-httpd.conf` | Auto-generated Apache configuration serving only `rebuild\public\`. |
| **Product Images Storage** | `C:\Projects\NearbyCafe\rebuild\storage\uploads\products\` | Uploaded product photos (served securely through `./api/index.php?route=product/image`). |
| **Launcher Logs & State** | `C:\Projects\NearbyCafe\rebuild\storage\launcher\` | Contains `launcher.log`, `apache-error.log`, and `ownership.json`. |

---

## Fresh Installation Guide (From GitHub to Running POS)

### Step 1: Install XAMPP
1. Download and install **[XAMPP for Windows (PHP 8.2+)](https://www.apachefriends.org/)** into the default directory:
   ```text
   C:\xampp
   ```
2. Ensure **Apache**, **MySQL (MariaDB)**, and **PHP** components are selected during installation. You do **not** need to keep the XAMPP Control Panel open for daily use.

### Step 2: Download or Clone the Project into `C:\Projects\NearbyCafe`
Place the project outside `C:\xampp\htdocs` (recommended path: `C:\Projects\NearbyCafe`).

- **Using Git (Command Prompt or PowerShell):**
  ```cmd
  cd /d C:\Projects
  git clone https://github.com/zakizap2134/venuevue_1.git NearbyCafe
  ```
- **Or using GitHub Download ZIP:**
  Extract the ZIP so that the `rebuild` folder is located at:
  ```text
  C:\Projects\NearbyCafe\rebuild
  ```
  *(Note: If you cloned from Git and `rebuild\public\index.html` is not yet built, install [Node.js 22+](https://nodejs.org/) or use a pre-built release package; `One-Click-Setup.bat` will automatically run `npm ci && npm run build` if needed).*

### Step 3: Run the 1-Click Client Setup (`One-Click-Setup.bat`)
1. Open File Explorer and go to:
   ```text
   C:\Projects\NearbyCafe\rebuild
   ```
2. Double-click **`One-Click-Setup.bat`** and click **Yes** on the Windows Administrator (UAC) prompt.
3. What `One-Click-Setup.bat` does automatically:
   - Verifies XAMPP at `C:\xampp`.
   - Creates `C:\Projects\NearbyCafe\rebuild\config\local.php` (pointing to `127.0.0.1:3306`, database `venuevue_rebuild`).
   - Registers dedicated manual-start Windows services (`NearbyCafeWeb_*` and `NearbyCafeDatabase_*`) and the shop Wi-Fi firewall rule for port `5174`.
   - Starts MariaDB and initializes all database tables (`tools\install.php` and `tools\migrate-stage4.php`) while preserving any existing shop data.
   - Generates your 64-character **One-Time Owner Setup Code**, copies it to your clipboard, and saves it to:
     ```text
     Desktop\NEARBY_CAFE_OWNER_SETUP_CODE.txt
     ```
   - Places the **Nearby Café** shortcut on your Windows Desktop (`Desktop\Nearby Café.lnk`).
   - Starts the web service and opens **`http://localhost:5174`** in your default browser.

### Step 4: Complete Owner Account Setup in Browser
1. In the browser window that opens (`http://localhost:5174`), click **Set up owner account**.
2. Paste (`Ctrl+V`) the setup code from the popup dialog or from `Desktop\NEARBY_CAFE_OWNER_SETUP_CODE.txt`.
3. Enter your **Owner display name**, **username**, and **password** (at least 12 characters).
4. Once signed in, go to **Management → Account security** to generate your permanent offline **Owner Recovery Code** and store it in a safe place.
5. Delete `Desktop\NEARBY_CAFE_OWNER_SETUP_CODE.txt`.

---

## How to Uninstall or Reset for Clean Testing (`Uninstall-Nearby-Cafe.bat`)

If you want to completely remove the installation or reset the laptop to test a brand-new 1-Click installation from scratch:
1. Open File Explorer and go to:
   ```text
   C:\Projects\NearbyCafe\rebuild
   ```
2. Double-click **`Uninstall-Nearby-Cafe.bat`** and accept the Administrator prompt.
3. Choose whether to **delete the database (`venuevue_rebuild`)**:
   - Click **Yes** for a 100% clean reset (wipes the database so your next `One-Click-Setup.bat` run creates a fresh database and new Owner Setup Code).
   - Click **No** if you only want to remove the services/shortcuts while keeping your existing sales, products, and accounts.

---

## Manual Developer Provisioning (Alternative)
If you need custom database credentials or manual CLI provisioning instead of `One-Click-Setup.bat`, follow the [detailed installation guide](docs/setup/INSTALLATION.md):

1. Install XAMPP and provision a genuinely new, separate database using the documented tools. Never run fresh-install steps over client data.
2. Place the application at `C:\Projects\NearbyCafe\rebuild`. Copy `config\local.example.php` to `config\local.php` only if `config\local.php` does not exist; enter actual connection settings.
3. Build the frontend if using Git source (`npm ci && npm run build`). Prepare the one-time initial Owner setup code (`php tools/install.php` and `php tools/prepare-owner-setup.php`), then run `Setup-Nearby-Cafe.bat` once while logged into the Owner's Windows account.
4. Open the desktop **Nearby Café** shortcut. In the browser choose **Set up owner account** and use the initial setup code. After sign-in, prepare an ongoing recovery code under **Management → Account security** and keep it offline. These are two different codes.

There is no public staff registration or third role. The Owner creates Baristas. The initial Owner setup closes after provisioning.

## Upgrade an existing installation

Follow [upgrade instructions](docs/setup/INSTALLATION.md#upgrade-an-existing-rebuild). Back up first, finish active sales, and preserve `config/local.php`, launcher configuration, storage, database files, accounts and browser data. Copy supplied source/build files into the existing folder without mirror/delete operations. **Schema version 4 needs no migration for this integration.** Older rebuild schemas require the existing documented migration after a backup. The original pre-rebuild database has no automatic converter; do not import it into the rebuild schema.

Keep the same scheme, host, port and browser profile while pending orders exist. `localhost`, `127.0.0.1` and the laptop IP are different origins. The launcher default remains `http://localhost:5174`; changing origins does not transfer IndexedDB orders.

## Daily Owner guide: Start → Use → Stop

1. Double-click **Nearby Café** on the desktop. Wait for **Checking setup → Starting database → Starting Nearby Café → Ready**.
2. Use the browser. Repeated clicks reuse the launcher/application; **Open Nearby Café** reopens the page.
3. Finish sales on all devices, choose **Stop Nearby Café**, and wait for confirmation. It stops only services it owns and is allowed to stop. Shared services can remain running.

Closing the launcher window alone leaves services running. Read [the short Owner guide](docs/setup/OWNER_GUIDE.md) for recovery and problem reporting.

## Developer commands

From `rebuild`, in PowerShell:

```powershell
npm ci
npm run build
& "C:\xampp\php\php.exe" tools\preflight.php
```

For development, start the configured database, then use two terminals in `rebuild`:

```powershell
# Terminal 1: PHP API and development router
& "C:\xampp\php\php.exe" -S 127.0.0.1:8081 -t public tools\router.php
```

```powershell
# Terminal 2: Vite frontend at http://localhost:5174
npm run dev
```

`START-DEV.bat` provides the PHP/Vite developer workflow and preflight. It does not provision/start MySQL. The Owner launcher uses a dedicated Apache service and the **built** frontend; it never runs npm or Vite. Stop the Owner launcher before starting Vite on the same port. Do not terminate unrelated listeners to resolve a conflict.

## Tablet / local-network access

Run `ipconfig` on the host and find the active shop Wi-Fi/hotspot IPv4 address. Tablets on that same trusted network open `http://HOST-IP:5174`. The one-time installer creates a scoped Private-profile/LocalSubnet web firewall rule; the developer must verify the network is trusted and marked Private. Do not open the database port to tablets or the public internet. Keep the host awake.

Local HTTP is **not encrypted**. HTTPS certificate provisioning is a separate deployment task if transport confidentiality is required. Internet independence does not remove the need for the host or Wi-Fi. See [Windows setup](docs/setup/WINDOWS_LAUNCHER_SETUP.md) for installer options and device acceptance.

## Account recovery

Owners use **Forgot password? → Owner/Admin recovery** with their username and saved ongoing recovery code. A successful reset revokes Owner sessions, displays a replacement code once, and returns to sign-in. Save the replacement code. If a response is lost, try signing in with the new password; Account security can replace the recovery code after sign-in. Do not assume an interrupted response means no change occurred.

Baristas contact the Owner for an authorized reset. If the Owner loses both password and code, authorized server-access support is required. Never put plaintext into `password_hash`. New passwords require **at least 12 Unicode code points**, no NUL, at most 72 UTF-8 bytes for bcrypt, and matching confirmation. Existing passwords continue to authenticate. See [recovery guide](docs/operations/RECOVERY.md) and [support reset](docs/txt/OWNER_SUPPORT_RESET.txt).

## Troubleshooting and verification

See [troubleshooting](docs/setup/TROUBLESHOOTING.md) for `DB_UNAVAILABLE`, `CONFIG_MISSING`, ports, assets, service permissions, `LAUNCHER_FAILED`, Apache syntax checks, the `File.Replace` fix and cached favicons. See [verification results](docs/academic/VERIFICATION.md) for passed automated checks and remaining Windows/device/client acceptance.

Private configuration, logs, database backups and ownership files are ignored. Git tracks source and lockfiles; generated JS/CSS/index build files are excluded from Git and included in the complete release ZIP. The old conversation export was removed from the new tree, but history was not rewritten. See [publication review](docs/academic/INTEGRATION.md#publication-review) before making the repository public or sharing old history.
