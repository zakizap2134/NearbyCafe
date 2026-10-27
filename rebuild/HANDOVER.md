# Nearby Café · VenueVue handover

Stage 5 / CTRL + STAY / COSC 75 — 19 October 2026

Nearby Café is the current shop identity supplied by the user in this conversation. VenueVue remains the software name. The supplied red-and-cream logo is included unchanged in `src/assets/nearby-cafe-logo.png`; CSS frames its existing canvas. Earlier stage reports retain their historical branding. The marketing poster is a visual reference, not a source for product prices, recipes or operating hours.

## Install or upgrade

Use the `rebuild/` folder. Do not mix it with the older application at the repository root. Preserve `config/local.php`, the database, recovery code and browser site data. Make a current backup before an upgrade.

For an existing Stage 4 installation, Stage 5 needs **no database migration**. From Command Prompt in `rebuild`:

```bat
C:\xampp\php\php.exe tools\preflight.php
npm ci
npm run build
```

Restart the existing server and reload each device using the **same address and browser profile**. The source ZIP also contains stages 1–4. For a fresh installation or an older rebuild schema, follow `README.md` first; the cumulative migration is still `tools/migrate-stage4.php`. Stage 5 does not change schema version 4, backup format, VAT computation or transaction rules.

For LAN use, build the app and configure the Apache Alias to point only to `rebuild/public/` as shown in `README.md`. Verify the actual laptop IP with `ipconfig`; confirm host and tablets open the same stable URL. Keep Apache and MySQL running and prevent host sleep during trading. `START-DEV.bat` is for development. It is not an Apache installer or database reset tool.

## Open the shop

1. Start the host, shop network, Apache and MySQL. Check the host's date/time, since expiration and reports use Manila time.
2. Sign in as Owner/Admin. Review stock alerts and pending deliveries. Record waste for expired/opened-out-of-date stock, and receive actual deliveries in base units.
3. Verify current menu prices and exact recipes for each size and temperature. Never infer recipes from the marketing poster. Activate only sellable variants.
4. Have Baristas sign in on their own accounts. Resolve older pending orders before starting a new shift.

## Take a cash sale

1. Select the drink's size/temperature and quantity. Disabled variants need owner attention for stock or recipes.
2. Enter cash received. Check the total and change; select **Charge → Confirm sale** once.
3. Wait for the confirmed receipt. A pending order is not yet a confirmed sale. Keep any cash already received associated with that order; do not collect it twice.
4. Leave Register open when reconnecting. The saved request retries automatically; use **Retry pending orders** if needed. A review outcome requires reconciliation, not blind resubmission.

## Maintain stock and staff

- **Ingredients / Catalogue / Recipes:** Owner/Admin maintains units, thresholds, shelf life, variants, prices and explicit recipes. Used records should be made inactive; only unused records can be deleted.
- **Stock:** one delivery per expiration/lot. Two 1-litre milk cartons mean `2` containers of `1000 mL`, not two base units.
- **Stock operations:** waste removes a measured quantity; a count correction sets the actual remaining quantity. Enter a reason. Do not change timestamps or receive replacement quantities as an adjustment.
- **Staff:** create or deactivate Barista accounts. Saving an existing account signs that staff member out. Account security provides password resets. Owner recovery uses Forgot password? with the saved offline code; Account security prepares/replaces it. CLI tools remain optional support paths.

## Close the shop

1. Ensure every device's pending/review queue is reconciled. Compare confirmed sales with cash collected and change paid out.
2. Open **Reports**, select the date/period and review gross, VATable sales and VAT. Download CSV if required. The daily summary does not lock the register.
3. Record waste and physical-count differences with reasons. Download an encrypted manual backup and keep its passphrase separately.
4. Sign out. Stop the host only after confirmed operations finish. Do not clear browser storage to solve an error.

## Recovery and support

Read `BACKUP_RESTORE.md` before restoring. A restore replaces records, signs everyone out and sends older queued requests for review. Current owner credentials are retained by in-app restore. Reconcile cash and physical stock before trading again.

When reporting a problem, record the screen/action, time, device/browser, displayed error code and support reference. Do not send passwords, session tokens, recovery codes or unprotected backups. Match the support reference to `storage/app.log`; run preflight for configuration/server errors.

| Responsibility | Team member |
|---|---|
| Host installation, release, API/database and network setup | Zaki Zapico — Project Manager / Lead Developer |
| Client menu/recipe validation, requirements and user walkthrough | Jenny Lyn Roquid — System Analyst |
| Device tests, evidence, regression and acceptance record | Rhea Mae Gregorio — QA & Testing Specialist |
| Client acceptance / approved shop data | Client representative: [TO CONFIRM] |

## Handover gate

Complete `ACCEPTANCE.md` on the actual Windows host and tablets. Passing the automated tests does not substitute for this sign-off. Installation address, client recipes/prices, production credentials, backup custody, printers (if any) and acceptance date remain [TO CONFIRM]. Printing integration is not included in this release.
