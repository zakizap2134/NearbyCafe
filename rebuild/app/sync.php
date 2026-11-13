<?php
declare(strict_types=1);

require_once __DIR__ . "/bootstrap.php";
require_once __DIR__ . "/catalogue.php";

/**
 * Resolve a user's lowercase username by primary key, returning null if absent.
 */
function sync_username_by_id(PDO $db, ?int $userId): ?string
{
    if ($userId === null || $userId <= 0) {
        return null;
    }
    $val = query($db, "SELECT username FROM users WHERE id=?", [$userId])->fetchColumn();
    return is_string($val) && $val !== "" ? $val : null;
}

/**
 * Resolve a destination user ID by natural username, optionally falling back to active OWNER.
 */
function sync_resolve_user_id(PDO $db, mixed $username, bool $fallbackToOwner = true): int
{
    if (is_string($username) && trim($username) !== "") {
        $id = query(
            $db,
            "SELECT id FROM users WHERE LOWER(username)=LOWER(?)",
            [trim($username)],
        )->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }
    if ($fallbackToOwner) {
        $owner = $db
            ->query("SELECT id FROM users WHERE role='OWNER' AND is_active=1 ORDER BY id LIMIT 1")
            ->fetchColumn();
        if ($owner) {
            return (int) $owner;
        }
        $any = $db->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
        if ($any) {
            return (int) $any;
        }
    }
    throw new RuntimeException("No matching user account found on destination.");
}

/**
 * Encode a product image file from disk as base64 if present and within size limits.
 */
function sync_encode_product_image(?string $filename): ?string
{
    $path = find_product_image_path($filename);
    if ($path === null) {
        return null;
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) < 12 || strlen($bytes) > 2 * 1024 * 1024) {
        return null;
    }
    return base64_encode($bytes);
}

/**
 * Validate and store base64-encoded product image bytes safely to disk.
 */
function sync_store_product_image_bytes(
    int $productId,
    ?string $preferredFilename,
    ?string $base64,
    ?string $oldFilename,
): ?string {
    if (!is_string($base64) || trim($base64) === "") {
        return $oldFilename;
    }
    $bytes = base64_decode(trim($base64), true);
    if (!is_string($bytes) || strlen($bytes) < 12 || strlen($bytes) > 2 * 1024 * 1024) {
        throw new RuntimeException("Synced image payload is invalid or exceeds 2 MB.");
    }
    $ext = null;
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        $ext = "jpg";
    } elseif (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
        $ext = "png";
    } elseif (substr($bytes, 0, 4) === "RIFF" && substr($bytes, 8, 4) === "WEBP") {
        $ext = "webp";
    } else {
        throw new RuntimeException("Synced image format must be JPG, PNG, or WebP.");
    }
    if (function_exists("getimagesizefromstring")) {
        $info = @getimagesizefromstring($bytes);
        if (
            !is_array($info) ||
            (int) ($info[0] ?? 0) < 1 ||
            (int) ($info[1] ?? 0) < 1 ||
            (int) $info[0] > 4096 ||
            (int) $info[1] > 4096
        ) {
            throw new RuntimeException("Synced image dimensions are invalid.");
        }
    }
    $targetFilename = null;
    if (
        is_string($preferredFilename) &&
        preg_match('/^prod_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|webp)$/D', $preferredFilename, $m) &&
        strtolower($m[1]) === $ext
    ) {
        $targetFilename = $preferredFilename;
    } else {
        $targetFilename = "prod_" . $productId . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
    }
    $targetPath = product_uploads_dir() . "/" . $targetFilename;
    if ($oldFilename !== null && $oldFilename !== "" && $oldFilename !== $targetFilename) {
        remove_product_image_file($oldFilename);
    }
    if (@file_put_contents($targetPath, $bytes, LOCK_EX) === false) {
        throw new RuntimeException("Could not write synced product image.");
    }
    return $targetFilename;
}

/**
 * Look up portable batch + 0-based container index for a stock container ID.
 */
function sync_container_ref(PDO $db, int $containerId): ?array
{
    $row = query(
        $db,
        "SELECT c.id, c.batch_id, b.delivery_key, i.name AS ingredient_name
         FROM stock_containers c
         JOIN stock_batches b ON b.id = c.batch_id
         JOIN ingredients i ON i.id = b.ingredient_id
         WHERE c.id = ?",
        [$containerId],
    )->fetch();
    if (!$row) {
        return null;
    }
    $index = (int) query(
        $db,
        "SELECT COUNT(*) FROM stock_containers WHERE batch_id = ? AND id < ?",
        [(int) $row["batch_id"], $containerId],
    )->fetchColumn();
    return [
        "delivery_key" => $row["delivery_key"],
        "ingredient_name" => $row["ingredient_name"],
        "container_index" => $index,
    ];
}

function sync_resolve_product(PDO $db, mixed $matchName, mixed $name): ?array
{
    foreach ([$matchName, $name] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== "") {
            $row = query(
                $db,
                "SELECT * FROM products WHERE LOWER(name) = LOWER(?)",
                [trim($candidate)],
            )->fetch();
            if ($row) {
                return $row;
            }
        }
    }
    return null;
}

function sync_resolve_ingredient(PDO $db, mixed $matchName, mixed $name): ?array
{
    foreach ([$matchName, $name] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== "") {
            $row = query(
                $db,
                "SELECT * FROM ingredients WHERE LOWER(name) = LOWER(?)",
                [trim($candidate)],
            )->fetch();
            if ($row) {
                return $row;
            }
        }
    }
    return null;
}

function sync_resolve_variant(
    PDO $db,
    mixed $matchSku,
    mixed $sku,
    mixed $productName,
    mixed $size,
    mixed $temperature,
): ?array {
    foreach ([$matchSku, $sku] as $candidateSku) {
        if (is_string($candidateSku) && trim($candidateSku) !== "") {
            $row = query(
                $db,
                "SELECT v.*, p.name AS product_name, p.is_active AS product_active, p.is_vat_exempt
                 FROM product_variants v
                 JOIN products p ON p.id = v.product_id
                 WHERE v.sku = ?",
                [trim($candidateSku)],
            )->fetch();
            if ($row) {
                return $row;
            }
        }
    }
    if (
        is_string($productName) &&
        trim($productName) !== "" &&
        is_string($size) &&
        is_string($temperature)
    ) {
        $row = query(
            $db,
            "SELECT v.*, p.name AS product_name, p.is_active AS product_active, p.is_vat_exempt
             FROM product_variants v
             JOIN products p ON p.id = v.product_id
             WHERE LOWER(p.name) = LOWER(?) AND v.size = ? AND v.temperature = ?",
            [trim($productName), $size, $temperature],
        )->fetch();
        if ($row) {
            return $row;
        }
    }
    return null;
}

function sync_valid_datetime(mixed $value, string $fallback): string
{
    if (
        is_string($value) &&
        preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', trim($value))
    ) {
        return trim($value);
    }
    return $fallback;
}

/**
 * Resolve or backfill an archived variant for historical receipt import so FK constraints hold.
 */
function sync_resolve_or_archive_variant(PDO $db, array $item): array
{
    $found = sync_resolve_variant(
        $db,
        null,
        $item["sku"] ?? null,
        $item["product_name"] ?? null,
        $item["size"] ?? null,
        $item["temperature"] ?? null,
    );
    if ($found) {
        return $found;
    }
    $productName = trim((string) ($item["product_name"] ?? ""));
    if ($productName === "") {
        $label = trim((string) ($item["variant_label"] ?? "Archived Item"));
        $productName = preg_replace('/\s+(S|M|L)(\s+(HOT|ICED))?$/', '', $label) ?: "Archived Item";
    }
    $category = trim((string) ($item["category"] ?? "Archived"));
    if ($category === "") {
        $category = "Archived";
    }
    $prod = sync_resolve_product($db, null, $productName);
    if (!$prod) {
        query(
            $db,
            "INSERT INTO products (name, category, image_filename, is_active, created_at, updated_at)
             VALUES (?, ?, NULL, 0, NOW(), NOW())",
            [substr($productName, 0, 120), substr($category, 0, 60)],
        );
        $productId = (int) $db->lastInsertId();
    } else {
        $productId = (int) $prod["id"];
    }
    $size = in_array($item["size"] ?? "", ["S", "M", "L"], true) ? $item["size"] : "M";
    $temp = in_array($item["temperature"] ?? "", ["HOT", "ICED", "NA"], true) ? $item["temperature"] : "NA";
    $existingByChoice = query(
        $db,
        "SELECT v.*, p.name AS product_name FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.product_id=? AND v.size=? AND v.temperature=?",
        [$productId, $size, $temp],
    )->fetch();
    if ($existingByChoice) {
        return $existingByChoice;
    }
    $rawSku = trim((string) ($item["sku"] ?? ""));
    $sku = $rawSku !== "" && preg_match('/^[A-Za-z0-9._-]+$/D', $rawSku)
        ? $rawSku
        : "ARCH-" . $productId . "-" . $size . "-" . $temp . "-" . substr(bin2hex(random_bytes(3)), 0, 6);
    if (query($db, "SELECT id FROM product_variants WHERE sku=?", [$sku])->fetch()) {
        $sku = substr($sku, 0, 50) . "-" . substr(bin2hex(random_bytes(3)), 0, 6);
    }
    $unitPrice = fixed($item["unit_price"] ?? $item["expected_price"] ?? "0.01", 2, "Price");
    query(
        $db,
        "INSERT INTO product_variants (product_id, size, temperature, sku, price, is_active)
         VALUES (?, ?, ?, ?, ?, 0)",
        [$productId, $size, $temp, $sku, decimal($unitPrice)],
    );
    $vid = (int) $db->lastInsertId();
    return query(
        $db,
        "SELECT v.*, p.name AS product_name FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=?",
        [$vid],
    )->fetch();
}

