<?php
/**
 * VenueVue - Cloud to Local Catalogue & Recipe Synchronizer
 * ----------------------------------------------------------------------------
 * Synchronizes products, variants, ingredients, recipes, stock batches, and
 * containers from InfinityFree Cloud down into the local MariaDB database.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbName = 'venuevue_rebuild';

$inputPath = $argv[1] ?? null;
if (!$inputPath || !file_exists($inputPath)) {
    fwrite(STDERR, "[ERROR] Missing or invalid cloud data JSON path.\n");
    exit(1);
}

$rawJson = file_get_contents($inputPath);
$cloudData = json_decode($rawJson, true);

if (!isset($cloudData['data']) && !isset($cloudData['products'])) {
    fwrite(STDERR, "[ERROR] Invalid cloud catalogue structure.\n");
    exit(1);
}

$data = $cloudData['data'] ?? $cloudData;

$products = $data['products'] ?? [];
$variants = $data['variants'] ?? [];
$ingredients = $data['ingredients'] ?? [];
$recipes = $data['recipes'] ?? [];
$batches = $data['batches'] ?? [];
$containers = $data['containers'] ?? [];

echo "=======================================================\n";
echo "VENUEVUE · DOWNSTREAM CLOUD -> LOCAL SYNCHRONIZATION\n";
echo "=======================================================\n";
echo "Payload counts:\n";
echo " - Products:    " . count($products) . "\n";
echo " - Variants:    " . count($variants) . "\n";
echo " - Ingredients: " . count($ingredients) . "\n";
echo " - Recipes:     " . count($recipes) . "\n";
echo " - Batches:     " . count($batches) . "\n";
echo " - Containers:  " . count($containers) . "\n";
echo "-------------------------------------------------------\n";

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbName};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $pdo->beginTransaction();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 1. Synchronize Products
    $stmtProd = $pdo->prepare("INSERT INTO products (id, name, category, image_filename, is_active, is_vat_exempt, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            category = VALUES(category),
            image_filename = VALUES(image_filename),
            is_active = VALUES(is_active),
            is_vat_exempt = VALUES(is_vat_exempt),
            updated_at = VALUES(updated_at)");

    foreach ($products as $p) {
        $stmtProd->execute([
            $p['id'],
            $p['name'],
            $p['category'] ?? 'Coffee',
            $p['image_filename'] ?? null,
            $p['is_active'] ?? 1,
            $p['is_vat_exempt'] ?? 0,
            $p['created_at'] ?? date('Y-m-d H:i:s'),
            $p['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
    }
    echo "[✓] Synchronized " . count($products) . " products.\n";

    // 2. Synchronize Product Variants
    $pdo->exec("DELETE FROM product_variants;");
    $stmtVar = $pdo->prepare("INSERT INTO product_variants (id, product_id, size, temperature, sku, price, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($variants as $v) {
        $stmtVar->execute([
            $v['id'],
            $v['product_id'],
            $v['size'],
            $v['temperature'],
            $v['sku'],
            $v['price'],
            $v['is_active'] ?? 1
        ]);
    }
    echo "[✓] Synchronized " . count($variants) . " product variants.\n";

    // 3. Synchronize Ingredients
    $stmtIng = $pdo->prepare("INSERT INTO ingredients (id, name, unit, minimum_stock, current_stock, opened_shelf_life_hours, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            unit = VALUES(unit),
            minimum_stock = VALUES(minimum_stock),
            current_stock = VALUES(current_stock),
            opened_shelf_life_hours = VALUES(opened_shelf_life_hours),
            is_active = VALUES(is_active)");

    foreach ($ingredients as $i) {
        $stmtIng->execute([
            $i['id'],
            $i['name'],
            $i['unit'],
            $i['minimum_stock'] ?? 0,
            $i['current_stock'] ?? 0,
            $i['opened_shelf_life_hours'] ?? null,
            $i['is_active'] ?? 1
        ]);
    }
    echo "[✓] Synchronized " . count($ingredients) . " ingredients.\n";

    // 4. Synchronize Recipes (Wipe and re-insert exact mappings from cloud)
    $pdo->exec("DELETE FROM recipes");
    $stmtRec = $pdo->prepare("INSERT INTO recipes (variant_id, ingredient_id, qty) VALUES (?, ?, ?)");
    foreach ($recipes as $r) {
        $stmtRec->execute([
            $r['variant_id'],
            $r['ingredient_id'],
            $r['qty']
        ]);
    }
    echo "[✓] Synchronized " . count($recipes) . " recipe components.\n";

    // 5. Synchronize Stock Batches
    $stmtBatch = $pdo->prepare("INSERT INTO stock_batches (id, ingredient_id, delivery_key, request_hash, reference, supplier, lot_reference, expiration_date, received_qty, remaining_qty, qty_sealed, qty_opened, quarantined, user_id, received_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ingredient_id = VALUES(ingredient_id),
            delivery_key = VALUES(delivery_key),
            request_hash = VALUES(request_hash),
            reference = VALUES(reference),
            supplier = VALUES(supplier),
            lot_reference = VALUES(lot_reference),
            expiration_date = VALUES(expiration_date),
            received_qty = VALUES(received_qty),
            remaining_qty = VALUES(remaining_qty),
            qty_sealed = VALUES(qty_sealed),
            qty_opened = VALUES(qty_opened),
            quarantined = VALUES(quarantined),
            user_id = VALUES(user_id),
            received_at = VALUES(received_at)");

    foreach ($batches as $b) {
        $stmtBatch->execute([
            $b['id'],
            $b['ingredient_id'],
            $b['delivery_key'] ?? sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
            $b['request_hash'] ?? null,
            $b['reference'] ?? 'DEL-CLOUD',
            $b['supplier'] ?? null,
            $b['lot_reference'] ?? null,
            $b['expiration_date'] ?? null,
            $b['received_qty'] ?? 0,
            $b['remaining_qty'] ?? 0,
            $b['qty_sealed'] ?? 0,
            $b['qty_opened'] ?? 0,
            $b['quarantined'] ?? 0,
            $b['user_id'] ?? 1,
            $b['received_at'] ?? date('Y-m-d H:i:s')
        ]);
    }
    echo "[✓] Synchronized " . count($batches) . " delivery stock batches.\n";

    // 6. Synchronize Stock Containers
    if (!empty($containers)) {
        $stmtCont = $pdo->prepare("INSERT INTO stock_containers (id, batch_id, received_qty, remaining_qty, opened_at, use_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                batch_id = VALUES(batch_id),
                received_qty = VALUES(received_qty),
                remaining_qty = VALUES(remaining_qty),
                opened_at = VALUES(opened_at),
                use_by = VALUES(use_by)");

        foreach ($containers as $c) {
            $stmtCont->execute([
                $c['id'],
                $c['batch_id'],
                $c['received_qty'] ?? 0,
                $c['remaining_qty'] ?? 0,
                $c['opened_at'] ?? null,
                $c['use_by'] ?? null
            ]);
        }
        echo "[✓] Synchronized " . count($containers) . " stock containers.\n";
    }

    // 7. Synchronize Barista / Staff Accounts
    $staff = $data['staff'] ?? [];
    if (!empty($staff)) {
        $stmtStaff = $pdo->prepare("INSERT INTO users (id, username, display_name, email, password_hash, role, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'BARISTA', ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                email = VALUES(email),
                password_hash = VALUES(password_hash),
                is_active = VALUES(is_active),
                updated_at = NOW()");

        $count = 0;
        foreach ($staff as $s) {
            if (($s['role'] ?? '') === 'BARISTA') {
                $stmtStaff->execute([
                    $s['id'],
                    $s['username'],
                    $s['display_name'],
                    $s['email'] ?? null,
                    $s['password_hash'],
                    $s['is_active'] ?? 1
                ]);
                $count++;
            }
        }
        echo "[✓] Synchronized " . $count . " barista staff accounts.\n";
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    $pdo->commit();

    echo "-------------------------------------------------------\n";
    echo "SUCCESS: Local database is now 100% in sync with Cloud!\n";
    echo "=======================================================\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[EXCEPTION] Sync failed: " . $e->getMessage() . "\n");
    exit(1);
}
