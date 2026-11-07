# Nearby Café · Multi-Device Setup & Access Guide
**VenueVue Café POS & Inventory Management System**  
**Repository:** [github.com/zakizap2134/NearbyCafe](https://github.com/zakizap2134/NearbyCafe)

---

## Quick Reference Summary

| Environment | Access Method | URL / Address | Credentials | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **Cloud Production** | Any browser (Phone / Laptop / PC) | `https://nearbycafe.infinityfreeapp.com` | User: `zakizap`<br>Email: `zapicozaki83@gmail.com`<br>Pass: `callmepapaton2x` | Online management, reports, and remote access from anywhere. |
| **Local Café Counter** | Local host browser | `http://127.0.0.1:5174` | User: `zakizap`<br>Email: `zapicozaki83@gmail.com`<br>Pass: `callmepapaton2x` | Offline-first POS counter, fast barcode/order entry with zero internet lag. |
| **Table-side Mobile** | Phone / Tablet on Café Wi-Fi | `http://<LAPTOP_LOCAL_IP>:5174` | Barista / Owner account | Mobile ordering at customer tables inside the café. |
| **Sync Engine** | Local Windows Daemon | `sync-engine\Run_InfinityFree_Live_Sync.bat` | Autonomous / Headless Edge | Automatically pushes local sales and syncs catalogue/accounts both ways. |

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

### Step 3: Run the Quick Device Setup (Installs Starter Database & Owner)
1. Open PowerShell or Command Prompt in the repository:
   ```powershell
   cd C:\Projects\NearbyCafe\sync-engine
   .\setup_new_device.bat
   ```
2. The automated script will:
   * Connect to your local XAMPP MySQL server at port 3306.
   * Create the database `venuevue_rebuild` if it does not exist yet.
   * Automatically import the complete Nearby Café catalogue (`venuevue-ready.sql`):
     - All 6 coffee items: Espresso, Caramel Macchiato, Dalgona, Spanish Latte, Dirty Matcha, Americano
     - All 9 variants with sizes, temperatures, and prices
     - All ingredients, recipes, and initial inventory batches
   * Set up synchronization tables (`sync_outbox`, `sync_inbox`, `sync_meta`).
   * Provision the `zakizap` Owner account with password `callmepapaton2x`.
   * Create `rebuild/config/local.php` and storage directories.

### Step 4: Run the 1-Click Windows Launcher Setup
1. Navigate into the rebuild folder:
   ```powershell
   cd C:\Projects\NearbyCafe\rebuild
   ```
2. Right-click **`One-Click-Setup.bat`** and choose **Run as administrator** (or double-click it).
3. The installer registers dedicated background Windows services (`NearbyCafeWeb` on port `5174`) and creates the **Nearby Café** shortcut on your Windows Desktop.

### Step 5: Start the Local POS & Automatic Live Sync
1. Double-click the **Nearby Café** desktop shortcut or run `rebuild\Start-Nearby-Cafe.bat`.
2. The app and the background sync bridge will start automatically!
3. Sign in at `http://localhost:5174/#/login` with:
   * **Username:** `zakizap`
   * **Password:** `callmepapaton2x`

---

## Scenario 4: Autonomous Offline-to-Cloud Sync Bridge (Bidirectional)

Nearby Café uses an **Offline-First Hybrid Architecture**:
* **100% Offline Capability**: During normal operations or an internet outage, the counter operates locally with zero network delay.
* **Autonomous Background Operation**: When the app starts, **`Start-NearbySync` runs automatically in the background** without requiring you to manually open any batch files. When the app closes, **`Stop-NearbySync` automatically terminates cleanly** with zero orphaned processes or database locks.

### What Synchronizes Automatically (Two-Way Sync):

1. **Staff & Barista Accounts (Bidirectional)**:
   * **Local $\rightarrow$ Cloud**: When you create or update a staff account in **Management → Staff** on your local laptop, a `STAFF_SAVE` event queues in `sync_outbox`. The background sync bridge pushes it to InfinityFree Cloud so the staff member can also sign in online.
   * **Cloud $\rightarrow$ Local (Vice-Versa)**: If an owner creates a Barista account remotely on the Cloud (`https://nearbycafe.infinityfreeapp.com`), the local sync bridge’s downstream cycle securely pulls the new barista account and its password hash into your laptop's MariaDB. The barista can sign in at the local counter even when offline!
   * *Safety Guard*: Only `BARISTA` accounts sync downstream. The `OWNER` profile (`zakizap`) is safeguarded and cannot be overwritten.

2. **Menu Catalogue & Product Images (Bidirectional)**:
   * Drink items, variants, sizes, prices, and recipe ingredients stay synchronized between local and cloud.
   * Uploaded product photos (such as the Espresso cup image in `storage/uploads/products/`) are automatically synced and deployed to match on both local and cloud.

3. **Transactions & Inventory Depletions (Local $\rightarrow$ Cloud)**:
   * Completed sales, receipts, void operations, and waste adjustments queue locally in `sync_outbox` and sync upstream whenever internet is available.

4. **Customer POS Receipt Presentation & Thermal Isolation**:
   * **Customer-Facing Receipt**: Displays a clean retail layout (`Item | Qty | Price`) using the gross retail price.
   * **VAT Transparency**: Full Philippine BIR-compliant tax summary is presented in the totals block (`VATable Sales`, `12% VAT`, `VAT-Exempt`, `Zero-Rated`, `Total`).
   * **Thermal POS Print Isolation**: When cashiers click **Print receipt**, the CSS print media engine (`@media print`) isolates strictly the 80mm receipt paper slip, completely hiding background panels, buttons, order history, and navigation bars from the physical printer.

---

## Scenario 5: Moving to a Fresh Device or Brand-New Cloud Account

If you replace the shop PC/laptop, or want to deploy to a **new InfinityFree account / new custom domain**, here is how to set it up:

### Choosing the Right Starter Database:

| SQL File | When to Use | What It Contains |
| :--- | :--- | :--- |
| **`venuevue-ready.sql`** *(Recommended)* | Fast deployment with standard café data | Pre-configured catalogue (Espresso, Macchiato, Dalgona, Spanish Latte, Matcha, Americano), recipes, stock batches, and Owner `zakizap` (`callmepapaton2x` / `zapicozaki83@gmail.com`). |
| **`venuevue-brand-new.sql`** | Setting up a completely new custom café | Clean database schema with 0 products and 0 inventory. Launches the interactive first-time store onboarding wizard at `/#/setup`. |

---

### A. Initializing the Database on the New Account

1. In your new InfinityFree vPanel (or cPanel), go to **MySQL Databases** and create a new database (e.g. `venuevue`). Note your:
   * **MySQL Host:** `sqlxxx.infinityfree.com`
   * **Database Name:** `if0_xxxxxxxx_venuevue`
   * **MySQL Username:** `if0_xxxxxxxx`
   * **MySQL Password:** Your hosting vPanel password
2. Click **phpMyAdmin** next to the newly created database.
3. In phpMyAdmin, click the **Import** tab at the top.
4. Click **Choose File**, select **`venuevue-ready.sql`** (or `venuevue-brand-new.sql`), and click **Go**.
   * *Result: Installs the complete schema, catalogue, recipes, inventory, sync tables, and the `zakizap` Owner account in 5 seconds.*

---

### B. Deploying Code to the New InfinityFree Account

1. **Option 1 (Easiest - 1-Click Upload via File Manager)**:
   * In the InfinityFree Control Panel, open the **File Manager**.
   * Navigate inside the **`htdocs/`** directory.
   * Click **Upload** -> select **`venuevue-nearbycafe.zip`** (found on your Desktop or repository root).
   * Right-click the uploaded ZIP and click **Extract**.
   * In `htdocs/config/`, rename `local.example.php` to `local.php` (or edit `local.php`) with your new MySQL details:
     ```php
     return [
         'db' => [
             'host' => 'sqlxxx.infinityfree.com', // Your new MySQL host
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
2. **Option 2 (Automated FTP Deploy Script)**:
   * Run the deployment script from PowerShell:
     ```powershell
     powershell -File C:\Projects\NearbyCafe\rebuild\scripts\deploy_to_cloud.ps1 -FtpUser "YOUR_NEW_IF0_USER" -FtpPass "YOUR_NEW_VPANEL_PASSWORD"
     ```

---

### C. Pointing the Local Sync Engine to the New Cloud

To switch the local POS sync engine to push to your new InfinityFree domain:

1. Double-click **`sync-engine\Configure_Cloud_Account.bat`** (or run in PowerShell):
   ```powershell
   cd C:\Projects\NearbyCafe\sync-engine
   .\Configure_Cloud_Account.bat
   ```
2. Enter your new cloud URL (e.g. `https://yourcafename.infinityfreeapp.com`).
3. Press Enter to confirm your username (`zakizap`) and password (`callmepapaton2x`).
4. That's it! The sync engine automatically updates its target and will push all offline and future transactions to your new cloud account whenever Nearby Café runs.

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
