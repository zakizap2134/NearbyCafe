<?php
declare(strict_types=1);
require_once __DIR__ . "/values.php";
function stock_containers(PDO $db, int $ingredient, bool $lock = false): array
{
    return query(
        $db,
        "SELECT c.*,b.ingredient_id,b.expiration_date,b.quarantined FROM stock_containers c JOIN stock_batches b ON b.id=c.batch_id WHERE b.ingredient_id=? ORDER BY c.id" .
            ($lock ? " FOR UPDATE" : ""),
        [$ingredient],
    )->fetchAll();
}
function stock_eligible(array $c, string $at): bool
{
    return !(int) $c["quarantined"] &&
        fixed($c["remaining_qty"], 3, "stock", true) > 0 &&
        ($c["expiration_date"] === null ||
            $c["expiration_date"] >= substr($at, 0, 10)) &&
        ($c["use_by"] === null || $c["use_by"] > $at);
}
function stock_plan(
    array $containers,
    int $need,
    string $at,
    string $name,
): array {
    $eligible = array_values(
        array_filter($containers, fn($c) => stock_eligible($c, $at)),
    );
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
    $plan = [];
    foreach ($eligible as $c) {
        $take = min($need, fixed($c["remaining_qty"], 3, "stock", true));
        if ($take > 0) {
            $plan[] = ["container" => $c, "take" => $take];
        }
        $need -= $take;
        if ($need === 0) {
            break;
        }
    }
    if ($need > 0) {
        problem(409, "INSUFFICIENT_STOCK", "Insufficient stock: " . $name);
    }
    return $plan;
}
function update_batch_totals(PDO $db, int $id): void
{
    query(
        $db,
        "UPDATE stock_batches SET remaining_qty=(SELECT COALESCE(SUM(remaining_qty),0) FROM stock_containers WHERE batch_id=?),qty_sealed=(SELECT COALESCE(SUM(remaining_qty),0) FROM stock_containers WHERE batch_id=? AND opened_at IS NULL),qty_opened=(SELECT COALESCE(SUM(remaining_qty),0) FROM stock_containers WHERE batch_id=? AND opened_at IS NOT NULL) WHERE id=?",
        [$id, $id, $id, $id],
    );
}
function record_movement(
    PDO $db,
    int $ingredient,
    int $batch,
    int $container,
    ?int $sale,
    int $user,
    string $type,
    int $delta,
    string $reason,
    string $at,
): void {
    query(
        $db,
        "INSERT INTO stock_movements(ingredient_id,batch_id,container_id,sale_id,user_id,type,quantity_delta,reason,created_at) VALUES(?,?,?,?,?,?,?,?,?)",
        [
            $ingredient,
            $batch,
            $container,
            $sale,
            $user,
            $type,
            decimal($delta, 3),
            $reason,
            $at,
        ],
    );
}
function receive_stock(
    PDO $db,
    array $body,
    int $user,
    string $requestId,
): array {
    $key = uuid_value($body["delivery_key"] ?? null);
    $r = [
        "ingredient_id" => positive_id($body["ingredient_id"] ?? null),
        "container_count" => positive_id(
            $body["container_count"] ?? null,
            "container count",
            1000,
        ),
        "quantity_per_container" => fixed(
            $body["quantity_per_container"] ?? null,
            3,
            "Quantity per container",
        ),
        "expiration_date" => valid_date($body["expiration_date"] ?? null),
        "reference" => label_text(
            $body["reference"] ?? null,
            "Delivery reference",
        ),
        "supplier" => optional_text($body["supplier"] ?? null, "Supplier"),
        "lot_reference" => optional_text(
            $body["lot_reference"] ?? null,
            "Lot reference",
        ),
    ];
    $hash = hash("sha256", json_encode($r, JSON_THROW_ON_ERROR));
    $old = query($db, "SELECT * FROM stock_batches WHERE delivery_key=?", [
        $key,
    ])->fetch();
    if ($old) {
        if (
            (int) $old["user_id"] !== $user ||
            !hash_equals($old["request_hash"], $hash)
        ) {
            problem(
                409,
                "KEY_CONFLICT",
                "Delivery key belongs to a different request.",
            );
        }
        return ["id" => (int) $old["id"], "duplicate" => true];
    }
    $ingredient = query(
        $db,
        "SELECT * FROM ingredients WHERE id=? FOR UPDATE",
        [$r["ingredient_id"]],
    )->fetch();
    if (!$ingredient || !(int) $ingredient["is_active"]) {
        problem(409, "INGREDIENT_UNAVAILABLE", "Choose an active ingredient.");
    }
    whole_units($ingredient, $r["quantity_per_container"]);
    if (
        $ingredient["opened_shelf_life_hours"] !== null &&
        $r["expiration_date"] === null
    ) {
        problem(
            422,
            "EXPIRATION_REQUIRED",
            "Expiration date is required for perishable ingredients.",
        );
    }
    $total = $r["container_count"] * $r["quantity_per_container"];
    if (
        $total > 999999999999 ||
        fixed($ingredient["current_stock"], 3, "stock", true) + $total >
            999999999999
    ) {
        problem(422, "QUANTITY_LIMIT", "The delivery exceeds the stock limit.");
    }
    $at = database_time($db);
    query(
        $db,
        "INSERT INTO stock_batches(ingredient_id,delivery_key,request_hash,reference,supplier,lot_reference,expiration_date,received_qty,remaining_qty,qty_sealed,user_id,received_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $r["ingredient_id"],
            $key,
            $hash,
            $r["reference"],
            $r["supplier"],
            $r["lot_reference"],
            $r["expiration_date"],
            decimal($total, 3),
            decimal($total, 3),
            decimal($total, 3),
            $user,
            $at,
        ],
    );
    $batch = (int) $db->lastInsertId();
    for ($i = 0; $i < $r["container_count"]; $i++) {
        query(
            $db,
            "INSERT INTO stock_containers(batch_id,received_qty,remaining_qty) VALUES(?,?,?)",
            [
                $batch,
                decimal($r["quantity_per_container"], 3),
                decimal($r["quantity_per_container"], 3),
            ],
        );
        record_movement(
            $db,
            $r["ingredient_id"],
            $batch,
            (int) $db->lastInsertId(),
            null,
            $user,
            "RECEIVE",
            $r["quantity_per_container"],
            $r["reference"],
            $at,
        );
    }
    query(
        $db,
        "UPDATE ingredients SET current_stock=current_stock+? WHERE id=?",
        [decimal($total, 3), $r["ingredient_id"]],
    );
    audit($db, $user, "STOCK_RECEIVED", $requestId);
    return ["id" => $batch, "duplicate" => false];
}
