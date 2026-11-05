<?php
/**
 * VenueVue Cloud Sync Telemetry Portal
 * ----------------------------------------------------------------------------
 * Visual status dashboard for the Shop Owner to inspect sync health.
 */

declare(strict_types=1);

$config = require __DIR__ . '/../config/sync_config.php';

try {
    $dbCfg = $config['cloud_db'];
    $dsn = "mysql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    try {
        $dbCfg = $config['local_db'];
        $dsn = "mysql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Exception $e2) {
        die("Database connection failed: " . htmlspecialchars($e2->getMessage()));
    }
}

// Fetch telemetry
$metaRows = $pdo->query("SELECT meta_key, meta_value FROM sync_meta")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalIngested = (int) $pdo->query("SELECT COUNT(*) FROM sync_inbox")->fetchColumn();
$recentItems = $pdo->query("SELECT id, transaction_key, entity_type, source_shop_id, status, received_at FROM sync_inbox ORDER BY id DESC LIMIT 15")->fetchAll();

$lastSync = $metaRows['last_cloud_ingest_at'] ?? 'Never';
$isRecentlySynced = ($lastSync !== 'Never' && (time() - strtotime($lastSync)) < 180);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VenueVue · Cloud Sync Telemetry</title>
    <style>
        :root {
            --bg: #fbf9f5;
            --surface: #ffffff;
            --border: #e6dfd5;
            --ink: #1c1917;
            --muted: #78716c;
            --red: #e01e14;
            --green: #16a34a;
            --amber: #d97706;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--ink); margin: 0; padding: 30px; }
        .container { max-width: 960px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
        .brand { display: flex; align-items: center; gap: 14px; }
        .brand h1 { margin: 0; font-size: 22px; font-weight: 800; }
        .brand p { margin: 0; font-size: 13px; color: var(--muted); }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 700; }
        .badge.online { background: #dcfce7; color: #15803d; }
        .badge.offline { background: #fee2e2; color: #b91c1c; }
        .badge .dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
        .card-label { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em; margin-bottom: 6px; }
        .card-val { font-size: 26px; font-weight: 800; color: var(--ink); }

        .feed-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
        .feed-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .feed-header h2 { margin: 0; font-size: 16px; font-weight: 750; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 10px 12px; color: var(--muted); font-size: 12px; border-bottom: 1px solid var(--border); }
        td { padding: 12px; border-bottom: 1px solid #f4f0eb; font-family: monospace; }
        .tag { padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; font-family: sans-serif; }
        .tag-applied { background: #e0f2fe; color: #0369a1; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="brand">
                <div>
                    <h1>VenueVue · Cloud Sync Telemetry</h1>
                    <p>Nearby Café · Intramuros Shop Store-and-Forward Monitor</p>
                </div>
            </div>
            <div>
                <?php if ($isRecentlySynced): ?>
                    <div class="badge online"><span class="dot"></span> SHOP ONLINE (SYNCED)</div>
                <?php else: ?>
                    <div class="badge offline"><span class="dot"></span> SHOP OFFLINE / QUEUED</div>
                <?php endif; ?>
            </div>
        </header>

        <div class="stats-grid">
            <div class="card">
                <div class="card-label">Shop Identity</div>
                <div class="card-val" style="font-size: 18px;"><?= htmlspecialchars($config['shop_name']) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Total Transactions Ingested</div>
                <div class="card-val"><?= number_format($totalIngested) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Last Successful Ingestion</div>
                <div class="card-val" style="font-size: 16px; font-family: monospace;"><?= htmlspecialchars($lastSync) ?></div>
            </div>
        </div>

        <div class="feed-card">
            <div class="feed-header">
                <h2>Recent Ingested Events (Idempotent Stream)</h2>
                <span style="font-size: 12px; color: var(--muted);">Showing latest <?= count($recentItems) ?> records</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Transaction Key (Idempotency)</th>
                        <th>Received At</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentItems)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--muted); font-family: sans-serif; padding: 24px;">No transactions ingested yet. Waiting for local shop push.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentItems as $r): ?>
                            <tr>
                                <td>#<?= htmlspecialchars((string) $r['id']) ?></td>
                                <td><strong><?= htmlspecialchars($r['entity_type']) ?></strong></td>
                                <td style="color: #6366f1;"><?= htmlspecialchars($r['transaction_key']) ?></td>
                                <td><?= htmlspecialchars($r['received_at']) ?></td>
                                <td><span class="tag tag-applied"><?= htmlspecialchars($r['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
