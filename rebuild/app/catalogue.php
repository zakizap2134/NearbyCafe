<?php
declare(strict_types=1);
require_once __DIR__ . "/stock.php";
function ingredient_stock(PDO $db, string $at): array
{
    $ingredients = $db
        ->query("SELECT * FROM ingredients ORDER BY name")
        ->fetchAll();
    foreach ($ingredients as &$i) {
        $eligible = 0;
        $waste = 0;
        foreach (stock_containers($db, (int) $i["id"]) as $c) {
            $n = fixed($c["remaining_qty"], 3, "stock", true);
            if (stock_eligible($c, $at)) {
                $eligible += $n;
            } elseif (!(int) $c["quarantined"]) {
                $waste += $n;
            }
        }
        $i["eligible_stock"] = decimal(
            (int) $i["is_active"] ? $eligible : 0,
            3,
        );
        $i["waste_due"] = decimal($waste, 3);
    }
    return $ingredients;
}
function catalogue_data(PDO $db): array
{
    $at = database_time($db);
    $available = [];
    foreach (ingredient_stock($db, $at) as $i) {
        $available[(int) $i["id"]] = fixed(
            $i["eligible_stock"],
            3,
            "stock",
            true,
        );
    }
    $variants = $db
        ->query(
            "SELECT v.*,p.name AS product_name,p.category,p.image_filename FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.is_active=1 AND p.is_active=1 AND p.is_vat_exempt=0 ORDER BY p.name,FIELD(v.size,'S','M','L'),v.temperature",
        )
        ->fetchAll();
    foreach ($variants as &$v) {
        $recipe = query(
            $db,
            "SELECT * FROM recipes WHERE variant_id=? ORDER BY ingredient_id",
            [$v["id"]],
        )->fetchAll();
        $count = $recipe ? PHP_INT_MAX : 0;
        foreach ($recipe as $r) {
            $count = min(
                $count,
                intdiv(
                    $available[(int) $r["ingredient_id"]] ?? 0,
                    fixed($r["qty"], 3, "recipe"),
                ),
            );
        }
        $v["available_count"] = $count;
        $v["recipe_ready"] = (bool) $recipe;
    }
    return [
        "variants" => $variants,
        "server_time" => $at,
        "today_sales" => today_sales($db, $at),
    ];
}
function today_sales(PDO $db, string $at): array
{
    $date = substr($at, 0, 10);
    $totals = query(
        $db,
        "SELECT COUNT(*) AS count,COALESCE(SUM(total_gross),0) AS gross,COALESCE(SUM(total_net),0) AS net,COALESCE(SUM(total_vat),0) AS vat FROM sales WHERE status='completed' AND sale_at>=? AND sale_at<DATE_ADD(?,INTERVAL 1 DAY)",
        [$date, $date],
    )->fetch();
    $totals["count"] = (int) $totals["count"];
    foreach (["gross", "net", "vat"] as $key) {
        $totals[$key] = decimal(fixed($totals[$key], 2, "total", true));
    }
    return [
        "date" => $date,
        "totals" => $totals,
        "recent" => query(
            $db,
            "SELECT id,transaction_key,user_id,sale_at,total_gross,total_net,total_vat,status,void_reason,voided_at FROM sales WHERE sale_at>=? AND sale_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY id DESC LIMIT 10",
            [$date, $date],
        )->fetchAll(),
    ];
}
function product_uploads_dir(): string
{
    $dir = __DIR__ . "/../public/uploads/products";
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}
function remove_product_image_file(?string $filename): void
{
    if (
        is_string($filename) &&
        $filename !== "" &&
        preg_match('/^prod_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|webp)$/D', $filename)
    ) {
        $path = product_uploads_dir() . "/" . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
function process_product_image(
    int $productId,
    array $body,
    ?string $oldFilename,
): ?string {
    $remove = $body["remove_image"] ?? false;
    if (in_array($remove, [true, 1, "1", "true"], true)) {
        remove_product_image_file($oldFilename);
        $oldFilename = null;
    }
    $bytes = null;
    if (
        isset($_FILES["image"]) &&
        is_array($_FILES["image"]) &&
        ($_FILES["image"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ) {
        if ((int) $_FILES["image"]["error"] !== UPLOAD_ERR_OK) {
            problem(
                422,
                "IMAGE_UPLOAD",
                "Image upload failed. Choose a JPG, PNG or WebP image up to 2 MB.",
            );
        }
        $bytes = @file_get_contents((string) $_FILES["image"]["tmp_name"]);
        if ($bytes === false) {
            problem(422, "IMAGE_UPLOAD", "Uploaded image could not be read.");
        }
    } elseif (
        isset($body["image_data"]) &&
        is_string($body["image_data"]) &&
        trim($body["image_data"]) !== ""
    ) {
        if (
            !preg_match(
                '/^data:image\/(jpeg|jpg|png|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/iD',
                trim($body["image_data"]),
                $m,
            )
        ) {
            problem(
                422,
                "IMAGE_FORMAT",
                "Choose a valid JPG, PNG or WebP image.",
            );
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false) {
            problem(422, "IMAGE_FORMAT", "Invalid image encoding.");
        }
    }
    if ($bytes === null) {
        return $oldFilename;
    }
    if (strlen($bytes) < 12 || strlen($bytes) > 2 * 1024 * 1024) {
        problem(
            422,
            "IMAGE_SIZE",
            "Product image must be 2 MB or smaller.",
        );
    }
    $ext = null;
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        $ext = "jpg";
    } elseif (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
        $ext = "png";
    } elseif (
        substr($bytes, 0, 4) === "RIFF" &&
        substr($bytes, 8, 4) === "WEBP"
    ) {
        $ext = "webp";
    } else {
        problem(
            422,
            "IMAGE_FORMAT",
            "Only JPG, PNG and WebP images are supported.",
        );
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
            problem(
                422,
                "IMAGE_FORMAT",
                "Image dimensions must be valid and at most 4096×4096 px.",
            );
        }
    }
    $filename =
        "prod_" .
        $productId .
        "_" .
        time() .
        "_" .
        bin2hex(random_bytes(4)) .
        "." .
        $ext;
    $dir = product_uploads_dir();
    if (@file_put_contents($dir . "/" . $filename, $bytes, LOCK_EX) === false) {
        problem(
            503,
            "IMAGE_STORAGE",
            "Could not save product image to public/uploads/products.",
        );
    }
    if ($oldFilename !== null && $oldFilename !== $filename) {
        remove_product_image_file($oldFilename);
    }
    return $filename;
}
function management_data(PDO $db): array
{
    $at = database_time($db);
    return [
        "server_time" => $at,
        "products" => $db
            ->query("SELECT * FROM products ORDER BY name")
            ->fetchAll(),
        "variants" => $db
            ->query(
                "SELECT * FROM product_variants ORDER BY product_id,FIELD(size,'S','M','L'),temperature",
            )
            ->fetchAll(),
        "ingredients" => ingredient_stock($db, $at),
        "recipes" => $db
            ->query("SELECT * FROM recipes ORDER BY variant_id,ingredient_id")
            ->fetchAll(),
        "batches" => $db
            ->query(
                "SELECT b.*,i.name AS ingredient_name,i.unit FROM stock_batches b JOIN ingredients i ON i.id=b.ingredient_id ORDER BY b.id DESC",
            )
            ->fetchAll(),
        "containers" => $db
            ->query("SELECT * FROM stock_containers ORDER BY id DESC")
            ->fetchAll(),
        "movements" => $db
            ->query(
                "SELECT m.*,i.name AS ingredient_name,u.display_name AS actor FROM stock_movements m JOIN ingredients i ON i.id=m.ingredient_id JOIN users u ON u.id=m.user_id ORDER BY m.id DESC LIMIT 30",
            )
            ->fetchAll(),
        "sales" => today_sales($db, $at),
    ];
}
function save_catalogue(
    PDO $db,
    array $session,
    array $body,
    string $requestId,
): array {
    $db->beginTransaction();
    try {
        lock_state($db);
        current_session($db, $session, true);
        $uid = (int) $session["user"]["id"];
        $action = $body["action"] ?? "";
        $id = isset($body["id"]) ? positive_id($body["id"]) : null;
        if ($action === "ingredient") {
            $name = label_text($body["name"] ?? null, "Ingredient name");
            $unit = $body["unit"] ?? "";
            if (!in_array($unit, ["g", "mL", "units"], true)) {
                problem(422, "VALIDATION", "Choose g, mL or units.");
            }
            $threshold = fixed(
                $body["minimum_stock"] ?? "0",
                3,
                "Low-stock threshold",
                true,
            );
            $hours =
                ($body["opened_shelf_life_hours"] ?? "") === ""
                    ? null
                    : positive_id(
                        $body["opened_shelf_life_hours"],
                        "Opened shelf life",
                        87600,
                    );
            if ($id) {
                $old = query($db, "SELECT * FROM ingredients WHERE id=?", [
                    $id,
                ])->fetch();
                if (!$old) {
                    problem(404, "NOT_FOUND", "Ingredient not found.");
                }
                if ($old["unit"] !== $unit) {
                    problem(
                        409,
                        "UNIT_CHANGE",
                        "Create a new ingredient to use a different base unit.",
                    );
                }
                query(
                    $db,
                    "UPDATE ingredients SET name=?,minimum_stock=?,opened_shelf_life_hours=?,is_active=? WHERE id=?",
                    [
                        $name,
                        decimal($threshold, 3),
                        $hours,
                        active_flag($body),
                        $id,
                    ],
                );
            } else {
                query(
                    $db,
                    "INSERT INTO ingredients(name,unit,minimum_stock,opened_shelf_life_hours,is_active) VALUES(?,?,?,?,?)",
                    [
                        $name,
                        $unit,
                        decimal($threshold, 3),
                        $hours,
                        active_flag($body),
                    ],
                );
                $id = (int) $db->lastInsertId();
            }
        } elseif ($action === "product") {
            $name = label_text($body["name"] ?? null, "Product name");
            $category = label_text($body["category"] ?? null, "Category", 60);
            if ($id) {
                $oldProd = query(
                    $db,
                    "SELECT id,image_filename FROM products WHERE id=?",
                    [$id],
                )->fetch();
                if (!$oldProd) {
                    problem(404, "NOT_FOUND", "Product not found.");
                }
                $imageFilename = process_product_image(
                    $id,
                    $body,
                    $oldProd["image_filename"] ?? null,
                );
                query(
                    $db,
                    "UPDATE products SET name=?,category=?,image_filename=?,is_active=?,updated_at=NOW() WHERE id=?",
                    [$name, $category, $imageFilename, active_flag($body), $id],
                );
            } else {
                query(
                    $db,
                    "INSERT INTO products(name,category,image_filename,is_active,created_at,updated_at) VALUES(?,?,NULL,?,NOW(),NOW())",
                    [$name, $category, active_flag($body)],
                );
                $id = (int) $db->lastInsertId();
                $imageFilename = process_product_image($id, $body, null);
                if ($imageFilename !== null) {
                    query(
                        $db,
                        "UPDATE products SET image_filename=? WHERE id=?",
                        [$imageFilename, $id],
                    );
                }
            }
        } elseif ($action === "variant") {
            $product = positive_id($body["product_id"] ?? null);
            $size = $body["size"] ?? "";
            $temperature = $body["temperature"] ?? "";
            $sku = label_text($body["sku"] ?? null, "SKU", 64);
            $price = fixed($body["price"] ?? null, 2, "Price");
            if (
                !in_array($size, ["S", "M", "L"], true) ||
                !in_array($temperature, ["HOT", "ICED", "NA"], true) ||
                !preg_match('/^[A-Za-z0-9._-]+$/D', $sku) ||
                $price > 999999999
            ) {
                problem(
                    422,
                    "VALIDATION",
                    "Check size, temperature, SKU and price (maximum 9,999,999.99).",
                );
            }
            if (
                !query($db, "SELECT id FROM products WHERE id=?", [
                    $product,
                ])->fetch()
            ) {
                problem(404, "NOT_FOUND", "Product not found.");
            }
            if ($id) {
                $old = query(
                    $db,
                    "SELECT product_id FROM product_variants WHERE id=?",
                    [$id],
                )->fetch();
                if (!$old) {
                    problem(404, "NOT_FOUND", "Variant not found.");
                }
                if ((int) $old["product_id"] !== $product) {
                    problem(
                        409,
                        "VARIANT_PRODUCT",
                        "A variant cannot be moved to another product.",
                    );
                }
                query(
                    $db,
                    "UPDATE product_variants SET size=?,temperature=?,sku=?,price=?,is_active=? WHERE id=?",
                    [
                        $size,
                        $temperature,
                        $sku,
                        decimal($price),
                        active_flag($body),
                        $id,
                    ],
                );
            } else {
                query(
                    $db,
                    "INSERT INTO product_variants(product_id,size,temperature,sku,price,is_active) VALUES(?,?,?,?,?,?)",
                    [
                        $product,
                        $size,
                        $temperature,
                        $sku,
                        decimal($price),
                        active_flag($body),
                    ],
                );
                $id = (int) $db->lastInsertId();
            }
        } elseif ($action === "recipe") {
            $id = positive_id($body["variant_id"] ?? null);
            $rows = $body["rows"] ?? null;
            if (
                !query($db, "SELECT id FROM product_variants WHERE id=?", [
                    $id,
                ])->fetch()
            ) {
                problem(404, "NOT_FOUND", "Variant not found.");
            }
            if (
                !is_array($rows) ||
                !array_is_list($rows) ||
                count($rows) > 100
            ) {
                problem(422, "VALIDATION", "Provide up to 100 recipe rows.");
            }
            $validated = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    problem(422, "VALIDATION", "Invalid recipe row.");
                }
                $iid = positive_id($row["ingredient_id"] ?? null);
                $qty = fixed($row["qty"] ?? null, 3, "Recipe quantity");
                if (isset($validated[$iid])) {
                    problem(
                        422,
                        "VALIDATION",
                        "Each ingredient may appear only once.",
                    );
                }
                $ing = query($db, "SELECT * FROM ingredients WHERE id=?", [
                    $iid,
                ])->fetch();
                if (!$ing || !(int) $ing["is_active"]) {
                    problem(422, "VALIDATION", "Choose an active ingredient.");
                }
                whole_units($ing, $qty);
                $validated[$iid] = $qty;
            }
            query($db, "DELETE FROM recipes WHERE variant_id=?", [$id]);
            foreach ($validated as $iid => $qty) {
                query(
                    $db,
                    "INSERT INTO recipes(variant_id,ingredient_id,qty) VALUES(?,?,?)",
                    [$id, $iid, decimal($qty, 3)],
                );
            }
        } elseif ($action === "receive") {
            assert_epoch($db, $body);
            $result = receive_stock($db, $body, $uid, $requestId);
            $db->commit();
            return $result;
        } else {
            problem(404, "NOT_FOUND", "Unknown management action.");
        }
        audit($db, $uid, strtoupper($action) . "_SAVED", $requestId, [
            "entity_id" => $id,
            "fields" => array_intersect_key(
                $body,
                array_flip([
                    "name",
                    "category",
                    "unit",
                    "minimum_stock",
                    "opened_shelf_life_hours",
                    "is_active",
                    "product_id",
                    "size",
                    "temperature",
                    "sku",
                    "price",
                    "variant_id",
                    "rows",
                ]),
            ),
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
