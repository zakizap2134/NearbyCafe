<?php
/**
 * VenueVue Store-and-Forward Local Synchronization Daemon
 * ----------------------------------------------------------------------------
 * Runs on the shop PC. Pings the cloud, flushes pending outbox records,
 * and gracefully pauses whenever the internet is down.
 */

declare(strict_types=1);

$config = require __DIR__ . '/../config/sync_config.php';

function getLocalPdo(array $dbCfg): ?PDO {
    try {
        $dsn = "mysql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['name']};charset=utf8mb4";
        return new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        echo "[" . date('H:i:s') . "] [ERROR] Local DB connection failed: " . $e->getMessage() . "\n";
        return null;
    }
}

function checkCloudHealth(string $baseUrl, int $timeoutSec, string $token): array {
    $url = rtrim($baseUrl, '/') . '/sync_health.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeoutSec,
        CURLOPT_TIMEOUT => $timeoutSec + 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $httpCode !== 200) {
        return ['online' => false, 'error' => $curlErr ?: "HTTP status $httpCode"];
    }

    $json = json_decode($resp, true);
    if (!is_array($json) || empty($json['ok'])) {
        return ['online' => false, 'error' => 'Invalid JSON from health endpoint'];
    }

    return ['online' => true, 'data' => $json];
}

function pushBatchToCloud(string $baseUrl, int $timeoutSec, string $token, string $shopId, array $records): array {
    $url = rtrim($baseUrl, '/') . '/sync_ingest.php';
    $payload = json_encode([
        'shop_id'   => $shopId,
        'batch_id'  => 'batch_' . time() . '_' . bin2hex(random_bytes(4)),
        'sent_at'   => date('c'),
        'count'     => count($records),
        'records'   => array_map(function($r) {
            return [
                'outbox_id'       => (int) $r['id'],
                'entity_type'     => $r['entity_type'],
                'transaction_key' => $r['transaction_key'],
                'payload'         => json_decode($r['payload'], true),
                'created_at'      => $r['created_at'],
            ];
        }, $records),
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => $timeoutSec,
        CURLOPT_TIMEOUT => $timeoutSec + 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $httpCode !== 200) {
        return ['ok' => false, 'error' => $curlErr ?: "HTTP status $httpCode"];
    }

    $json = json_decode($resp, true);
    return is_array($json) ? $json : ['ok' => false, 'error' => 'Malformed JSON response'];
}

function runSyncCycle(PDO $pdo, array $config): void {
    $timestamp = date('Y-m-d H:i:s');

    // 1. Check pending count
    $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM sync_outbox WHERE status = 'PENDING'");
    $pendingCount = (int) $stmt->fetch()['cnt'];

    // 2. Ping Cloud Health
    $health = checkCloudHealth(
        $config['cloud_api_url'], 
        $config['connect_timeout_seconds'], 
        $config['sync_secret_token']
    );

    if (!$health['online']) {
        // INTERNET IS DOWN OR CLOUD UNREACHABLE
        echo "[$timestamp] [OFFLINE] Internet or Cloud unreachable ({$health['error']}). Outbox queue preserved: $pendingCount pending events.\n";
        
        $pdo->prepare("
            UPDATE sync_meta SET meta_value = 'OFFLINE' WHERE meta_key = 'sync_status'
        ")->execute();
        $pdo->prepare("
            UPDATE sync_meta SET meta_value = :now WHERE meta_key = 'last_sync_attempt_at'
        ")->execute([':now' => $timestamp]);
        return;
    }

    // INTERNET IS ONLINE
    $pdo->prepare("
        UPDATE sync_meta SET meta_value = 'ONLINE' WHERE meta_key = 'sync_status'
    ")->execute();

    if ($pendingCount === 0) {
        echo "[$timestamp] [ONLINE - IDLE] Cloud reachable. Outbox is clean (0 pending events).\n";
        return;
    }

    echo "[$timestamp] [ONLINE - SYNCING] Cloud reachable! Processing $pendingCount pending outbox events...\n";

    // 3. Fetch batch
    $batchStmt = $pdo->prepare("
        SELECT id, entity_type, transaction_key, payload, created_at
        FROM sync_outbox
        WHERE status = 'PENDING'
        ORDER BY id ASC
        LIMIT :limit
    ");
    $batchStmt->bindValue(':limit', (int) $config['batch_size'], PDO::PARAM_INT);
    $batchStmt->execute();
    $records = $batchStmt->fetchAll();

    if (empty($records)) return;

    $ids = array_column($records, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Mark as IN_FLIGHT
    $pdo->prepare("UPDATE sync_outbox SET status = 'IN_FLIGHT', attempts = attempts + 1 WHERE id IN ($placeholders)")->execute($ids);

    // 4. Push to Cloud
    $pushRes = pushBatchToCloud(
        $config['cloud_api_url'],
        $config['request_timeout_seconds'],
        $config['sync_secret_token'],
        $config['shop_id'],
        $records
    );

    if (!empty($pushRes['ok'])) {
        // SUCCESS: Mark as SYNCED
        $pdo->prepare("
            UPDATE sync_outbox 
            SET status = 'SYNCED', synced_at = NOW(), error_message = NULL 
            WHERE id IN ($placeholders)
        ")->execute($ids);

        $pdo->prepare("
            UPDATE sync_meta SET meta_value = NOW() WHERE meta_key = 'last_successful_sync_at'
        ")->execute();

        $accepted = $pushRes['data']['accepted_count'] ?? count($ids);
        $dupes = $pushRes['data']['duplicates_ignored'] ?? 0;
        echo "[$timestamp] [SYNC SUCCESS] Pushed " . count($ids) . " events to cloud (Accepted: $accepted, Duplicates: $dupes).\n";
    } else {
        // FAILED: Rollback to PENDING
        $errMsg = $pushRes['error'] ?? 'Unknown push error';
        echo "[$timestamp] [SYNC FAILED] Push rejected: $errMsg. Resetting batch to PENDING for retry.\n";
        $pdo->prepare("
            UPDATE sync_outbox 
            SET status = 'PENDING', error_message = :err 
            WHERE id IN ($placeholders)
        ")->execute(array_merge($ids, [':err' => $errMsg]));
    }
}

// ----------------------------------------------------------------------------
// Execution Entry Point
// ----------------------------------------------------------------------------
$isOnce = in_array('--once', $argv, true);
echo "=== VenueVue Store-and-Forward Sync Daemon Started ===\n";
echo "Shop ID: " . $config['shop_id'] . "\n";
echo "Target Cloud: " . $config['cloud_api_url'] . "\n";
echo "Mode: " . ($isOnce ? "Single Execution" : "Continuous Loop ({$config['daemon_sleep_seconds']}s sleep)") . "\n\n";

$pdo = getLocalPdo($config['local_db']);
if (!$pdo) exit(1);

do {
    runSyncCycle($pdo, $config);
    if ($isOnce) break;
    sleep((int) $config['daemon_sleep_seconds']);
} while (true);
