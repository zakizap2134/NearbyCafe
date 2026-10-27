# Stage 4 verification — 19 October 2026

Scope: full rebuild Stage 4, based on Stage 3 commit `271835d`. Tests ran against the disposable `venuevue_rebuild_test` database, never a client database. The application remains isolated in `rebuild/`; the previous implementation at the repository root is preserved.

## Implemented

- Owner waste, absolute count adjustments, batch metadata corrections and quarantine, with stock movements, reason, actor and timestamp.
- Durable device-saved stock operations and server idempotency; stale counts cannot overwrite later sales.
- Unused catalogue/variant/ingredient deletion with protection for linked history; staff creation/edit/activation/deactivation and session revocation.
- Database-backed audit/movement history; daily Z-reading and period reports; stored gross/net/VAT in reports, receipts and CSV, with a CSV totals row.
- Owner-authenticated encrypted manual backup; validated preview with replacement counts; stale-preview detection; atomic restore; current owner credential preservation; session revocation and older-request review.
- Cumulative Stage 4 migration, operational instructions and a server-only raw-restore finalization utility.

## Executed checks

| Area | Result and evidence |
|---|---|
| Production frontend | `npm run build` passed. |
| PHP syntax | All 26 PHP files in app, API, tools and tests passed `php -l`. |
| Existing gates | Foundation, setup, role restrictions, Stage 2 sale/stock/VAT workflow and Stage 3 concurrency/reconnection/recovery gates passed again. |
| Migration | Fresh installation and populated rebuild migration/rerun retained accounts, sales, stock and the existing restore epoch. |
| Stock operations | Waste, positive/negative count corrections, stale count rejection, over-received rejection, quarantine, expired waste and preserved opening clocks passed. Duplicate replay did not deduct twice. |
| Stock rollback | A forced failure during the required audit write rolled back the movement, container, batch and ingredient updates. |
| Staff and catalogue | Owner reauthentication, Barista-only account management, deactivation/session revocation, unused deletion and historical-record protection passed. |
| Reports and CSV | Stored gross/net/VAT matched database aggregates. Empty periods, invalid ranges, daily summary, audit/movement filters, CSV totals and formula-leading text escaping passed. |
| Backup validation | Wrong passphrase and ciphertext tampering rejected. Preview rejected inconsistent stock/VAT, missing foreign references, changed owner identity, unexpected columns and impossible dates without replacing records. |
| Restore concurrency | Changes after preview rejected the restore. |
| Restore rollback | An injected failure at the final `BACKUP_RESTORED` audit write left all original rows, sessions and runtime epoch unchanged. |
| Successful restore | Replaced records matched the backup; current owner password/recovery hash survived; staff credentials came from the backup; sessions were revoked; a restore audit event was written. |
| Queue protection | Old/missing-epoch sales and old receiving/stock-operation requests were rejected after restore without changing stock; a new-epoch sale succeeded. |
| Host fallback | Server-only finalization required owner credentials, checked stock/VAT, changed the epoch and revoked sessions. |
| Browser management | Actual UI waste with a lost committed response survived reload and replayed once. Reports, receipt detail, CSV download, staff creation and audit history passed. |
| Browser restore | Actual encrypted download/upload, stale-preview rejection, confirmed restore and sign-out passed. A second device's older pending order required review; a new sale then confirmed successfully. |
| Browser layout | Tablet viewport had no horizontal page overflow. Screenshots were inspected; active-tab hover contrast was corrected. No JavaScript page errors were reported by the browser suite. |

Full suite output ended with **`ALL STAGE 1–4 CHECKS PASSED`**. Tests are in `tests/integration.py`, `stage4_cases.py`, `stage4-browser.mjs` and the prior stage test files. Backup corruption fixtures are generated only by a CLI helper restricted to the disposable test database.

## Test runtime and limits

- PHP 8.3.6 with PDO MySQL/OpenSSL; MariaDB 10.11.14; headless Chromium 134 through Playwright. Frontend packages use the repository lockfile.
- Database tests used independent PHP workers for concurrent checkout. Restore tests used real InnoDB deletes/inserts and injected database failures, rather than mocked storage.
- Automated tests used small synthetic datasets. The configured 8 MiB backup ceiling, larger production datasets, native MySQL 8, Windows/XAMPP, power-loss recovery and actual client tablets still require host acceptance testing. A SQL rollback test does not simulate a disk failure.
- The daily summary is an informational Z-reading; no fiscal close, VAT-exempt transaction, discount, profit analytics or automated backup was added.
- Stage 5 remains final presentation polish, actual host/device acceptance, client data validation and handover. Stage 4 does not mean the entire project has passed client acceptance.

Upgrade and recovery instructions: [README.md](README.md), [BACKUP_RESTORE.md](BACKUP_RESTORE.md), [RECOVERY.md](RECOVERY.md).
