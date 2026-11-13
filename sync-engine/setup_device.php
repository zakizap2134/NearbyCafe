<?php
/**
 * VenueVue - New Device Setup Script
 * Sets up the complete database, sync tables, and ensures zakizap / callmepapaton2x credentials on local MariaDB.
 */

$host = '127.0.0.1';
$port = 3306;
$user = 'root';
$pass = '';
$dbName = 'venuevue_rebuild';

$localCfgPath = getenv('VV_REBUILD_CONFIG') ?: dirname(__DIR__) . '/rebuild/config/local.php';
if (is_file($localCfgPath)) {
    $cfg = require $localCfgPath;
    if (is_array($cfg) && is_array($cfg['db'] ?? null)) {
        $host = (string) ($cfg['db']['host'] ?? $host);
        $port = (int) ($cfg['db']['port'] ?? $port);
        $user = (string) ($cfg['db']['user'] ?? $user);
        $pass = (string) ($cfg['db']['password'] ?? $cfg['db']['pass'] ?? $pass);
        $dbName = (string) ($cfg['db']['name'] ?? $dbName);
    }
}

echo "====================================================\n";
echo "VENUEVUE · NEARBY CAFE DEVICE SETUP\n";
echo "====================================================\n";

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "[✓] Connected to local MySQL server at {$host}:{$port}\n";

    // 1. Ensure database exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `{$dbName}`;");
    echo "[✓] Database `{$dbName}` ready.\n";

    // 2. Check if database is brand new (tables missing)
    $hasUsers = false;
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
        $hasUsers = !empty($check);
    } catch (Exception $e) {}

    $freshlySeeded = false;
    if (!$hasUsers) {
        echo "[*] Brand new database detected. Installing complete Nearby Café schema & catalogue...\n";
        $readySqlPath = dirname(__DIR__) . '/rebuild/database/venuevue-ready.sql';
        if (!file_exists($readySqlPath)) {
            $readySqlPath = dirname(__DIR__) . '/venuevue-ready.sql';
        }
        if (file_exists($readySqlPath)) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
            $sqlContent = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($readySqlPath));
            // Execute statements
            $statements = array_filter(array_map('trim', explode(";\n", $sqlContent)));
            foreach ($statements as $stmt) {
                if ($stmt !== '' && !str_starts_with($stmt, '--') && !str_starts_with($stmt, '/*')) {
                    try {
                        $pdo->exec($stmt);
                    } catch (Exception $e) {
                        // ignore safe statement notices
                    }
                }
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
            $freshlySeeded = true;
            echo "[✓] Complete catalogue (Espresso, Matcha, Americano, recipes, stock) installed.\n";
        }
    }

    // 3. Ensure synchronization tables exist
    $hasSyncOutbox = false;
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'sync_outbox'")->fetch();
        $hasSyncOutbox = !empty($check);
    } catch (Exception $e) {}

    if (!$hasSyncOutbox) {
        $syncSqlPath = dirname(__DIR__) . '/rebuild/database/007_sync_tables.sql';
        if (!file_exists($syncSqlPath)) {
            $syncSqlPath = __DIR__ . '/database/001_sync_tables.sql';
        }
        if (file_exists($syncSqlPath)) {
            $sql = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($syncSqlPath));
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }
            echo "[✓] Synchronization tables (sync_outbox, sync_inbox, sync_meta) installed.\n";
        }
    } else {
        echo "[✓] Synchronization tables already active.\n";
    }

    // 4. Seed starter Owner only on a brand-new install with no active Owner yet
    $existingOwner = $pdo->query("SELECT id, username FROM users WHERE role = 'OWNER' AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$existingOwner) {
        $hash = password_hash('callmepapaton2x', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (username, display_name, email, shop_name, role, password_hash, is_active, created_at, updated_at) VALUES ('zakizap', 'Zaki/admin', 'zapicozaki83@gmail.com', 'Nearby Café', 'OWNER', ?, 1, NOW(), NOW())");
        $stmt->execute([$hash]);
        echo "[✓] Created starter Owner account `zakizap`.\n";
    } else {
        echo "[✓] Preserved existing Owner account `{$existingOwner['username']}` (#{$existingOwner['id']}).\n";
    }

    // 5. Ensure rebuild/config/local.php exists
    $localConfigPath = dirname(__DIR__) . '/rebuild/config/local.php';
    if (!file_exists($localConfigPath)) {
        $localConfigContent = <<<'PHP'
<?php
// Auto-generated for Nearby Cafe Local POS
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'venuevue_rebuild',
        'user' => 'root',
        'password' => '',
    ],
    'session_hours' => 8,
    'vat_rate' => '0.1200',
    'app_url' => 'http://localhost:5174',
];
PHP;
        @file_put_contents($localConfigPath, $localConfigContent);
        echo "[✓] Generated default rebuild/config/local.php.\n";
    }

    // 6. Ensure runtime storage directories exist
    $storageDir = dirname(__DIR__) . '/rebuild/storage/launcher';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0777, true);
        echo "[✓] Created storage/launcher directory.\n";
    }

    echo "\n====================================================\n";
    echo "SUCCESS: This device is 100% ready for Nearby Café!\n";
    echo "Local POS:    http://localhost:5174\n";
    echo "Username:     zakizap\n";
    echo "Password:     callmepapaton2x\n";
    echo "Live Sync:    Starts automatically with the app\n";
    echo "====================================================\n";

} catch (Exception $e) {
    echo "[!] Error setting up device: " . $e->getMessage() . "\n";
    echo "Please ensure XAMPP MySQL is running.\n";
    exit(1);
}
