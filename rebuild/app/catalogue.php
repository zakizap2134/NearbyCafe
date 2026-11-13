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
    $rawIngredients = ingredient_stock($db, $at);
    $available = [];
    $lowStockAlerts = [];
    foreach ($rawIngredients as $i) {
        $available[(int) $i["id"]] = fixed(
            $i["eligible_stock"],
            3,
            "stock",
            true,
        );
        if ((int) $i["is_active"]) {
            $eligible = (float) $i["eligible_stock"];
            $min = (float) $i["minimum_stock"];
            if ($min > 0 && $eligible <= $min) {
                $lowStockAlerts[] = [
                    "id" => (int) $i["id"],
                    "name" => $i["name"],
                    "unit" => $i["unit"],
                    "eligible_stock" => $i["eligible_stock"],
                    "minimum_stock" => $i["minimum_stock"],
                    "is_out_of_stock" => ($eligible <= 0.0001),
                ];
            }
        }
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
        $hasLowIngredient = false;
        foreach ($recipe as $r) {
            $count = min(
                $count,
                intdiv(
                    $available[(int) $r["ingredient_id"]] ?? 0,
                    fixed($r["qty"], 3, "recipe"),
                ),
            );
            foreach ($lowStockAlerts as $lsa) {
                if ($lsa["id"] === (int) $r["ingredient_id"]) {
                    $hasLowIngredient = true;
                }
            }
        }
        $v["available_count"] = $count;
        $v["recipe_ready"] = (bool) $recipe;
        $v["has_low_ingredient"] = $hasLowIngredient;
    }
    return [
        "variants" => $variants,
        "server_time" => $at,
        "today_sales" => today_sales($db, $at),
        "low_stock_alerts" => $lowStockAlerts,
        "ingredients" => $rawIngredients,
    ];
}
function today_sales(PDO $db, string $at): array
{
    $date = substr($at, 0, 10);
    $totals = query(
        $db,
        "SELECT COUNT(*) AS count,COALESCE(SUM(total_gross),0) AS gross,COALESCE(SUM(total_net),0) AS net,COALESCE(SUM(total_vat),0) AS vat,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')='CASH' THEN total_gross ELSE 0 END),0) AS cash_drawer_gross,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')<>'CASH' THEN total_gross ELSE 0 END),0) AS online_gross FROM sales WHERE status='completed' AND sale_at>=? AND sale_at<DATE_ADD(?,INTERVAL 1 DAY)",
        [$date, $date],
    )->fetch();
    $totals["count"] = (int) $totals["count"];
    foreach (["gross", "net", "vat", "cash_drawer_gross", "online_gross"] as $key) {
        $totals[$key] = decimal(fixed($totals[$key], 2, "total", true));
    }
    $weeklyRows = query(
        $db,
        "SELECT DATE(sale_at) AS date,
                COALESCE(SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END),0) AS count,
                COALESCE(SUM(CASE WHEN status='completed' THEN total_gross ELSE 0 END),0) AS gross,
                COALESCE(SUM(CASE WHEN status='completed' THEN total_net ELSE 0 END),0) AS net,
                COALESCE(SUM(CASE WHEN status='completed' THEN total_vat ELSE 0 END),0) AS vat,
                COALESCE(SUM(CASE WHEN status='completed' AND COALESCE(payment_method,'CASH')='CASH' THEN total_gross ELSE 0 END),0) AS cash_drawer_gross,
                COALESCE(SUM(CASE WHEN status='completed' AND COALESCE(payment_method,'CASH')<>'CASH' THEN total_gross ELSE 0 END),0) AS online_gross,
                COALESCE(SUM(CASE WHEN status='voided' THEN 1 ELSE 0 END),0) AS void_count,
                COALESCE(SUM(CASE WHEN status='voided' THEN total_gross ELSE 0 END),0) AS void_gross
         FROM sales
         WHERE sale_at>=DATE_SUB(?,INTERVAL 6 DAY)
           AND sale_at<DATE_ADD(?,INTERVAL 1 DAY)
         GROUP BY DATE(sale_at)
         ORDER BY date ASC",
        [$date, $date],
    )->fetchAll();
    $byDate = [];
    foreach ($weeklyRows as $wRow) {
        $dKey = (string) $wRow["date"];
        $byDate[$dKey] = [
            "date" => $dKey,
            "count" => (int) $wRow["count"],
            "gross" => decimal(fixed($wRow["gross"], 2, "gross", true)),
            "net" => decimal(fixed($wRow["net"], 2, "net", true)),
            "vat" => decimal(fixed($wRow["vat"], 2, "vat", true)),
            "cash_drawer_gross" => decimal(fixed($wRow["cash_drawer_gross"], 2, "cash", true)),
            "online_gross" => decimal(fixed($wRow["online_gross"], 2, "online", true)),
            "void_count" => (int) $wRow["void_count"],
            "void_gross" => decimal(fixed($wRow["void_gross"], 2, "void_gross", true)),
        ];
    }
    $weekly = [];
    $baseDt = new DateTimeImmutable($date);
    for ($i = 6; $i >= 0; $i--) {
        $dStr = $baseDt->modify("-{$i} days")->format("Y-m-d");
        $weekly[] = $byDate[$dStr] ?? [
            "date" => $dStr,
            "count" => 0,
            "gross" => "0.00",
            "net" => "0.00",
            "vat" => "0.00",
            "cash_drawer_gross" => "0.00",
            "online_gross" => "0.00",
            "void_count" => 0,
            "void_gross" => "0.00",
        ];
    }
    return [
        "date" => $date,
        "totals" => $totals,
        "weekly" => $weekly,
        "recent" => query(
            $db,
            "SELECT id,transaction_key,user_id,sale_at,payment_method,payment_reference,customer_name,total_gross,total_net,total_vat,status,void_reason,voided_at FROM sales WHERE sale_at>=? AND sale_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY id DESC LIMIT 10",
            [$date, $date],
        )->fetchAll(),
    ];
}
function product_uploads_dir(): string
{
    $dir = __DIR__ . "/../storage/uploads/products";
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}
function find_product_image_path(?string $filename): ?string
{
    if (
        !is_string($filename) ||
        $filename === "" ||
        !preg_match('/^prod_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|webp)$/D', $filename)
    ) {
        return null;
    }
    foreach ([
        product_uploads_dir() . "/" . $filename,
        __DIR__ . "/../public/uploads/products/" . $filename,
        __DIR__ . "/../uploads/products/" . $filename,
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}
function remove_product_image_file(?string $filename): void
{
    if (
        is_string($filename) &&
        $filename !== "" &&
        preg_match('/^prod_[0-9]+_[0-9]+_[a-f0-9]{8}\.(jpg|png|webp)$/D', $filename)
    ) {
        foreach ([
            product_uploads_dir() . "/" . $filename,
            __DIR__ . "/../public/uploads/products/" . $filename,
            __DIR__ . "/../uploads/products/" . $filename,
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
function serve_product_image(string $filename): never
{
    $path = find_product_image_path($filename);
    if ($path === null) {
        http_response_code(404);
        header("Content-Type: text/plain; charset=utf-8");
        echo "Not Found";
        exit();
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        "png" => "image/png",
        "webp" => "image/webp",
        default => "image/jpeg",
    };
    http_response_code(200);
    header("Content-Type: " . $mime);
    header("Cache-Control: public, max-age=86400");
    header("X-Content-Type-Options: nosniff");
    readfile($path);
    exit();
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
            "Could not save product image to storage/uploads/products.",
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
        "staff" => $db
            ->query(
                "SELECT id,username,display_name,email,password_hash,role,is_active FROM users WHERE role='BARISTA' ORDER BY id",
            )
            ->fetchAll(),
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
        $syncEntityType = null;
        $syncPayload = null;
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
            $prevIngName = null;
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
                $prevIngName = (string) $old["name"];
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
            $syncEntityType = "CATALOGUE_INGREDIENT";
            $syncPayload = [
                "action" => "ingredient",
                "match_name" => $prevIngName ?? $name,
                "name" => $name,
                "unit" => $unit,
                "minimum_stock" => decimal($threshold, 3),
                "opened_shelf_life_hours" => $hours,
                "is_active" => active_flag($body),
            ];
        } elseif ($action === "product") {
            $name = label_text($body["name"] ?? null, "Product name");
            $category = label_text($body["category"] ?? null, "Category", 60);
            $prevProdName = null;
            if ($id) {
                $oldProd = query(
                    $db,
                    "SELECT id,name,image_filename FROM products WHERE id=?",
                    [$id],
                )->fetch();
                if (!$oldProd) {
                    problem(404, "NOT_FOUND", "Product not found.");
                }
                $prevProdName = (string) $oldProd["name"];
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
            $prodRow = query($db, "SELECT * FROM products WHERE id=?", [$id])->fetch();
            $imgBase64 = null;
            $savedImg = $prodRow["image_filename"] ?? null;
            if (is_string($savedImg) && $savedImg !== "") {
                $imgPath = find_product_image_path($savedImg);
                if ($imgPath !== null) {
                    $imgBytes = @file_get_contents($imgPath);
                    if (is_string($imgBytes) && $imgBytes !== "") {
                        $imgBase64 = base64_encode($imgBytes);
                    }
                }
            }
            $syncEntityType = "CATALOGUE_PRODUCT";
            $syncPayload = [
                "action" => "product",
                "match_name" => $prevProdName ?? $name,
                "name" => $name,
                "category" => $category,
                "is_active" => active_flag($body),
                "is_vat_exempt" => (int) ($prodRow["is_vat_exempt"] ?? 0),
                "image_filename" => $savedImg,
                "image_base64" => $imgBase64,
                "remove_image" => in_array($body["remove_image"] ?? false, [true, 1, "1", "true"], true),
            ];
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
            $prodRow = query($db, "SELECT id,name FROM products WHERE id=?", [
                $product,
            ])->fetch();
            if (!$prodRow) {
                problem(404, "NOT_FOUND", "Product not found.");
            }
            $prevSku = null;
            $prevSize = null;
            $prevTemp = null;
            if ($id) {
                $old = query(
                    $db,
                    "SELECT product_id,sku,size,temperature FROM product_variants WHERE id=?",
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
                $prevSku = (string) $old["sku"];
                $prevSize = (string) $old["size"];
                $prevTemp = (string) $old["temperature"];
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
            $syncEntityType = "CATALOGUE_VARIANT";
            $syncPayload = [
                "action" => "variant",
                "product_name" => (string) $prodRow["name"],
                "match_sku" => $prevSku ?? $sku,
                "match_size" => $prevSize ?? $size,
                "match_temperature" => $prevTemp ?? $temperature,
                "size" => $size,
                "temperature" => $temperature,
                "sku" => $sku,
                "price" => decimal($price),
                "is_active" => active_flag($body),
            ];
        } elseif ($action === "recipe") {
            $id = positive_id($body["variant_id"] ?? null);
            $rows = $body["rows"] ?? null;
            $varMeta = query(
                $db,
                "SELECT v.id,v.sku,v.size,v.temperature,p.name AS product_name FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=?",
                [$id],
            )->fetch();
            if (!$varMeta) {
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
            $ingNames = [];
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
                $ingNames[$iid] = (string) $ing["name"];
            }
            query($db, "DELETE FROM recipes WHERE variant_id=?", [$id]);
            $portableRows = [];
            foreach ($validated as $iid => $qty) {
                query(
                    $db,
                    "INSERT INTO recipes(variant_id,ingredient_id,qty) VALUES(?,?,?)",
                    [$id, $iid, decimal($qty, 3)],
                );
                $portableRows[] = [
                    "ingredient_name" => $ingNames[$iid],
                    "qty" => decimal($qty, 3),
                ];
            }
            $syncEntityType = "CATALOGUE_RECIPE";
            $syncPayload = [
                "action" => "recipe",
                "variant_sku" => (string) $varMeta["sku"],
                "product_name" => (string) $varMeta["product_name"],
                "size" => (string) $varMeta["size"],
                "temperature" => (string) $varMeta["temperature"],
                "rows" => $portableRows,
            ];
        } elseif ($action === "receive") {
            assert_epoch($db, $body);
            $result = receive_stock($db, $body, $uid, $requestId);
            if (!($result["duplicate"] ?? false)) {
                $batchRow = query(
                    $db,
                    "SELECT sb.*, i.name AS ingredient_name FROM stock_batches sb JOIN ingredients i ON i.id=sb.ingredient_id WHERE sb.id=?",
                    [(int) $result["id"]],
                )->fetch();
                if ($batchRow) {
                    sync_enqueue($db, "STOCK_RECEIVE", "receive-" . $batchRow["delivery_key"], [
                        "delivery_key" => $batchRow["delivery_key"],
                        "ingredient_name" => $batchRow["ingredient_name"],
                        "container_count" => (int) ($body["container_count"] ?? 1),
                        "quantity_per_container" => (string) ($body["quantity_per_container"] ?? "0"),
                        "expiration_date" => $batchRow["expiration_date"],
                        "reference" => $batchRow["reference"],
                        "supplier" => $batchRow["supplier"],
                        "lot_reference" => $batchRow["lot_reference"],
                        "actor_username" => $session["user"]["username"] ?? null,
                        "received_at" => $batchRow["received_at"],
                    ]);
                }
            }
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

        // Enqueue portable catalogue mutation into sync outbox
        if ($syncEntityType !== null && is_array($syncPayload)) {
            $syncKey = sprintf("cat-%s-%s-%s", $action, (string) ($id ?? "new"), bin2hex(random_bytes(6)));
            sync_enqueue($db, $syncEntityType, $syncKey, $syncPayload);
        }

        $db->commit();
        return ["id" => $id];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
