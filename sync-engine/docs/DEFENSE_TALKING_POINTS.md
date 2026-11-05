# VenueVue: Capstone & Thesis Defense Q&A Guide
## Topic: Store-and-Forward Synchronization & Offline Resilience

Use this guide when professors or panelists ask questions about how VenueVue handles internet outages and synchronization.

---

### Question 1: "What happens to VenueVue if the internet connection drops right in the middle of a transaction?"
**Recommended Answer:**
> *"Because VenueVue uses a Local-Authoritative architecture, the counter register does not depend on the internet to process transactions. When a barista rings up an order, the transaction is finalized directly on the local shop computer in under 15 milliseconds. The order and raw ingredient deductions are committed locally, and an asynchronous record is placed into our local `sync_outbox`. The customer gets their receipt and change immediately with zero lag. When the internet connection returns, our background sync daemon flushes the queued records to the cloud automatically."*

---

### Question 2: "Why did you choose an Asynchronous Store-and-Forward Outbox instead of Bi-Directional Database Replication (Master-Master)?"
**Recommended Answer:**
> *"Bi-directional master-master replication introduces what distributed systems call a 'Split-Brain' conflict. If both the local counter and the cloud portal accept writes during a network partition, resolving conflicting sales IDs and inventory counts requires distributed locks that cause deadlocks and stock drift. In a physical coffee shop, the physical milk and espresso beans only exist in one place—at Legaspi St., Intramuros. Therefore, we designated the physical shop as the Single Source of Truth for operations. The cloud acts as a telemetry replica. This eliminates race conditions and guarantees 100% data integrity without complex database locks."*

---

### Question 3: "What if a network drop occurs right after the cloud receives a batch, but before the local shop receives the acknowledgment? Won't the local daemon re-send it and duplicate the sales?"
**Recommended Answer:**
> *"We implemented cryptographic Idempotency via unique `transaction_key` UUIDs. Every transaction carries an immutable UUID. On the cloud side, our `sync_inbox` enforces a unique key constraint. If a duplicate packet arrives, the cloud recognizes the existing key, marks the record as `SKIPPED_DUPLICATE`, and returns a success response without creating a duplicate sale or deducting inventory twice. We proved this in Phase 4 of our automated simulation test."*

---

### Question 4: "What does the shop owner see when looking at the online portal while the shop is offline?"
**Recommended Answer:**
> *"The cloud portal does not crash or display an error page. Instead, our telemetry badge clearly informs the owner: `○ Shop Offline · Last synced at [Time]`. The owner can view all historical data and previous Z-readings up to the last successful sync point. Once the shop reconnects, the badge switches back to `● Shop Online · Live` and all numbers catch up in seconds."*

---

### Question 5: "How does this relate to the CAP Theorem?"
**Recommended Answer:**
> *"Under the CAP Theorem, during a network partition (P) between Intramuros and the cloud, you must choose between immediate Consistency (C) or Availability (A). For a brick-and-mortar retail coffee shop, counter Availability is non-negotiable—you cannot tell a student to wait 20 minutes for a coffee because the ISP fiber is cut. Therefore, VenueVue prioritizes Availability and Partition Tolerance (AP) with Eventual Consistency. The local counter remains 100% available, and consistency is achieved as soon as the network partition resolves."*
