<?php
declare(strict_types=1);

/**
 * Nearby Cafe - Safe Downstream Cloud Snapshot Reconciler (Legacy CLI Wrapper)
 * Synchronizes missing natural-keyed catalogue/staff metadata without destructive wipes
 * or raw primary-key collisions.
 */

$appDir = realpath(__DIR__ . "/../../rebuild/app");
if ($appDir === false || !is_file($appDir . "/sync.php")) {
    fwrite(STDERR, "[ERROR] Application sync module not found.\n");
    exit(1);
}

require_once $appDir . "/bootstrap.php";
require_once $appDir . "/sync.php";

$inputPath = $argv[1] ?? null;
if (!$inputPath || !is_file($inputPath)) {
    fwrite(STDERR, "[ERROR] Missing or invalid cloud data JSON path.\n");
    exit(1);
}

$rawJson = file_get_contents($inputPath);
$cloudData = json_decode((string) $rawJson, true);
if (!is_array($cloudData) || (!isset($cloudData["data"]) && !isset($cloudData["products"]))) {
    fwrite(STDERR, "[ERROR] Invalid cloud catalogue structure.\n");
    exit(1);
}

$data = is_array($cloudData["data"] ?? null) ? $cloudData["data"] : $cloudData;

try {
    $db = sync_cli_db();
    $applied = 0;

    foreach ($data["ingredients"] ?? [] as $ing) {
        if (!is_array($ing) || empty($ing["name"])) {
            continue;
        }
        $res = sync_apply_event($db, [
            "entity_type" => "CATALOGUE_INGREDIENT",
            "transaction_key" => "legacy-ing-" . bin2hex(random_bytes(6)),
            "payload" => $ing,
        ], "cloud-legacy", "legacy-sync-" . bin2hex(random_bytes(4)));
        if ($res["status"] === "APPLIED") {
            $applied++;
        }
    }

    foreach ($data["products"] ?? [] as $prod) {
        if (!is_array($prod) || empty($prod["name"])) {
            continue;
        }
        $res = sync_apply_event($db, [
            "entity_type" => "CATALOGUE_PRODUCT",
            "transaction_key" => "legacy-prod-" . bin2hex(random_bytes(6)),
            "payload" => $prod,
        ], "cloud-legacy", "legacy-sync-" . bin2hex(random_bytes(4)));
        if ($res["status"] === "APPLIED") {
            $applied++;
        }
    }

    $prodNamesById = [];
    foreach ($data["products"] ?? [] as $prod) {
        if (is_array($prod) && isset($prod["id"], $prod["name"])) {
            $prodNamesById[(int) $prod["id"]] = (string) $prod["name"];
        }
    }
    foreach ($data["variants"] ?? [] as $var) {
        if (!is_array($var) || empty($var["sku"])) {
            continue;
        }
        if (empty($var["product_name"]) && isset($var["product_id"])) {
            $var["product_name"] = $prodNamesById[(int) $var["product_id"]] ?? null;
        }
        if (empty($var["product_name"])) {
            continue;
        }
        $res = sync_apply_event($db, [
            "entity_type" => "CATALOGUE_VARIANT",
            "transaction_key" => "legacy-var-" . bin2hex(random_bytes(6)),
            "payload" => $var,
        ], "cloud-legacy", "legacy-sync-" . bin2hex(random_bytes(4)));
        if ($res["status"] === "APPLIED") {
            $applied++;
        }
    }

    echo json_encode(["ok" => true, "applied" => $applied], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Legacy sync failed: " . $e->getMessage() . "\n");
    exit(1);
}
