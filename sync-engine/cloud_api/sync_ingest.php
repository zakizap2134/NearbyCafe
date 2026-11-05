<?php
/**
 * VenueVue Cloud Ingestion Endpoint
 * ----------------------------------------------------------------------------
 * Receives batches of transactions from the physical shop daemon.
 * Enforces strict idempotency via transaction_key to prevent duplicate counting.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config/sync_config.php';

// 1. Authenticate Request
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches) || !hash_equals($config['sync_secret_token'], trim($matches[1]))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// 2. Read JSON Input
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload) || empty($payload['records']) || !is_array($payload['records'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or empty batch payload']);
    exit;
}

$shopId = (string) ($payload['shop_id'] ?? 'unknown_shop');
$records = $payload['records'];

// 3. Connect to Cloud Database
try {
    $dbCfg = $config['cloud_db'];
    // If running in local simulation, fallback to local DB credentials if cloud host fails
    $dsn = "mysql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // In local testing/simulation mode:
    try {
        $dbCfg = $config['local_db'];
        $dsn = "mysql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e2) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database connection failed: ' . $e2->getMessage()]);
        exit;
    }
}

// 4. Atomic Ingestion with Idempotency
$accepted = 0;
$duplicates = 0;
$errors = [];

$checkStmt = $pdo->prepare("SELECT id FROM sync_inbox WHERE transaction_key = :tx_key");
$insertInboxStmt = $pdo->prepare("
    INSERT INTO sync_inbox (transaction_key, entity_type, source_shop_id, payload, received_at, status)
    VALUES (:tx_key, :entity_type, :source_shop_id, :payload, NOW(), :status)
");

$pdo->beginTransaction();

try {
    foreach ($records as $item) {
        $txKey = (string) ($item['transaction_key'] ?? '');
        $entityType = (string) ($item['entity_type'] ?? 'SALE');
        $rawPayload = json_encode($item['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (empty($txKey)) {
            continue;
        }

        // Idempotency Check
        $checkStmt->execute([':tx_key' => $txKey]);
        if ($checkStmt->fetch()) {
            // Already ingested previously! Safely skip to prevent double counting.
            $duplicates++;
            continue;
        }

        // Record into sync_inbox
        $insertInboxStmt->execute([
            ':tx_key'         => $txKey,
            ':entity_type'    => $entityType,
            ':source_shop_id' => $shopId,
            ':payload'        => $rawPayload,
            ':status'         => 'APPLIED',
        ]);

        $accepted++;
    }

    // Update Cloud Metadata
    $pdo->prepare("
        INSERT INTO sync_meta (meta_key, meta_value) 
        VALUES ('last_cloud_ingest_at', NOW()) 
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
    ")->execute();

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Batch processing error: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'ok' => true,
    'data' => [
        'shop_id'            => $shopId,
        'batch_id'           => $payload['batch_id'] ?? '',
        'accepted_count'     => $accepted,
        'duplicates_ignored' => $duplicates,
        'config_version'     => 1,
        'processed_at'       => date('c'),
    ],
], JSON_PRETTY_PRINT);
