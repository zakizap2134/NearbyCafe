# Nearby Café / VenueVue — 5–7 minute demonstration

Prepare a separate demonstration database, a validated sample recipe and enough eligible stock. Keep recovery codes/passwords out of the presentation. Do not claim a new completion percentage merely because the fifth implementation stage is prepared.

| Time | Presenter | Show and explain |
|---|---|---|
| 0:00–0:40 | Zaki | Nearby Café branding, VenueVue software name; local browser → PHP → MySQL. Internet independence requires the local host and Wi-Fi to remain available. |
| 0:40–1:20 | Jenny Lyn | Owner/Barista access; the agreed product, size, temperature and recipe requirements. Explain no substitutions/add-ons in the current scope. |
| 1:20–2:10 | Jenny Lyn | One explicit variant recipe and its received batch/containers, expiry, current stock and low-stock threshold. |
| 2:10–3:20 | Zaki | Barista selects the variant, enters cash and confirms. Show change and itemized VAT-inclusive receipt. |
| 3:20–4:10 | Rhea Mae | Refresh owner stock and show the exact recipe deduction and actor-linked movement. Compare sales totals. |
| 4:10–5:00 | Rhea Mae | Queue a test order while the tablet cannot reach the host, then reconnect. Explain pending versus confirmed and demonstrate one confirmed sale. |
| 5:00–6:00 | Zaki | Daily/period report and CSV; show encrypted backup download and restore preview only. Explain explicit confirmation, sign-out and old-order review. |
| 6:00–7:00 | Team | Present automated test results and the actual-device acceptance checklist. State open items and invite questions. |

If time is short, prioritize recipe → delivery → sale → remaining stock → report. Rehearse full restore separately; it intentionally replaces data and signs everyone out.

**Likely questions:**

- **Why 12% VAT-inclusive?** This is the confirmed business rule. The displayed price is the customer total; net/VAT are extracted and stored at sale time.
- **What happens without internet?** The running LAN system continues. If a tablet loses the local host, an already-loaded Register can save pending orders for reconnection. A fresh page load still needs the host; there is no service-worker app cache.
- **What prevents double deduction?** Saved transaction keys, server idempotency, a database transaction and concurrent-submission tests.
- **Is it finished?** The implemented stages and automated checks are documented. Actual Windows/tablet/client acceptance is recorded separately and must not be presented as completed before it is run.
- **Why did the branding change?** The user supplied Nearby Café's current name, logo and visual references. VenueVue remains the software; earlier evidence uses the previous shop name.
