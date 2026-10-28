> Historical task record. The complete Tasks 1–5 release instructions and results are in START_HERE.md and RECOVERY_TASK5_VERIFICATION.md.

> **Browser recovery update (Tasks 1–4, 2026-10-20):** Owners can now prepare an offline recovery code in **Management → Account security**, then use **Forgot password?** on sign-in. See [APPLY_BROWSER_RECOVERY_TASKS1-4.md](APPLY_BROWSER_RECOVERY_TASKS1-4.md) for this combined release. CLI launchers remain optional support tools. Earlier verification reports describe their original releases.

# Browser Owner recovery — Task 1 backend

Task 1 only. Based on the complete Stages 1–5 + Tasks 1–7 source at commit `9a43ca8`. No new recovery screen or browser code-generation screen is included yet. Those remain Tasks 2–3; visual polish and final combined acceptance remain Tasks 4–5.

## API contract

`POST /api/index.php?route=recovery/owner`

No bearer token is required. Authorization is the current offline recovery code for an active Owner account. A client-supplied role, account ID or Barista session cannot replace that credential.

Request headers:

- `Content-Type: application/json` (optional charset parameter).
- `Origin`: the browser's origin, matching the host serving the API. Missing, opaque/null and cross-origin values are rejected. A supplied Fetch Metadata site value must be `same-origin` or `none`.

JSON fields:

| Field | Rule |
|---|---|
| `username` | Existing username rules, case-insensitive, surrounding whitespace ignored |
| `recovery_code` | Current 256-bit offline code, case-insensitive; display hyphens and surrounding whitespace accepted |
| `new_password` | At least 12 Unicode code points, at most 72 UTF-8 bytes, no NUL characters |
| `confirm_password` | Exact match to the new password |

Success is HTTP 200 using the existing `ok/data/request_id` envelope. `data` contains `reset: true`, `sessions_revoked: true`, and the replacement `recovery_code`. No authenticated session is created. The future frontend must display the replacement once and ask the owner to save it before leaving the screen.

The existing recovery-code format and SHA-256 hashes remain compatible with CLI-generated codes. Recovery codes do not currently expire by date; they become invalid when replaced or consumed. No expiry column or retroactive expiration rule is introduced.

| Status / code | Meaning |
|---|---|
| 403 `RECOVERY_INVALID` | Generic username/code failure; same message for unknown usernames, Baristas, inactive Owners, missing codes and incorrect/used codes |
| 429 `RECOVERY_LIMIT` | Temporary attempt limit; `Retry-After` gives seconds remaining |
| 422 `VALIDATION` | Password byte-length or confirmation validation failed; code is not consumed |
| 403 `RECOVERY_ORIGIN` | Request did not come through the permitted same-origin flow |
| 400 / 413 / 415 / 405 | Existing JSON, body-size, content-type or method protections |
| 500 `SERVER_ERROR` | Request failed; use its diagnostic reference without exposing internal errors |

## Security and consistency

All credential reads and writes occur under the existing `app_state` transaction lock. Password update, all Owner session deletions, replacement-code hash, appropriate account-limit cleanup and `OWNER_RECOVERED` audit commit together. Failure rolls back all of them. Other users' sessions and all stock, sale, VAT and restore records are untouched.

Failed credential attempts use fixed 15-minute windows: 5 per normalized username, 30 per server-observed IP, and 100 globally. Malformed credentials and unknown usernames are counted. For an active Owner, the existing 5-attempt CLI recovery bucket is also checked and updated, preventing a switch between CLI and HTTP from bypassing the account limit. Expired buckets reset; successful recovery clears account-specific recovery/login/reauthentication counters, while address/global abuse counters remain. Forwarded headers never select the address or permitted origin.

The new endpoint accepts JSON only, has no CORS grant, and returns `Cache-Control: no-store`, `Referrer-Policy: no-referrer` and the existing content-type/sniffing protections. Origin checks are a browser request safeguard, not an authentication credential; the recovery code is always required.

Passwords and codes are not written into URLs, logs, audit details, browser storage or a replay cache. Only the recovery hash is stored. Concurrent identical recovery requests produce at most one successful reset; subsequent requests fail with a generic credential error or attempt limit and cannot retrieve the replacement code.

If the success response is lost after commit, the client must not claim a confirmed result or automatically retry a reset. The new password works, the old code is invalid, and the lost replacement cannot be retrieved. The authenticated code-replacement flow planned for Task 2 will issue a new code after current-password reauthentication. Existing CLI code rotation remains available for authorized support during this backend-only stage.

## Deployment and upgrade

No database migration, SQL import, account recreation or frontend rebuild is required for these backend changes. Existing accounts, recovery hashes and schema version 4 are retained.

Apply the patch from the repository root, or copy the changed `rebuild/` files from the Task 1 ZIP into the existing combined source. Preserve `config/local.php`, the database and browser site data. Restart the PHP server and Vite after replacing source. This milestone does not add a new button or form; the existing Forgot password instructions remain until Task 3.

The Vite development proxy now preserves the browser-facing Host (`changeOrigin: false`) so Origin checking works through the development URL. Direct Apache hosting reads the actual Host and HTTPS server state. A custom reverse proxy must preserve the external Host and supply the actual scheme through a trusted server configuration; the app deliberately ignores client-supplied forwarded headers. Never expose private source/configuration directories as a document root.

LAN HTTP does not encrypt passwords or recovery codes in transit. Use HTTPS for transport confidentiality. There is no WAN/email service dependency; the local host and network must be available.

## Verification

Run the existing isolated harness with `VV_BROWSER_RECOVERY_TEST=1` to include Task 1 checks. It drops/recreates only `venuevue_rebuild_test`; use a disposable database server. Requires Python, PHP/PDO MySQL, the MySQL/MariaDB CLI, Node and installed npm dependencies for the real Vite proxy check.

```sh
VV_TEST_DB_PORT=3307 VV_BROWSER_RECOVERY_TEST=1 python tests/integration.py
```

The additional cases cover malformed/used codes, role and account restrictions, generic errors, fixed-window expiry, per-user/IP/global limits, CLI/HTTP shared limits, late audit rollback, concurrent duplicate resets, CLI/HTTP races, all Owner sessions, staff session preservation, a closed response connection, secret-free logs/database fields and the actual development proxy. Final recorded results are included with this Task 1 deliverable. Actual Windows/XAMPP, browser recovery screens and physical-device acceptance remain for later tasks.
