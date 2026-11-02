<?php
declare(strict_types=1);
require_once __DIR__ . "/catalogue.php";
require_once __DIR__ . "/recovery.php";

function owner_reauth(PDO $db, array $session, array $body, string $ref): void
{
    current_session($db, $session, true);
    $password = $body["owner_password"] ?? null;
    if (!is_string($password) || strlen($password) > 72) {
        problem(422, "VALIDATION", "Enter your current owner password.");
    }
    $hash = query($db, "SELECT password_hash FROM users WHERE id=?", [
        $session["user"]["id"],
    ])->fetchColumn();
    credential_check(
        $db,
        (int) $session["user"]["id"],
        "owner-reauth",
        password_verify($password, $hash),
        $ref,
    );
}
function inventory_operation(
    PDO $db,
    array $session,
    array $body,
    string $ref,
): array {
    $key = uuid_value($body["operation_key"] ?? null);
    $kind = $body["kind"] ?? "";
    if (!in_array($kind, ["waste", "adjust", "batch", "clear_expired"], true)) {
        problem(
            422,
            "VALIDATION",
            "Choose waste, adjustment, batch correction or expired stock write-off.",
        );
    }
    $defaultReason =
        $kind === "clear_expired"
            ? "Shift audit: Write off expired or past use-by stock"
            : null;
    $reason = label_text(
        ($body["reason"] ?? "") !== "" ? $body["reason"] : $defaultReason,
        "Reason",
        180,
    );
    if ($kind === "clear_expired") {
        $ingFilter =
            isset($body["ingredient_id"]) && $body["ingredient_id"] !== ""
                ? positive_id($body["ingredient_id"])
                : null;
        $normalized = [
            "kind" => $kind,
            "ingredient_id" => $ingFilter,
            "reason" => $reason,
        ];
    } else {
        $id = positive_id($body["id"] ?? null);
        $normalized = ["kind" => $kind, "id" => $id, "reason" => $reason];
        if ($kind === "batch") {
            $normalized += [
                "expiration_date" => valid_date($body["expiration_date"] ?? null),
                "supplier" => optional_text($body["supplier"] ?? null, "Supplier"),
                "lot_reference" => optional_text(
                    $body["lot_reference"] ?? null,
                    "Lot reference",
                ),
                "quarantined" => active_flag([
                    "is_active" => $body["quarantined"] ?? 0,
                ]),
            ];
        } else {
            $normalized += [
                "quantity" => fixed(
                    $body["quantity"] ?? null,
                    3,
                    "Quantity",
                    $kind === "adjust",
                ),
                "expected_remaining" => fixed(
                    $body["expected_remaining"] ?? null,
                    3,
                    "Expected remaining quantity",
                    true,
                ),
            ];
        }
    }
    $hash = hash("sha256", json_encode($normalized, JSON_THROW_ON_ERROR));
    $uid = (int) $session["user"]["id"];
    $db->beginTransaction();
    try {
        lock_state($db);
        current_session($db, $session, true);
        assert_epoch($db, $body);
        $old = query(
            $db,
            "SELECT * FROM stock_operations WHERE operation_key=?",
            [$key],
        )->fetch();
        if ($old) {
            if (
                (int) $old["user_id"] !== $uid ||
                !hash_equals($old["request_hash"], $hash)
            ) {
                problem(
                    409,
                    "KEY_CONFLICT",
                    "This operation key belongs to different details.",
                );
            }
            $result = json_decode(
                $old["result_json"],
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            $db->commit();
            return $result + ["duplicate" => true];
        }
        $at = database_time($db);
        if ($kind === "clear_expired") {
            $sql =
                "SELECT c.*,b.ingredient_id,b.expiration_date,b.quarantined FROM stock_containers c JOIN stock_batches b ON b.id=c.batch_id WHERE c.remaining_qty>0 AND b.quarantined=0";
            $params = [];
            if ($normalized["ingredient_id"] !== null) {
                $sql .= " AND b.ingredient_id=?";
                $params[] = $normalized["ingredient_id"];
            }
            $sql .= " ORDER BY c.id FOR UPDATE";
            $candidates = query($db, $sql, $params)->fetchAll();
            $expiredList = array_values(
                array_filter($candidates, fn($c) => !stock_eligible($c, $at)),
            );
            if (!$expiredList) {
                problem(
                    422,
                    "NO_EXPIRED_STOCK",
                    "No expired or past use-by containers remain to write off.",
                );
            }
            $touchedBatches = [];
            $cleared = [];
            foreach ($expiredList as $c) {
                $cid = (int) $c["id"];
                $bid = (int) $c["batch_id"];
                $iid = (int) $c["ingredient_id"];
                query(
                    $db,
                    "SELECT id FROM ingredients WHERE id=? FOR UPDATE",
                    [$iid],
                )->fetch();
                $before = fixed($c["remaining_qty"], 3, "Remaining", true);
                if ($before <= 0) {
                    continue;
                }
                $delta = -$before;
                query(
                    $db,
                    "UPDATE stock_containers SET remaining_qty='0.000' WHERE id=?",
                    [$cid],
                );
                query(
                    $db,
                    "UPDATE ingredients SET current_stock=current_stock+? WHERE id=?",
                    [decimal($delta, 3), $iid],
                );
                $touchedBatches[$bid] = true;
                $detailSuffix =
                    $c["expiration_date"] !== null &&
                    $c["expiration_date"] < substr($at, 0, 10)
                        ? " [Expired " . $c["expiration_date"] . "]"
                        : " [Past use-by " . ($c["use_by"] ?? $at) . "]";
                $combined = $reason . $detailSuffix;
                preg_match('/^.{0,180}/us', $combined, $rm);
                $itemReason = $rm[0] ?? substr($combined, 0, 180);
                record_movement(
                    $db,
                    $iid,
                    $bid,
                    $cid,
                    null,
                    $uid,
                    "WASTE",
                    $delta,
                    $itemReason,
                    $at,
                );
                $cleared[] = [
                    "container_id" => $cid,
                    "batch_id" => $bid,
                    "ingredient_id" => $iid,
                    "wasted_qty" => decimal($before, 3),
                ];
            }
            foreach (array_keys($touchedBatches) as $bid) {
                update_batch_totals($db, (int) $bid);
            }
            $result = [
                "cleared_count" => count($cleared),
                "containers" => $cleared,
            ];
            audit($db, $uid, "EXPIRED_WASTE_CLEARED", $ref, [
                "cleared_count" => count($cleared),
                "containers" => $cleared,
                "reason" => $reason,
            ]);
        } elseif ($kind === "batch") {
            $before = query(
                $db,
                "SELECT * FROM stock_batches WHERE id=? FOR UPDATE",
                [$id],
            )->fetch();
            if (!$before) {
                problem(404, "NOT_FOUND", "Batch not found.");
            }
            $ing = query($db, "SELECT * FROM ingredients WHERE id=?", [
                $before["ingredient_id"],
            ])->fetch();
            if (
                $ing["opened_shelf_life_hours"] !== null &&
                $normalized["expiration_date"] === null
            ) {
                problem(
                    422,
                    "EXPIRATION_REQUIRED",
                    "Perishable batches require an expiration date.",
                );
            }
            query(
                $db,
                "UPDATE stock_batches SET expiration_date=?,supplier=?,lot_reference=?,quarantined=? WHERE id=?",
                [
                    $normalized["expiration_date"],
                    $normalized["supplier"],
                    $normalized["lot_reference"],
                    $normalized["quarantined"],
                    $id,
                ],
            );
            $container = (int) query(
                $db,
                "SELECT id FROM stock_containers WHERE batch_id=? ORDER BY id LIMIT 1",
                [$id],
            )->fetchColumn();
            record_movement(
                $db,
                (int) $before["ingredient_id"],
                $id,
                $container,
                null,
                $uid,
                "ADJUSTMENT",
                0,
                "Batch correction: " . $reason,
                $at,
            );
            $result = ["batch_id" => $id];
            audit($db, $uid, "BATCH_CORRECTED", $ref, [
                "before" => array_intersect_key($before, $normalized),
                "after" => $normalized,
            ]);
        } else {
            $c = query(
                $db,
                "SELECT c.*,b.ingredient_id,b.expiration_date,b.quarantined FROM stock_containers c JOIN stock_batches b ON b.id=c.batch_id WHERE c.id=? FOR UPDATE",
                [$id],
            )->fetch();
            if (!$c) {
                problem(404, "NOT_FOUND", "Container not found.");
            }
            $ing = query(
                $db,
                "SELECT * FROM ingredients WHERE id=? FOR UPDATE",
                [$c["ingredient_id"]],
            )->fetch();
            whole_units($ing, $normalized["quantity"]);
            $before = fixed($c["remaining_qty"], 3, "Remaining", true);
            if ($before !== $normalized["expected_remaining"]) {
                problem(
                    409,
                    "STOCK_CHANGED",
                    "Stock changed since you opened this form. Refresh and recount.",
                );
            }
            $after =
                $kind === "waste"
                    ? $before - $normalized["quantity"]
                    : $normalized["quantity"];
            if ($after < 0) {
                problem(
                    422,
                    "QUANTITY_LIMIT",
                    "Waste cannot exceed the remaining quantity.",
                );
            }
            if ($after > fixed($c["received_qty"], 3, "Received")) {
                problem(
                    422,
                    "QUANTITY_LIMIT",
                    "A count correction cannot exceed this container’s received quantity. Receive additional stock as a new delivery.",
                );
            }
            if (
                $after > $before &&
                !stock_eligible(
                    array_replace($c, ["remaining_qty" => "1"]),
                    $at,
                )
            ) {
                problem(
                    409,
                    "EXPIRED_STOCK",
                    "Cannot add quantity to expired or quarantined stock.",
                );
            }
            $delta = $after - $before;
            if ($delta === 0) {
                problem(422, "NO_CHANGE", "The count is unchanged.");
            }
            if (
                $delta > 0 &&
                fixed($ing["current_stock"], 3, "Stock", true) + $delta >
                    999999999999
            ) {
                problem(422, "QUANTITY_LIMIT", "Stock limit exceeded.");
            }
            query(
                $db,
                "UPDATE stock_containers SET remaining_qty=? WHERE id=?",
                [decimal($after, 3), $id],
            );
            query(
                $db,
                "UPDATE ingredients SET current_stock=current_stock+? WHERE id=?",
                [decimal($delta, 3), $ing["id"]],
            );
            update_batch_totals($db, (int) $c["batch_id"]);
            record_movement(
                $db,
                (int) $ing["id"],
                (int) $c["batch_id"],
                $id,
                null,
                $uid,
                $kind === "waste" ? "WASTE" : "ADJUSTMENT",
                $delta,
                $reason,
                $at,
            );
            $result = [
                "container_id" => $id,
                "remaining_qty" => decimal($after, 3),
                "quantity_delta" => decimal($delta, 3),
            ];
            audit(
                $db,
                $uid,
                $kind === "waste" ? "WASTE_RECORDED" : "STOCK_ADJUSTED",
                $ref,
                [
                    "container_id" => $id,
                    "before" => decimal($before, 3),
                    "after" => decimal($after, 3),
                    "reason" => $reason,
                ],
            );
        }
        query(
            $db,
            "INSERT INTO stock_operations(operation_key,request_hash,user_id,result_json,created_at) VALUES(?,?,?,?,?)",
            [$key, $hash, $uid, json_encode($result, JSON_THROW_ON_ERROR), $at],
        );
        $db->commit();
        return $result + ["duplicate" => false];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function staff_save(PDO $db, array $session, array $body, string $ref): array
{
    $name = username($body["username"] ?? null);
    $display = label_text($body["display_name"] ?? null, "Display name");
    $email = valid_email($body["email"] ?? null, false);
    $id = isset($body["id"]) ? positive_id($body["id"]) : null;
    if (isset($body["role"]) && $body["role"] !== "BARISTA") {
        problem(
            422,
            "STAFF_REQUIRED",
            "Only Barista accounts can be managed here.",
        );
    }
    $active = active_flag($body);
    $hash = null;
    if (!$id) {
        if (!is_string($body["password"] ?? null)) {
            problem(422, "VALIDATION", "Enter an initial password.");
        }
        $hash = new_password($body["password"], $body["confirm_password"] ?? null);
    }
    $db->beginTransaction();
    try {
        lock_state($db);
        owner_reauth($db, $session, $body, $ref);
        if ($id) {
            $old = query(
                $db,
                "SELECT * FROM users WHERE id=? AND role='BARISTA'",
                [$id],
            )->fetch();
            if (!$old) {
                problem(422, "STAFF_REQUIRED", "Choose an existing Barista.");
            }
            $emailToSave = array_key_exists("email", $body) ? $email : ($old["email"] ?? null);
            query(
                $db,
                "UPDATE users SET username=?,display_name=?,email=?,is_active=?,updated_at=NOW() WHERE id=?",
                [$name, $display, $emailToSave, $active, $id],
            );
            query($db, "DELETE FROM sessions WHERE user_id=?", [$id]);
        } else {
            query(
                $db,
                "INSERT INTO users(username,display_name,email,password_hash,role,is_active,created_at,updated_at) VALUES(?,?,?,?,'BARISTA',?,NOW(),NOW())",
                [$name, $display, $email, $hash, $active],
            );
            $id = (int) $db->lastInsertId();
        }
        audit($db, (int) $session["user"]["id"], "STAFF_SAVED:" . $id, $ref, [
            "username" => $name,
            "display_name" => $display,
            "email" => $email,
            "is_active" => $active,
        ]);
        $db->commit();
        return ["id" => $id];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function owner_profile_save(PDO $db, array $session, array $body, string $ref): array
{
    $email = valid_email($body["email"] ?? null, true);
    $rawShop = $body["shop_name"] ?? null;
    $shopName = is_string($rawShop) && trim($rawShop) !== "" ? trim($rawShop) : "Nearby Café";
    if (strlen($shopName) > 120) {
        problem(422, "VALIDATION", "Shop name must contain 1–120 bytes.");
    }
    $uid = (int) $session["user"]["id"];
    $db->beginTransaction();
    try {
        lock_state($db);
        owner_reauth($db, $session, $body, $ref);
        query(
            $db,
            "UPDATE users SET email=?,shop_name=?,updated_at=NOW() WHERE id=? AND role='OWNER'",
            [$email, $shopName, $uid],
        );
        audit($db, $uid, "OWNER_PROFILE_UPDATED", $ref, [
            "email" => $email,
            "shop_name" => $shopName,
        ]);
        $updated = query($db, "SELECT * FROM users WHERE id=?", [$uid])->fetch();
        $db->commit();
        return ["user" => public_user($updated)];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function delete_catalogue(
    PDO $db,
    array $session,
    array $body,
    string $ref,
): array {
    $kind = $body["kind"] ?? "";
    $id = positive_id($body["id"] ?? null);
    $tables = [
        "product" => "products",
        "variant" => "product_variants",
        "ingredient" => "ingredients",
    ];
    if (!isset($tables[$kind])) {
        problem(422, "VALIDATION", "Choose a product, variant or ingredient.");
    }
    $db->beginTransaction();
    try {
        lock_state($db);
        current_session($db, $session, true);
        $table = $tables[$kind];
        $old = query($db, "SELECT * FROM $table WHERE id=?", [$id])->fetch();
        if (!$old) {
            problem(404, "NOT_FOUND", "Record not found.");
        }
        $used = match ($kind) {
            "product" => query(
                $db,
                "SELECT COUNT(*) FROM product_variants WHERE product_id=?",
                [$id],
            )->fetchColumn(),
            "variant" => query(
                $db,
                "SELECT COUNT(*) FROM sale_items WHERE variant_id=?",
                [$id],
            )->fetchColumn(),
            "ingredient" => query(
                $db,
                "SELECT (SELECT COUNT(*) FROM recipes WHERE ingredient_id=?)+(SELECT COUNT(*) FROM stock_batches WHERE ingredient_id=?)",
                [$id, $id],
            )->fetchColumn(),
        };
        if ((int) $used > 0) {
            problem(
                409,
                "RECORD_IN_USE",
                "This record has related data. Set it inactive to preserve history.",
            );
        }
        if ($kind === "variant") {
            query($db, "DELETE FROM recipes WHERE variant_id=?", [$id]);
        }
        if ($kind === "product" && !empty($old["image_filename"])) {
            remove_product_image_file((string) $old["image_filename"]);
        }
        query($db, "DELETE FROM $table WHERE id=?", [$id]);
        audit($db, (int) $session["user"]["id"], "CATALOGUE_DELETED", $ref, [
            "kind" => $kind,
            "record" => $old,
        ]);
        $db->commit();
        return ["deleted" => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
