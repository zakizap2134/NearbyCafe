# Nearby Café · Multi-Device Setup & Access Guide
**VenueVue Café POS & Inventory Management System**  
**Repository:** [github.com/zakizap2134/NearbyCafe](https://github.com/zakizap2134/NearbyCafe)

---

## Quick Reference Summary

| Environment | Access Method | URL / Address | Credentials | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **Cloud Production** | Any browser (Phone / Laptop / PC) | `https://nearbycafe.infinityfreeapp.com` | User: `zakizap`<br>Pass: `callmepapaton2x` | Online management, reports, and remote access from anywhere. |
| **Local Café Counter** | Local host browser | `http://127.0.0.1:5174` | User: `zakizap`<br>Pass: `callmepapaton2x` | Offline-first POS counter, fast barcode/order entry with zero internet lag. |
| **Table-side Mobile** | Phone / Tablet on Café Wi-Fi | `http://<LAPTOP_LOCAL_IP>:5174` | Barista / Owner account | Mobile ordering at customer tables inside the café. |
| **Sync Engine** | Local Windows Daemon | `sync-engine\Run_InfinityFree_Live_Sync.bat` | Autonomous / Headless Edge | Automatically pushes offline local sales to InfinityFree Cloud. |

---

## Scenario 1: Using Another Device Anywhere (Zero Installation)

If you are away from the café laptop and want to check sales, manage stock, or test the system from a **phone, tablet, school desktop, or another laptop**:

1. Open any web browser (Chrome, Safari, Edge, Firefox).
2. Go to: **`https://nearbycafe.infinityfreeapp.com`**
3. On the login screen, enter your credentials:
   * **Username:** `zakizap` (or email: `zapicozaki83@gmail.com`)
   * **Password:** `callmepapaton2x`
4. Click **Sign in**.
5. You will see your account header: **`Owner / Admin · Zaki/admin`**.
6. You now have full access to:
   * **Register:** Ring up orders or simulate transactions online.
   * **Management → Overview:** View net sales, gross revenue, cash drawer status, and stock alerts.
   * **Management → Catalogue:** Add/edit coffee variants, pastries, prices, and recipe links.
   * **Management → Reports:** View Z-readings, daily sales summaries, and export Excel/CSV reports.
   * **Management → Staff:** Manage barista PINs and staff accounts.

> [!NOTE]
> This requires **no software installation**. It works natively on Android phones, iPhones, iPads, MacBooks, Chromebooks, and Windows PCs.

---

## Scenario 2: Connecting Mobile Tablets on the Local Café Wi-Fi

When running the café counter on your main laptop, staff can use phones or tablets to take orders table-side even without an active internet connection:

```
[ Counter Laptop (192.168.1.15:5174) ]
                   │
    ┌──────────────┴──────────────┐
    │     Café Wi-Fi Router       │
    └──────────────┬──────────────┘
                   │
    ┌──────────────┴──────────────┐
    │                             │
[ Server Phone ]           [ iPad / Tablet ]
```

1. Ensure the counter laptop is running Nearby Café (`http://localhost:5174`).
2. Find the laptop's local IP address:
   * Open PowerShell on the laptop and run:
     ```powershell
     ipconfig
     ```
   * Look for `IPv4 Address` under your active Wi-Fi adapter (e.g., `192.168.1.15`).
3. Connect the phone or tablet to the **same Wi-Fi network**.
4. Open the mobile browser and navigate to:
   ```
   http://192.168.1.15:5174
   ```
5. Log in with a Barista account or Owner credentials to begin taking orders!

---

## Scenario 3: Setting Up a Brand-New Laptop or PC from Scratch

If you get a new laptop, change devices, or want a teammate to run the local server on their computer:

### Step 1: Install Required Software
1. **XAMPP for Windows (PHP 8.2+)**: Download and install from [apachefriends.org](https://www.apachefriends.org/). Install at default path `C:\xampp`.
2. **Git for Windows**: Download from [git-scm.com](https://git-scm.com/).
3. **Node.js (LTS version 18 or 20)**: Download from [nodejs.org](https://nodejs.org/).

### Step 2: Clone the GitHub Repository
Open PowerShell or Command Prompt and clone the repository:
```powershell
mkdir C:\Projects
cd C:\Projects
git clone https://github.com/zakizap2134/NearbyCafe.git
```

### Step 3: Run the 1-Click Automated Setup
1. Navigate into the rebuild folder:
   ```powershell
   cd C:\Projects\NearbyCafe\rebuild
   ```
2. Right-click **`One-Click-Setup.bat`** and choose **Run as administrator** (or double-click it).
3. The automated script will:
   * Check your XAMPP installation (`C:\xampp\apache` and `C:\xampp\mysql`).
   * Initialize the MariaDB database `venuevue_rebuild` on port `3306`.
   * Configure `config/local.php` with proper port and session security.
   * Register dedicated background Windows services (`NearbyCafeWeb` on port `5174`).
   * Create the **Nearby Café** shortcut on your Windows Desktop.

### Step 4: Configure the `zakizap` Owner Account & Sync Tables
In PowerShell, run the quick initialization script:
```powershell
cd C:\Projects\NearbyCafe\sync-engine
.\setup_new_device.bat
```
This automatically ensures:
* The `zakizap` Owner account is configured with password `callmepapaton2x`.
* The `sync_outbox`, `sync_inbox`, and `sync_meta` synchronization tables are active.

### Step 5: Start the Local POS
Double-click the **Nearby Café** desktop shortcut or open your browser to:
```
http://localhost:5174/#/login
```
Sign in with:
* **Username:** `zakizap`
* **Password:** `callmepapaton2x`

---

## Scenario 4: Running the Offline-to-Cloud Sync Bridge

Nearby Café uses an **Offline-First Hybrid Architecture**:
* During regular operation or an internet outage, the counter operates 100% locally with zero latency.
* Transactions are saved to the local MariaDB database and queued in `sync_outbox`.
* When internet is connected, the autonomous sync daemon pushes transactions to the InfinityFree cloud.

### Starting the Sync Daemon
To enable automatic background syncing on your laptop:
1. Double-click:
   ```
   C:\Projects\NearbyCafe\sync-engine\Run_InfinityFree_Live_Sync.bat
   ```
2. The daemon will launch a lightweight background Edge session that:
   * Completes the InfinityFree security handshake (`/aes.js`).
   * Polls the local outbox every 15 seconds.
   * Automatically flushes pending transactions to the cloud when online.
   * Gracefully buffers orders when offline, ensuring **zero data loss**.

---

## Scenario 5: Moving to a Fresh Device or Brand-New Cloud Account

If you replace the shop PC/laptop, or want to deploy to a **new InfinityFree account / new custom domain**, here is how to set it up:

### A. Migrating Existing Café Data vs. Starting 100% Clean

* **To preserve your existing products, variants, recipes, stock levels, and sales history:**
  1. On your current/old device, go to **Management → Backup & restore**.
  2. Click **Download Backup (.sql)** and save the file to a flash drive or cloud storage.
  3. On your new device or new cloud database, import that `.sql` file via phpMyAdmin (`http://localhost/phpmyadmin` locally or vPanel phpMyAdmin on InfinityFree).
  * *Result: All your catalogue, recipes, inventory stock, staff accounts, and sales are restored instantly.*
* **To start with a 100% clean/fresh café database:**
  1. Import the clean starter schema from `rebuild/database/schema.sql`.

---

### B. Deploying Code to a New InfinityFree Cloud Account

When creating a new InfinityFree account, you will get a new domain (e.g. `yourcafename.infinityfreeapp.com`), a new username (`if0_xxxxxxxx`), and a new MySQL host (`sqlxxx.infinityfree.com`).

1. **Deploy the frontend and backend files in 30 seconds**:
   Run the automated deploy script from PowerShell:
   ```powershell
   powershell -File C:\Projects\NearbyCafe\rebuild\scripts\deploy_to_cloud.ps1 -FtpUser "YOUR_NEW_IF0_USER" -FtpPass "YOUR_NEW_VPANEL_PASSWORD"
   ```
2. **Configure Database Credentials on the New Cloud**:
   In the new InfinityFree File Manager, edit `htdocs/config/local.php` with your new database connection details:
   ```php
   return [
       'db' => [
           'host' => 'sqlxxx.infinityfree.com', // Found on your InfinityFree MySQL page
           'port' => 3306,
           'name' => 'if0_xxxxxxxx_venuevue',   // Your new database name
           'user' => 'if0_xxxxxxxx',            // Your new username
           'password' => 'YOUR_VPANEL_PASSWORD',
       ],
       'session_hours' => 12,
       'vat_rate' => '0.1200',
       'app_url' => 'https://yourcafename.infinityfreeapp.com',
   ];
   ```

---

### C. Pointing the Local Sync Engine to the New Cloud

To connect your shop PC's offline sync daemon to the new cloud URL:

1. In `sync-engine/config/sync_config.php`, update:
   ```php
   'cloud_api_url' => 'https://yourcafename.infinityfreeapp.com/api',
   ```
2. In `sync-engine/local_daemon/infinityfree_sync_bridge.mjs`, update:
   ```javascript
   cloud_url: "https://yourcafename.infinityfreeapp.com",
   cloud_user: "zakizap",           // Your admin username on the new shop
   cloud_pass: "your_password",     // Your admin password
   ```

---

## Troubleshooting & FAQs

### Q1: Can I use Nearby Café if the internet goes down?
**Yes.** The Local POS server (`http://127.0.0.1:5174`) runs entirely on your local machine using Apache and MariaDB. Cashiers can continue ringing up customers, deducting inventory, and printing receipts. Once internet connectivity is restored, run the sync bridge to push all recorded sales to the cloud.

### Q2: Why did I see "Let's reconnect: The host returned an unreadable response"?
This occurs exclusively on InfinityFree's free tier due to their **`/aes.js` anti-bot security challenge**:
1. When opening the cloud site in a fresh browser session, InfinityFree serves an HTML challenge script to verify you are a human browser before allowing access to the PHP API.
2. If an API request executes before the browser stores the security cookie (`__test`), the API receives the HTML security page instead of JSON, triggering the *"unreadable response"* notice.
3. **How to resolve**:
   * Simply click **"Try again"** or press **Ctrl + F5** (hard refresh). The security cookie is now saved, and the app connects normally.
   * In our latest build, the app detects this challenge automatically and silently refreshes the browser to pass the handshake without interrupting you.

### Q3: Why does the sync bridge use headless Edge/Playwright?
InfinityFree's free hosting tier uses an anti-bot security challenge (`<script src="/aes.js"></script>`) that blocks standard cURL scripts. Our Edge daemon runs the challenge in an automated browser context to acquire the `__test` cookie, enabling seamless API synchronization without paying for premium hosting.

### Q4: What if port 5174 is in use on my new laptop?
If another application is using port 5174, edit `rebuild/tools/launcher-service.ps1` or run:
```powershell
Stop-Service NearbyCafeWeb
```
You can also run the built-in development server:
```powershell
cd C:\Projects\NearbyCafe\rebuild
php -S 127.0.0.1:8080 -t public
```

### Q5: How do I pull the latest changes from GitHub on another device?
Whenever updates are pushed to GitHub, simply run in your project folder:
```powershell
cd C:\Projects\NearbyCafe
git pull origin main
```
