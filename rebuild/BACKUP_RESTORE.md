# Stage 4 — backup, restore and management operations

Run this application from `rebuild/`, using the same `config/local.php` as checkout. These procedures apply to the full rebuild only. They do not import an old phase database.

## Routine backup

1. Sign in as Owner/Admin. Open **Management → Backup & restore**.
2. Enter the current owner password and a separate backup passphrase (12–128 bytes). Download the `.vvbackup` file and verify the browser completed the download.
3. Keep a copy away from the host laptop; keep the passphrase separately. The application cannot recover a forgotten backup passphrase.
4. Back up the source/version and private `config/local.php` separately. Never commit configuration, downloaded backups or recovery codes to Git. Use a disposable installation to rehearse a restore before relying on a backup.

The file contains encrypted accounts/password hashes, catalogue, recipes, batches/containers, stock movements, sales and stored VAT, audit history, permanent checkout outcomes, recovery hashes and stock-operation replay records. It does **not** include application files, database configuration, browser drafts/queues, active sessions, rate-limit buckets or runtime restore metadata. Current owner credentials are preserved during in-app restore; staff credentials are restored from the file.

Encryption: AES-256-GCM with a random 16-byte salt, 12-byte IV and 16-byte authentication tag; PBKDF2-HMAC-SHA256 with 210,000 iterations derives the key. A wrong passphrase or altered ciphertext fails authentication. A successful download does not itself prove that the chosen file/passphrase can later be restored: rehearse on an isolated copy.

## In-app restore

1. Stop sales and stock edits on every device. Reconcile any cash already collected. Download a fresh backup of the **current** database before proceeding. Leave device browser storage intact.
2. Choose a Stage 4 `.vvbackup` file. Enter its passphrase and your current owner password. Click **Preview restore**.
3. Review the backup timestamp and current/replacement row counts. Preview checks format, schema, columns, scalar values, dates, owner identity, primary/foreign-key relationships, stock movement/batch/container/ingredient totals and stored sale/VAT totals without replacing data.
4. Type `RESTORE`, then select **Replace records and sign out**. Any application record change since preview—including new sales, login/logout audit events and edits—invalidates the preview. Review again in that case.
5. A successful restore signs everyone out. Sign in with the **current owner password**. The current owner recovery code still works. Baristas use the passwords stored in the backup; reset them through Account security if needed.
6. Check Reports and Audit history, then physically reconcile stock and cash. Review every device's pending orders and deliveries before reopening the register.

Restore requires the same single active owner ID and username as the current database. It replaces the declared business tables with prepared inserts, in foreign-key dependency order. It uses one InnoDB transaction under the same application lock as checkout. No `TRUNCATE`, schema changes, arbitrary uploaded SQL, or disabling of foreign keys occurs. Database constraints, coercion checks and stock/VAT invariants are rechecked before commit. Failure rolls back records, sessions and restore metadata together. MySQL may leave gaps in auto-increment IDs after rollback; those are not missing sales.

The restored audit history is the history contained in the backup, followed by a new `BACKUP_RESTORED` event. Later audit entries from the replaced database remain available only in the pre-restore backup. Keep both backups if that history is needed.

### Interrupted confirmation and older device requests

- Do not assume a timeout means failure. If the server committed but its response was lost, your session will have been revoked. Sign in again and check the `BACKUP_RESTORED` audit entry and actual records before attempting another restore.
- Restore rotates the host's restore epoch. Checkout, receiving and stock-operation requests retain the epoch captured when prepared. Requests from an earlier epoch return `RESTORE_REVIEW` without changing stock. Legacy requests lacking an epoch are accepted only before the host's first restore; afterward they also require review.
- Existing nonempty drafts keep their original epoch. Empty drafts can start afresh with the current epoch. Confirmed device history may describe a sale absent from an older restored backup: a device receipt alone is not evidence that a sale is present in the restored database.
- For each affected order, compare its items, cash, transaction key and timing with restored sales and physical stock. The owner decides whether to re-enter a missing sale or simply acknowledge an existing one. Never blindly resubmit or clear browser storage. Marking review complete only records a local acknowledgement; it does not insert a sale or adjust stock.
- The database backup does not save device-local orders. Keep devices and browser profiles available during recovery. A new host address uses different browser storage and needs deliberate operator reconciliation.

