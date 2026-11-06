# Nearby Café — Installation and Deployment Guide

This guide covers both deployment pathways for Nearby Café (VenueVue):
1. **Cloud Web Deployment**: Deploying online to shared web hosting (e.g. InfinityFree, cPanel, or Apache) with MariaDB/MySQL, HTTPS, and Gmail SMTP email recovery.
2. **Offline Local POS Deployment**: Installing locally on a dedicated Windows laptop running XAMPP 8.2+ for shop Wi-Fi counter sales.

---

## File & Folder Locations Reference

| Component / Artifact | Path / Location | Description |
|---|---|---|
| **Cloud Deployment Package** | `Desktop\venuevue-nearbycafe.zip` | Release ZIP containing built frontend, backend modules, `.htaccess`, and database schema. |
| **Web Root on Hosting** | `htdocs/` (or `public_html/`) | Public web root on the shared host. |
| **Database Connection Config** | `htdocs/config/local.php` (or `rebuild/config/local.php`) | Database connection credentials (`host`, `name`, `user`, `password`). Never committed to Git. |
| **SMTP Configuration** | `rebuild/storage/smtp.json` | Web-managed SMTP credentials saved automatically from the Management dashboard. |
| **Server Outbox Log** | `rebuild/storage/mail-outbox.log` | Private server-side log of dispatched reset codes for admin auditing. |
| **Product Images** | `rebuild/storage/uploads/products/` | Uploaded product photos, served securely via `api/index.php?route=product/image`. |
| **1-Click Local Installer** | `rebuild\One-Click-Setup.bat` | Automated 1-click setup script for Windows local host laptops. |
| **1-Click Local Uninstaller** | `rebuild\Uninstall-Nearby-Cafe.bat` | Automated service/shortcut uninstaller and optional database reset tool. |
| **Daily Launcher Shortcut** | `Desktop\Nearby Café.lnk` | Starts, opens, and stops Nearby Café services on local Windows installs. |

---

## Part 1: Cloud Web Deployment (InfinityFree / cPanel / Shared Host)

### Step 1: Upload the Deployment ZIP
1. Open your hosting **Control Panel** (e.g. InfinityFree vPanel or cPanel) and open **File Manager**.
2. Navigate inside the **`htdocs`** (or `public_html`) directory.
3. Click **Upload** $\rightarrow$ **Upload Zip** $\rightarrow$ select **`Desktop\venuevue-nearbycafe.zip`**.
4. The File Manager unpacks all application files, including:
   - Root files: `index.html`, `favicon.ico`, `.htaccess`, and `assets/` (bundled JS & CSS)
   - Application directories: `app/`, `config/`, `database/`, `storage/`, and `api/`

### Step 2: Configure MySQL Database Credentials
1. In your hosting control panel, open **MySQL Databases** and note:
   - **MySQL Host** (e.g., `sql305.infinityfree.com`)
   - **Database Name** (e.g., `if0_43331399_venuevue`)
   - **MySQL Username** (e.g., `if0_43331399`)
   - **Password** (your vPanel / hosting password)
2. In File Manager, edit **`htdocs/config/local.php`**:
   ```php
   <?php
   declare(strict_types=1);

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
3. Save the file. When anyone visits the website, VenueVue automatically runs database schema migrations (`ensure_void_and_image_schema`).

### Step 3: Complete First-Time Web Onboarding
1. Open your website domain in your browser: `https://your-domain.com`.
2. Because the database is brand new, the **Welcome to Nearby Café** setup screen (`/#/setup`) opens automatically:
   - Enter your **Shop Name** (e.g., *Nearby Café*).
   - Enter your **Owner Display Name** and **Username**.
   - Enter your **Owner Recovery Email** (e.g., `yourname@gmail.com`).
   - Choose a password with at least 12 characters.
3. Click **Create store & start workspace**. You are logged in immediately as the Store Owner.

### Step 4: Configure Gmail SMTP for Live Email Delivery
1. Go to **Management → Account security**.
2. Under **Email Delivery & Gmail SMTP Setup**:
   - **Sender Gmail**: Enter your Gmail address (e.g., `nearbycafe.pos@gmail.com`).
   - **Google 16-Character App Password**:
     1. Open [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords) (ensure 2-Step Verification is ON in your Google Account).
     2. Create an App Password with the name *Nearby Café*.
     3. Copy the 16 letters and paste them into the form.
   - Enter your current Owner password.
   - Click **Connect & Test Email Delivery**.
3. VenueVue connects directly to Google SMTP, verifies the handshake, saves the settings in `storage/smtp.json`, and sends a live test verification code to your inbox!

---

## Part 2: Offline Windows 1-Click Setup (Local POS)

For running Nearby Café locally on a shop laptop using XAMPP:

### Quick Start:
1. Ensure full **XAMPP 8.2+** (Apache, PHP 8.2+, MariaDB) is installed at `C:\xampp`.
2. Extract or clone VenueVue to `C:\Projects\NearbyCafe` so the application folder is at `C:\Projects\NearbyCafe\rebuild`.
3. Open `C:\Projects\NearbyCafe\rebuild` in File Explorer and double-click **`One-Click-Setup.bat`** (or right-click and choose **Run as administrator**).
4. Accept the Windows Administrator (UAC) prompt:
   - Verifies XAMPP installation (`C:\xampp`) and database options (`C:\xampp\mysql\bin\my.ini`).
   - Creates `C:\Projects\NearbyCafe\rebuild\config\local.php` (pointing to `127.0.0.1:3306`, database `venuevue_rebuild`).
   - Registers dedicated Windows background services (`NearbyCafeWeb` and `NearbyCafeDatabase`) and configures the shop Wi-Fi firewall rule for port `5174`.
   - Starts MariaDB and provisions the schema.
   - Creates the **Nearby Café** desktop shortcut (`Desktop\Nearby Café.lnk`).
   - Opens `http://localhost:5174` in your browser.
5. In the browser, complete the **Welcome to Nearby Café** setup form to create your Owner account.

---

## How to Uninstall or Reset for Clean Testing (`Uninstall-Nearby-Cafe.bat`)

To reset a local installation or test a clean setup from scratch:
1. Open `C:\Projects\NearbyCafe\rebuild` in File Explorer.
2. Double-click **`Uninstall-Nearby-Cafe.bat`** and accept the Administrator prompt.
3. When prompted:
   - Choose **Yes** to delete the database (`venuevue_rebuild`) for a 100% clean slate.
   - Choose **No** to keep your sales and products while removing only the services and Desktop shortcut.

---

## Upgrades and Updates

When updating an existing installation:
1. Preserve `config/local.php` and `storage/` (which contains your product photos and SMTP credentials).
2. For cloud hosting: upload the new `venuevue-nearbycafe.zip` over `htdocs`.
3. For local installations: pull the latest Git changes and run `npm run build` inside `rebuild/`.
4. Hard-refresh your browser (`Ctrl + Shift + R`) to load the new frontend assets.
