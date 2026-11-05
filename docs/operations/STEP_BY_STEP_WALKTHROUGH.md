# Nearby Café · Step-by-Step User Walkthrough
**From Offline Local Counter to Live Cloud Synchronization**

---

## Overview

Nearby Café operates with a **Hybrid Offline-First Architecture**:
1. **Local Mode (`http://127.0.0.1:5174`)**: High-speed counter POS. Works 100% offline with zero internet dependence.
2. **Online Cloud (`https://nearbycafe.infinityfreeapp.com`)**: Cloud backup, remote owner access, and multi-device reporting.
3. **Sync Bridge (`Run_InfinityFree_Live_Sync.bat`)**: The bridge that connects them together automatically.

---

## PART 1: Using the Local System (In the Café)

### Step 1: Open Local POS
1. On your Windows laptop, double-click the **Nearby Café** shortcut on your Desktop (or open your browser to `http://localhost:5174`).
2. The login screen will appear.

### Step 2: Log In as Owner
* **Username:** `zakizap`
* **Password:** `callmepapaton2x`
* Click **Sign in**.
* The top navigation bar will show: `Nearby Café` · `Owner / Admin Zaki/admin`.

### Step 3: Ring Up an Order (POS Cash Register)
1. Click **Register** on the top menu.
2. You will see the active drink catalogue (*Espresso*, *Caramel Macchiato*, *Iced Dalgona Coffee*, *Spanish Latte*, *Dirty Matcha Latte*, *Hot Cafe Americano*).
3. Click on a drink (e.g., **Spanish Latte**):
   * Select size: **M (16oz)**.
   * Select temperature: **ICED**.
   * Click **Add to Order**.
4. The drink will appear in the cart on the right.

### Step 4: Complete the Checkout
1. Review the order total (e.g., ₱145.00).
2. Enter the cash amount given by the customer (e.g., `500.00`).
3. Click **Confirm Payment**.
4. A full official receipt is displayed on screen showing:
   * Net sales & 12% VAT breakdown.
   * Cash tendered and exact change to give the customer.
5. In the background, the atomic recipe engine deducts the exact grams of coffee beans, mL of fresh milk, and condensed milk from your local stock!

### Step 5: Check Inventory & Reports in Management
1. Click **Management** on the top menu.
2. **Overview Tab**: See today's live gross sales, VAT totals, customer count, and transaction log.
3. **Recipes Tab**: View the exact ingredients linked to each variant (19 recipe mappings).
4. **Stock Tab**: Check current stock levels, open containers, and expiration dates.

---

## PART 2: Using the Online / Cloud System (InfinityFree)

### Step 1: Turn On the Cloud Sync Bridge
1. Open the folder:
   ```
   C:\Projects\NearbyCafe\sync-engine\
   ```
2. Double-click **`Run_InfinityFree_Live_Sync.bat`**.
3. A command window will open and display:
   ```
   Launching headless Edge session to establish InfinityFree security handshake...
   [SYNC DOWN] Pulled & Synchronized products & recipes from Cloud to Local.
   [ONLINE - IDLE] InfinityFree Live & In Sync.
   ```
4. **Leave this window open** during café operating hours. It polls every 15 seconds:
   * Any sales made on the local laptop are pushed to the cloud.
   * Any new products or recipes added on the cloud are pulled down to local.

### Step 2: Open the Online Cloud App (Any Device)
You can open this from **this laptop, a phone, tablet, or another computer**:
1. Open your web browser and navigate to:
   ```
   https://nearbycafe.infinityfreeapp.com
   ```
2. Log in with your credentials:
   * **Username:** `zakizap`
   * **Password:** `callmepapaton2x`
3. Click **Sign in**.

### Step 3: View Real-Time Synced Reports Online
1. In the cloud app, click **Management**.
2. Go to **Overview**:
   * You will see the transactions and sales made from your local counter!
3. Go to **Reports**:
   * View the daily Z-reading summary or export sales data as Excel/CSV directly to your phone or remote computer.

### Step 4: Adding a New Product or Recipe Online (Two-Way Sync Test)
Want to add a new drink while away from the shop?
1. On the cloud website (`https://nearbycafe.infinityfreeapp.com`), go to **Management → Catalogue**.
2. Click **Add Product** (or edit an existing drink's price).
3. Save the product.
4. On your café laptop, the **Sync Bridge** automatically detects the new item, downloads it, and updates the local MariaDB database.
5. Refresh your local register at `http://localhost:5174/#/register` — the new drink is ready for your baristas to sell!
