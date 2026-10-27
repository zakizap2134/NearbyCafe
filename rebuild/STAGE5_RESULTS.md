# Stage 5 — Nearby Café branding and handover

Verified 19 October 2026, based on Stage 4 commit `089e560`.

## Delivered

- The user-supplied Nearby Café wordmark is included unchanged, with its original red background. The red-and-cream marketing design informed the login grid, colors and typography. No menu data was inferred from the poster.
- Updated login, navigation branding, page title, footer and receipts; VenueVue remains the software name. Removed the development-stage label from the shop-facing footer.
- Warm neutral working surfaces and darker red action controls; supplied logo framed through CSS. Reduced-motion preference respected and disabled controls use a not-allowed cursor.
- `HANDOVER.md`: installation/upgrade distinction, opening/closing routine, sales, stock, staff and recovery.
- `ACCEPTANCE.md`: actual-host/device checks with all results explicitly NOT RUN until witnessed.
- `DEMO_SCRIPT.md`: a 5–7 minute classroom demonstration and concise Q&A points.

## Executed verification

| Check | Result |
|---|---|
| Frontend production build | PASS — Vite build includes a locally served logo asset |
| Stage 1 foundation/setup/roles | PASS |
| Stage 2 checkout/recipes/stock/VAT/rollback | PASS |
| Stage 3 concurrent submissions, queue persistence/reconnection and account recovery | PASS |
| Stage 4 staff, waste/counts, reports/CSV, encrypted preview/restore and failure rollback | PASS |
| Stage 5 brand check | PASS — Nearby Café title and supplied logo load; current UI contains no TravelBean wording |
| Desktop/tablet/mobile checks | PASS — 1440, 768 and 390 px widths; no horizontal page overflow; wide data tables remain scrollable |
| Runtime network/page errors | PASS — branding test recorded no external asset requests or JavaScript page errors |
| Visual review | Login, tablet reports and mobile Register screenshots inspected |

Full suite ended with **`ALL STAGE 1–5 AUTOMATED CHECKS PASSED`**. Test environment: disposable MariaDB 10.11.14, PHP 8.3.6, Chromium 134 via Playwright. No client database was used. The supplied logo copy was checked byte-for-byte against the attachment. `docs/nearby-login-preview.png` is a screenshot of the implemented login page.

## Upgrade compatibility and remaining gate

Stage 5 changes presentation, documentation and test coverage. PHP transaction/API logic, schema, inventory eligibility/deduction, stored VAT, IndexedDB keys and backup/restore format are unchanged from Stage 4. Existing Stage 4 installations need a frontend build and preflight, not a new database migration. Preserve existing configuration and device browser storage.

**Actual Windows/XAMPP, native MySQL, Safari/physical tablets, client menu/recipe approval and acceptance sign-off remain pending.** No new completion percentage, production performance figure or client approval is inferred from the automated results. Complete `ACCEPTANCE.md` before calling the installation accepted for live use.
