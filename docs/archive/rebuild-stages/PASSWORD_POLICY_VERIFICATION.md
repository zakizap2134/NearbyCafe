# Password policy verification — 21 October 2026

## Result

All requested automated checks passed on the final source. Full details are in `tests/PASSWORD_POLICY_ALL_RESULTS.txt`. Tests used disposable databases and synthetic accounts; no client database was accessed. No push or merge was performed.

Environment: PHP 8.3.6, PCRE 10.42, mbstring unavailable, MariaDB 10.11, Node 24.19.0, Vite 8.3.0, headless Chromium on Linux. The Unicode implementation therefore does not depend on mbstring. Windows/XAMPP PHP 8.2.12 and MariaDB 10.4.32 remain host acceptance checks.

| Check | Result |
|---|---|
| Shared PHP/JavaScript validation | PASS: 20 matching cases, plus malformed Unicode rejection |
| 11 versus 12 characters | PASS: ASCII, accented letters and supplementary-plane emoji |
| Code-point semantics | PASS: combining marks count separately; emoji are not double-counted as UTF-16 units |
| bcrypt boundary | PASS: 72 bytes accepted; 73-byte ASCII and oversized accented/emoji passwords rejected |
| Exact password preservation | PASS: whitespace and newline helper cases hash and verify without alteration |
| Confirmation and NUL | PASS: missing/mismatching confirmation and NUL rejected; staff API checks cannot be bypassed |
| Initial Owner setup | PASS: Unicode creation/login, validation without writes, audit rollback and concurrent setup |
| Staff creation/reset | PASS: backend and browser paths, owner authorization, attempts, rollback and session revocation |
| Browser Owner recovery | PASS: Unicode validation and a live 12-emoji reset; code acknowledgement and sign-in behavior |
| CLI create Owner/Barista, recover Owner, support reset | PASS: invalid input leaves records unchanged; Unicode success through recovery/support/staff paths |
| Existing accounts | PASS: legacy six-character accented password still signs in; current-cost hash unchanged; lower-cost hash rehashes successfully |
| Existing sessions/data | PASS: validation failures preserve account/session/code/audit state; unrelated Owner session and sales/stock records retained |
| Recovery security | PASS: wrong/used/replaced codes, generic errors, attempt limits, atomic audit rollback, eight concurrent resets and reset/replacement races |
| Proxy and response handling | PASS: actual Vite proxy origin checks; lost committed responses; no secret replay or plaintext secret persistence |
| Support reset | PASS: active Owner targeting, cancellation, audit-failure rollback, hash-only code, Owner revocation, staff preservation and HTTP denial |
| POS regression | PASS: checkout/rollback/idempotency, FEFO/opened stock, expiration, VAT/receipts, real reports/CSV, backup/restore, pending orders and role navigation |
| PHP syntax | PASS: all 28 app/tools PHP files |
| Production build | PASS: 43 modules; bundled frontend supplied |

## Test maintenance

The first combined run exposed an existing recovery browser fixture that omitted the current stock-operation epoch after the backup/restore suite. The test now obtains that marker from `health` before receiving stock. The complete combined suite was rerun successfully. No transaction or stock-rule implementation was changed for this correction.

The shared helper test should run from the `rebuild` directory, as documented. Tests and current operational instructions supersede older historical stage reports that describe the previous byte-based minimum.

## Remaining acceptance

- Run the supplied preflight and build on the actual Windows/XAMPP host.
- Reload all client tabs after deploying both frontend and backend; staff writes now require a confirmation field.
- Confirm entry of accented letters/emoji using the shop's real desktop/tablet/mobile keyboards and browsers.
- Client acceptance and screen-reader/device checks on physical hardware were not performed in this environment.

No migration is required. Configuration, existing hashes and sessions are not bulk rewritten. Normal successful authorized resets retain their existing revocation behavior; normal sign-in may rehash an older bcrypt cost as before.