function sync_apply_sale(PDO $db, array $payload, string $txKey, string $requestId): string
{
    $saleKey = trim((string) ($payload["transaction_key"] ?? $txKey));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $saleKey)) {
        throw new RuntimeException("Invalid transaction_key for synced sale.");
    }
    $existing = query(
        $db,
        "SELECT id FROM sales WHERE transaction_key=? FOR UPDATE",
        [$saleKey],
    )->fetch();
    if ($existing) {
        return "SKIPPED_DUPLICATE";
    }
    $items = $payload["items"] ?? null;
    if (!is_array($items) || count($items) < 1) {
        throw new RuntimeException("Synced sale contains no items.");
    }
    $at = sync_valid_datetime($payload["sale_at"] ?? null, database_time($db));
    $cashierId = sync_resolve_user_id($db, $payload["cashier_username"] ?? null, true);

    $lines = [];
    $requirements = [];
    $gross = 0;
    $netSum = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new RuntimeException("Invalid item entry in synced sale.");
        }
        $qty = positive_id($item["qty"] ?? null, "qty", 1000);
        $variant = sync_resolve_variant(
            $db,
            null,
            $item["sku"] ?? null,
            $item["product_name"] ?? null,
            $item["size"] ?? null,
            $item["temperature"] ?? null,
        );
        if (!$variant && isset($item["variant_id"]) && is_numeric($item["variant_id"])) {
            $variant = query(
                $db,
                "SELECT v.*, p.name AS product_name FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=?",
                [(int) $item["variant_id"]],
            )->fetch() ?: null;
        }
        if (!$variant) {
            throw new RuntimeException("Variant not found for SKU " . ($item["sku"] ?? "(unknown)"));
        }
        $price = fixed($item["expected_price"] ?? $item["unit_price"] ?? $variant["price"], 2, "Price");
        $g = $price * $qty;
        $n = intdiv($g * 100 + 56, 112);
        $gross += $g;
        $netSum += $n;

        $recipes = query(
            $db,
            "SELECT r.ingredient_id, r.qty FROM recipes r WHERE r.variant_id=?",
            [(int) $variant["id"]],
        )->fetchAll();
        foreach ($recipes as $r) {
            $iid = (int) $r["ingredient_id"];
            $need = fixed($r["qty"], 3, "Recipe") * $qty;
            $requirements[$iid] = ($requirements[$iid] ?? 0) + $need;
        }

        $label = trim((string) ($item["variant_label"] ?? ""));
        if ($label === "") {
            $label = $variant["product_name"] . " " . $variant["size"] .
                ($variant["temperature"] === "NA" ? "" : " " . $variant["temperature"]);
        }
        $lines[] = [
            "variant_id" => (int) $variant["id"],
            "label" => substr($label, 0, 180),
            "qty" => $qty,
            "unit_price" => $price,
            "gross" => $g,
            "net" => $n,
        ];
    }
    $net = intdiv($gross * 100 + 56, 112);
    $lines[count($lines) - 1]["net"] += $net - $netSum;

    $cash = fixed($payload["cash_received"] ?? decimal($gross), 2, "Cash received", true);
    if ($cash < $gross) {
        $cash = $gross;
    }
    $paymentMethod = is_string($payload["payment_method"] ?? null) && trim($payload["payment_method"]) !== ""
        ? strtoupper(substr(trim($payload["payment_method"]), 0, 24))
        : "CASH";
    $paymentRef = is_string($payload["payment_reference"] ?? null) && trim($payload["payment_reference"]) !== ""
        ? substr(trim($payload["payment_reference"]), 0, 80)
        : null;
    $customerName = is_string($payload["customer_name"] ?? null) && trim($payload["customer_name"]) !== ""
        ? substr(trim($payload["customer_name"]), 0, 100)
        : null;

    ksort($requirements);
    $plans = [];
    foreach ($requirements as $iid => $need) {
        $ingredient = query($db, "SELECT * FROM ingredients WHERE id=? FOR UPDATE", [$iid])->fetch();
        if (!$ingredient) {
            continue;
        }
        $containers = stock_containers($db, $iid, true);
        $eligible = array_values(array_filter($containers, fn($c) => stock_eligible($c, $at)));
        usort(
            $eligible,
            fn($a, $b) => [
                $a["opened_at"] === null ? 1 : 0,
                $a["expiration_date"] ?? "9999-12-31",
                $a["use_by"] ?? "9999-12-31",
                $a["id"],
            ] <=> [
                $b["opened_at"] === null ? 1 : 0,
                $b["expiration_date"] ?? "9999-12-31",
                $b["use_by"] ?? "9999-12-31",
                $b["id"],
            ],
        );
        $remainingNeed = $need;
        $allocations = [];
        $taken = 0;
        foreach ($eligible as $c) {
            $take = min($remainingNeed, fixed($c["remaining_qty"], 3, "stock", true));
            if ($take > 0) {
                $allocations[] = ["container" => $c, "take" => $take];
                $taken += $take;
                $remainingNeed -= $take;
            }
            if ($remainingNeed === 0) {
                break;
            }
        }
        $plans[$iid] = [
            "ingredient" => $ingredient,
            "allocations" => $allocations,
            "taken" => $taken,
            "shortfall" => $remainingNeed,
        ];
    }

    $hash = hash("sha256", json_encode(["sync_key" => $saleKey, "items" => $lines], JSON_THROW_ON_ERROR));
    query(
        $db,
        "INSERT INTO sales (transaction_key, request_hash, user_id, sale_at, payment_method, payment_reference, customer_name, cash_received, change_due, total_gross, total_net, total_vat, vat_rate)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $saleKey,
            $hash,
            $cashierId,
            $at,
            $paymentMethod,
            $paymentRef,
            $customerName,
            decimal($cash),
            decimal($cash - $gross),
            decimal($gross),
            decimal($net),
            decimal($gross - $net),
            "0.1200",
        ],
    );
    $saleId = (int) $db->lastInsertId();
    foreach ($lines as $line) {
        query(
            $db,
            "INSERT INTO sale_items (sale_id, variant_id, variant_label, qty, unit_price, line_gross, line_net, line_vat)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $saleId,
                $line["variant_id"],
                $line["label"],
                $line["qty"],
                decimal($line["unit_price"]),
                decimal($line["gross"]),
                decimal($line["net"]),
                decimal($line["gross"] - $line["net"]),
            ],
        );
    }

    $touchedBatches = [];
    $shortfalls = [];
    foreach ($plans as $iid => $plan) {
        foreach ($plan["allocations"] as $alloc) {
            $c = $alloc["container"];
            $take = $alloc["take"];
            if ($c["opened_at"] === null) {
                $hours = $plan["ingredient"]["opened_shelf_life_hours"];
                $useBy = $hours === null
                    ? null
                    : (new DateTimeImmutable($at))->modify("+" . (int) $hours . " hours")->format("Y-m-d H:i:s");
                query($db, "UPDATE stock_containers SET opened_at=?, use_by=? WHERE id=?", [$at, $useBy, $c["id"]]);
            }
            query(
                $db,
                "UPDATE stock_containers SET remaining_qty = remaining_qty - ? WHERE id = ?",
                [decimal($take, 3), $c["id"]],
            );
            $touchedBatches[(int) $c["batch_id"]] = true;
            record_movement($db, $iid, (int) $c["batch_id"], (int) $c["id"], $saleId, $cashierId, "SALE", -$take, "Sale #" . $saleId, $at);
        }
        $curStock = fixed($plan["ingredient"]["current_stock"], 3, "Stock", true);
        $stockDeduct = min($curStock, $plan["taken"]);
        if ($stockDeduct > 0) {
            query($db, "UPDATE ingredients SET current_stock = current_stock - ? WHERE id = ?", [decimal($stockDeduct, 3), $iid]);
        }
        if ($plan["shortfall"] > 0) {
            $shortfalls[] = [
                "ingredient" => $plan["ingredient"]["name"],
                "shortfall" => decimal($plan["shortfall"], 3),
            ];
        }
    }
    foreach (array_keys($touchedBatches) as $bid) {
        update_batch_totals($db, (int) $bid);
    }
    if ($shortfalls) {
        audit($db, $cashierId, "SYNC_STOCK_SHORTFALL", $requestId, [
            "transaction_key" => $saleKey,
            "sale_id" => $saleId,
            "shortfalls" => $shortfalls,
        ]);
    }
    audit($db, $cashierId, "SALE_SYNCED", $requestId, ["transaction_key" => $saleKey, "sale_id" => $saleId]);
    return "APPLIED";
}

