# VenueVue · Standalone Store-and-Forward Synchronization Module

**Target Client:** Nearby Café (Legaspi St., Intramuros, Manila near LPU)  
**Academic Project:** COSC 75: Software Engineering II | Team CTRL + STAY  
**Status:** Standalone Module (Independent of Git Repository)  

---

## What is This Module?

This project is an **independent, production-grade Store-and-Forward synchronization engine** designed for VenueVue. It bridges the **Local On-Premise Register (100% Offline-First LAN)** with the **Remote Online Cloud Portal (HTTPS Web Mirror)** without risking counter freezes or database split-brain corruption during Intramuros internet outages.

---

## Directory Structure

```
VenueVue_Sync_Module/
├── README.md                           <- Master documentation and quickstart
├── config/
│   └── sync_config.php                 <- Shared settings, Bearer secrets, timeouts
├── database/
│   └── 001_sync_tables.sql             <- Outbox, Inbox, and Meta schema definitions
├── local_daemon/
│   ├── sync_worker.php                 <- The store-and-forward polling and push daemon
│   ├── outbox_helper.php               <- Helper class to enqueue events during sales
│   └── run_sync_worker.bat             <- 1-click Windows runner for local background sync
├── cloud_api/
│   ├── sync_health.php                 <- Fast ping endpoint (< 3s timeout)
│   ├── sync_ingest.php                 <- Atomic batch ingestion with UUID idempotency
│   └── index.php                       <- Web dashboard showing live telemetry badge
├── simulator/
│   ├── test_outage_simulation.py       <- Automated 4-phase network outage test suite
│   └── run_simulation.bat              <- 1-click test runner
└── docs/
    ├── ARCHITECTURE_DESIGN.md          <- Technical whitepaper & CAP theorem analysis
    └── DEFENSE_TALKING_POINTS.md       <- Scripted Q&A for thesis presentation/defense
```

---

## How It Works During Network Outages

1. **Normal State (Internet UP)**:
   - Barista takes orders locally. Response time: < 15ms.
   - Background worker pings the cloud and pushes pending orders in small batches.
   - Online portal shows: `● Shop Online · Live`.

2. **Outage State (Internet DOWN)**:
   - Counter register operates with **zero interruption** and **zero latency**.
   - Completed transactions queue inside the local `sync_outbox` table.
   - Sync daemon quietly pauses without blocking the counter or spamming logs.
   - Online portal shows: `○ Shop Offline · Last synced: [Time]`.

3. **Restored State (Internet RETURNS)**:
   - Sync daemon detects connection.
   - Automatically pushes all queued orders in an atomic batch.
   - Cloud inbox receives and applies the transactions.
   - Online portal catches up in seconds without manual intervention.

4. **Idempotency Safeguard (Duplicate Retries)**:
   - Every transaction carries a unique `transaction_key` UUID.
   - If a connection flickers during an ACK, the retry is recognized as an existing key and safely skipped (`SKIPPED_DUPLICATE`).
   - Revenue and ingredient deductions are **never counted twice**.

---

## Verification & Testing

To run the automated 4-phase network outage simulation:
1. Double click `simulator/run_simulation.bat` or run:
   ```cmd
   python simulator/test_outage_simulation.py
   ```
2. The test simulates:
   - **Phase 1**: Online state (2 sales synced live)
   - **Phase 2**: Complete internet blackout (4 rush sales queued locally, 0 lag)
   - **Phase 3**: Internet reconnection (4 orders batch synced, totals reconciled)
   - **Phase 4**: Duplicate network retries (idempotency verified)

---

## Deployment to InfinityFree Cloud

1. Upload the files in `cloud_api/` to your cloud web host (e.g. `https://nearbycafe.infinityfreeapp.com/api/`).
2. Run `database/001_sync_tables.sql` in phpMyAdmin on InfinityFree.
3. Configure `config/sync_config.php` with your cloud URL and secret token.
4. On the shop PC, run `local_daemon/run_sync_worker.bat` in the background.
