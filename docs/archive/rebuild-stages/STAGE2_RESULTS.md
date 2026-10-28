# Stage 2 verification — core sales and inventory

Date: 19 October 2026 (Asia/Manila). Scope: Stage 2 of the full rebuild, on top of Stage 1. Tests use a disposable `venuevue_rebuild_test` database and generated test accounts/data, never client records.

## Milestone result: passed in the test environment

Owner creates ingredient → product → size/temperature variant → explicit recipe → delivery containers. Barista selects a drink, enters cash, confirms the sale and receives a stored VAT receipt. Owner sees the corresponding stock deduction, movements and updated daily sales totals.

| Gate | Observed result |
|---|---|
| Foundation regression | Fresh install, safe rerun, missing config/database/owner diagnostics, login/logout, throttling, expired/deactivated sessions and two-role API enforcement passed. |
| Stage 1 upgrade | Foundation-only database migrated to version 2 with both users and existing sessions preserved; repeat migration passed. |
| Owner maintenance | Product, variant, ingredient, explicit recipe and stock-receiving API checks passed; browser created each through the actual UI. |
| Cash and VAT example | 2 × ₱150 Latte + 1 × ₱120 Americano produced ₱420 gross, ₱375 net, ₱45 VAT, ₱80 change from ₱500. |
| Exact deduction | 2 × 220 mL from two 1000 mL cartons left 1560 mL: 1000 sealed + 560 opened. Exactly one carton received its opening timestamp and 48-hour use-by. |
| Validation | Changed price, underpayment, missing recipe, fractional countable units and insufficient shared ingredients rejected without sale/stock writes. |
| Atomic rollback | A deliberately failing final audit insert rolled back sale header, items, movements, opening metadata, containers, batches and cached stock. The same request succeeded after removing the injected fault. |
| Duplicate protection | Identical sale and delivery retries returned the original result without another stock mutation. Reusing a key with altered data or a different sale owner was rejected. |
| Expiration | Opened-first/FEFO selection passed. Expired batches and overdue opened containers were excluded; stock expiring on the sale date remained eligible. |
| VAT snapshots | Half-up rounding and final-line residual matched invoice totals; saved prices/VAT survived later menu price changes. Barista could not view another user's receipt. |
| Lost response in browser | Host committed checkout; the response was deliberately dropped. Reload retained the submitted request in IndexedDB. Manual retry returned the original sale ID and correct receipt. |
| UI regression | Invalid login feedback, owner navigation, Barista redirect, loading/empty states, desktop/tablet/mobile overflow checks and zero browser JavaScript errors passed. Desktop Register, Management and stock screenshots were inspected. |
| Invariants | Ingredient cache = batch totals = stock movement sums; batch total = container sum = sealed + opened; line gross/net/VAT sums matched saved sale totals. |
| Build and syntax | Vite production build and PHP syntax checks passed. |

## Runtime used

- Linux container; PHP 8.3.6 with PDO MySQL.
- MariaDB 10.11.14 / InnoDB on isolated port 3307.
- Node.js 24.19.0; pinned project dependencies.
- Playwright driving Chromium 134.0.6998.35; actual PHP API and MariaDB, not mocked domain responses. The browser test deliberately intercepts only the lost-response scenario.

Run `npm run build`, then the isolated `tests/integration.py` harness with `VV_BROWSER_TEST=1`; see README for runtime overrides. `stage2_cases.py` is invoked by that harness, not run directly. The SQL test client explicitly uses Manila time so session/date tests are independent of the machine's default timezone.

## Remaining gates and boundaries

- Windows/XAMPP, native MySQL 8, physical tablet/LAN and client UAT are **not yet verified**. Follow the documented preflight and first-sale walkthrough on the actual host.
- Stage 3: multi-client concurrency and broader interruption/reconnection tests, automatic pending-order submission, durable order history/review and secure account recovery/reset. Stage 2 persists one submitted checkout per account and supports manual retry; it does not persist an ordinary unsent basket or load the application with the local host unavailable.
- Stage 4: waste and authorized adjustment entry, complete audit/history/report filtering, Z-reading/period reports, CSV exports, staff screens and manual backup/restore. Stage 2 shows expired stock alerts but does not record disposal through the UI.
- No legacy-database import or destructive schema replacement. No client recipes/prices/data were invented or seeded. Menu inputs used in tests are fixtures only.
- Stored per-container use-by timestamps remain fixed when the ingredient's shelf-life setting changes. Runtime depends on the host's correct Manila date/time.
- The application is still an academic development build. These results do not claim real-device acceptance or tax-system accreditation.