function sync_apply_sale_history(PDO $db, array $payload, string $txKey, string $requestId): string
{
    $saleKey = trim((string) ($payload["transaction_key"] ?? $txKey));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $saleKey)) {
        throw new RuntimeException("Invalid transaction_key for historical sale.");
    }
    $existing = query($db, "SELECT * FROM sales WHERE transaction_key=? FOR UPDATE", [$saleKey])->fetch();
    if ($existing) {
        $incomingStatus = ($payload["status"] ?? "completed") === "voided" ? "voided" : "completed";
        if (($existing["status"] ?? "completed") === "completed" && $incomingStatus === "voided") {
            $voidedBy = sync_resolve_user_id($db, $payload["voided_by_username"] ?? null, true);
            $voidedAt = sync_valid_datetime($payload["voided_at"] ?? null, database_time($db));
            $voidReason = substr((string) ($payload["void_reason"] ?? "Synced void"), 0, 255);
            query(
                $db,
                "UPDATE sales SET status='voided', void_reason=?, voided_by=?, voided_at=? WHERE id=?",
                [$voidReason, $voidedBy, $voidedAt, (int) $existing["id"]],
            );
            audit($db, $voidedBy, "SALE_VOID_RECONCILED", $requestId, ["transaction_key" => $saleKey]);
            return "APPLIED";
        }
        return "SKIPPED_DUPLICATE";
    }
    $items = $payload["items"] ?? null;
    if (!is_array($items) || count($items) < 1) {
        throw new RuntimeException("Historical sale contains no items.");
    }
    $saleAt = sync_valid_datetime($payload["sale_at"] ?? null, database_time($db));
    $cashierId = sync_resolve_user_id($db, $payload["cashier_username"] ?? null, true);
    $status = ($payload["status"] ?? "completed") === "voided" ? "voided" : "completed";
    $voidReason = $status === "voided" ? substr((string) ($payload["void_reason"] ?? "Voided"), 0, 255) : null;
    $voidedBy = $status === "voided" ? sync_resolve_user_id($db, $payload["voided_by_username"] ?? null, true) : null;
    $voidedAt = $status === "voided" ? sync_valid_datetime($payload["voided_at"] ?? null, $saleAt) : null;

    $reqHash = is_string($payload["request_hash"] ?? null) && strlen($payload["request_hash"]) === 64
        ? $payload["request_hash"]
        : hash("sha256", json_encode(["history_key" => $saleKey, "items" => $items], JSON_THROW_ON_ERROR));

    query(
        $db,
        "INSERT INTO sales (transaction_key, request_hash, user_id, sale_at, payment_method, payment_reference, customer_name, cash_received, change_due, total_gross, total_net, total_vat, vat_rate, status, void_reason, voided_by, voided_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $saleKey,
            $reqHash,
            $cashierId,
            $saleAt,
            strtoupper(substr(trim((string) ($payload["payment_method"] ?? "CASH")), 0, 24)) ?: "CASH",
            is_string($payload["payment_reference"] ?? null) && trim($payload["payment_reference"]) !== ""
                ? substr(trim($payload["payment_reference"]), 0, 80) : null,
            is_string($payload["customer_name"] ?? null) && trim($payload["customer_name"]) !== ""
                ? substr(trim($payload["customer_name"]), 0, 100) : null,
            decimal(fixed($payload["cash_received"] ?? $payload["total_gross"] ?? "0.00", 2, "Cash", true)),
            decimal(fixed($payload["change_due"] ?? "0.00", 2, "Change", true)),
            decimal(fixed($payload["total_gross"] ?? "0.00", 2, "Gross", true)),
            decimal(fixed($payload["total_net"] ?? "0.00", 2, "Net", true)),
            decimal(fixed($payload["total_vat"] ?? "0.00", 2, "VAT", true)),
            (string) ($payload["vat_rate"] ?? "0.1200"),
            $status,
            $voidReason,
            $voidedBy,
            $voidedAt,
        ],
    );
    $saleId = (int) $db->lastInsertId();
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $variant = sync_resolve_or_archive_variant($db, $item);
        query(
            $db,
            "INSERT INTO sale_items (sale_id, variant_id, variant_label, qty, unit_price, line_gross, line_net, line_vat)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $saleId,
                (int) $variant["id"],
                substr((string) ($item["variant_label"] ?? $variant["product_name"]), 0, 180),
                positive_id($item["qty"] ?? 1, "qty", 10000),
                decimal(fixed($item["unit_price"] ?? "0.00", 2, "Price", true)),
                decimal(fixed($item["line_gross"] ?? "0.00", 2, "Gross", true)),
                decimal(fixed($item["line_net"] ?? "0.00", 2, "Net", true)),
                decimal(fixed($item["line_vat"] ?? "0.00", 2, "VAT", true)),
            ],
        );
    }
    audit($db, $cashierId, "SALE_HISTORY_RECONCILED", $requestId, ["transaction_key" => $saleKey, "sale_id" => $saleId]);
    return "APPLIED";
}

function sync_apply_sale_void(PDO $db, array $payload, string $requestId): string
{
    $saleKey = trim((string) ($payload["transaction_key"] ?? ""));
    if ($saleKey === "") {
        throw new RuntimeException("Missing transaction_key in SALE_VOID payload.");
    }
    $sale = query($db, "SELECT * FROM sales WHERE transaction_key=? FOR UPDATE", [$saleKey])->fetch();
    if (!$sale) {
        throw new RuntimeException("Parent sale not found yet for void: " . $saleKey);
    }
    if (($sale["status"] ?? "completed") === "voided") {
        return "SKIPPED_DUPLICATE";
    }
    $saleId = (int) $sale["id"];
    $ownerId = sync_resolve_user_id($db, $payload["voided_by_username"] ?? null, true);
    $at = sync_valid_datetime($payload["voided_at"] ?? null, database_time($db));
    $reason = substr(trim((string) ($payload["reason"] ?? "Synced void")), 0, 255) ?: "Synced void";
    $restoreStock = (bool) ($payload["restore_stock"] ?? false);

    $movements = query(
        $db,
        "SELECT * FROM stock_movements WHERE sale_id=? AND type='SALE' ORDER BY id FOR UPDATE",
        [$saleId],
    )->fetchAll();
    if ($restoreStock) {
        $batches = [];
        foreach ($movements as $m) {
            $take = fixed(ltrim((string) $m["quantity_delta"], "-"), 3, "Sale deduction", true);
            $c = query($db, "SELECT * FROM stock_containers WHERE id=? FOR UPDATE", [$m["container_id"]])->fetch();
            query($db, "SELECT id FROM ingredients WHERE id=? FOR UPDATE", [$m["ingredient_id"]])->fetch();
            if ($c) {
                $rem = fixed($c["remaining_qty"], 3, "Remaining", true);
                $rec = fixed($c["received_qty"], 3, "Received", true);
                $restoreQty = min($take, max(0, $rec - $rem));
                if ($restoreQty > 0) {
                    query($db, "UPDATE stock_containers SET remaining_qty = remaining_qty + ? WHERE id = ?", [decimal($restoreQty, 3), $c["id"]]);
                    query($db, "UPDATE ingredients SET current_stock = current_stock + ? WHERE id = ?", [decimal($restoreQty, 3), $m["ingredient_id"]]);
                    $batches[(int) $c["batch_id"]] = true;
                }
                record_movement($db, (int) $m["ingredient_id"], (int) $m["batch_id"], (int) $m["container_id"], $saleId, $ownerId, "ADJUSTMENT", $restoreQty, substr("Void sale #" . $saleId . " (stock restored): " . $reason, 0, 255), $at);
            }
        }
        foreach (array_keys($batches) as $bid) {
            update_batch_totals($db, (int) $bid);
        }
    } else {
        foreach ($movements as $m) {
            record_movement($db, (int) $m["ingredient_id"], (int) $m["batch_id"], (int) $m["container_id"], $saleId, $ownerId, "ADJUSTMENT", 0, substr("Void sale #" . $saleId . " (stock wasted): " . $reason, 0, 255), $at);
        }
    }
    query(
        $db,
        "UPDATE sales SET status='voided', void_reason=?, voided_by=?, voided_at=? WHERE id=?",
        [$reason, $ownerId, $at, $saleId],
    );
    audit($db, $ownerId, "SALE_VOIDED_SYNC", $requestId, ["transaction_key" => $saleKey, "sale_id" => $saleId, "restore_stock" => $restoreStock]);
    return "APPLIED";
}

function sync_apply_catalogue_ingredient(PDO $db, array $payload, string $requestId): string
{
    $name = label_text($payload["name"] ?? null, "Ingredient name");
    $unit = (string) ($payload["unit"] ?? "");
    if (!in_array($unit, ["g", "mL", "units"], true)) {
        throw new RuntimeException("Invalid ingredient unit in sync payload.");
    }
    $threshold = fixed($payload["minimum_stock"] ?? "0", 3, "Low-stock threshold", true);
    $rawHours = $payload["opened_shelf_life_hours"] ?? null;
    $hours = ($rawHours === null || $rawHours === "") ? null : positive_id($rawHours, "Opened shelf life", 87600);
    $active = (int) ((bool) ($payload["is_active"] ?? 1));

    $existing = sync_resolve_ingredient($db, $payload["match_name"] ?? null, $name);
    if ($existing) {
        if ($existing["unit"] === $unit) {
            query(
                $db,
                "UPDATE ingredients SET name=?, minimum_stock=?, opened_shelf_life_hours=?, is_active=? WHERE id=?",
                [$name, decimal($threshold, 3), $hours, $active, (int) $existing["id"]],
            );
        } else {
            query(
                $db,
                "UPDATE ingredients SET name=?, minimum_stock=?, opened_shelf_life_hours=?, is_active=? WHERE id=?",
                [$name, decimal($threshold, 3), $hours, $active, (int) $existing["id"]],
            );
        }
    } else {
        query(
            $db,
            "INSERT INTO ingredients (name, unit, minimum_stock, opened_shelf_life_hours, is_active)
             VALUES (?, ?, ?, ?, ?)",
            [$name, $unit, decimal($threshold, 3), $hours, $active],
        );
    }
    audit($db, null, "SYNC_INGREDIENT_SAVED", $requestId, ["name" => $name]);
    return "APPLIED";
}

