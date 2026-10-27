# VenueVue (Nearby Café) — Antigravity Workspace Context & Agent Rules

## 1. Project Identity & Academic Baseline
- **Official Title**: *VenueVue: An Internet-Independent, Locally Networked, Offline-First Point-of-Sale and Recipe-Driven Inventory System for Nearby Café*
- **Client / Café Brand**: **Nearby Café** (coordinated in Tondo, Manila; formerly referenced as *TravelBean MNL* in the initial September 2026 planning baseline)
- **Course & Institution**: COSC 75: Software Engineering II — Cavite State University (CEIT), Indang, Cavite
- **Team (`CTRL + STAY`)**:
  - **Zaki Zapico** — Project Manager / Lead Developer (220 planned hours)
  - **Jenny Lyn Roquid** — Systems Analyst (170 planned hours)
  - **Rhea Mae Gregorio** — QA & Testing Specialist (150 planned hours)
- **Total Planned Effort**: 540 team hours across WBS 1.0–10.0 (includes 24-hour correction reserve in WBS 8.0: Zaki 8h, Jenny 4h, Rhea 12h)
- **Target Completion & Handover Date**: November 20, 2026
- **Base Client Budget**: ₱3,520 local deployment baseline (2 representatives × 4 visits × ₱400 round-trip Indang, Cavite ↔ Tondo, Manila transport = ₱3,200 + ₱320 10% contingency; ₱0 student labor/licensing/local hosting). Optional online PHP/MySQL HTTPS hosting is a modular add-on outside the local base budget.
- **GitHub Repository**: `https://github.com/zakizap2134/venuevue_1`
  - **Baseline Commit**: `ac70e70e667c26d10c58056bbcaa8c7f4d28bd37`
  - **Integration Branch**: `integration/nearby-cafe-complete-update` (head commit `e80e8b88163501683e336e6ba820b4fbb65e6036`, packaged in `NearbyCafe_Integration.bundle`)

---

## 2. Authoritative Directory & File Map
All active development and deployment live inside `rebuild/` (never modify obsolete root prototypes if present):
- **Backend PHP Engine (`rebuild/app/`)**:
  - `@rebuild/app/bootstrap.php` — Runtime initialization, session/CSRF handling, database connection, private storage resolution
  - `@rebuild/app/auth.php` — Role-based authentication (`Owner` and `Barista`), session tokens, reauthentication guards
  - `@rebuild/app/owner_setup.php`, `@rebuild/app/browser_recovery.php`, `@rebuild/app/recovery.php`, `@rebuild/app/recovery_preparation.php` — Guarded initial Owner setup, staff password resets, and single-use Owner recovery codes
  - `@rebuild/app/catalogue.php`, `@rebuild/app/management.php` — Products, active variants (size/temperature/SKU/price), explicit recipe rows, ingredient thresholds, and CRUD operations
  - `@rebuild/app/stock.php` — Batch inventory, FEFO (First-Expired, First-Out) + opened-container priority deduction, expiry/use-by eligibility, waste, and authorized adjustments
  - `@rebuild/app/checkout.php` — Atomic cash checkout, 12% VAT-inclusive extraction and rounding reconciliation, idempotency key validation, and row-locked stock deduction
  - `@rebuild/app/reports.php` — Historical stored gross/net/VAT receipts, daily/period summaries, and CSV export
  - `@rebuild/app/backup.php` — Owner-triggered encrypted backup and restore with host finalization
  - `@rebuild/app/values.php` — Strict input validation, fixed-precision monetary/quantity helpers, and password policy validation (12–72 UTF-8 bytes, >=12 Unicode code points, NUL rejection)
- **Database Schema (`rebuild/database/`)**:
  - `@rebuild/database/001_foundation.sql` through `@rebuild/database/004_management.sql` (Schema v4, MariaDB/MySQL InnoDB)
- **React Frontend (`rebuild/src/`)**:
  - `@rebuild/src/main.jsx`, `@rebuild/src/api.js`, `@rebuild/src/checkout-store.js`, `@rebuild/src/order-sync.js`, `@rebuild/src/password-policy.js`
  - `@rebuild/src/screens/` & `@rebuild/src/components/` — Register POS screen, Management tabs, Account Setup/Recovery modals, and Nearby Café theme (`@rebuild/src/style.css`)
- **Windows One-Click Launcher (`rebuild/launcher/` & `rebuild/*.bat`)**:
  - `@rebuild/Setup-Nearby-Cafe.bat`, `@rebuild/Start-Nearby-Cafe.bat`, `@rebuild/Stop-Nearby-Cafe.bat`
  - `@rebuild/launcher/NearbyCafe.ps1`, `@rebuild/launcher/Windows.psm1`, `@rebuild/launcher/Stack.psm1`, `@rebuild/launcher/Setup.Helpers.psm1`, `@rebuild/launcher/Install.ps1`
- **CLI & Operational Tools (`rebuild/tools/`)**:
  - `@rebuild/tools/preflight.php`, `@rebuild/tools/install.php`, `@rebuild/tools/recover-owner.php`, `@rebuild/tools/prepare-owner-setup.php`, `@rebuild/tools/support-reset-owner.php`
