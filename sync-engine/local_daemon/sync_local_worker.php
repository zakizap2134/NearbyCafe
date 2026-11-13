<?php
declare(strict_types=1);

/**
 * Nearby Cafe Bidirectional Sync Worker (Local CLI)
 * Uses canonical rebuild/app/bootstrap.php + sync.php helpers with JSON stdio so
 * payloads, product images, and UTF-8 text pass cleanly without shell escaping bugs.
 */

$appDir = realpath(__DIR__ . "/../../rebuild/app");
if ($appDir === false || !is_file($appDir . "/sync.php")) {
    fwrite(STDERR, json_encode(["ok" => false, "error" => "Application sync module not found."]) . PHP_EOL);
    exit(1);
}

require_once $appDir . "/bootstrap.php";
require_once $appDir . "/sync.php";

function worker_read_stdin_json(): array
{
    $raw = stream_get_contents(STDIN);
    if (!is_string($raw) || trim($raw) === "") {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON input passed to sync_local_worker.php.");
    }
    return $decoded;
}

function worker_pending(PDO $db, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $pendingTotal = (int) $db->query("SELECT COUNT(*) FROM sync_outbox WHERE status='PENDING'")->fetchColumn();
    $rows = $db
        ->query("SELECT id, entity_type, transaction_key, payload, attempts, created_at FROM sync_outbox WHERE status='PENDING' ORDER BY id ASC LIMIT " . $limit)
        ->fetchAll();

    $events = [];
    foreach ($rows as $row) {
        $payload = json_decode((string) $row["payload"], true);
        if (!is_array($payload)) {
            $payload = [];
        }
        if (
            in_array($row["entity_type"], ["CATALOGUE_PRODUCT", "CATALOGUE_SAVE"], true) &&
            ($row["entity_type"] === "CATALOGUE_PRODUCT" || ($payload["action"] ?? "") === "product") &&
            empty($payload["remove_image"]) &&
            empty($payload["image_base64"]) &&
            !empty($payload["image_filename"])
        ) {
            $b64 = sync_encode_product_image((string) $payload["image_filename"]);
            if ($b64 !== null) {
                $payload["image_base64"] = $b64;
            }
        }
        $events[] = [
            "id" => (int) $row["id"],
            "entity_type" => $row["entity_type"],
            "transaction_key" => $row["transaction_key"],
            "payload" => $payload,
            "created_at" => $row["created_at"],
        ];
    }
    return [
        "ok" => true,
        "pending_total" => $pendingTotal,
        "events" => $events,
    ];
}

function worker_mark_synced(PDO $db, array $input): array
{
    $ids = array_values(array_filter(array_map("intval", $input["ids"] ?? []), fn($id) => $id > 0));
    if ($ids) {
        $placeholders = implode(",", array_fill(0, count($ids), "?"));
        query(
            $db,
            "UPDATE sync_outbox SET status='SYNCED', synced_at=NOW(), error_message=NULL WHERE id IN (" . $placeholders . ")",
            $ids,
        );
    }
    return ["ok" => true, "updated" => count($ids)];
}

function worker_mark_failed(PDO $db, array $input): array
{
    $failures = $input["failures"] ?? [];
    $updated = 0;
    foreach ($failures as $f) {
        if (!is_array($f) || empty($f["id"])) {
            continue;
        }
        $id = (int) $f["id"];
        $err = substr((string) ($f["error"] ?? "Sync error"), 0, 500);
        query(
            $db,
            "UPDATE sync_outbox
             SET attempts = attempts + 1,
                 error_message = ?,
                 status = CASE WHEN attempts + 1 >= 30 THEN 'FAILED' ELSE 'PENDING' END
             WHERE id = ?",
            [$err, $id],
        );
        $updated++;
    }
    return ["ok" => true, "updated" => $updated];
}

function worker_apply_events(PDO $db, array $input): array
{
    $shopId = trim((string) ($input["shop_id"] ?? "cloud-main"));
    $events = $input["events"] ?? [];
    $acked = [];
    $failed = [];
    $applied = 0;
    $skipped = 0;

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $cloudId = isset($event["id"]) ? (int) $event["id"] : 0;
        try {
            $res = sync_apply_event($db, $event, $shopId, "sync-pull-" . bin2hex(random_bytes(4)));
            if ($res["status"] === "SKIPPED_DUPLICATE") {
                $skipped++;
            } else {
                $applied++;
            }
            if ($cloudId > 0) {
                $acked[] = $cloudId;
            }
        } catch (Throwable $e) {
            if ($cloudId > 0) {
                $failed[] = ["id" => $cloudId, "error" => $e->getMessage()];
            }
        }
    }
    return [
        "ok" => true,
        "applied" => $applied,
        "skipped" => $skipped,
        "acked_ids" => $acked,
        "failed" => $failed,
    ];
}