function sync_apply_catalogue_product(PDO $db, array $payload, string $requestId): string
{
    $name = label_text($payload["name"] ?? null, "Product name");
    $category = label_text($payload["category"] ?? "Beverage", "Category", 60);
    $active = (int) ((bool) ($payload["is_active"] ?? 1));
    $vatExempt = (int) ((bool) ($payload["is_vat_exempt"] ?? 0));
    $removeImage = (bool) ($payload["remove_image"] ?? false);
    $preferredFilename = is_string($payload["image_filename"] ?? null) ? trim($payload["image_filename"]) : null;
    $validPreferred = ($preferredFilename !== null && preg_match('/^prod_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|webp)$/D', $preferredFilename))
        ? $preferredFilename
        : null;
    $imageBase64 = is_string($payload["image_base64"] ?? null) ? $payload["image_base64"] : null;

    $existing = sync_resolve_product($db, $payload["match_name"] ?? null, $name);
    if ($existing) {
        $prodId = (int) $existing["id"];
        $imgFilename = $existing["image_filename"] ?? null;
        if ($removeImage) {
            remove_product_image_file(is_string($imgFilename) ? $imgFilename : null);
            $imgFilename = null;
        } elseif ($imageBase64 !== null && $imageBase64 !== "") {
            $imgFilename = sync_store_product_image_bytes($prodId, $preferredFilename, $imageBase64, is_string($imgFilename) ? $imgFilename : null);
        } elseif (($imgFilename === null || $imgFilename === "") && $validPreferred !== null && !empty($payload["image_exists"])) {
            $imgFilename = $validPreferred;
        }
        query(
            $db,
            "UPDATE products SET name=?, category=?, image_filename=?, is_active=?, is_vat_exempt=?, updated_at=NOW() WHERE id=?",
            [$name, $category, $imgFilename, $active, $vatExempt, $prodId],
        );
    } else {
        $initialImage = (!$removeImage && ($imageBase64 === null || $imageBase64 === "") && $validPreferred !== null && !empty($payload["image_exists"]))
            ? $validPreferred
            : null;
        query(
            $db,
            "INSERT INTO products (name, category, image_filename, is_active, is_vat_exempt, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
            [$name, $category, $initialImage, $active, $vatExempt],
        );
        $prodId = (int) $db->lastInsertId();
        if (!$removeImage && $imageBase64 !== null && $imageBase64 !== "") {
            $imgFilename = sync_store_product_image_bytes($prodId, $preferredFilename, $imageBase64, null);
            query($db, "UPDATE products SET image_filename=? WHERE id=?", [$imgFilename, $prodId]);
        }
    }
    audit($db, null, "SYNC_PRODUCT_SAVED", $requestId, ["name" => $name]);
    return "APPLIED";
}

function sync_apply_catalogue_variant(PDO $db, array $payload, string $requestId): string
{
    $prod = sync_resolve_product($db, null, $payload["product_name"] ?? null);
    if (!$prod && isset($payload["product_id"]) && is_numeric($payload["product_id"])) {
        $prod = query($db, "SELECT * FROM products WHERE id=?", [(int) $payload["product_id"]])->fetch() ?: null;
    }
    if (!$prod) {
        throw new RuntimeException("Product not found for synced variant: " . ($payload["product_name"] ?? "(unknown)"));
    }
    $productId = (int) $prod["id"];
    $size = (string) ($payload["size"] ?? "M");
    $temperature = (string) ($payload["temperature"] ?? "NA");
    $sku = label_text($payload["sku"] ?? null, "SKU", 64);
    if (
        !in_array($size, ["S", "M", "L"], true) ||
        !in_array($temperature, ["HOT", "ICED", "NA"], true) ||
        !preg_match('/^[A-Za-z0-9._-]+$/D', $sku)
    ) {
        throw new RuntimeException("Invalid variant attributes in sync payload.");
    }
    $price = fixed($payload["price"] ?? null, 2, "Price");
    $active = (int) ((bool) ($payload["is_active"] ?? 1));

    $target = sync_resolve_variant(
        $db,
        $payload["match_sku"] ?? null,
        $sku,
        $prod["name"],
        $payload["match_size"] ?? $size,
        $payload["match_temperature"] ?? $temperature,
    );
    $choiceCollision = query(
        $db,
        "SELECT * FROM product_variants WHERE product_id=? AND size=? AND temperature=?",
        [$productId, $size, $temperature],
    )->fetch();

    if ($choiceCollision && (!$target || (int) $choiceCollision["id"] !== (int) $target["id"])) {
        $target = $choiceCollision;
    }

    if ($target) {
        $tid = (int) $target["id"];
        $skuOwner = query($db, "SELECT id FROM product_variants WHERE sku=? AND id<>?", [$sku, $tid])->fetchColumn();
        if ($skuOwner) {
            query($db, "DELETE FROM recipes WHERE variant_id=?", [(int) $skuOwner]);
            $used = (int) query($db, "SELECT COUNT(*) FROM sale_items WHERE variant_id=?", [(int) $skuOwner])->fetchColumn();
            if ($used === 0) {
                query($db, "DELETE FROM product_variants WHERE id=?", [(int) $skuOwner]);
            } else {
                $fallbackSku = substr($sku, 0, 50) . "-OLD" . $skuOwner;
                query($db, "UPDATE product_variants SET sku=?, is_active=0 WHERE id=?", [$fallbackSku, (int) $skuOwner]);
            }
        }
        query(
            $db,
            "UPDATE product_variants SET product_id=?, size=?, temperature=?, sku=?, price=?, is_active=? WHERE id=?",
            [$productId, $size, $temperature, $sku, decimal($price), $active, $tid],
        );
    } else {
        query(
            $db,
            "INSERT INTO product_variants (product_id, size, temperature, sku, price, is_active)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$productId, $size, $temperature, $sku, decimal($price), $active],
        );
    }
    audit($db, null, "SYNC_VARIANT_SAVED", $requestId, ["sku" => $sku]);
    return "APPLIED";
}

function sync_apply_catalogue_recipe(PDO $db, array $payload, string $requestId): string
{
    $variant = sync_resolve_variant(
        $db,
        null,
        $payload["variant_sku"] ?? null,
        $payload["product_name"] ?? null,
        $payload["size"] ?? null,
        $payload["temperature"] ?? null,
    );
    if (!$variant && isset($payload["variant_id"]) && is_numeric($payload["variant_id"])) {
        $variant = query($db, "SELECT * FROM product_variants WHERE id=?", [(int) $payload["variant_id"]])->fetch() ?: null;
    }
    if (!$variant) {
        throw new RuntimeException("Variant not found for synced recipe.");
    }
    $variantId = (int) $variant["id"];
    $rows = $payload["rows"] ?? [];
    if (!is_array($rows)) {
        throw new RuntimeException("Invalid recipe rows in sync payload.");
    }
    $validated = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ing = sync_resolve_ingredient($db, null, $row["ingredient_name"] ?? null);
        if (!$ing && isset($row["ingredient_id"]) && is_numeric($row["ingredient_id"])) {
            $ing = query($db, "SELECT * FROM ingredients WHERE id=?", [(int) $row["ingredient_id"]])->fetch() ?: null;
        }
        if (!$ing) {
            throw new RuntimeException("Ingredient not found for synced recipe: " . ($row["ingredient_name"] ?? "(unknown)"));
        }
        $qty = fixed($row["qty"] ?? null, 3, "Recipe quantity");
        $validated[(int) $ing["id"]] = $qty;
    }
    query($db, "DELETE FROM recipes WHERE variant_id=?", [$variantId]);
    foreach ($validated as $iid => $qty) {
        query(
            $db,
            "INSERT INTO recipes (variant_id, ingredient_id, qty) VALUES (?, ?, ?)",
            [$variantId, $iid, decimal($qty, 3)],
        );
    }
    audit($db, null, "SYNC_RECIPE_SAVED", $requestId, ["variant_id" => $variantId]);
    return "APPLIED";
}

function sync_apply_catalogue_delete(PDO $db, array $payload, string $requestId): string
{
    $kind = (string) ($payload["kind"] ?? "");
    $tableMap = [
        "product" => "products",
        "variant" => "product_variants",
        "ingredient" => "ingredients",
    ];
    if (!isset($tableMap[$kind])) {
        throw new RuntimeException("Invalid delete kind in sync payload.");
    }
    $row = match ($kind) {
        "product" => sync_resolve_product($db, null, $payload["name"] ?? null),
        "ingredient" => sync_resolve_ingredient($db, null, $payload["name"] ?? null),
        "variant" => sync_resolve_variant(
            $db,
            null,
            $payload["sku"] ?? null,
            $payload["product_name"] ?? null,
            $payload["size"] ?? null,
            $payload["temperature"] ?? null,
        ),
    };
    if (!$row) {
        return "SKIPPED_DUPLICATE";
    }
    $id = (int) $row["id"];
    $table = $tableMap[$kind];
    $used = match ($kind) {
        "product" => (int) query($db, "SELECT COUNT(*) FROM product_variants WHERE product_id=?", [$id])->fetchColumn(),
        "variant" => (int) query($db, "SELECT COUNT(*) FROM sale_items WHERE variant_id=?", [$id])->fetchColumn(),
        "ingredient" => (int) query(
            $db,
            "SELECT (SELECT COUNT(*) FROM recipes WHERE ingredient_id=?) + (SELECT COUNT(*) FROM stock_batches WHERE ingredient_id=?)",
            [$id, $id],
        )->fetchColumn(),
    };
    if ($used > 0) {
        query($db, "UPDATE $table SET is_active=0 WHERE id=?", [$id]);
        audit($db, null, "CATALOGUE_DEACTIVATED_BY_SYNC", $requestId, ["kind" => $kind, "id" => $id]);
        return "APPLIED";
    }
    if ($kind === "variant") {
        query($db, "DELETE FROM recipes WHERE variant_id=?", [$id]);
    }
    if ($kind === "product" && !empty($row["image_filename"])) {
        remove_product_image_file((string) $row["image_filename"]);
    }
    query($db, "DELETE FROM $table WHERE id=?", [$id]);
    audit($db, null, "CATALOGUE_DELETED_SYNC", $requestId, ["kind" => $kind, "id" => $id]);
    return "APPLIED";
}

