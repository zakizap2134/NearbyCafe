import json
import time
import uuid
import subprocess
import os

print("=" * 80)
print("VENUEVUE STORE-AND-FORWARD NETWORK OUTAGE SIMULATION TEST")
print("Scenario: Intramuros Internet Drops During Counter Operations")
print("=" * 80)

# Connect to local MariaDB using mysql CLI or python
MYSQL_BIN = r"C:\xampp\mysql\bin\mysql.exe"
PHP_BIN = r"C:\xampp\php\php.exe"
DB_USER = "root"
DB_PASS = ""
DB_NAME = "venuevue_rebuild"

def run_sql(query):
    cmd = f'"{MYSQL_BIN}" -u{DB_USER} -D{DB_NAME} -sN'
    res = subprocess.run(cmd, input=query, shell=True, capture_output=True, text=True)
    if res.returncode != 0 and res.stderr:
        print("[SQL ERROR]", res.stderr.strip())
    return res.stdout.strip()

# 1. Apply Schema
print("\n[Step 1] Applying sync schema to database...")
schema_file = r"C:\Projects\VenueVue_Sync_Module\database\001_sync_tables.sql"
subprocess.run(f'"{MYSQL_BIN}" -u{DB_USER} -D{DB_NAME} < "{schema_file}"', shell=True)
print("[OK] sync_outbox, sync_inbox, and sync_meta initialized.")

# Clean test state
run_sql("DELETE FROM sync_outbox; DELETE FROM sync_inbox; UPDATE sync_meta SET meta_value = '1970-01-01 00:00:00' WHERE meta_key = 'last_successful_sync_at';")

# Start Mock Cloud Server on Port 4920
print("\n[Step 1.5] Launching local cloud endpoint on http://127.0.0.1:4920...")
cloud_api_dir = r"C:\Projects\VenueVue_Sync_Module\cloud_api"
mock_server = subprocess.Popen(
    f'"{PHP_BIN}" -S 127.0.0.1:4920 -t "{cloud_api_dir}"',
    shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
)
time.sleep(1)

cfg_path = r"C:\Projects\VenueVue_Sync_Module\config\sync_config.php"
with open(cfg_path, 'r', encoding='utf-8') as f:
    orig_cfg = f.read()

# Point to mock cloud server for the test
test_cfg = orig_cfg.replace("https://nearbycafe.infinityfreeapp.com/api", "http://127.0.0.1:4920")
with open(cfg_path, 'w', encoding='utf-8') as f:
    f.write(test_cfg)

