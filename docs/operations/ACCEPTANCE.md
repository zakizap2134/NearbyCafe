# Nearby Café — actual-host acceptance record

All rows below are **NOT RUN** until the team performs them on the client's intended installation. Record evidence and failures rather than treating the checklist as a pass certificate.

- Host OS / XAMPP / PHP / database version: [TO CONFIRM]
- Host URL and IP: [TO CONFIRM]
- Tablet model / OS / browser version: [TO CONFIRM]
- Tester / client witness / date: [TO CONFIRM]

| ID | Action on a test database | Required result | Status / evidence |
|---|---|---|---|
| U01 | Install/build using README; run preflight | App opens at documented stable URL; preflight passes | NOT RUN |
| U02 | Open from host and two tablets | Logo loads locally; readable touch layout; no exposed config/tools paths | NOT RUN |
| U03 | Sign in as each role; try direct management access as Barista | Owner gets Register + Management; Barista gets Register only and API denial | NOT RUN |
| U04 | Create ingredient, product, two variants and explicit recipes | Each variant retains its own price and quantities | NOT RUN |
| U05 | Receive two containers plus another expiry/lot | Deliveries stay distinguishable; opened/sealed quantities reconcile | NOT RUN |
| U06 | Sell a known recipe, enter cash, confirm | One sale; correct change, gross/net/VAT and ingredient deductions | NOT RUN |
| U07 | Try an underpaid or unavailable sale | Clear error; no partial stock deduction | NOT RUN |
| U08 | Submit the same saved request during reconnection | Original receipt returns; one sale and deduction | NOT RUN |
| U09 | Open a carton and test expiration/use-by with controlled test data | Opened stock consumed first; expired stock excluded; opening clock preserved | NOT RUN |
| U10 | Disconnect a tablet after loading, queue an order, reconnect | Pending order survives; confirmation or explicit review; no duplicate cash collection | NOT RUN |
| U11 | With the host/Wi-Fi active, disconnect WAN only | Sales remain operational; no external fonts/assets needed | NOT RUN |
| U12 | Waste and recount; retry after a lost response | Correct stock movement, actor/reason; no duplicate movement | NOT RUN |
| U13 | Compare receipts, daily report, period report and CSV | Stored gross/net/VAT agree; CSV totals row present | NOT RUN |
| U14 | Download backup; restore into an isolated compatible test installation | Preview, explicit confirmation, correct restored data and forced sign-out | NOT RUN |
| U15 | Keep a pre-restore pending order on another device | Order requires owner review; new reconciled order can be sold | NOT RUN |
| U16 | Reset a Barista password and rehearse offline owner recovery | Old sessions revoked; authorized recovery works; codes kept private | NOT RUN |
| U17 | Restart Apache/MySQL and reconnect at the same URL | Confirmed records persist; unanswered orders reconcile | NOT RUN |
| U18 | Client checks logo, menu, prices, units and recipes | Client approves recorded data and wording | NOT RUN |

Use controlled fixtures for destructive/expiration/restore demonstrations. Never change the host clock or restore a database during live trading. A full power-loss drill and a larger-data backup rehearsal require a dedicated test installation and a recovery copy.

For recipe accuracy, compare expected and actual deductions per ingredient/unit; do not combine grams and millilitres. For reconnection, count queued requests, reconciled outcomes and duplicate sales separately. Record checkout latency on the actual LAN if the course requires it. No production performance figures have been claimed here.

- Outstanding issues / severity / responsible person / retest date: [TO CONFIRM]
- Client decision (accept / accept with conditions / reject): [TO CONFIRM]
- Client name/signature and date: [TO CONFIRM]
- Team acceptance and release date: [TO CONFIRM]