function sync_apply_stock_receive(PDO $db, array $payload, string $requestId): string
{
    $key = trim((string) ($payload["delivery_key"] ?? ""));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $key)) {
        throw new RuntimeException("Invalid delivery_key for synced stock receive.");
    }
    $existing = query($db, "SELECT id FROM stock_batches WHERE delivery_key=? FOR UPDATE", [$key])->fetch();
    if ($existing) {
        return "SKIPPED_DUPLICATE";
    }
    $ing = sync_resolve_ingredient($db, null, $payload["ingredient_name"] ?? null);
    if (!$ing && isset($payload["ingredient_id"]) && is_numeric($payload["ingredient_id"])) {
        $ing = query($db, "SELECT * FROM ingredients WHERE id=? FOR UPDATE", [(int) $payload["ingredient_id"]])->fetch() ?: null;
    }
    if (!$ing) {
        throw new RuntimeException("Ingredient not found for synced delivery: " . ($payload["ingredient_name"] ?? "(unknown)"));
    }
    $ingLocked = query($db, "SELECT * FROM ingredients WHERE id=? FOR UPDATE", [(int) $ing["id"]])->fetch();
    $userId = sync_resolve_user_id($db, $payload["actor_username"] ?? null, true);
    $at = sync_valid_datetime($payload["received_at"] ?? null, database_time($db));
    $count = positive_id($payload["container_count"] ?? null, "container count", 1000);
    $qtyPer = fixed($payload["quantity_per_container"] ?? null, 3, "Quantity per container");
    whole_units($ingLocked, $qtyPer);
    $expDate = valid_date($payload["expiration_date"] ?? null);
    $reference = label_text($payload["reference"] ?? "Synced Delivery", "Delivery reference");
    $supplier = optional_text($payload["supplier"] ?? null, "Supplier");
    $lotRef = optional_text($payload["lot_reference"] ?? null, "Lot reference");
    $total = $count * $qtyPer;

    $r = [
        "ingredient_id" => (int) $ingLocked["id"],
        "container_count" => $count,
        "quantity_per_container" => $qtyPer,
        "expiration_date" => $expDate,
        "reference" => $reference,
        "supplier" => $supplier,
        "lot_reference" => $lotRef,
    ];
    $hash = hash("sha256", json_encode($r, JSON_THROW_ON_ERROR));

    query(
        $db,
        "INSERT INTO stock_batches (ingredient_id, delivery_key, request_hash, reference, supplier, lot_reference, expiration_date, received_qty, remaining_qty, qty_sealed, user_id, received_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            (int) $ingLocked["id"],
            $key,
            $hash,
            $reference,
            $supplier,
            $lotRef,
            $expDate,
            decimal($total, 3),
            decimal($total, 3),
            decimal($total, 3),
            $userId,
            $at,
        ],
    );
    $batchId = (int) $db->lastInsertId();
    for ($i = 0; $i < $count; $i++) {
        query(
            $db,
            "INSERT INTO stock_containers (batch_id, received_qty, remaining_qty) VALUES (?, ?, ?)",
            [$batchId, decimal($qtyPer, 3), decimal($qtyPer, 3)],
        );
        record_movement($db, (int) $ingLocked["id"], $batchId, (int) $db->lastInsertId(), null, $userId, "RECEIVE", $qtyPer, $reference, $at);
    }
    query(
        $db,
        "UPDATE ingredients SET current_stock = current_stock + ? WHERE id = ?",
        [decimal($total, 3), (int) $ingLocked["id"]],
    );
    audit($db, $userId, "STOCK_RECEIVED_SYNC", $requestId, ["delivery_key" => $key, "batch_id" => $batchId]);
    return "APPLIED";
}

