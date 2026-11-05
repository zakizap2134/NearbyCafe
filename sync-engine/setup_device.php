<?php
/**
 * VenueVue - New Device Setup Script
 * Sets up sync tables and ensures zakizap / callmepapaton2x credentials on local MariaDB.
 */

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbName = 'venuevue_rebuild';

echo "====================================================\n";
echo "VENUEVUE · NEW DEVICE CONFIGURATION\n";
echo "====================================================\n";

try {
    $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "[✓] Connected to local MySQL server at {$host}:3306\n";

    // 1. Ensure database exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `{$dbName}`;");
    echo "[✓] Database `{$dbName}` ready.\n";

    // 2. Import sync tables
    $syncSqlPath = __DIR__ . '/database/001_sync_tables.sql';
    if (file_exists($syncSqlPath)) {
        $sql = file_get_contents($syncSqlPath);
        $pdo->exec($sql);
        echo "[✓] Synchronization tables (sync_outbox, sync_inbox, sync_meta) installed.\n";
    }

    // 3. Ensure zakizap account exists with password callmepapaton2x
    $hash = password_hash('callmepapaton2x', PASSWORD_BCRYPT);
    $existingUser = $pdo->query("SELECT id FROM users WHERE username = 'zakizap' OR id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if ($existingUser) {
        $stmt = $pdo->prepare("UPDATE users SET username = 'zakizap', display_name = 'Zaki/admin', email = 'zapicozaki83@gmail.com', shop_name = 'Nearby Café', role = 'OWNER', password_hash = ?, is_active = 1 WHERE id = ?");
        $stmt->execute([$hash, $existingUser['id']]);
        echo "[✓] Updated existing user #{$existingUser['id']} to `zakizap` with role OWNER.\n";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, display_name, email, shop_name, role, password_hash, is_active, created_at, updated_at) VALUES ('zakizap', 'Zaki/admin', 'zapicozaki83@gmail.com', 'Nearby Café', 'OWNER', ?, 1, NOW(), NOW())");
        $stmt->execute([$hash]);
        echo "[✓] Created new Owner account `zakizap`.\n";
    }

    echo "\n====================================================\n";
    echo "SUCCESS: This device is now ready for Nearby Café!\n";
    echo "URL:         http://localhost:5174/#/login\n";
    echo "Username:    zakizap\n";
    echo "Password:    callmepapaton2x\n";
    echo "Sync Bridge: sync-engine\\Run_InfinityFree_Live_Sync.bat\n";
    echo "====================================================\n";

} catch (Exception $e) {
    echo "[!] Error setting up device: " . $e->getMessage() . "\n";
    echo "Please ensure XAMPP MySQL is running.\n";
    exit(1);
}
