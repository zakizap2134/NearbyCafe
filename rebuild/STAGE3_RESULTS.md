# Stage 3 verification — reliability and account recovery

Date: 19 October 2026 (Asia/Manila). Stage 3 of the full rebuild, based on Stage 2 commit `edc6ed8`. Only disposable test accounts and `venuevue_rebuild_test` were used; no client data was changed.

## Result: automated gates passed

| Gate | Observed result |
|---|---|
| Stage 1/2 regression | Fresh installation, migration, login/logout, two-role enforcement, owner setup, catalogue/recipe/stock-in, cash/change, VAT snapshots, expiration and transaction rollback passed again. |
| Populated upgrade | Stage 2 database with sales and stock upgraded to schema 3 without changing existing domain records. Rerunning the migration preserved them. |
| Concurrent identical requests | Eight simultaneous requests through multiple PHP workers produced one sale ID, one successful initial commit and seven duplicate responses; ingredient stock deducted once. |
| Competing sales | Eight simultaneous distinct requests competed for two drinks' worth of stock: two confirmed, six rejected, no negative stock. |
| Permanent rejection | Replenishing after rejection did not turn the original rejected key into a sale. Changing its payload was rejected. |
| Delivery consistency | Parallel identical stock-in retries made no extra movements. A final audit failure rolled back a new delivery completely. |
| Boundary times | Stock expiring today was eligible at 23:59:59 and excluded at the next midnight. Opened stock was excluded exactly at use-by. Allocation used the frozen sale timestamp. |
| Draft persistence | Browser reload restored basket and cash. A competing tab's outdated draft revision could not overwrite the current draft or initiate a new checkout. |
| Multiple pending orders | Two orders saved while checkout requests were unavailable retained their original keys and automatically confirmed after reconnection from two tabs; inventory decreased only twice. |
| Account isolation | Switching to Owner did not show/submit the Barista's local orders. Returning to the Barista account resumed its queue. |
| Lost committed response | The browser dropped a response after the server committed. Reload and automatic replay returned the original receipt without another deduction. |
| Review/history | A queued stale-price order became a review outcome. Acknowledging it retained a reviewed record across reload; no sale was created. |
| Browser data upgrade | A real Stage 2 IndexedDB v1 pending order migrated to v2 and confirmed with its original UUID. The test reader observes the database without upgrading it. |
| Unavailable browser storage | Deliberately blocked IndexedDB prevented checkout submission and kept Charge disabled. |
| Staff password reset | Owner role plus current-password check enforced; owner-target and malformed-password requests rejected. Five wrong reauthentication attempts triggered throttling. Successful reset revoked staff sessions and recorded the target ID in the audit action. |
| Reset failure atomicity | Injected audit failure left the old password/session usable. Successful UI reset forced a Barista to sign in again; its queued order survived and confirmed after sign-in with the new password. |
| Offline owner recovery | Code stored only as a hash; invalid attempts throttled. Recovery changed password, revoked sessions and rotated the code atomically. The used code failed on reuse. Injected audit failure preserved the old credentials/code. |
| Recovery surface/logs | CLI recovery paths returned HTTP 404 through the test router. Diagnostic logs contained neither issued recovery codes nor test recovery passwords. |
| Build, syntax and UI | Vite production build and PHP syntax checks passed. Register and Account security screenshots were inspected; the existing responsive layout checks passed. |

## Tested environment and commands

- Linux, PHP 8.3.6/PDO MySQL, MariaDB 10.11.14/InnoDB, Node 24.19.0 and Chromium 134.0.6998.35.
- The integration harness enables four PHP CLI workers on POSIX to exercise actual overlapping requests. Windows CLI workers are not supported; this is not a Windows concurrency claim.
- `npm run build`; PHP lint of `app/`, `tools/`, `public/` and the time-boundary test; `VV_BROWSER_TEST=1 python tests/integration.py` with the documented portable-runtime overrides.
- The harness executes foundation checks, `stage2_cases.py`, `stage3_cases.py`, `time-boundaries.php` and all three browser scripts. It drops/recreates only its named test database on the isolated test server. See README before running it.

## Implemented operational rules

Drafts, pending submissions and local history are account-scoped. Queued bodies are immutable. Successful receipts cannot be downgraded by a late response from another tab. Authentication failures from an older account cannot clear the newly signed-in account. The worker stops after logout/leaving Register, retries with bounded backoff, and resumes with the same account and site address. Only a host receipt marks a completed sale; pending orders do not reserve stock.

Owner recovery requires the offline code and trusted host-console access. Staff resets require an active Owner session and owner password reauthentication. Both are audited and revoke the affected sessions. Follow [RECOVERY.md](RECOVERY.md) to issue and store the initial code after installation/upgrade.

## Remaining acceptance and scope

- Windows/XAMPP, native MySQL 8, physical Android/iOS/Windows tablets, actual hotspot interruptions and client UAT remain unverified.
- Browser timers may pause in background tabs. Reopen/focus Register to resume. Loading the application after the host goes down still requires that host to return; Stage 3 does not add a service-worker app cache.
- Browser data deletion/eviction, changing the site address, device loss and full server power-loss recovery are outside these automated checks. Preserve unresolved orders and reconcile any collected cash before making a corrected sale.
- Stage 4 still covers waste/adjustment entry, complete management/audit/history/report filters, Z-reading/period reports, CSV, full staff administration and manual backup/restore. Stage 5 covers presentation polish and handover/UAT.
- The old application/database has not been replaced, imported, pushed or merged by this work. Use only the rebuild's setup and migration instructions.