function sync_apply_stock_batch_snapshot(PDO $db, array $payload, string $requestId): string
{
    $key = trim((string) ($payload["delivery_key"] ?? ""));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $key)) {
        throw new RuntimeException("Invalid delivery_key for synced stock snapshot.");
    }
    if (query($db, "SELECT id FROM stock_batches WHERE delivery_key=? FOR UPDATE", [$key])->fetch()) {
        return "SKIPPED_DUPLICATE";
    }
    $ing = sync_resolve_ingredient($db, null, $payload["ingredient_name"] ?? null);
    if (!$ing) {
        throw new RuntimeException("Ingredient not found for synced batch: " . ($payload["ingredient_name"] ?? "(unknown)"));
    }
    $ingLocked = query($db, "SELECT * FROM ingredients WHERE id=? FOR UPDATE", [(int) $ing["id"]])->fetch();
    $ingId = (int) $ingLocked["id"];
    $userId = sync_resolve_user_id($db, $payload["actor_username"] ?? null, true);
    $at = sync_valid_datetime($payload["received_at"] ?? null, database_time($db));
    $expDate = valid_date($payload["expiration_date"] ?? null);
    $reference = label_text($payload["reference"] ?? "Synced Batch", "Delivery reference");
    $supplier = optional_text($payload["supplier"] ?? null, "Supplier");
    $lotRef = optional_text($payload["lot_reference"] ?? null, "Lot reference");
    $quarantined = (int) ((bool) ($payload["quarantined"] ?? 0));

    $rawContainers = is_array($payload["containers"] ?? null) && $payload["containers"] ? $payload["containers"] : [[
        "received_qty" => $payload["received_qty"] ?? null,
        "remaining_qty" => $payload["remaining_qty"] ?? ($payload["received_qty"] ?? "0"),
        "opened_at" => null,
        "use_by" => null,
    ]];
    $cleanContainers = [];
    $totalReceived = 0;
    $totalRemaining = 0;
    foreach ($rawContainers as $c) {
        if (!is_array($c)) {
            continue;
        }
        $cRec = fixed($c["received_qty"] ?? null, 3, "Container received quantity");
        $cRem = fixed($c["remaining_qty"] ?? "0", 3, "Container remaining quantity", true);
        if ($cRem > $cRec) {
            $cRem = $cRec;
        }
        whole_units($ingLocked, $cRec);
        whole_units($ingLocked, $cRem);
        $openedAt = is_string($c["opened_at"] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', trim($c["opened_at"]))
            ? trim($c["opened_at"])
            : null;
        $useBy = $openedAt !== null && is_string($c["use_by"] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', trim($c["use_by"]))
            ? trim($c["use_by"])
            : null;
        if ($useBy !== null && $useBy <= $openedAt) {
            $useBy = null;
        }
        $cleanContainers[] = [
            "received_qty" => $cRec,
            "remaining_qty" => $cRem,
            "opened_at" => $openedAt,
            "use_by" => $useBy,
        ];
        $totalReceived += $cRec;
        $totalRemaining += $cRem;
    }
    if (!$cleanContainers || $totalReceived <= 0) {
        throw new RuntimeException("Synced stock batch snapshot has no valid containers.");
    }
    $hash = hash("sha256", json_encode([
        "delivery_key" => $key,
        "ingredient_name" => $ingLocked["name"],
        "containers" => $cleanContainers,
    ], JSON_THROW_ON_ERROR));

    query(
        $db,
        "INSERT INTO stock_batches (ingredient_id, delivery_key, request_hash, reference, supplier, lot_reference, expiration_date, received_qty, remaining_qty, qty_sealed, qty_opened, quarantined, user_id, received_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, '0.000', '0.000', '0.000', ?, ?, ?)",
        [
            $ingId,
            $key,
            $hash,
            $reference,
            $supplier,
            $lotRef,
            $expDate,
            decimal($totalReceived, 3),
            $quarantined,
            $userId,
            $at,
        ],
    );
    $batchId = (int) $db->lastInsertId();
    foreach ($cleanContainers as $cc) {
        query(
            $db,
            "INSERT INTO stock_containers (batch_id, received_qty, remaining_qty, opened_at, use_by)
             VALUES (?, ?, ?, ?, ?)",
            [
                $batchId,
                decimal($cc["received_qty"], 3),
                decimal($cc["remaining_qty"], 3),
                $cc["opened_at"],
                $cc["use_by"],
            ],
        );
        $cid = (int) $db->lastInsertId();
        record_movement($db, $ingId, $batchId, $cid, null, $userId, "RECEIVE", $cc["received_qty"], $reference, $at);
        if ($cc["remaining_qty"] < $cc["received_qty"]) {
            $usedDelta = $cc["remaining_qty"] - $cc["received_qty"];
            record_movement($db, $ingId, $batchId, $cid, null, $userId, "ADJUSTMENT", $usedDelta, "Synced batch balance", $at);
        }
    }
    update_batch_totals($db, $batchId);
    if ($totalRemaining > 0) {
        query(
            $db,
            "UPDATE ingredients SET current_stock = current_stock + ? WHERE id = ?",
            [decimal($totalRemaining, 3), $ingId],
        );
    }
    audit($db, $userId, "STOCK_BATCH_SNAPSHOT_SYNC", $requestId, ["delivery_key" => $key, "batch_id" => $batchId]);
    return "APPLIED";
}

function sync_apply_inventory_op(PDO $db, array $payload, string $txKey, string $requestId): string
{
    $opKey = trim((string) ($payload["operation_key"] ?? $txKey));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $opKey)) {
        throw new RuntimeException("Invalid operation_key for synced inventory op.");
    }
    if (query($db, "SELECT operation_key FROM stock_operations WHERE operation_key=? FOR UPDATE", [$opKey])->fetch()) {
        return "SKIPPED_DUPLICATE";
    }
    $kind = (string) ($payload["kind"] ?? "");
    $uid = sync_resolve_user_id($db, $payload["actor_username"] ?? null, true);
    $at = sync_valid_datetime($payload["created_at"] ?? null, database_time($db));
    $reason = substr(trim((string) ($payload["reason"] ?? "Synced inventory update")), 0, 255) ?: "Synced inventory update";
    $hash = hash("sha256", json_encode($payload, JSON_THROW_ON_ERROR));

    if ($kind === "clear_expired") {
        $ingId = null;
        if (!empty($payload["ingredient_name"])) {
            $ing = sync_resolve_ingredient($db, null, $payload["ingredient_name"]);
            if ($ing) {
                $ingId = (int) $ing["id"];
            }
        } elseif (!empty($payload["ingredient_id"])) {
            $ingId = (int) $payload["ingredient_id"];
        }
        $sql = "SELECT c.*, b.ingredient_id, b.expiration_date, b.quarantined
                FROM stock_containers c JOIN stock_batches b ON b.id = c.batch_id
                WHERE c.remaining_qty > 0 AND b.quarantined = 0";
        $params = [];
        if ($ingId !== null) {
            $sql .= " AND b.ingredient_id = ?";
            $params[] = $ingId;
        }
        $sql .= " ORDER BY c.id FOR UPDATE";
        $candidates = query($db, $sql, $params)->fetchAll();
        $expiredList = array_values(array_filter($candidates, fn($c) => !stock_eligible($c, $at)));
        $touchedBatches = [];
        $cleared = [];
        foreach ($expiredList as $c) {
            $cid = (int) $c["id"];
            $bid = (int) $c["batch_id"];
            $iid = (int) $c["ingredient_id"];
            $ing = query($db, "SELECT current_stock FROM ingredients WHERE id=? FOR UPDATE", [$iid])->fetch();
            $before = fixed($c["remaining_qty"], 3, "Remaining", true);
            if ($before <= 0) {
                continue;
            }
            $curStock = fixed($ing["current_stock"] ?? "0", 3, "Stock", true);
            $stockDeduct = min($curStock, $before);
            query($db, "UPDATE stock_containers SET remaining_qty='0.000' WHERE id=?", [$cid]);
            if ($stockDeduct > 0) {
                query($db, "UPDATE ingredients SET current_stock = current_stock - ? WHERE id=?", [decimal($stockDeduct, 3), $iid]);
            }
            $touchedBatches[$bid] = true;
            record_movement($db, $iid, $bid, $cid, null, $uid, "WASTE", -$before, substr($reason, 0, 180), $at);
            $cleared[] = ["container_id" => $cid, "wasted_qty" => decimal($before, 3)];
        }
        foreach (array_keys($touchedBatches) as $bid) {
            update_batch_totals($db, (int) $bid);
        }
        $result = ["cleared_count" => count($cleared), "containers" => $cleared];
        query(
            $db,
            "INSERT INTO stock_operations (operation_key, request_hash, user_id, result_json, created_at) VALUES (?, ?, ?, ?, ?)",
            [$opKey, $hash, $uid, json_encode($result, JSON_THROW_ON_ERROR), $at],
        );
        audit($db, $uid, "SYNC_EXPIRED_CLEARED", $requestId, $result);
        return "APPLIED";
    }

    if ($kind === "batch") {
        $batch = null;
        if (!empty($payload["delivery_key"])) {
            $batch = query($db, "SELECT * FROM stock_batches WHERE delivery_key=? FOR UPDATE", [$payload["delivery_key"]])->fetch();
        } elseif (!empty($payload["batch_id"])) {
            $batch = query($db, "SELECT * FROM stock_batches WHERE id=? FOR UPDATE", [(int) $payload["batch_id"]])->fetch();
        }
        if (!$batch) {
            throw new RuntimeException("Batch not found for synced batch correction.");
        }
        $bid = (int) $batch["id"];
        $expDate = valid_date($payload["expiration_date"] ?? null);
        $supplier = optional_text($payload["supplier"] ?? null, "Supplier");
        $lotRef = optional_text($payload["lot_reference"] ?? null, "Lot reference");
        $quarantined = (int) ((bool) ($payload["quarantined"] ?? 0));
        query(
            $db,
            "UPDATE stock_batches SET expiration_date=?, supplier=?, lot_reference=?, quarantined=? WHERE id=?",
            [$expDate, $supplier, $lotRef, $quarantined, $bid],
        );
        $cid = (int) query($db, "SELECT id FROM stock_containers WHERE batch_id=? ORDER BY id LIMIT 1", [$bid])->fetchColumn();
        if ($cid > 0) {
            record_movement($db, (int) $batch["ingredient_id"], $bid, $cid, null, $uid, "ADJUSTMENT", 0, "Batch correction: " . $reason, $at);
        }
        $result = ["batch_id" => $bid];
        query(
            $db,
            "INSERT INTO stock_operations (operation_key, request_hash, user_id, result_json, created_at) VALUES (?, ?, ?, ?, ?)",
            [$opKey, $hash, $uid, json_encode($result, JSON_THROW_ON_ERROR), $at],
        );
        audit($db, $uid, "SYNC_BATCH_CORRECTED", $requestId, $result);
        return "APPLIED";
    }

    if ($kind === "waste" || $kind === "adjust") {
        $c = null;
        if (!empty($payload["delivery_key"])) {
            $batch = query($db, "SELECT id FROM stock_batches WHERE delivery_key=?", [$payload["delivery_key"]])->fetch();
            if ($batch) {
                $offset = max(0, (int) ($payload["container_index"] ?? 0));
                $c = query(
                    $db,
                    "SELECT c.*, b.ingredient_id, b.expiration_date, b.quarantined
                     FROM stock_containers c JOIN stock_batches b ON b.id = c.batch_id
                     WHERE c.batch_id = ? ORDER BY c.id ASC LIMIT 1 OFFSET " . $offset . " FOR UPDATE",
                    [(int) $batch["id"]],
                )->fetch();
            }
        } elseif (!empty($payload["container_id"])) {
            $c = query(
                $db,
                "SELECT c.*, b.ingredient_id, b.expiration_date, b.quarantined
                 FROM stock_containers c JOIN stock_batches b ON b.id = c.batch_id
                 WHERE c.id = ? FOR UPDATE",
                [(int) $payload["container_id"]],
            )->fetch();
        }
        if (!$c) {
            throw new RuntimeException("Container not found for synced inventory op.");
        }
        $cid = (int) $c["id"];
        $ing = query($db, "SELECT * FROM ingredients WHERE id=? FOR UPDATE", [(int) $c["ingredient_id"]])->fetch();
        $before = fixed($c["remaining_qty"], 3, "Remaining", true);
        $rec = fixed($c["received_qty"], 3, "Received", true);
        if ($kind === "waste") {
            $qty = fixed($payload["quantity"] ?? 0, 3, "Quantity", true);
            $after = max(0, $before - $qty);
        } else {
            $target = fixed($payload["after_qty"] ?? $payload["quantity"] ?? $before, 3, "Quantity", true);
            $after = min($rec, max(0, $target));
        }
        $delta = $after - $before;
        if ($delta !== 0) {
            query($db, "UPDATE stock_containers SET remaining_qty=? WHERE id=?", [decimal($after, 3), $cid]);
            $curStock = fixed($ing["current_stock"], 3, "Stock", true);
            $newStock = max(0, $curStock + $delta);
            $stockChange = $newStock - $curStock;
            if ($stockChange !== 0) {
                query($db, "UPDATE ingredients SET current_stock = current_stock + ? WHERE id=?", [decimal($stockChange, 3), (int) $ing["id"]]);
            }
            update_batch_totals($db, (int) $c["batch_id"]);
            record_movement($db, (int) $ing["id"], (int) $c["batch_id"], $cid, null, $uid, $kind === "waste" ? "WASTE" : "ADJUSTMENT", $delta, $reason, $at);
        }
        $result = [
            "container_id" => $cid,
            "remaining_qty" => decimal($after, 3),
            "quantity_delta" => decimal($delta, 3),
        ];
        query(
            $db,
            "INSERT INTO stock_operations (operation_key, request_hash, user_id, result_json, created_at) VALUES (?, ?, ?, ?, ?)",
            [$opKey, $hash, $uid, json_encode($result, JSON_THROW_ON_ERROR), $at],
        );
        audit($db, $uid, "SYNC_INVENTORY_" . strtoupper($kind), $requestId, $result);
        return "APPLIED";
    }
    throw new RuntimeException("Unsupported inventory operation kind: " . $kind);
}