- **Project Documentation (`docs/` & `Docu/`)**:
  - `@Docu/VenueVue_NearbyCafe_Project_Plan.docx` — Finalized COSC 75 Project Plan (zero `[TO CONFIRM]` tags, 540h WBS, hardware/latency specs, optional online hosting retained)
  - `@docs/INSTALLATION.md`, `@docs/INTEGRATION.md`, `@docs/PUBLISHING.md`, `@docs/VERIFICATION.md`, `@docs/OWNER_GUIDE.md`, `@docs/TROUBLESHOOTING.md`

---

## 3. Core Architectural & Domain Invariants (Always Enforce)
1. **Deployment Modes & Authority**:
   - **Primary Local LAN Mode**: Runs on a Windows 10/11 host PC (XAMPP Apache 2.4, PHP 8.2.12+, MariaDB 10.4.32+ InnoDB) serving up to 3 concurrent tablet/browser clients over local Wi-Fi (802.11ac/ax) with **≤ 2.0s (p95) confirmation latency** and zero WAN/internet requirement.
   - **Optional Online Mode**: Uses a cloud-hosted PHP 8.2+ API and MariaDB/MySQL InnoDB database over HTTPS (**≤ 3.0s confirmation latency**).
   - **Single Authoritative Database**: Local and online modes do **not** auto-synchronize independent databases. Never claim automatic cloud-to-LAN database merging or fresh cold page loads while offline.
2. **Inventory & Recipe Deduction Rules**:
   - Every sellable product has explicit active variants (`size`, `temperature`, `SKU`, `price`) with explicit recipe ingredient rows (`grams`, `millilitres`, `pieces`).
   - Stock deduction is strictly **atomic** inside an InnoDB transaction with consistent row-locking order (`SELECT ... FOR UPDATE`). If any ingredient lacks sufficient eligible stock, reject the entire sale with zero partial writes.
   - **Eligibility & Prioritization**: Exclude exhausted/quarantined batches, past-expiration batches (batches expiring today remain eligible until end of day `23:59:59`), and opened containers past their `use_by` timestamp. Deduct from **eligible opened containers first**, then sealed batches, ordered by earliest expiration/use-by timestamp (FEFO) using a single frozen transaction timestamp.
3. **12% VAT & Monetary Precision**:
   - All menu prices are **12% VAT-inclusive**. Extract net and VAT server-side, store sale-time rates and amounts, and reconcile rounding so line totals and receipt totals match to the centavo. Never claim statutory BIR accreditation.
4. **Offline Pending Queue & Idempotency**:
   - Browser clients persist drafts and account-bound pending orders in **IndexedDB** (`@rebuild/src/order-sync.js`).
   - Every checkout request carries a stable client transaction key and payload fingerprint. Repeated submissions with the same key return the original committed sale without duplicating sales or ingredient deductions; conflicting payloads under the same key are flagged for review.
5. **Security & Password Policy Parity**:
   - Both PHP (`@rebuild/app/values.php`) and JavaScript (`@rebuild/src/password-policy.js`) enforce identical password rules: **at least 12 Unicode code points** and **at most 72 UTF-8 bytes**, rejecting NUL bytes (`\0`), requiring exact confirmation match, and never trimming leading/trailing whitespace.
   - Private runtime config (`rebuild/config/local.php`) and `rebuild/storage/` must never be committed to Git or exposed over HTTP.
6. **Windows PowerShell 5.1 Compatibility**:
   - In `@rebuild/launcher/Windows.psm1`, always use `[NullString]::Value` (never `$null` or `""`) as the third argument to `[System.IO.File]::Replace(tempPath, destPath, [NullString]::Value)` so Windows PowerShell 5.1 (`5.1.26100.x`) does not throw an empty-backup-path exception.
   - Preserve UTF-8 with BOM on Windows PowerShell `.ps1`/`.psm1` scripts that contain non-ASCII characters (such as `Nearby Café` or em-dashes).

---

## 4. Verification & Safe Upgrade Commands
- **Safe Upgrade Rule**: When upgrading an existing shop installation, copy only `rebuild/` code/assets while preserving `rebuild/config/local.php`, `rebuild/storage/`, MariaDB data files, and browser IndexedDB site data. Never run destructive fresh-install SQL over a live shop database.
- **Non-Destructive Verification Commands** (run from `rebuild/`):
  ```powershell
  npm ci
  npm run build
  node tests/password-policy.mjs
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\launcher-persistence.ps1
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\launcher-orchestration.ps1
  ```
- **Remaining Milestones Before Nov 20, 2026 Handover**:
  1. Confirm native Windows PowerShell 5.1 execution of `Start-Nearby-Cafe.bat` and `Stop-Nearby-Cafe.bat` on the target Windows host (PHP 8.2.12 / MariaDB 10.4.32).
  2. Test 3-device concurrent LAN checkout and offline reconnection recovery on physical tablets/browsers.
  3. Complete SRS traceability alignment with Jenny and execute User Acceptance Testing (UAT) and backup/restore rehearsal with Rhea and the Nearby Café Owner.
