# Task 6 — Nearby Café theme

Baseline: Stage 5 commit `a33cfb2`.

The interface now uses muted red (#A93226), warm cream (#FFF9F0), and soft blush (#F8EAE5). Theme colors are centralized in the Nearby Café CSS variables. The login hero and sidebar use softer surfaces; selected navigation and primary actions retain clear emphasis. Keyboard focus receives a visible outline. The supplied logo image and its original colors are unchanged.

Runtime changes are limited to `src/style.css` and the browser theme-color in `index.html`. No authentication, API, checkout, inventory, VAT, or database logic was changed. Tasks 1–5 and 7 were not started.

## Validation

- `npm run build` passed.
- Login, Management, and Register visual smoke checks passed at widths of 1440, 768, and 390 pixels without page overflow or JavaScript errors.
- Preview screenshots use mocked data; they are visual checks, not proof of live backend integration.
- Key palette text pairs meet 4.5:1 contrast: white on action red 6.62:1; muted text on blush 5.02:1; action red on blush 5.64:1. This is not a full accessibility audit.
- Desktop login, tablet Management, and mobile Register screenshots were visually inspected.

## Apply the update

Back up your current source, then apply the patch to the Stage 5 repository or copy the updated source files. Preserve your local configuration and database. Inside `rebuild`, run `npm run build`, then refresh the browser. If dependencies are absent, run `npm ci` first. No database migration is required.

## Previews

See `docs/task6-login-1440.png`, `docs/task6-management-768.png`, and `docs/task6-register-390.png`. Additional desktop, tablet, and mobile screenshots are included in the same directory.
