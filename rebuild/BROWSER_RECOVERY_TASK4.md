> Historical task record. The complete Tasks 1–5 release instructions and results are in START_HERE.md and RECOVERY_TASK5_VERIFICATION.md.

# Browser recovery Tasks 1–4 — reconstructed combined release

Date: 2026-10-20
Baseline: 9a43ca8 (complete rebuild Stages 1–5 and original account Tasks 1–7).

Earlier downloadable Tasks 2–3 and in-progress Task 4 files were lost during workspace maintenance. This release restores the surviving Task 1 backend and reconstructs Tasks 2–3 against the actual baseline. It does not claim to reproduce unavailable commit hashes. The combined patch is against 9a43ca8, not the unavailable Task 3 commit.

## Included functionality

- Task 1: POST `recovery/owner` with username, recovery_code, new_password and confirm_password. Active Owner only; generic credential errors, attempt limits, atomic password/code rotation, audit and session revocation. Codes are single-use and hash-only at rest. CLI support retained.
- Task 2: GET `management/recovery-code/status` returns prepared/version only. POST `management/recovery-code/generate` requires owner_password, expected_version and explicit confirm_replace when a code exists. Owner reauthentication, concurrency control and atomic audit protect replacement. The UI displays plaintext once without storage or replay caching.
- Task 3: clickable Forgot password route, Owner reset form, Barista guidance, validation, loading, attempt-limit messages and honest unconfirmed-response guidance. No automatic authentication or reset retries. Saved-code acknowledgement and sensitive-state cleanup.
- Task 4: muted red and cream recovery surfaces, original logo retained in a small left brand area, responsive forms, visible focus, focused error announcements, labelled controls, modal keyboard containment, minimum touch targets and wrapping recovery codes. Plain-language sheets and manual Copy/Download fallback instructions. Setup and ongoing recovery codes are clearly distinguished.

Task 4 changes presentation/accessibility; no checkout, inventory, VAT or database schema changes were made. Backend additions belong to restored Tasks 1–2. Task 5 was not started. Nothing was pushed or merged.

## Privacy and lost responses

Passwords/codes are never placed in URLs, browser storage or response replay caches. Displayed codes live only in component state and user-initiated clipboard/download output; clearing a screen cannot erase a user's clipboard or downloaded file. Download and clipboard failures explain manual recording. A successful browser download request cannot verify that the Owner retained the file; explicit acknowledgement remains required.

If a reset response is lost, the password may already have changed. Try the new password; after sign-in, prepare a replacement code through Account security. A lost code-generation response likewise requires reauthentication and replacement, not replay.

## Recorded follow-up

The legacy backend Owner-target denial text in app/recovery.php still refers to server-console recovery. This is a non-blocking wording issue for a Task 1/2 follow-up; its authorization behavior was preserved. No blocking functional defect was identified in the verified recovery paths. Physical-device, Windows/XAMPP and screen-reader acceptance remain manual checks; automated checks are not a WCAG certification.
