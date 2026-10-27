<?php
declare(strict_types=1);
require_once __DIR__ . "/stock.php";
function sale_receipt(PDO $db, array $sale, bool $duplicate = false): array
{
    unset($sale["request_hash"]);
    $sale["id"] = (int) $sale["id"];
    $sale["user_id"] = (int) $sale["user_id"];
    $sale["duplicate"] = $duplicate;
    $sale["items"] = query(
        $db,
        "SELECT variant_id,variant_label,qty,unit_price,line_gross,line_net,line_vat FROM sale_items WHERE sale_id=? ORDER BY id",
        [$sale["id"]],
    )->fetchAll();
    return $sale;
}
function normalize_sale(array $body): array
{
    if (
        array_diff(array_keys($body), [
            "transaction_key",
            "cash_received",
            "items",
            "expected_epoch",
        ])
    ) {
        problem(422, "VALIDATION", "Unexpected sale fields.");
    }
    $key = uuid_value($body["transaction_key"] ?? null);
    $cash = fixed($body["cash_received"] ?? null, 2, "Cash received", true);
    $items = $body["items"] ?? null;
    if ($cash > 999999999) {
        problem(422, "VALIDATION", "Cash received exceeds 9,999,999.99.");
    }
    if (
        !is_array($items) ||
        !array_is_list($items) ||
        count($items) < 1 ||
        count($items) > 50
    ) {
        problem(422, "VALIDATION", "Provide 1–50 sale lines.");
    }
    $lines = [];
    foreach ($items as $line) {
        if (
            !is_array($line) ||
            array_diff(array_keys($line), [
                "variant_id",
                "qty",
                "expected_price",
            ])
        ) {
            problem(422, "VALIDATION", "Unexpected sale item fields.");
        }
        $id = positive_id($line["variant_id"] ?? null);
        if (isset($lines[$id])) {
            problem(
                422,
                "VALIDATION",
                "Combine duplicate variants into one line.",
            );
        }
        $lines[$id] = [
            "variant_id" => $id,
            "qty" => positive_id($line["qty"] ?? null, "Sale quantity", 100),
            "expected_price" => fixed(
                $line["expected_price"] ?? null,
                2,
                "Displayed price",
            ),
        ];
    }
    return ["key" => $key, "cash" => $cash, "items" => array_values($lines)];
}
function checkout(
    PDO $db,
    array $session,
    array $body,
    string $requestId,
): array {
    $request = normalize_sale($body);
    $hash = hash("sha256", json_encode($request, JSON_THROW_ON_ERROR));
    $uid = (int) $session["user"]["id"];
    $db->beginTransaction();
    try {
        lock_state($db);
        current_session($db, $session);
        assert_epoch($db, $body);
        $old = query($db, "SELECT * FROM sales WHERE transaction_key=?", [
            $request["key"],
        ])->fetch();
        if ($old) {
            if (
                (int) $old["user_id"] !== $uid ||
                !hash_equals($old["request_hash"], $hash)
            ) {
                problem(
                    409,
                    "KEY_CONFLICT",
                    "This transaction key belongs to a different sale or account.",
                );
            }
            $receipt = sale_receipt($db, $old, true);
            $db->commit();
            return $receipt;
        }
        $rejected = query(
            $db,
            "SELECT * FROM checkout_rejections WHERE transaction_key=?",
            [$request["key"]],
        )->fetch();
        if ($rejected) {
            if (
                (int) $rejected["user_id"] !== $uid ||
                !hash_equals($rejected["request_hash"], $hash)
            ) {
                problem(
                    409,
                    "KEY_CONFLICT",
                    "This transaction key belongs to a different request.",
                );
            }
            problem(
                (int) $rejected["status_code"],
                $rejected["error_code"],
                $rejected["message"],
            );
        }
        $db->exec("SAVEPOINT checkout_work");
        $canRecordRejection = true;
        // Capture one authoritative server time after the workflow lock, including at midnight.
        $at = database_time($db);
        $requirements = [];
        $lines = [];
        $gross = 0;
        $netSum = 0;
        foreach ($request["items"] as $item) {
            $variant = query(
                $db,
                "SELECT v.*,p.name AS product_name,p.is_active AS product_active,p.is_vat_exempt FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=? FOR UPDATE",
                [$item["variant_id"]],
            )->fetch();
            if (
                !$variant ||
                !(int) $variant["is_active"] ||
                !(int) $variant["product_active"] ||
                (int) $variant["is_vat_exempt"]
            ) {
                problem(
                    409,
                    "VARIANT_UNAVAILABLE",
                    "A selected variant is no longer available. Review the basket.",
                );
            }
            $price = fixed($variant["price"], 2, "Price");
            if ($price !== $item["expected_price"]) {
                problem(
                    409,
                    "PRICE_CHANGED",
                    "Price changed: " .
                        $variant["product_name"] .
                        ". Refresh the menu and review the basket.",
                );
            }
            $recipe = query(
                $db,
                "SELECT * FROM recipes WHERE variant_id=? ORDER BY ingredient_id",
                [$variant["id"]],
            )->fetchAll();
            if (!$recipe) {
                problem(
                    409,
                    "RECIPE_MISSING",
                    "Recipe missing: " . $variant["product_name"],
                );
            }
            foreach ($recipe as $row) {
                $id = (int) $row["ingredient_id"];
                $requirements[$id] =
                    ($requirements[$id] ?? 0) +
                    fixed($row["qty"], 3, "Recipe quantity") * $item["qty"];
            }
            $g = $price * $item["qty"];
            $n = intdiv($g * 100 + 56, 112);
            $gross += $g;
            $netSum += $n;
            $lines[] = $item + [
                "label" =>
                    $variant["product_name"] .
                    " · " .
                    $variant["size"] .
                    ($variant["temperature"] === "NA"
                        ? ""
                        : " " . $variant["temperature"]),
                "gross" => $g,
                "net" => $n,
            ];
        }
        if ($gross > 999999999) {
            problem(422, "SALE_LIMIT", "Sale total exceeds 9,999,999.99.");
        }
        if ($request["cash"] < $gross) {
            problem(
                422,
                "UNDERPAYMENT",
                "Cash received is less than the total.",
            );
        }
        $net = intdiv($gross * 100 + 56, 112);
        $lines[count($lines) - 1]["net"] += $net - $netSum;
        foreach ($lines as $line) {
            if ($line["net"] < 0 || $line["net"] > $line["gross"]) {
                problem(
                    422,
                    "VAT_ALLOCATION",
                    "The last line cannot absorb the VAT rounding adjustment. Review the basket order.",
                );
            }
        }
        ksort($requirements);
        $plans = [];
        foreach ($requirements as $id => $need) {
            $ingredient = query(
                $db,
                "SELECT * FROM ingredients WHERE id=? FOR UPDATE",
                [$id],
            )->fetch();
            if (!$ingredient || !(int) $ingredient["is_active"]) {
                problem(
                    409,
                    "INGREDIENT_UNAVAILABLE",
                    "An ingredient in the recipe is inactive.",
                );
            }
            $plans[$id] = [
                "ingredient" => $ingredient,
                "allocations" => stock_plan(
                    stock_containers($db, $id, true),
                    $need,
                    $at,
                    $ingredient["name"],
                ),
            ];
            if (fixed($ingredient["current_stock"], 3, "Stock", true) < $need) {
                throw new RuntimeException("Stock cache inconsistent");
            }
        }
        // No writes above: validate all lines and aggregated shared ingredient requirements first.
        query(
            $db,
            "INSERT INTO sales(transaction_key,request_hash,user_id,sale_at,cash_received,change_due,total_gross,total_net,total_vat,vat_rate) VALUES(?,?,?,?,?,?,?,?,?,?)",
            [
                $request["key"],
                $hash,
                $uid,
                $at,
                decimal($request["cash"]),
                decimal($request["cash"] - $gross),
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
                "INSERT INTO sale_items(sale_id,variant_id,variant_label,qty,unit_price,line_gross,line_net,line_vat) VALUES(?,?,?,?,?,?,?,?)",
                [
                    $saleId,
                    $line["variant_id"],
                    $line["label"],
                    $line["qty"],
                    decimal($line["expected_price"]),
                    decimal($line["gross"]),
                    decimal($line["net"]),
                    decimal($line["gross"] - $line["net"]),
                ],
            );
        }
        $batches = [];
        foreach ($plans as $id => $plan) {
            foreach ($plan["allocations"] as $allocation) {
                $c = $allocation["container"];
                $take = $allocation["take"];
                if ($c["opened_at"] === null) {
                    $hours = $plan["ingredient"]["opened_shelf_life_hours"];
                    $useBy =
                        $hours === null
                            ? null
                            : (new DateTimeImmutable($at))
                                ->modify("+" . (int) $hours . " hours")
                                ->format("Y-m-d H:i:s");
                    query(
                        $db,
                        "UPDATE stock_containers SET opened_at=?,use_by=? WHERE id=?",
                        [$at, $useBy, $c["id"]],
                    );
                }
                query(
                    $db,
                    "UPDATE stock_containers SET remaining_qty=remaining_qty-? WHERE id=?",
                    [decimal($take, 3), $c["id"]],
                );
                $batches[(int) $c["batch_id"]] = true;
                record_movement(
                    $db,
                    $id,
                    (int) $c["batch_id"],
                    (int) $c["id"],
                    $saleId,
                    $uid,
                    "SALE",
                    -$take,
                    "Sale #" . $saleId,
                    $at,
                );
            }
            query(
                $db,
                "UPDATE ingredients SET current_stock=current_stock-? WHERE id=?",
                [decimal($requirements[$id], 3), $id],
            );
        }
        foreach (array_keys($batches) as $id) {
            update_batch_totals($db, $id);
        }
        audit($db, $uid, "SALE_CONFIRMED", $requestId);
        $receipt = sale_receipt(
            $db,
            query($db, "SELECT * FROM sales WHERE id=?", [$saleId])->fetch(),
        );
        $db->commit();
        return $receipt;
    } catch (Throwable $e) {
        if (
            ($canRecordRejection ?? false) &&
            $e instanceof AppError &&
            in_array(
                $e->errorCode,
                [
                    "VARIANT_UNAVAILABLE",
                    "PRICE_CHANGED",
                    "RECIPE_MISSING",
                    "SALE_LIMIT",
                    "UNDERPAYMENT",
                    "VAT_ALLOCATION",
                    "INGREDIENT_UNAVAILABLE",
                    "INSUFFICIENT_STOCK",
                ],
                true,
            )
        ) {
            try {
                $db->exec("ROLLBACK TO SAVEPOINT checkout_work");
                query(
                    $db,
                    "INSERT INTO checkout_rejections(transaction_key,request_hash,user_id,status_code,error_code,message,created_at) VALUES(?,?,?,?,?,?,NOW())",
                    [
                        $request["key"],
                        $hash,
                        $uid,
                        $e->status,
                        $e->errorCode,
                        $e->getMessage(),
                    ],
                );
                audit($db, $uid, "SALE_REJECTED", $requestId);
                $db->commit();
            } catch (Throwable $failure) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $failure;
            }
        }
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
