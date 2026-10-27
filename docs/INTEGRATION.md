# Integration record — 23 October 2026

## Source and baseline

- GitHub repository: `https://github.com/zakizap2134/venuevue_1`.
- Inspected remote `main`: **`ac70e70e667c26d10c58056bbcaa8c7f4d28bd37`** (`Add README with setup instructions for VenueVue`).
- Integration branch: **`integration/nearby-cafe-complete-update`**.
- Authoritative package: **`NearbyCafe_Windows_Launcher_Complete_Source.zip`**, SHA-256 **`899c1131112d11799b9156c1ff029dcc676e1b9a8e74a2525304c9e4de628b6e`**.
- No essential source file or logo was missing. All package application files were carried forward; the original logo hash is checked by the asset test. Generated build output was rebuilt from the supplied lockfile.
- No applicable `AGENTS.md` was found in the workspace ancestry or repository. The previous checkout had local account/theme changes; it was left untouched. Work took place in a separate clone based on remote `main`.
- The Windows folder `C:\Projects\VenueVue\rebuild` is not mounted here. Its private configuration, service registry and client database were not accessed. This package does not modify them until the developer deliberately installs the update.

## Reconciliation and removals

The remote baseline contained the old root React/PHP application, not the completed rebuild. The supplied rebuild has its own package/lockfile, API, schema, private app directory, tools and launcher. Imports, scripts and API entry points resolve within `rebuild`; none requires the old root runtime.

| Removed from the new tree | Reason |
|---|---|
| Root `src/`, `index.html`, `vite.config.js`, `package.json`, `package-lock.json` | Superseded frontend; all current development/build commands target `rebuild` and its retained lockfile |
| Root `backend/`, `database/`, `.env.example` | Superseded API/schema/configuration model; rebuild uses private `app/`, `config/local.php` and `database/001…004` |
| `RUN-VENUEVUE.bat`, `STOP-VENUEVUE.bat` | Launch the retired root app; replaced by rebuild developer and Owner launchers |
| `previousPrompts/` | Conversation/session exports and duplicate attachments; no runtime dependency, inappropriate as application source |

`Docu/` course documents remain unchanged as historical documents. They may describe earlier branding/architecture and are not deployment instructions. No Git history was rewritten. Removing SQL **source files** from the new repository tree does not run SQL or delete an installed database. The full release is installed by overlaying only `source/rebuild`, not by mirroring the repository onto the live host.

## Changes beyond the package

1. `Save-NearbyState` retains `File.Move` for first creation and atomic `File.Replace` for existing files, supplies `NullString.Value` for the optional backup path, and cleans its temporary file in `finally`.
2. `Save-NearbyOwnership` saves a candidate state before publishing ownership in memory. On failure it reports `OWNERSHIP_SAVE_FAILED`, leaves services intact and does not falsely claim the newly started service. A safe unowned service can remain running; follow the documented support path.
3. New filesystem tests exercise first creation, existing replacement, spaces and failed replacement cleanup. Orchestration tests inject save failures and verify no premature browser launch, false ownership or service stop.
4. Root installation, upgrade, Owner, troubleshooting, publishing and verification guides replace the old root README. Support-reset wording now accounts for the production launcher and retains authorized CLI support.
5. Vite was pinned from 8.3.0 to 8.3.5 after `npm audit` confirmed advisories including [GHSA-587h-7wmj-h5pm](https://github.com/vitejs/launch-editor/security/advisories/GHSA-587h-7wmj-h5pm). The lockfile and frontend were regenerated and affected integration/browser checks rerun. Other direct dependency pins were preserved.
6. `.gitignore` protects runtime/private data; `.gitattributes` preserves Windows script line endings. PowerShell launcher files retain their UTF-8 BOM for Windows 5.1.

No checkout, inventory, VAT, authentication, recovery API, database schema, password validation or pending-order code was changed. No new schema migration is introduced.

## Earlier requirements retained

| Requirement | Actual implementation / evidence |
|---|---|
| Exactly Owner/Admin and Barista | `app/auth.php`, API role checks; role-denial and inactive-account regressions |
| Product/variant/recipe CRUD | `app/catalogue.php`, `src/screens/Management.jsx`; explicit per-variant recipes and prices |
| Cash Charge → confirmed sale | `app/checkout.php`, Register/pending-order UI; receipt and exact stock regression |
| Atomic deductions and idempotency | Checkout transaction, batch/container totals, movements and sale rows; rollback, duplicate and concurrent tests |
| FEFO / expiration / opened stock | `app/stock.php`: opened stock before sealed, then expiration/use-by; single sale timestamp; time-boundary tests |
| Ingredients, receiving, waste and adjustments | Catalogue/inventory APIs and management forms; user/time in movements; expired-stock review |
| Persistent orders and reconnection | IndexedDB queue, account binding, restore epoch, durable original keys; reload/lost-response/same-account tests |
| Owner/staff account setup and recovery | Guarded browser Owner setup, Owner-authorized staff management, browser recovery/preparation and authorized CLI support |
| Password policy | Shared PHP helper plus frontend code-point checks: 12 code points, 72 UTF-8 bytes, NUL/confirmation rejection; existing sign-in preserved |
| VAT-inclusive 12% | Stored line/sale gross/net/VAT, half-up cents and final-line reconciliation; receipt/report/CSV tests |
| Dashboard/audit/reports/CSV | Database-backed APIs, daily and period totals; real-record browser tests |
| Manual backup/restore | Encrypted format, Owner reauthentication, preview and atomic restore; tested only with disposable databases |
| Nearby Café branding/header/icons | Original logo, cream header with compact red logo area, muted actions; local favicon/ICO checks and browser layout regression |
| Windows daily launcher | Dedicated Apache plus configured database, readiness checks, service ownership and safe shutdown; simulated/Linux checks passed, native acceptance remains open |

The recommended opened-shelf-life values are shown as an editable starting guide; no client inventory is seeded automatically. Substitutions, add-ons, discounts, service charges and VAT-exempt sales remain outside the implemented workflow. There is no automatic legacy-root-database migration or bundled PHP/MariaDB runtime installer. Physical shop-device and client acceptance are still required; this is not a claim that every deployment requirement has passed on Windows.

## Publication review

The old root `README.md` exposed demo Owner/Barista login credentials. The old database scripts contain demo/bootstrap data, and `previousPrompts/` contains private conversation exports. These paths are removed from the new tree, but remain in existing Git history. If any deployed account uses those published demo passwords, change its password and revoke affected sessions through authorized account management. Rotate any real credentials reused or disclosed in past conversation exports. No token/private-key pattern was detected by the scoped baseline scan, but that is not proof that the historical conversations contain no sensitive information.

The new tracked tree excludes private local/launcher config, runtime storage/logs, backups, database files, dependencies and generated test artifacts. Synthetic test credentials are explicitly for disposable fixtures, not usable default accounts. Safe config examples and schema source remain. Existing course documents were retained; review permission to publish those documents separately if the repository audience changes.

Git history was neither scrubbed nor force-pushed. A cumulative textual patch of the entire old tree would repeat deleted conversation content and credentials in deletion hunks, so the delivery uses a **baseline-dependent incremental Git bundle** instead. It carries the new commits and new objects, without packaging the removed historical blobs. The complete source ZIP is the simplest installation path and has no `.git` directory.
