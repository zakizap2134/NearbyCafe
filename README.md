# Nearby Café — a VenueVue Project by CTRL+Stay

A locally hosted and cloud-deployable point-of-sale and recipe-driven inventory workspace built for **CTRL + STAY · COSC 75 / Software Engineering II**. React 18, Vite, and React Router provide the responsive interface; PHP 8.2+ (PDO) and MariaDB/MySQL manage accounts, atomic recipe inventory, sales, and audit security.

> [!TIP]
> **Using another laptop, phone, or tablet?**  
> Read the complete **[Multi-Device Setup & Access Guide](MULTI_DEVICE_GUIDE.md)** for instructions on accessing live cloud production or setting up on a new device.

---

## Quick Access Summary

| Environment | Access Link | Credentials | Status |
| :--- | :--- | :--- | :--- |
| **Live Cloud Production** | **[nearbycafe.infinityfreeapp.com](https://nearbycafe.infinityfreeapp.com)** | User: `zakizap`<br>Pass: `callmepapaton2x` | **ONLINE (Active)** |
| **Local Café Counter** | `http://127.0.0.1:5174` | User: `zakizap`<br>Pass: `callmepapaton2x` | **100% Offline-Ready** |
| **Hybrid Sync Engine** | `sync-engine/` | Autonomous Playwright/Edge | **Active (Outbox Bridge)** |

---

## Documentation Map

- **Multi-Device & Setup**: **[Multi-Device Access Guide](MULTI_DEVICE_GUIDE.md)** · [Installation & Upgrades (Cloud & Local)](docs/setup/INSTALLATION.md) · [Owner Guide & Wi-Fi Hardening](docs/setup/OWNER_GUIDE.md) · [Windows Launcher Setup](docs/setup/WINDOWS_LAUNCHER_SETUP.md) · [Start Here](docs/setup/START_HERE.md)
- **Offline Sync Engine**: [Sync Architecture](sync-engine/docs/ARCHITECTURE_DESIGN.md) · [Defense Presentation Points](sync-engine/docs/DEFENSE_TALKING_POINTS.md) · [Sync Engine Overview](sync-engine/README.md)
- **Operations & Recovery (`docs/operations/`)**: [Account Recovery & Email SMTP](docs/operations/RECOVERY.md) · [Backup, Restore & Outage Recovery](docs/operations/BACKUP_RESTORE.md) · [Handover Guide](docs/operations/HANDOVER.md) · [Demo Script](docs/operations/DEMO_SCRIPT.md) · [UAT Acceptance](docs/operations/ACCEPTANCE.md)
- **Academic & Verification (`docs/academic/`)**: [Verification Report](docs/academic/VERIFICATION.md) · [Integration Review](docs/academic/INTEGRATION.md) · [Publishing Guide](docs/academic/PUBLISHING.md)
- **Historical Archive (`docs/archive/rebuild-stages/`)**: [Full Index](docs/README.md#4-historical-rebuild-archive-docsarchiverebuild-stages)

---

## What It Does

- **Owner/Admin Role**: Full access to Register and the unified Management workspace (Products, Variants with images, Explicit Recipes, Ingredients, Delivery Batches, Waste/Count Adjustments, Staff Management, Audit History, Sales Reports with Z-reading and CSV export, and Encrypted Backup/Restore).
- **Barista Role**: Register access and personal pending orders. Management is strictly restricted by server-side authorization.
- **Recipe-Driven Inventory**: Atomic deductions on completed sales, tracking batches, expiration dates, and opened-container use-by hours.
- **Resilient Pending Orders**: Unconfirmed sales survive browser reloads and reconnect for completion without double-charging cash.
- **Cloud Web Onboarding**: Browser-based first-time owner initialization at `/#/setup` on a fresh database without requiring server terminal access.
- **Interactive Gmail SMTP Setup**: Configure and live-test Google SMTP directly inside **Management → Account security** using a 16-character Google App Password with zero FTP editing.
- **Secure 6-Digit Email Recovery**: Lost passwords are reset via a 15-minute 6-digit verification code sent strictly to the owner's private email inbox. Codes are **never exposed on-screen**, preventing unauthorized account takeovers.

---

## Deployment & File Locations Reference

| Component / Artifact | Path / Location | Purpose |
|---|---|---|
| **Cloud Deployment ZIP** | `Desktop\venuevue-nearbycafe.zip` | Complete, pre-built deployment package ready to upload to `htdocs` on InfinityFree or cPanel. |
| **Cloud Public Directory** | `htdocs/` | Root web folder containing `index.html`, `assets/`, `.htaccess`, and `api/index.php`. |
| **Application Logic** | `rebuild/app/` (or `htdocs/app/`) | Core PHP backend modules (`auth.php`, `browser_recovery.php`, `management.php`, `reports.php`). |
| **Database Connection Config** | `rebuild/config/local.php` | MariaDB/MySQL connection settings (`host`, `name`, `user`, `password`). Never committed to Git. |
| **SMTP Configuration** | `rebuild/storage/smtp.json` | Web-managed SMTP credentials saved automatically when configured in the Management UI. |
| **Server Outbox Audit Log** | `rebuild/storage/mail-outbox.log` | Private server-side log of dispatched reset codes for admin troubleshooting. |
| **Product Images** | `rebuild/storage/uploads/products/` | Uploaded product photos served securely through `api/index.php?route=product/image`. |
| **Local 1-Click Installer** | `rebuild\One-Click-Setup.bat` | Automated offline installer for Windows host laptops with XAMPP. |
| **Local 1-Click Uninstaller** | `rebuild\Uninstall-Nearby-Cafe.bat` | Automated uninstaller and database reset tool for testing clean installations. |

---

## Deployment Option 1: Cloud & Online Shared Hosting (e.g. InfinityFree / cPanel)

### Step 1: Upload the Release ZIP
1. Open your hosting **Control Panel** (e.g., InfinityFree vPanel or cPanel) $\rightarrow$ open **File Manager**.
2. Navigate inside the **`htdocs`** (or `public_html`) folder.
3. Click **Upload** $\rightarrow$ **Upload Zip** $\rightarrow$ select **`Desktop\venuevue-nearbycafe.zip`**.
4. The File Manager will automatically unpack all files into `htdocs`.

### Step 2: Configure Database Credentials
1. In your hosting control panel, open **MySQL Databases** and note your connection details:
   - MySQL Host (e.g., `sql305.infinityfree.com`)
   - Database Name (e.g., `if0_43331399_venuevue`)
   - Username (e.g., `if0_43331399`)
   - Password (your hosting account/vPanel password)
2. In File Manager, edit **`htdocs/config/local.php`** and insert your credentials:
   ```php
   return [
       'db' => [
           'host' => 'sql305.infinityfree.com',
           'port' => 3306,
           'name' => 'if0_43331399_venuevue',
           'user' => 'if0_43331399',
           'password' => 'YOUR_VPANEL_PASSWORD_HERE',
       ],
       'session_hours' => 12,
       'vat_rate' => '0.1200',
       'app_url' => 'https://nearbycafe.infinityfreeapp.com',
   ];
   ```
3. Save the file. The database schema auto-migrates automatically on the first page load.

### Step 3: Complete Initial Onboarding
1. Open your website domain in your browser: `https://your-domain.com`.
2. The initial **Welcome to Nearby Café** setup screen (`/#/setup`) will appear:
   - Enter your **Café / Shop Name**.
   - Set your **Owner Display Name** and **Username**.
   - Enter your **Owner Recovery Email** (e.g., your Gmail address).
   - Choose a secure password (at least 12 characters).
3. Click **Create store & start workspace**. You will be logged in immediately.

### Step 4: Configure Gmail SMTP for Live Email Delivery
1. Go to **Management → Account security**.
2. Scroll to **Email Delivery & Gmail SMTP Setup**:
   - **Sender Gmail Address**: Enter your Gmail address (e.g., `nearbycafe.pos@gmail.com`).
   - **Google 16-Character App Password**: Generate an App Password at [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords) and paste it here.
   - Enter your current Owner password.
   - Click **Connect & Test Email Delivery**.
3. The server tests authentication with Google SMTP and sends a live test verification code to your email inbox!

---

## Deployment Option 2: Offline Windows 1-Click Setup (Local POS)

For running Nearby Café locally on a shop laptop using XAMPP:

1. Install **[XAMPP for Windows (PHP 8.2+)](https://www.apachefriends.org/)** at `C:\xampp`.
2. Extract the project into `C:\Projects\NearbyCafe\rebuild`.
3. Double-click **`One-Click-Setup.bat`** and accept the Administrator prompt:
   - Configures local database `venuevue_rebuild` on `127.0.0.1:3306`.
   - Registers dedicated Windows background services (`NearbyCafeWeb` and `NearbyCafeDatabase`).
   - Opens `http://localhost:5174` in your browser.
4. Create your Owner account and start selling!

To stop the services at the end of the day, use the **Nearby Café** desktop shortcut and click **Stop Nearby Café**. To test a fresh installation from scratch, run **`Uninstall-Nearby-Cafe.bat`**.

---

## Password Recovery & Security Model

Nearby Café enforces strict, modern authentication security:

### How Password Recovery Works:
1. On the login screen, click **Forgot password?** (`/#/forgot-password`).
2. Enter your registered email address and click **Send verification code**.
   - If the email is not registered in the system, an interactive warning appears: *"No account is registered with this email."*
   - If registered, a cryptographically secure 6-digit code is generated and dispatched to your email inbox (valid for 15 minutes).
3. Open your email inbox, copy the 6-digit code, and enter it along with your new password ($\ge 12$ characters).
4. Click **Reset password & sign in**. All previous sessions are revoked, and you are signed in.

### Security Guarantees:
- **No On-Screen Code Display**: Verification codes are **never** rendered on the webpage or returned in API responses. Only a person with access to the private email account can recover the password.
- **Brute-Force & Flood Protection**: Limited to 6 requests per 15-minute window per email/IP.
- **FastCGI Session Token Integrity**: Supports custom `X-Session-Token` headers alongside `Authorization: Bearer` to guarantee seamless logins even when Apache FastCGI strips standard auth headers.
- **Barista Resets**: Baristas contact their Store Owner, who can reset their password directly in **Management → Staff** or **Management → Account security**.

---

## Support & Verification

- **Integration Testing Status**: 29 / 29 automated tests passed (Local backend, Playwright browser E2E, and InfinityFree live production).
- **PHP Version Compatibility**: PHP 8.2+ (tested on PHP 8.2.12 and 8.2.20).
- **Database Engine**: MariaDB 10.4+ / MySQL 8.0+ (InnoDB, `utf8mb4_unicode_ci`).