## Size limits and host configuration

The in-app format permits up to **8 MiB of plaintext JSON**, 100,000 rows per table and 250,000 rows overall. The encrypted file is larger because of Base64; the browser accepts up to 12 MiB and restore API requests up to 16 MiB. Ordinary API requests retain the 8 KiB limit. Reports allow at most 367 inclusive days and CSV exports at most 50,000 lines; choose smaller periods when needed.

Configure the PHP/Apache host to allow a 16 MiB JSON request (`post_max_size=16M` or larger), sufficient memory (256 MiB recommended), and enough execution time for the database size (for example `max_execution_time=180`). Restart Apache after changing `php.ini`. The UI waits up to 180 seconds for backup/restore. Runtime database permissions need SELECT/INSERT/UPDATE/DELETE on the rebuild tables; migrations separately need table-creation privileges. Keep all application tables InnoDB. Test the largest expected backup on the actual host; this stage's automated tests use smaller fixtures.

## Administrator fallback for larger databases

An authorized host administrator can use the installed MySQL/MariaDB dump tool or database administration interface. Keep the app stopped. Export the **rebuild database**, including all Stage 4 tables, into an access-controlled location outside the web root. With the installed dump tool, use `--single-transaction`, `--no-tablespaces`, the actual database name and a password prompt (`-p` without the password); never put passwords in a command or source file. Raw SQL exports are not encrypted by the in-app mechanism: protect them with your host's encrypted storage.

Restore a raw SQL dump into an **isolated replacement database first**, using the same compatible schema and database engine. Preserve the current database and configuration. Check the restored owner credentials and configure a test copy to use that database. Raw database restore can bring back older owner passwords and recovery hashes; it does not have the in-app owner-credential preservation behavior.

Before reconnecting devices, with the web server still stopped, run from `rebuild/`:

```bat
C:\xampp\php\php.exe tools\preflight.php
C:\xampp\php\php.exe tools\finalize-host-restore.php
C:\xampp\php\php.exe tools\recovery-code.php
```

The finalization tool requires the restored owner's current credentials, checks stock/VAT totals, revokes restored sessions, rotates the runtime epoch, and writes `HOST_RESTORE_FINALIZED`. It does not perform the database import. Keep the newly rotated owner recovery code offline. Rehearse checkout and reports on the replacement before switching the live configuration. Keep the old database untouched until the owner accepts the recovered state. Reconcile devices, stock and cash as described above.

## Other Stage 4 management workflows

- **Catalogue/ingredients:** create, edit and deactivate records. Delete only unused records; linked sale/stock history is protected. Used variants retain stored sale labels/prices/VAT even when the current catalogue changes.
- **Stock operations:** record waste or set an absolute counted quantity for a selected container. Reason, actor and timestamp are mandatory/server-recorded. The expected prior quantity prevents a stale form overwriting a concurrent sale. A positive correction cannot exceed the original received quantity or make expired/quarantined stock sellable. New stock belongs in a new delivery. Count corrections do not reset opening times or use-by dates.
- **Batch corrections:** change supplier, lot reference, expiration or quarantine status with a reason. This writes a zero-quantity `ADJUSTMENT` movement and before/after audit detail. Expiry corrections must reflect the actual delivery label; they do not reset an opened container's use-by.
- **Staff:** owner reauthentication creates or edits Barista accounts, including activation/deactivation. Saving an existing staff account revokes its sessions. No third role or owner promotion is exposed. Password resets remain in Account security.
- **Reports:** daily summaries (Z-reading) and period totals sum stored `gross`, `net` and `vat`; they do not recalculate history from current menu prices. VAT remittance is the recorded VAT total for the selected period under the agreed no-exemption/no-discount scope. The summary does not perform a fiscal register close. Receipt formatting alone does not constitute tax-system certification.
- **CSV:** every sale line has gross/net/VAT and the file ends with a totals row. Formula-leading staff/product text is escaped for spreadsheet use. Downloaded CSVs contain business records; handle them accordingly.

Stage 5 includes Nearby Café branding and handover guides. Actual Windows/XAMPP deployment and physical-device/client acceptance remain to be recorded in `ACCEPTANCE.md`. The backup format and recovery rules remain unchanged.