function worker_status_meta(PDO $db, array $input): array
{
    $updates = $input["updates"] ?? [];
    if (is_array($updates)) {
        foreach ($updates as $k => $v) {
            if (!is_string($k) || trim($k) === "") {
                continue;
            }
            query(
                $db,
                "INSERT INTO sync_meta (meta_key, meta_value, updated_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()",
                [substr(trim($k), 0, 64), $v === null ? "" : (string) $v],
            );
        }
    }
    $pending = (int) $db->query("SELECT COUNT(*) FROM sync_outbox WHERE status='PENDING'")->fetchColumn();
    $failed = (int) $db->query("SELECT COUNT(*) FROM sync_outbox WHERE status='FAILED'")->fetchColumn();
    return ["ok" => true, "pending" => $pending, "failed" => $failed];
}

function worker_diff_reconcile(PDO $db, array $input): array
{
    $cloud = $input["cloud"] ?? null;
    if (!is_array($cloud)) {
        throw new RuntimeException("cloud snapshot is required for diff-reconcile.");
    }
    $local = sync_snapshot_data($db);
    $appliedLocally = 0;
    $pushEvents = [];
    $downloadImages = [];
    $errors = [];

    $localIngByName = [];
    foreach ($local["ingredients"] as $ing) {
        $localIngByName[strtolower((string) $ing["name"])] = $ing;
    }
    $cloudIngByName = [];
    foreach ($cloud["ingredients"] ?? [] as $ing) {
        if (is_array($ing) && !empty($ing["name"])) {
            $cloudIngByName[strtolower((string) $ing["name"])] = $ing;
        }
    }

    // 1. Reconcile missing ingredients both ways
    foreach ($cloudIngByName as $key => $cIng) {
        if (!isset($localIngByName[$key])) {
            try {
                sync_apply_event($db, [
                    "entity_type" => "CATALOGUE_INGREDIENT",
                    "transaction_key" => "rec-pull-ing-" . bin2hex(random_bytes(6)),
                    "payload" => $cIng,
                ], "cloud-reconcile", "reconcile-ing-" . bin2hex(random_bytes(4)));
                $appliedLocally++;
                $localIngByName[$key] = $cIng;
            } catch (Throwable $e) {
                $errors[] = "Ing pull (" . $cIng["name"] . "): " . $e->getMessage();
            }
        }
    }
    foreach ($localIngByName as $key => $lIng) {
        if (!isset($cloudIngByName[$key])) {
            $pushEvents[] = [
                "entity_type" => "CATALOGUE_INGREDIENT",
                "transaction_key" => "rec-push-ing-" . sha1($key),
                "payload" => $lIng,
            ];
        }
    }

    // 2. Reconcile missing products and missing product images both ways
    $localProdByName = [];
    foreach ($local["products"] as $p) {
        $localProdByName[strtolower((string) $p["name"])] = $p;
    }
    $cloudProdByName = [];
    foreach ($cloud["products"] ?? [] as $p) {
        if (is_array($p) && !empty($p["name"])) {
            $cloudProdByName[strtolower((string) $p["name"])] = $p;
        }
    }

    foreach ($cloudProdByName as $key => $cProd) {
        if (!isset($localProdByName[$key])) {
            try {
                sync_apply_event($db, [
                    "entity_type" => "CATALOGUE_PRODUCT",
                    "transaction_key" => "rec-pull-prod-" . bin2hex(random_bytes(6)),
                    "payload" => $cProd + ["image_filename" => $cProd["image_filename"] ?? null],
                ], "cloud-reconcile", "reconcile-prod-" . bin2hex(random_bytes(4)));
                $appliedLocally++;
                if (!empty($cProd["image_filename"]) && !empty($cProd["image_exists"])) {
                    $downloadImages[] = (string) $cProd["image_filename"];
                }
            } catch (Throwable $e) {
                $errors[] = "Prod pull (" . $cProd["name"] . "): " . $e->getMessage();
            }
        } else {
            $lProd = $localProdByName[$key];
            if (!empty($cProd["image_filename"]) && !empty($cProd["image_exists"])) {
                if (empty($lProd["image_filename"])) {
                    sync_suppress_outbox(true);
                    try {
                        query(
                            $db,
                            "UPDATE products SET image_filename=? WHERE LOWER(name)=LOWER(?)",
                            [$cProd["image_filename"], $lProd["name"]],
                        );
                    } finally {
                        sync_suppress_outbox(false);
                    }
                    $downloadImages[] = (string) $cProd["image_filename"];
                } elseif ($lProd["image_filename"] === $cProd["image_filename"] && empty($lProd["image_exists"])) {
                    $downloadImages[] = (string) $cProd["image_filename"];
                }
            }
        }
    }
    foreach ($localProdByName as $key => $lProd) {
        $cProd = $cloudProdByName[$key] ?? null;
        $needPush = !$cProd || (!empty($lProd["image_exists"]) && empty($cProd["image_exists"]) && empty($cProd["image_filename"]));
        if ($needPush) {
            $payload = $lProd;
            if (!empty($lProd["image_filename"]) && !empty($lProd["image_exists"])) {
                $b64 = sync_encode_product_image((string) $lProd["image_filename"]);
                if ($b64 !== null) {
                    $payload["image_base64"] = $b64;
                }
            }
            $pushEvents[] = [
                "entity_type" => "CATALOGUE_PRODUCT",
                "transaction_key" => "rec-push-prod-" . sha1($key . "-" . ($lProd["image_filename"] ?? "")),
                "payload" => $payload,
            ];
        }
    }

    // 3. Reconcile missing variants both ways
    $localVarBySku = [];
    $localVarByChoice = [];
    foreach ($local["variants"] as $v) {
        $localVarBySku[$v["sku"]] = $v;
        $localVarByChoice[strtolower($v["product_name"]) . "|" . $v["size"] . "|" . $v["temperature"]] = $v;
    }
    $cloudVarBySku = [];
    $cloudVarByChoice = [];
    foreach ($cloud["variants"] ?? [] as $v) {
        if (is_array($v) && !empty($v["sku"])) {
            $cloudVarBySku[$v["sku"]] = $v;
            $cloudVarByChoice[strtolower((string) $v["product_name"]) . "|" . $v["size"] . "|" . $v["temperature"]] = $v;
        }
    }
    foreach ($cloudVarBySku as $sku => $cVar) {
        $cKey = strtolower((string) $cVar["product_name"]) . "|" . $cVar["size"] . "|" . $cVar["temperature"];
        if (!isset($localVarBySku[$sku]) && !isset($localVarByChoice[$cKey])) {
            try {
                sync_apply_event($db, [
                    "entity_type" => "CATALOGUE_VARIANT",
                    "transaction_key" => "rec-pull-var-" . bin2hex(random_bytes(6)),
                    "payload" => $cVar,
                ], "cloud-reconcile", "reconcile-var-" . bin2hex(random_bytes(4)));
                $appliedLocally++;
                $localVarBySku[$sku] = $cVar;
                $localVarByChoice[$cKey] = $cVar;
            } catch (Throwable $e) {
                $errors[] = "Var pull (" . $sku . "): " . $e->getMessage();
            }
        }
    }
    foreach ($localVarBySku as $sku => $lVar) {
        $lKey = strtolower((string) $lVar["product_name"]) . "|" . $lVar["size"] . "|" . $lVar["temperature"];
        if (!isset($cloudVarBySku[$sku]) && !isset($cloudVarByChoice[$lKey])) {
            $pushEvents[] = [
                "entity_type" => "CATALOGUE_VARIANT",
                "transaction_key" => "rec-push-var-" . sha1($sku),
                "payload" => $lVar,
            ];
        }
    }

    // 4. Reconcile missing recipes when destination variant currently has 0 recipe rows
    $localRecipesBySku = [];
    foreach ($local["recipes"] as $r) {
        $localRecipesBySku[$r["variant_sku"]][] = [
            "ingredient_name" => $r["ingredient_name"],
            "qty" => $r["qty"],
        ];
    }
    $cloudRecipesBySku = [];
    foreach ($cloud["recipes"] ?? [] as $r) {
        if (is_array($r) && !empty($r["variant_sku"])) {
            $cloudRecipesBySku[$r["variant_sku"]][] = [
                "ingredient_name" => $r["ingredient_name"],
                "qty" => $r["qty"],
            ];
        }
    }
    foreach ($cloudRecipesBySku as $sku => $cRows) {
        if (empty($localRecipesBySku[$sku])) {
            try {
                sync_apply_event($db, [
                    "entity_type" => "CATALOGUE_RECIPE",
                    "transaction_key" => "rec-pull-rec-" . bin2hex(random_bytes(6)),
                    "payload" => ["variant_sku" => $sku, "rows" => $cRows],
                ], "cloud-reconcile", "reconcile-rec-" . bin2hex(random_bytes(4)));
                $appliedLocally++;
            } catch (Throwable $e) {
                $errors[] = "Recipe pull (" . $sku . "): " . $e->getMessage();
            }
        }
    }
    foreach ($localRecipesBySku as $sku => $lRows) {
        if (empty($cloudRecipesBySku[$sku])) {
            $vMeta = $localVarBySku[$sku] ?? null;
            $pushEvents[] = [
                "entity_type" => "CATALOGUE_RECIPE",
                "transaction_key" => "rec-push-rec-" . sha1($sku),
                "payload" => [
                    "variant_sku" => $sku,
                    "product_name" => $vMeta["product_name"] ?? null,
                    "size" => $vMeta["size"] ?? null,
                    "temperature" => $vMeta["temperature"] ?? null,
                    "rows" => $lRows,
                ],
            ];
        }
    }

    // 4b. Reconcile missing stock batches & containers both ways (by delivery_key)
    $localBatchesByKey = [];
    foreach ($local["batches"] ?? [] as $b) {
        if (is_array($b) && !empty($b["delivery_key"])) {
            $localBatchesByKey[$b["delivery_key"]] = $b;
        }
    }
    $cloudBatchesByKey = [];
    foreach ($cloud["batches"] ?? [] as $b) {
        if (is_array($b) && !empty($b["delivery_key"])) {
            $cloudBatchesByKey[$b["delivery_key"]] = $b;
        }
    }
    foreach ($cloudBatchesByKey as $dk => $cBatch) {
        if (!isset($localBatchesByKey[$dk])) {
            try {
                sync_apply_event($db, [
                    "entity_type" => "STOCK_BATCH_SNAPSHOT",
                    "transaction_key" => "rec-pull-batch-" . $dk,
                    "payload" => $cBatch,
                ], "cloud-reconcile", "reconcile-batch-" . bin2hex(random_bytes(4)));
                $appliedLocally++;
                $localBatchesByKey[$dk] = $cBatch;
            } catch (Throwable $e) {
                $errors[] = "Batch pull (" . $dk . "): " . $e->getMessage();
            }
        }
    }
    foreach ($localBatchesByKey as $dk => $lBatch) {
        if (!isset($cloudBatchesByKey[$dk])) {
            $pushEvents[] = [
                "entity_type" => "STOCK_BATCH_SNAPSHOT",
                "transaction_key" => "rec-push-batch-" . $dk,
                "payload" => $lBatch,
            ];
        }
    }

    // 5. Reconcile missing barista accounts both ways (matched by username, never raw id)
    $localStaffByUser = [];
    foreach ($local["staff"] as $s) {
        $localStaffByUser[strtolower((string) $s["username"])] = $s;
    }
    $cloudStaffByUser = [];
    foreach ($cloud["staff"] ?? [] as $s) {
        if (is_array($s) && !empty($s["username"])) {
            $cloudStaffByUser[strtolower((string) $s["username"])] = $s;
        }
    }
    foreach ($cloudStaffByUser as $uKey => $cStaff) {
        if (!isset($localStaffByUser[$uKey])) {
            try {
                sync_apply_event($db, [
                    "entity_type" => "STAFF_SAVE",
                    "transaction_key" => "rec-pull-staff-" . bin2hex(random_bytes(6)),
                    "payload" => $cStaff,
                ], "cloud-reconcile", "reconcile-staff-" . bin2hex(random_bytes(4)));
                $appliedLocally++;
            } catch (Throwable $e) {
                $errors[] = "Staff pull (" . $cStaff["username"] . "): " . $e->getMessage();
            }
        }
    }
    foreach ($localStaffByUser as $uKey => $lStaff) {
        if (!isset($cloudStaffByUser[$uKey])) {
            $pushEvents[] = [
                "entity_type" => "STAFF_SAVE",
                "transaction_key" => "rec-push-staff-" . sha1($uKey),
                "payload" => $lStaff,
            ];
        }
    }

    // 6. Owner profile fallback if one side is missing email
    $lOwner = $local["owner"] ?? null;
    $cOwner = is_array($cloud["owner"] ?? null) ? $cloud["owner"] : null;
    if ($lOwner && $cOwner) {
        if (empty($lOwner["email"]) && !empty($cOwner["email"])) {
            try {
                sync_apply_event($db, [
                    "entity_type" => "OWNER_PROFILE",
                    "transaction_key" => "rec-pull-owner-" . bin2hex(random_bytes(6)),
                    "payload" => $cOwner,
                ], "cloud-reconcile", "reconcile-owner-" . bin2hex(random_bytes(4)));
                $appliedLocally++;
            } catch (Throwable $e) {
                $errors[] = "Owner pull: " . $e->getMessage();
            }
        } elseif (!empty($lOwner["email"]) && empty($cOwner["email"])) {
            $pushEvents[] = [
                "entity_type" => "OWNER_PROFILE",
                "transaction_key" => "rec-push-owner-" . sha1((string) $lOwner["email"]),
                "payload" => $lOwner,
            ];
        }
    }

    // 7. Reconcile missing historical sales & historical voids in both directions
    $localSalesMap = [];
    foreach ($local["sales_keys"] as $s) {
        $localSalesMap[$s["transaction_key"]] = $s["status"] ?? "completed";
    }
    $cloudSalesMap = [];
    foreach ($cloud["sales_keys"] ?? [] as $s) {
        if (is_array($s) && !empty($s["transaction_key"])) {
            $cloudSalesMap[$s["transaction_key"]] = $s["status"] ?? "completed";
        }
    }

    $pullSaleKeys = [];
    foreach ($cloudSalesMap as $txKey => $cStatus) {
        $lStatus = $localSalesMap[$txKey] ?? null;
        if ($lStatus === null || ($lStatus === "completed" && $cStatus === "voided")) {
            $pullSaleKeys[] = $txKey;
        }
    }

    $pushSaleKeys = [];
    $pushSaleVoidOverride = [];
    foreach ($localSalesMap as $txKey => $lStatus) {
        $cStatus = $cloudSalesMap[$txKey] ?? null;
        if ($cStatus === null) {
            $pushSaleKeys[] = $txKey;
        } elseif ($cStatus === "completed" && $lStatus === "voided") {
            $pushSaleKeys[] = $txKey;
            $pushSaleVoidOverride[$txKey] = true;
        }
    }

    if ($pushSaleKeys) {
        foreach (array_slice(sync_export_sales_by_keys($db, array_slice($pushSaleKeys, 0, 50)), 0, 50) as $ev) {
            $rawTx = (string) ($ev["payload"]["transaction_key"] ?? "");
            if (isset($pushSaleVoidOverride[$rawTx])) {
                $ev["transaction_key"] = "hist-void-" . $rawTx;
            }
            $pushEvents[] = $ev;
        }
    }

    return [
        "ok" => true,
        "applied_locally" => $appliedLocally,
        "push_events" => $pushEvents,
        "pull_sale_keys" => array_slice($pullSaleKeys, 0, 50),
        "remaining_pull_sales" => max(0, count($pullSaleKeys) - 50),
        "remaining_push_sales" => max(0, count($pushSaleKeys) - 50),
        "download_images" => array_values(array_unique($downloadImages)),
        "errors" => $errors,
    ];
}