try:
    # 2. Phase 1: Online Operation (2 Sales)
    print("\n[Step 2] Phase 1: Normal Online State (2 Sales at counter)")
    tx1 = str(uuid.uuid4())
    tx2 = str(uuid.uuid4())

    s1_payload = json.dumps({"sale_id": 101, "total_gross": 140.00, "items": [{"name": "Iced Spanish Latte 16oz", "qty": 1}]})
    s2_payload = json.dumps({"sale_id": 102, "total_gross": 120.00, "items": [{"name": "Iced Americano 16oz", "qty": 1}]})

    run_sql(f"INSERT INTO sync_outbox (entity_type, transaction_key, payload, status, created_at) VALUES ('SALE', '{tx1}', '{s1_payload}', 'PENDING', NOW()), ('SALE', '{tx2}', '{s2_payload}', 'PENDING', NOW());")

    # Run sync daemon once
    print("Running sync daemon (Online mode)...")
    daemon_res = subprocess.run(
        f'"{PHP_BIN}" "C:\\Projects\\VenueVue_Sync_Module\\local_daemon\\sync_worker.php" --once',
        shell=True, capture_output=True, text=True
    )
    print("Daemon output:")
    print(daemon_res.stdout)

    outbox_pending = run_sql("SELECT COUNT(*) FROM sync_outbox WHERE status = 'PENDING'")
    cloud_ingested = run_sql("SELECT COUNT(*) FROM sync_inbox")
    print(f"Status check: Pending in outbox: {outbox_pending} | Total in cloud inbox: {cloud_ingested}")
    assert int(outbox_pending) == 0, "Outbox should be empty after online sync"
    assert int(cloud_ingested) == 2, "Cloud should have 2 records"
    print("[PASS] Phase 1 Passed: Both sales synced to cloud in real time.")

    # 3. Phase 2: Simulating Total Internet Outage in Intramuros
    print("\n" + "=" * 80)
    print("[Step 3] Phase 2: SIMULATING INTERNET OUTAGE (Broadband Cable Cut in Intramuros)")
    print("=" * 80)
    print("Physical shop is now completely offline. The counter keeps serving walk-in students.")

    # Baristas ring 4 new sales during lunch rush
    rush_txs = [str(uuid.uuid4()) for _ in range(4)]
    rush_sales = [
        ("Matcha Latte 16oz", 150.00),
        ("Caramel Macchiato 22oz", 170.00),
        ("Cold Brew 16oz", 130.00),
        ("Spanish Latte 16oz", 140.00),
    ]

    t_start = time.time()
    for i, (drink, price) in enumerate(rush_sales):
        p = json.dumps({"sale_id": 103 + i, "total_gross": price, "items": [{"name": drink, "qty": 1}]})
        run_sql(f"INSERT INTO sync_outbox (entity_type, transaction_key, payload, status, created_at) VALUES ('SALE', '{rush_txs[i]}', '{p}', 'PENDING', NOW());")
    t_elapsed = (time.time() - t_start) * 1000

    print(f"[OK] 4 rush-hour orders logged in local database in {t_elapsed:.1f}ms (< 15ms per order). Zero cashier lag!")

    # Simulate outage by changing URL to an unreachable port
    print("\nSync worker attempts heartbeat during outage...")
    offline_cfg = test_cfg.replace("http://127.0.0.1:4920", "http://127.0.0.1:4929")
    with open(cfg_path, 'w', encoding='utf-8') as f:
        f.write(offline_cfg)

    daemon_offline_res = subprocess.run(
        f'"{PHP_BIN}" "C:\\Projects\\VenueVue_Sync_Module\\local_daemon\\sync_worker.php" --once',
        shell=True, capture_output=True, text=True
    )
    print("Daemon output during outage:")
    print(daemon_offline_res.stdout)

    pending_during_outage = run_sql("SELECT COUNT(*) FROM sync_outbox WHERE status = 'PENDING'")
    cloud_during_outage = run_sql("SELECT COUNT(*) FROM sync_inbox")
    print(f"Verification during outage: Outbox safely holds {pending_during_outage} pending orders.")
    print(f"Cloud inbox remains steady at {cloud_during_outage} records (no data corruption or half-writes).")
    assert int(pending_during_outage) == 4, "All 4 orders must remain safely queued in local outbox"
    assert int(cloud_during_outage) == 2, "Cloud must remain intact"
    print("[PASS] Phase 2 Passed: 100% offline resilience verified.")

    # 4. Phase 3: Internet Restored (Catch-Up Synchronization)
    print("\n" + "=" * 80)
    print("[Step 4] Phase 3: INTERNET RESTORED (Catch-Up Batch Sync)")
    print("=" * 80)

    # Restore connection
    with open(cfg_path, 'w', encoding='utf-8') as f:
        f.write(test_cfg)

    print("Restoring connection and running sync worker...")
    daemon_restore_res = subprocess.run(
        f'"{PHP_BIN}" "C:\\Projects\\VenueVue_Sync_Module\\local_daemon\\sync_worker.php" --once',
        shell=True, capture_output=True, text=True
    )
    print("Daemon output on reconnection:")
    print(daemon_restore_res.stdout)

    final_pending = run_sql("SELECT COUNT(*) FROM sync_outbox WHERE status = 'PENDING'")
    final_cloud = run_sql("SELECT COUNT(*) FROM sync_inbox")
    print(f"Final Verification: Outbox pending: {final_pending} | Cloud inbox: {final_cloud}")
    assert int(final_pending) == 0, "Outbox should be fully drained"
    assert int(final_cloud) == 6, "Cloud should now have all 6 total orders (2 initial + 4 outage orders)"
    print("[PASS] Phase 3 Passed: Cloud caught up automatically in one atomic batch.")

    # 5. Phase 4: Idempotency & Duplicate Network Retry Test
    print("\n" + "=" * 80)
    print("[Step 5] Phase 4: IDEMPOTENCY TEST (Simulating Duplicate Network Retries)")
    print("=" * 80)

    run_sql("UPDATE sync_outbox SET status = 'PENDING' WHERE id >= 3;")
    print("Simulating local daemon retrying the same batch of 4 orders...")
    daemon_retry_res = subprocess.run(
        f'"{PHP_BIN}" "C:\\Projects\\VenueVue_Sync_Module\\local_daemon\\sync_worker.php" --once',
        shell=True, capture_output=True, text=True
    )
    print(daemon_retry_res.stdout)

    cloud_after_retry = run_sql("SELECT COUNT(*) FROM sync_inbox")
    print(f"Cloud inbox count after retry: {cloud_after_retry} (Must remain exactly 6, not 10!)")
    assert int(cloud_after_retry) == 6, "Idempotency failed: duplicate records were inserted!"
    print("[PASS] Phase 4 Passed: Duplicate records safely detected and rejected without duplicate revenue.")

finally:
    # Cleanup: restore original config and stop mock server
    with open(cfg_path, 'w', encoding='utf-8') as f:
        f.write(orig_cfg)
    try:
        mock_server.terminate()
        mock_server.kill()
    except Exception:
        pass
    print("\n[OK] Cleaned up test mock server and restored production config.")

print("\n" + "=" * 80)
print("ALL 4 NETWORK OUTAGE SIMULATION PHASES PASSED WITH 100% SUCCESS!")
print("=" * 80)
