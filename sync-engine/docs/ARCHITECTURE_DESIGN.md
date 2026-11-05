# VenueVue: Hybrid Store-and-Forward Synchronization Architecture
## Technical Whitepaper & Architectural Specification

**Industry Partner:** Nearby Café – Legaspi St., Intramuros, Manila (Near LPU)  
**Engineering Team:** CTRL + STAY (Cavite State University - CEIT)  
**Module Type:** Autonomous Store-and-Forward Edge Synchronization  

---

## 1. Executive Summary & Problem Domain

Nearby Café operates in a high-density university environment along Legaspi St. in Intramuros, Manila. The physical shop experiences two severe environmental constraints:
1. **Intramuros Wireless Signal Degradation**: Thick Spanish-era stone walls severely attenuate cellular networks (4G/5G) and local broadband fiber frequently experiences dropouts.
2. **University Class Dismissal Rushes**: Order volume arrives in sudden 10-minute spikes (up to 15–20 drinks) where counter checkout speed must remain under 15 seconds per transaction.

A pure cloud POS (e.g. Loyverse, Shopify) is an unacceptable operational risk: when the internet drops, the counter halts. Conversely, a pure offline POS leaves the business owner blind when away from the shop.

This module delivers the **Hybrid Store-and-Forward Architecture**: the physical shop operates 100% locally with zero latency, while an asynchronous daemon pushes transactions to the cloud whenever internet connectivity is available.

---

## 2. CAP Theorem Alignment

In distributed systems, the **CAP Theorem** dictates that a system can only guarantee at most two of the three properties during a network partition:
- **Consistency (C)**: Every read receives the most recent write.
- **Availability (A)**: Every non-failing node returns a response.
- **Partition Tolerance (P)**: The system continues to operate despite network drops.

```
                  [ CAP THEOREM TRADE-OFF ]

                         Availability (A)
                               ▲
                              / \
                             /   \
                            /     \
    [ Cloud POS Fails ]    /   ★   \    [ VenueVue Hybrid ]
                          / VenueVue\
                         /  Sweetspot\
                        /             \
                       /               \
         Consistency (C) ─────────────── Partition Tolerance (P)
```

### VenueVue's Design Choice: AP (Availability + Partition Tolerance) with Eventual Consistency
- **Availability at the Counter is Non-Negotiable**: A student standing at the register must receive their coffee and change immediately. VenueVue never waits on a remote cloud round-trip.
- **Partition Tolerance is Built-In**: When Intramuros internet drops, the system gracefully partitions itself. The local shop operates autonomously.
- **Eventual Consistency via Idempotent Outbox**: When internet returns, the cloud converges to match the local shop's state within seconds, guaranteeing that total sales, inventory depletion, and Z-readings match 100%.

---

## 3. The 3 Architectural Options Compared

| Feature | Option 1: Pure Cloud POS | Option 2: Bi-Directional Master Sync | Option 3: Local-Authoritative Outbox (VenueVue) |
| :--- | :---: | :---: | :---: |
| **Counter Speed** | Latency-bound (200–1200ms) | Slow (database locks) | **Ultra-Fast (< 15ms over LAN)** |
| **Behavior During Outage** | ❌ **Fails / Freezes** | ⚠️ Split-brain conflicts | ✅ **100% Normal Operation** |
| **Risk of Duplicate Sales** | Low | High (ID collisions) | **Zero (UUID Idempotency)** |
| **Inventory Accounting** | High drift if offline | High risk of stock corruption | **Guaranteed FIFO Precision** |
| **Owner Remote Visibility** | Live | Fragile | **Live with Offline Telemetry Badge** |

---

## 4. End-to-End Data Flow

### Phase 1: Normal Operation (Internet is UP)
```
[Barista Rings Order] 
        │
        ▼ (Local LAN < 15ms)
[Local Shop MariaDB] 
   ├── 1. Writes to `sales` and `stock_movements` (Authoritative)
   └── 2. Inserts into `sync_outbox` (Status: PENDING)
        │
        ▼ (Asynchronous Background Worker)
[Local Sync Worker] 
   ├── Pings Cloud Health (`GET /sync_health.php`) ➔ 200 OK
   └── Batches outbox records (`POST /sync_ingest.php`)
        │
        ▼ (HTTPS WAN)
[Cloud Ingestion API]
   ├── Verifies Bearer Token
   ├── Checks `transaction_key` in `sync_inbox` (Idempotency)
   └── Commits atomic transaction
        │
        ▼
[Cloud Database Mirrors Shop State] ➔ Owner views live metrics from home
```

### Phase 2: Total Internet Blackout in Intramuros
```
[Barista Rings 5 Orders During Rush]
        │
        ▼ (< 15ms local response)
[Local Shop MariaDB]
   ├── Writes to local `sales` and `stock_movements`
   └── Inserts into `sync_outbox` (Status: PENDING)
        │
        ▼
[Local Sync Worker]
   ├── Pings Cloud Health ➔ Fails (Timeout / DNS error)
   ├── Updates local status: `sync_status = OFFLINE`
   └── Sleeps quietly without blocking counter operations
        │
   [Outbox safely accumulates pending records. Zero data loss.]
```

### Phase 3: Internet Restored (Catch-Up Synchronization)
```
[Internet Restored / Staff Enables Mobile Hotspot]
        │
        ▼
[Local Sync Worker]
   ├── Pings Cloud Health ➔ 200 OK (Connection Restored)
   ├── Fetches all accumulated PENDING outbox records
   └── Pushes atomic batch to `POST /sync_ingest.php`
        │
        ▼
[Cloud Ingestion API]
   ├── Ingests the 5 missed transactions in one transaction
   └── Returns `{ ok: true, accepted_count: 5, duplicates_ignored: 0 }`
        │
        ▼
[Local Outbox] ➔ Marks records as SYNCED
[Cloud Dashboard] ➔ Switches to `● Shop Online · Synced just now`
```

---

## 5. Security & Idempotency Safeguards

1. **Bearer Token Authentication**: Pushes are authenticated with a pre-shared cryptographic secret (`SYNC_SECRET_KEY`).
2. **UUID Idempotency Keys**: Every transaction carries an immutable `transaction_key` (UUID v4). If a Wi-Fi packet drops after cloud processing but before the shop receives the ACK, the shop will safely retry without creating phantom duplicate sales.
3. **Database Isolation**: The physical counter database and cloud database do not share database credentials, preventing unauthorized remote access into the physical register hardware.