function worker_save_image(array $input): array
{
    $filename = trim((string) ($input["filename"] ?? ""));
    $base64 = trim((string) ($input["base64"] ?? ""));
    if (!preg_match('/^prod_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|webp)$/D', $filename, $m)) {
        throw new RuntimeException("Invalid product image filename.");
    }
    if ($base64 === "") {
        throw new RuntimeException("Image base64 content is required.");
    }
    $bytes = base64_decode($base64, true);
    if (!is_string($bytes) || strlen($bytes) < 12 || strlen($bytes) > 2 * 1024 * 1024) {
        throw new RuntimeException("Image bytes invalid or too large.");
    }
    $dest = product_uploads_dir() . "/" . $filename;
    if (@file_put_contents($dest, $bytes, LOCK_EX) === false) {
        throw new RuntimeException("Failed to save downloaded image to disk.");
    }
    return ["ok" => true, "filename" => $filename];
}

try {
    $cmd = $argv[1] ?? "";
    $db = sync_cli_db();
    $limit = 25;
    foreach (array_slice($argv, 2) as $arg) {
        if (str_starts_with($arg, "--limit=")) {
            $limit = (int) substr($arg, 8);
        }
    }
    $result = match ($cmd) {
        "pending" => worker_pending($db, $limit),
        "mark-synced" => worker_mark_synced($db, worker_read_stdin_json()),
        "mark-failed" => worker_mark_failed($db, worker_read_stdin_json()),
        "apply-events" => worker_apply_events($db, worker_read_stdin_json()),
        "status-meta" => worker_status_meta($db, worker_read_stdin_json()),
        "diff-reconcile" => worker_diff_reconcile($db, worker_read_stdin_json()),
        "save-image" => worker_save_image(worker_read_stdin_json()),
        default => throw new RuntimeException("Unknown worker command: " . $cmd),
    };
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(["ok" => false, "error" => $e->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