function sync_safe_user_email(PDO $db, mixed $rawEmail, int $excludeUserId): ?string
{
    if (!is_string($rawEmail) || trim($rawEmail) === "") {
        return null;
    }
    $clean = strtolower(trim($rawEmail));
    if (strlen($clean) > 191 || !filter_var($clean, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $dup = query($db, "SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND id<>?", [$clean, $excludeUserId])->fetchColumn();
    return $dup ? null : $clean;
}

function sync_apply_staff_save(PDO $db, array $payload, string $requestId): string
{
    $name = username($payload["username"] ?? null);
    $display = label_text($payload["display_name"] ?? null, "Display name");
    $active = (int) ((bool) ($payload["is_active"] ?? 1));
    $matchName = is_string($payload["match_username"] ?? null) && trim($payload["match_username"]) !== ""
        ? strtolower(trim($payload["match_username"]))
        : $name;

    $ownerCheck = query($db, "SELECT id FROM users WHERE LOWER(username)=LOWER(?) AND role='OWNER'", [$name])->fetch();
    if ($ownerCheck) {
        throw new RuntimeException("Cannot overwrite OWNER account via STAFF_SAVE.");
    }
    $existing = query($db, "SELECT * FROM users WHERE LOWER(username)=LOWER(?) AND role='BARISTA'", [$matchName])->fetch();
    if (!$existing && $matchName !== $name) {
        $existing = query($db, "SELECT * FROM users WHERE LOWER(username)=LOWER(?) AND role='BARISTA'", [$name])->fetch();
    }

    $hash = is_string($payload["password_hash"] ?? null) && strlen($payload["password_hash"]) >= 50
        ? $payload["password_hash"]
        : null;

    if ($existing) {
        $id = (int) $existing["id"];
        $email = sync_safe_user_email($db, $payload["email"] ?? null, $id);
        if ($email === null && !empty($existing["email"]) && (!isset($payload["email"]) || $payload["email"] === null)) {
            $email = null;
        }
        if ($hash !== null) {
            query(
                $db,
                "UPDATE users SET username=?, display_name=?, email=?, is_active=?, password_hash=?, updated_at=NOW() WHERE id=?",
                [$name, $display, $email, $active, $hash, $id],
            );
        } else {
            query(
                $db,
                "UPDATE users SET username=?, display_name=?, email=?, is_active=?, updated_at=NOW() WHERE id=?",
                [$name, $display, $email, $active, $id],
            );
        }
        query($db, "DELETE FROM sessions WHERE user_id=?", [$id]);
    } else {
        $email = sync_safe_user_email($db, $payload["email"] ?? null, 0);
        if ($hash === null) {
            $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
        }
        query(
            $db,
            "INSERT INTO users (username, display_name, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'BARISTA', ?, NOW(), NOW())",
            [$name, $display, $email, $hash, $active],
        );
    }
    audit($db, null, "SYNC_STAFF_SAVED", $requestId, ["username" => $name]);
    return "APPLIED";
}

function sync_apply_user_password(PDO $db, array $payload, string $requestId): string
{
    $name = username($payload["username"] ?? null);
    $hash = $payload["password_hash"] ?? "";
    if (
        !is_string($hash) ||
        strlen($hash) < 50 ||
        (!str_starts_with($hash, '$2y$') && !str_starts_with($hash, '$2a$') && !str_starts_with($hash, '$argon2'))
    ) {
        throw new RuntimeException("Invalid password_hash in USER_PASSWORD payload.");
    }
    $user = query($db, "SELECT id, username FROM users WHERE LOWER(username)=LOWER(?)", [$name])->fetch();
    if (!$user) {
        throw new RuntimeException("User not found for password sync: " . $name);
    }
    $uid = (int) $user["id"];
    query($db, "UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?", [$hash, $uid]);
    query($db, "DELETE FROM sessions WHERE user_id=?", [$uid]);
    query($db, "DELETE FROM login_limits WHERE bucket=?", [hash("sha256", "user:" . $user["username"])]);
    audit($db, $uid, "SYNC_PASSWORD_UPDATED", $requestId, ["username" => $user["username"]]);
    return "APPLIED";
}

function sync_apply_owner_profile(PDO $db, array $payload, string $requestId): string
{
    $owner = null;
    if (is_string($payload["username"] ?? null) && trim($payload["username"]) !== "") {
        $owner = query(
            $db,
            "SELECT * FROM users WHERE LOWER(username)=LOWER(?) AND role='OWNER'",
            [trim($payload["username"])],
        )->fetch();
    }
    if (!$owner) {
        $owner = $db->query("SELECT * FROM users WHERE role='OWNER' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
    }
    if (!$owner) {
        throw new RuntimeException("No active OWNER found on destination.");
    }
    $uid = (int) $owner["id"];
    $email = sync_safe_user_email($db, $payload["email"] ?? null, $uid) ?? $owner["email"];
    $shopName = is_string($payload["shop_name"] ?? null) && trim($payload["shop_name"]) !== ""
        ? substr(trim($payload["shop_name"]), 0, 120)
        : ($owner["shop_name"] ?? "Nearby Café");
    $displayName = is_string($payload["display_name"] ?? null) && trim($payload["display_name"]) !== ""
        ? substr(trim($payload["display_name"]), 0, 100)
        : $owner["display_name"];
    query(
        $db,
        "UPDATE users SET display_name=?, email=?, shop_name=?, updated_at=NOW() WHERE id=?",
        [$displayName, $email, $shopName, $uid],
    );
    audit($db, $uid, "SYNC_OWNER_PROFILE_UPDATED", $requestId, ["email" => $email, "shop_name" => $shopName]);
    return "APPLIED";
}

/**
 * Apply a single portable sync event idempotently with outbox suppression to prevent loops.
 */
function sync_apply_event(
    PDO $db,
    array $event,
    string $sourceShopId,
    string $requestId,
): array {
    $entityType = strtoupper(trim((string) ($event["entity_type"] ?? "")));
    $txKey = trim((string) ($event["transaction_key"] ?? ""));
    $rawPayload = $event["payload"] ?? [];
    if (is_string($rawPayload)) {
        $decoded = json_decode($rawPayload, true);
        $payload = is_array($decoded) ? $decoded : [];
    } elseif (is_array($rawPayload)) {
        $payload = $rawPayload;
    } else {
        $payload = [];
    }
    if ($entityType === "" || $txKey === "") {
        throw new RuntimeException("Sync event requires entity_type and transaction_key.");
    }
    if ($entityType === "CATALOGUE_SAVE") {
        $action = (string) ($payload["action"] ?? "");
        $entityType = match ($action) {
            "ingredient" => "CATALOGUE_INGREDIENT",
            "product" => "CATALOGUE_PRODUCT",
            "variant" => "CATALOGUE_VARIANT",
            "recipe" => "CATALOGUE_RECIPE",
            "receive" => "STOCK_RECEIVE",
            default => "CATALOGUE_SAVE",
        };
    }

    $already = query($db, "SELECT status FROM sync_inbox WHERE transaction_key=?", [$txKey])->fetch();
    if ($already && in_array($already["status"], ["APPLIED", "SKIPPED_DUPLICATE"], true)) {
        return ["transaction_key" => $txKey, "status" => "SKIPPED_DUPLICATE"];
    }

    $db->beginTransaction();
    sync_suppress_outbox(true);
    try {
        lock_state($db);
        $dup = query($db, "SELECT status FROM sync_inbox WHERE transaction_key=? FOR UPDATE", [$txKey])->fetch();
        if ($dup && in_array($dup["status"], ["APPLIED", "SKIPPED_DUPLICATE"], true)) {
            $db->commit();
            return ["transaction_key" => $txKey, "status" => "SKIPPED_DUPLICATE"];
        }

        $outcome = match ($entityType) {
            "SALE" => sync_apply_sale($db, $payload, $txKey, $requestId),
            "SALE_HISTORY" => sync_apply_sale_history($db, $payload, $txKey, $requestId),
            "SALE_VOID" => sync_apply_sale_void($db, $payload, $requestId),
            "CATALOGUE_INGREDIENT" => sync_apply_catalogue_ingredient($db, $payload, $requestId),
            "CATALOGUE_PRODUCT" => sync_apply_catalogue_product($db, $payload, $requestId),
            "CATALOGUE_VARIANT" => sync_apply_catalogue_variant($db, $payload, $requestId),
            "CATALOGUE_RECIPE" => sync_apply_catalogue_recipe($db, $payload, $requestId),
            "CATALOGUE_DELETE" => sync_apply_catalogue_delete($db, $payload, $requestId),
            "STOCK_RECEIVE" => sync_apply_stock_receive($db, $payload, $requestId),
            "STOCK_BATCH_SNAPSHOT" => sync_apply_stock_batch_snapshot($db, $payload, $requestId),
            "INVENTORY_OP" => sync_apply_inventory_op($db, $payload, $txKey, $requestId),
            "STAFF_SAVE" => sync_apply_staff_save($db, $payload, $requestId),
            "USER_PASSWORD" => sync_apply_user_password($db, $payload, $requestId),
            "OWNER_PROFILE" => sync_apply_owner_profile($db, $payload, $requestId),
            default => throw new RuntimeException("Unknown sync entity_type: " . $entityType),
        };

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: "{}";
        query(
            $db,
            "INSERT INTO sync_inbox (transaction_key, entity_type, source_shop_id, payload, received_at, processed_at, status, error_log)
             VALUES (?, ?, ?, ?, NOW(), NOW(), ?, NULL)
             ON DUPLICATE KEY UPDATE
               entity_type = VALUES(entity_type),
               payload = VALUES(payload),
               processed_at = NOW(),
               status = VALUES(status),
               error_log = NULL",
            [$txKey, $entityType, substr($sourceShopId, 0, 64), $encodedPayload, $outcome],
        );
        $db->commit();
        return ["transaction_key" => $txKey, "status" => $outcome];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        try {
            $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: "{}";
            query(
                $db,
                "INSERT INTO sync_inbox (transaction_key, entity_type, source_shop_id, payload, received_at, processed_at, status, error_log)
                 VALUES (?, ?, ?, ?, NOW(), NOW(), 'ERROR', ?)
                 ON DUPLICATE KEY UPDATE
                   processed_at = NOW(),
                   status = 'ERROR',
                   error_log = VALUES(error_log)",
                [$txKey, $entityType, substr($sourceShopId, 0, 64), $encodedPayload, substr($e->getMessage(), 0, 500)],
            );
        } catch (Throwable) {
            // Do not mask original sync failure
        }
        throw $e;
    } finally {
        sync_suppress_outbox(false);
    }
}

function sync_push_endpoint(PDO $db, array $session, array $body, string $requestId): array
{
    current_session($db, $session, true);
    $shopId = trim((string) ($body["shop_id"] ?? "local-main-shop"));
    $events = $body["events"] ?? null;
    if (!is_array($events)) {
        problem(422, "VALIDATION", "events array is required.");
    }
    $results = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $txKey = (string) ($event["transaction_key"] ?? "");
        try {
            $res = sync_apply_event($db, $event, $shopId, $requestId);
            $results[] = [
                "transaction_key" => $txKey,
                "ok" => true,
                "status" => $res["status"],
            ];
        } catch (Throwable $e) {
            $results[] = [
                "transaction_key" => $txKey,
                "ok" => false,
                "error" => $e->getMessage(),
            ];
        }
    }
    return ["results" => $results, "server_time" => database_time($db)];
}

function sync_pull_endpoint(PDO $db, array $params): array
{
    $limit = isset($params["limit"]) && is_numeric($params["limit"]) ? (int) $params["limit"] : 50;
    $limit = max(1, min(100, $limit));
    $rows = $db
        ->query("SELECT id, entity_type, transaction_key, payload, created_at FROM sync_outbox WHERE status='PENDING' ORDER BY id ASC LIMIT " . $limit)
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
        "events" => $events,
        "server_time" => database_time($db),
    ];
}

function sync_ack_endpoint(PDO $db, array $body): array
{
    $acked = $body["acked_ids"] ?? [];
    $failed = $body["failed"] ?? [];
    if (!is_array($acked) || !is_array($failed)) {
        problem(422, "VALIDATION", "acked_ids and failed arrays are required.");
    }
    $cleanIds = array_values(array_filter(array_map("intval", $acked), fn($id) => $id > 0));
    if ($cleanIds) {
        $placeholders = implode(",", array_fill(0, count($cleanIds), "?"));
        query(
            $db,
            "UPDATE sync_outbox SET status='SYNCED', synced_at=NOW(), error_message=NULL WHERE id IN (" . $placeholders . ")",
            $cleanIds,
        );
    }
    foreach ($failed as $f) {
        if (!is_array($f) || empty($f["id"])) {
            continue;
        }
        query(
            $db,
            "UPDATE sync_outbox SET attempts = attempts + 1, error_message = ? WHERE id = ?",
            [substr((string) ($f["error"] ?? "Apply failed"), 0, 500), (int) $f["id"]],
        );
    }
    return ["acked_count" => count($cleanIds), "failed_count" => count($failed)];
}

function sync_snapshot_data(PDO $db): array
{
    $products = $db
        ->query("SELECT name, category, image_filename, is_active, is_vat_exempt FROM products ORDER BY name ASC")
        ->fetchAll();
    foreach ($products as &$p) {
        $p["is_active"] = (int) $p["is_active"];
        $p["is_vat_exempt"] = (int) $p["is_vat_exempt"];
        $p["image_exists"] = !empty($p["image_filename"]) && find_product_image_path((string) $p["image_filename"]) !== null;
    }
    unset($p);

    $variants = $db
        ->query(
            "SELECT p.name AS product_name, v.size, v.temperature, v.sku, v.price, v.is_active
             FROM product_variants v
             JOIN products p ON p.id = v.product_id
             ORDER BY p.name ASC, v.sku ASC",
        )
        ->fetchAll();
    foreach ($variants as &$v) {
        $v["is_active"] = (int) $v["is_active"];
    }
    unset($v);

    $ingredients = $db
        ->query("SELECT name, unit, minimum_stock, opened_shelf_life_hours, is_active FROM ingredients ORDER BY name ASC")
        ->fetchAll();
    foreach ($ingredients as &$i) {
        $i["is_active"] = (int) $i["is_active"];
        $i["opened_shelf_life_hours"] = $i["opened_shelf_life_hours"] !== null ? (int) $i["opened_shelf_life_hours"] : null;
    }
    unset($i);

    $recipes = $db
        ->query(
            "SELECT v.sku AS variant_sku, p.name AS product_name, v.size, v.temperature, i.name AS ingredient_name, r.qty
             FROM recipes r
             JOIN product_variants v ON v.id = r.variant_id
             JOIN products p ON p.id = v.product_id
             JOIN ingredients i ON i.id = r.ingredient_id
             ORDER BY v.sku ASC, i.name ASC",
        )
        ->fetchAll();

    $staff = $db
        ->query(
            "SELECT username, display_name, email, is_active, password_hash
             FROM users WHERE role='BARISTA' ORDER BY username ASC",
        )
        ->fetchAll();
    foreach ($staff as &$s) {
        $s["is_active"] = (int) $s["is_active"];
    }
    unset($s);

    $owner = $db
        ->query("SELECT username, display_name, email, shop_name FROM users WHERE role='OWNER' AND is_active=1 ORDER BY id LIMIT 1")
        ->fetch() ?: null;

    $salesKeys = $db
        ->query("SELECT transaction_key, status, void_reason, voided_at FROM sales ORDER BY id ASC")
        ->fetchAll();

    $batchRows = $db
        ->query(
            "SELECT b.delivery_key, i.name AS ingredient_name, b.reference, b.supplier, b.lot_reference,
                    b.expiration_date, b.received_qty, b.remaining_qty, b.quarantined, b.received_at,
                    u.username AS actor_username
             FROM stock_batches b
             JOIN ingredients i ON i.id = b.ingredient_id
             JOIN users u ON u.id = b.user_id
             ORDER BY b.received_at ASC, b.id ASC",
        )
        ->fetchAll();
    $containersByBatch = [];
    foreach (
        $db->query(
            "SELECT b.delivery_key, c.received_qty, c.remaining_qty, c.opened_at, c.use_by
             FROM stock_containers c
             JOIN stock_batches b ON b.id = c.batch_id
             ORDER BY c.batch_id ASC, c.id ASC",
        )->fetchAll() as $cr
    ) {
        $containersByBatch[$cr["delivery_key"]][] = [
            "received_qty" => $cr["received_qty"],
            "remaining_qty" => $cr["remaining_qty"],
            "opened_at" => $cr["opened_at"],
            "use_by" => $cr["use_by"],
        ];
    }
    foreach ($batchRows as &$b) {
        $b["quarantined"] = (int) $b["quarantined"];
        $b["containers"] = $containersByBatch[$b["delivery_key"]] ?? [];
    }
    unset($b);

    return [
        "server_time" => database_time($db),
        "products" => $products,
        "variants" => $variants,
        "ingredients" => $ingredients,
        "recipes" => $recipes,
        "batches" => $batchRows,
        "staff" => $staff,
        "owner" => $owner,
        "sales_keys" => $salesKeys,
    ];
}

function sync_export_sales_by_keys(PDO $db, array $keys): array
{
    $cleanKeys = [];
    foreach ($keys as $k) {
        if (is_string($k) && preg_match('/^[0-9a-f-]{36}$/i', trim($k))) {
            $cleanKeys[] = trim($k);
        }
    }
    $cleanKeys = array_values(array_unique(array_slice($cleanKeys, 0, 100)));
    if (!$cleanKeys) {
        return [];
    }
    $placeholders = implode(",", array_fill(0, count($cleanKeys), "?"));
    $sales = query(
        $db,
        "SELECT s.*, u.username AS cashier_username, vu.username AS voided_by_username
         FROM sales s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN users vu ON vu.id = s.voided_by
         WHERE s.transaction_key IN (" . $placeholders . ")
         ORDER BY s.sale_at ASC, s.id ASC",
        $cleanKeys,
    )->fetchAll();

    $events = [];
    foreach ($sales as $s) {
        $items = query(
            $db,
            "SELECT si.variant_label, si.qty, si.unit_price, si.line_gross, si.line_net, si.line_vat,
                    v.sku, v.size, v.temperature, p.name AS product_name, p.category
             FROM sale_items si
             JOIN product_variants v ON v.id = si.variant_id
             JOIN products p ON p.id = v.product_id
             WHERE si.sale_id = ?
             ORDER BY si.id ASC",
            [(int) $s["id"]],
        )->fetchAll();
        $events[] = [
            "entity_type" => "SALE_HISTORY",
            "transaction_key" => (($s["status"] ?? "completed") === "voided" ? "hist-void-" : "hist-") . $s["transaction_key"],
            "payload" => [
                "transaction_key" => $s["transaction_key"],
                "request_hash" => $s["request_hash"],
                "cashier_username" => $s["cashier_username"],
                "sale_at" => $s["sale_at"],
                "payment_method" => $s["payment_method"] ?? "CASH",
                "payment_reference" => $s["payment_reference"] ?? null,
                "customer_name" => $s["customer_name"] ?? null,
                "cash_received" => $s["cash_received"],
                "change_due" => $s["change_due"],
                "total_gross" => $s["total_gross"],
                "total_net" => $s["total_net"],
                "total_vat" => $s["total_vat"],
                "vat_rate" => $s["vat_rate"],
                "status" => $s["status"] ?? "completed",
                "void_reason" => $s["void_reason"] ?? null,
                "voided_by_username" => $s["voided_by_username"] ?? null,
                "voided_at" => $s["voided_at"] ?? null,
                "items" => $items,
            ],
        ];
    }
    return $events;
}

function sync_reconcile_endpoint(PDO $db): array
{
    return sync_snapshot_data($db);
}

function sync_export_sales_endpoint(PDO $db, array $body): array
{
    $keys = $body["transaction_keys"] ?? [];
    if (!is_array($keys)) {
        problem(422, "VALIDATION", "transaction_keys array is required.");
    }
    return ["events" => sync_export_sales_by_keys($db, $keys)];
}

/**
 * Open a PDO handle for CLI sync daemons, supporting VV_SYNC_DB_* env overrides for isolated tests.
 */
function sync_cli_db(): PDO
{
    $dbName = getenv("VV_SYNC_DB_NAME");
    if (is_string($dbName) && $dbName !== "") {
        $host = getenv("VV_SYNC_DB_HOST") ?: "127.0.0.1";
        $port = (int) (getenv("VV_SYNC_DB_PORT") ?: "3306");
        $user = getenv("VV_SYNC_DB_USER") ?: "root";
        $pass = getenv("VV_SYNC_DB_PASS");
        if ($pass === false) {
            $pass = "";
        }
        $dsn = "mysql:host=" . $host . ";port=" . $port . ";charset=utf8mb4;dbname=" . $dbName;
        $db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec("SET time_zone = '+08:00'");
        ensure_void_and_image_schema($db);
        return $db;
    }
    $db = connection();
    schema_ready($db);
    return $db;
}
