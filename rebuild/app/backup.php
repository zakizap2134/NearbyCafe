<?php
declare(strict_types=1);
require_once __DIR__ . "/management.php";
// Dependency order matters. Sessions, rate limits, schema metadata and restore epochs are never imported.
const BACKUP_TABLES = [
    "users",
    "products",
    "product_variants",
    "ingredients",
    "recipes",
    "stock_batches",
    "stock_containers",
    "sales",
    "sale_items",
    "stock_movements",
    "audit_events",
    "audit_details",
    "checkout_rejections",
    "owner_recovery",
    "stock_operations",
];
const BACKUP_MAX_BYTES = 8388608;
function backup_snapshot(PDO $db): array
{
    $tables = [];
    $bytes = 0;
    foreach (BACKUP_TABLES as $table) {
        $order = match ($table) {
            "recipes" => "variant_id,ingredient_id",
            "checkout_rejections" => "transaction_key",
            "owner_recovery" => "user_id",
            "stock_operations" => "operation_key",
            "audit_details" => "audit_id",
            default => "id",
        };
        $rows = $db->query("SELECT * FROM `$table` ORDER BY $order");
        $tables[$table] = [];
        while ($row = $rows->fetch()) {
            $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;
            if ($bytes > BACKUP_MAX_BYTES) {
                problem(
                    422,
                    "BACKUP_LIMIT",
                    "This database exceeds the in-app backup limit. Use the documented host database procedure.",
                );
            }
            $tables[$table][] = $row;
        }
    }
    return $tables;
}
function snapshot_hash(array $tables): string
{
    return hash("sha256", json_encode($tables, JSON_THROW_ON_ERROR));
}
function backup_password(array $body): string
{
    $p = $body["backup_password"] ?? null;
    if (
        !is_string($p) ||
        strlen($p) < 12 ||
        strlen($p) > 128 ||
        str_contains($p, "\0")
    ) {
        problem(
            422,
            "BACKUP_PASSWORD",
            "Use a backup passphrase of 12–128 bytes.",
        );
    }
    return $p;
}
function encrypt_backup(array $payload, string $password): array
{
    $plain = json_encode($payload, JSON_THROW_ON_ERROR);
    if (strlen($plain) > BACKUP_MAX_BYTES) {
        problem(
            422,
            "BACKUP_LIMIT",
            "This database exceeds the 8 MiB in-app backup limit. Use the documented host database backup procedure.",
        );
    }
    $salt = random_bytes(16);
    $iv = random_bytes(12);
    $key = hash_pbkdf2("sha256", $password, $salt, 210000, 32, true);
    $tag = "";
    $cipher = openssl_encrypt(
        $plain,
        "aes-256-gcm",
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        "VenueVue backup v1",
        16,
    );
    if ($cipher === false) {
        throw new RuntimeException("Encryption failed");
    }
    return [
        "format" => "venuevue-encrypted-backup",
        "version" => 1,
        "cipher" => "aes-256-gcm",
        "kdf" => "pbkdf2-sha256",
        "iterations" => 210000,
        "salt" => base64_encode($salt),
        "iv" => base64_encode($iv),
        "tag" => base64_encode($tag),
        "data" => base64_encode($cipher),
    ];
}
function decrypt_backup(mixed $file, string $password): array
{
    if (
        !is_array($file) ||
        array_keys($file) !== [
            "format",
            "version",
            "cipher",
            "kdf",
            "iterations",
            "salt",
            "iv",
            "tag",
            "data",
        ] ||
        $file["format"] !== "venuevue-encrypted-backup" ||
        $file["version"] !== 1 ||
        $file["cipher"] !== "aes-256-gcm" ||
        $file["kdf"] !== "pbkdf2-sha256" ||
        $file["iterations"] !== 210000
    ) {
        problem(
            422,
            "BACKUP_FORMAT",
            "Choose an unmodified VenueVue encrypted backup file.",
        );
    }
    $decoded = [];
    foreach (
        ["salt" => 16, "iv" => 12, "tag" => 16, "data" => null]
        as $field => $length
    ) {
        if (
            !is_string($file[$field]) ||
            strlen($file[$field]) > BACKUP_MAX_BYTES * 1.4 ||
            ($v = base64_decode($file[$field], true)) === false ||
            ($length !== null && strlen($v) !== $length)
        ) {
            problem(422, "BACKUP_FORMAT", "Invalid backup encoding.");
        }
        $decoded[$field] = $v;
    }
    if (strlen($decoded["data"]) > BACKUP_MAX_BYTES) {
        problem(422, "BACKUP_LIMIT", "Backup exceeds the 8 MiB payload limit.");
    }
    $key = hash_pbkdf2("sha256", $password, $decoded["salt"], 210000, 32, true);
    $plain = openssl_decrypt(
        $decoded["data"],
        "aes-256-gcm",
        $key,
        OPENSSL_RAW_DATA,
        $decoded["iv"],
        $decoded["tag"],
        "VenueVue backup v1",
    );
    if ($plain === false) {
        problem(
            422,
            "BACKUP_AUTH",
            "Wrong backup passphrase or damaged backup. No records were restored.",
        );
    }
    try {
        $payload = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        problem(422, "BACKUP_FORMAT", "The backup content is invalid.");
    }
    if (
        !is_array($payload) ||
        ($payload["format"] ?? null) !== "venuevue-data" ||
        ($payload["schema_version"] ?? null) !== VV_SCHEMA_VERSION ||
        !is_array($payload["tables"] ?? null) ||
        array_keys($payload["tables"]) !== BACKUP_TABLES
    ) {
        problem(
            422,
            "BACKUP_SCHEMA",
            "Only complete Stage 4 backups can be restored by this version.",
        );
    }
    return $payload;
}
function validate_backup(PDO $db, array $payload, array $owner): void
{
    $total = 0;
    $ownerFound = false;
    foreach (BACKUP_TABLES as $table) {
        $rows = $payload["tables"][$table];
        if (
            !is_array($rows) ||
            !array_is_list($rows) ||
            count($rows) > 100000
        ) {
            problem(422, "BACKUP_ROWS", "Invalid or oversized backup table.");
        }
        $total += count($rows);
        if ($total > 250000) {
            problem(422, "BACKUP_LIMIT", "Backup has too many records.");
        }
        $specs = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        $columns = array_column($specs, "Field");
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== $columns) {
                problem(
                    422,
                    "BACKUP_COLUMNS",
                    "Backup columns do not match " . $table . ".",
                );
            }
            foreach ($specs as $s) {
                $v = $row[$s["Field"]];
                $type = $s["Type"];
                if ($v === null) {
                    if ($s["Null"] !== "YES") {
                        problem(
                            422,
                            "BACKUP_VALUE",
                            "Unexpected null in " . $table . ".",
                        );
                    }
                    continue;
                }
                if (!is_string($v) && !is_int($v)) {
                    problem(
                        422,
                        "BACKUP_VALUE",
                        "Invalid scalar value in " . $table . ".",
                    );
                }
                if (
                    preg_match("/^(tinyint|smallint|int|bigint)/", $type) &&
                    (!preg_match('/^-?[0-9]+$/D', (string) $v) ||
                        (str_contains($type, "unsigned") &&
                            str_starts_with((string) $v, "-")))
                ) {
                    problem(
                        422,
                        "BACKUP_VALUE",
                        "Invalid integer in " . $table . ".",
                    );
                }
                if (
                    preg_match("/^decimal\((\d+),(\d+)\)/", $type, $dm) &&
                    !preg_match(
                        "/^-?[0-9]{1," .
                            ((int) $dm[1] - (int) $dm[2]) .
                            "}(?:\.[0-9]{1," .
                            $dm[2] .
                            '})?$/D',
                        (string) $v,
                    )
                ) {
                    problem(
                        422,
                        "BACKUP_VALUE",
                        "Invalid decimal precision in " . $table . ".",
                    );
                }
                if (str_starts_with($type, "enum(")) {
                    preg_match_all("/'([^']+)'/", $type, $matches);
                    if (!in_array($v, $matches[1], true)) {
                        problem(
                            422,
                            "BACKUP_VALUE",
                            "Invalid enumeration in " . $table . ".",
                        );
                    }
                }
                if (
                    preg_match("/^(?:var)?char\((\d+)\)/", $type, $m) &&
                    strlen((string) $v) > (int) $m[1] * 4
                ) {
                    problem(
                        422,
                        "BACKUP_VALUE",
                        "Text exceeds the column size.",
                    );
                }
                if (
                    in_array($type, ["date", "datetime"], true) &&
                    (!is_string($v) ||
                        !preg_match(
                            $type === "date"
                                ? '/^\d{4}-\d{2}-\d{2}$/D'
                                : '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',
                            $v,
                        ))
                ) {
                    problem(
                        422,
                        "BACKUP_VALUE",
                        "Invalid date in " . $table . ".",
                    );
                }
                if (in_array($type, ["date", "datetime"], true)) {
                    $format = $type === "date" ? "Y-m-d" : "Y-m-d H:i:s";
                    $date = DateTimeImmutable::createFromFormat(
                        "!" . $format,
                        $v,
                    );
                    if (!$date || $date->format($format) !== $v) {
                        problem(
                            422,
                            "BACKUP_VALUE",
                            "Invalid calendar date in " . $table . ".",
                        );
                    }
                }
                if (
                    in_array(
                        $s["Field"],
                        ["is_active", "is_vat_exempt", "quarantined"],
                        true,
                    ) &&
                    !in_array((string) $v, ["0", "1"], true)
                ) {
                    problem(422, "BACKUP_VALUE", "Invalid boolean value.");
                }
                if (in_array($s["Field"], ["details", "result_json"], true)) {
                    try {
                        $json = json_decode(
                            (string) $v,
                            true,
                            32,
                            JSON_THROW_ON_ERROR,
                        );
                    } catch (JsonException) {
                        problem(422, "BACKUP_VALUE", "Invalid stored JSON.");
                    }
                    if (!is_array($json)) {
                        problem(
                            422,
                            "BACKUP_VALUE",
                            "Invalid stored JSON object.",
                        );
                    }
                }
            }
            if ($table === "users") {
                if (
                    password_get_info($row["password_hash"])["algoName"] !==
                    "bcrypt"
                ) {
                    problem(
                        422,
                        "BACKUP_VALUE",
                        "Invalid account password hash.",
                    );
                }
                if ($row["role"] === "OWNER") {
                    if (
                        $ownerFound ||
                        (int) $row["id"] !== (int) $owner["id"] ||
                        $row["username"] !== $owner["username"] ||
                        (int) $row["is_active"] !== 1
                    ) {
                        problem(
                            422,
                            "BACKUP_OWNER",
                            "Backup must contain the same single active owner identity.",
                        );
                    }
                    $ownerFound = true;
                }
            }
        }
    }
    if (!$ownerFound) {
        problem(
            422,
            "BACKUP_OWNER",
            "The backup is missing the current owner identity.",
        );
    }
    validate_backup_relations($db, $payload["tables"]);
}
function validate_backup_relations(PDO $db, array $tables): void
{
    $fail = fn() => problem(
        422,
        "BACKUP_INVARIANT",
        "Backup relationships or stock/sale totals are inconsistent. No records were restored.",
    );
    $maps = [];
    foreach ($tables as $table => $rows) {
        $keys = array_column(
            $db
                ->query("SHOW KEYS FROM `$table` WHERE Key_name='PRIMARY'")
                ->fetchAll(),
            "Column_name",
        );
        $seen = [];
        foreach ($rows as $row) {
            $key = json_encode(array_map(fn($k) => (string) $row[$k], $keys));
            if (isset($seen[$key])) {
                $fail();
            }
            $seen[$key] = true;
            foreach ($row as $col => $value) {
                if (
                    $value !== null &&
                    in_array(
                        $col,
                        ["id", "user_id", "audit_id", "transaction_key"],
                        true,
                    )
                ) {
                    $maps[$table][$col][(string) $value] = true;
                }
            }
        }
    }
    $foreign = $db
        ->query(
            "SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL",
        )
        ->fetchAll();
    foreach ($foreign as $f) {
        $table = $f["TABLE_NAME"];
        if (!isset($tables[$table])) {
            continue;
        }
        foreach ($tables[$table] as $row) {
            $value = $row[$f["COLUMN_NAME"]];
            if (
                $value !== null &&
                !isset(
                    $maps[$f["REFERENCED_TABLE_NAME"]][
                        $f["REFERENCED_COLUMN_NAME"]
                    ][(string) $value],
                )
            ) {
                $fail();
            }
        }
    }
    // Integer fixed-point validation also runs during preview, before any deletion.
    $num = function ($v, int $places) use ($fail): int {
        if (
            !preg_match(
                "/^(-?)([0-9]{1,12})(?:\.([0-9]{1," . $places . '}))?$/D',
                (string) $v,
                $m,
            )
        ) {
            $fail();
        }
        return ($m[1] === "-" ? -1 : 1) *
            ((int) $m[2] * 10 ** $places +
                (int) str_pad($m[3] ?? "", $places, "0"));
    };
    $by = [];
    foreach (
        [
            "ingredients",
            "stock_batches",
            "stock_containers",
            "sales",
            "sale_items",
        ]
        as $t
    ) {
        foreach ($tables[$t] as $r) {
            $by[$t][$r["id"]] = $r;
        };
    }
    $containerSums = [];
    $ingredientMoves = [];
    $batchSums = [];
    $ingredientStock = [];
    $saleSums = [];
    foreach ($tables["stock_movements"] as $m) {
        $c = $by["stock_containers"][$m["container_id"]];
        $b = $by["stock_batches"][$m["batch_id"]];
        $delta = $num($m["quantity_delta"], 3);
        if (
            (int) $c["batch_id"] !== (int) $b["id"] ||
            (int) $b["ingredient_id"] !== (int) $m["ingredient_id"] ||
            ($m["type"] === "SALE" &&
                ($m["sale_id"] === null || $delta >= 0)) ||
            ($m["type"] === "WASTE" && $delta >= 0) ||
            ($m["type"] === "RECEIVE" && $delta <= 0)
        ) {
            $fail();
        }
        $containerSums[$c["id"]] = ($containerSums[$c["id"]] ?? 0) + $delta;
        $ingredientMoves[$m["ingredient_id"]] =
            ($ingredientMoves[$m["ingredient_id"]] ?? 0) + $delta;
    }
    foreach ($tables["stock_containers"] as $c) {
        $r = $num($c["received_qty"], 3);
        $n = $num($c["remaining_qty"], 3);
        $b = $by["stock_batches"][$c["batch_id"]];
        $i = $by["ingredients"][$b["ingredient_id"]];
        if (
            $r <= 0 ||
            $n < 0 ||
            $n > $r ||
            $n !== ($containerSums[$c["id"]] ?? 0) ||
            ($c["use_by"] !== null &&
                ($c["opened_at"] === null ||
                    $c["use_by"] <= $c["opened_at"])) ||
            ($i["unit"] === "units" && ($r % 1000 || $n % 1000))
        ) {
            $fail();
        }
        $s = $batchSums[$b["id"]] ?? [0, 0, 0, 0];
        $s[0] += $r;
        $s[1] += $n;
        $s[$c["opened_at"] === null ? 2 : 3] += $n;
        $batchSums[$b["id"]] = $s;
    }
    foreach ($tables["stock_batches"] as $b) {
        $s = $batchSums[$b["id"]] ?? [0, 0, 0, 0];
        foreach (
            ["received_qty", "remaining_qty", "qty_sealed", "qty_opened"]
            as $j => $k
        ) {
            if ($num($b[$k], 3) !== $s[$j]) {
                $fail();
            }
        }
        $ingredientStock[$b["ingredient_id"]] =
            ($ingredientStock[$b["ingredient_id"]] ?? 0) + $s[1];
    }
    foreach ($tables["ingredients"] as $i) {
        $n = $num($i["current_stock"], 3);
        if (
            $n < 0 ||
            $num($i["minimum_stock"], 3) < 0 ||
            $n !== ($ingredientStock[$i["id"]] ?? 0) ||
            $n !== ($ingredientMoves[$i["id"]] ?? 0) ||
            ($i["opened_shelf_life_hours"] !== null &&
                (int) $i["opened_shelf_life_hours"] < 1)
        ) {
            $fail();
        }
    }
    foreach ($tables["recipes"] as $r) {
        $n = $num($r["qty"], 3);
        if (
            $n <= 0 ||
            ($by["ingredients"][$r["ingredient_id"]]["unit"] === "units" &&
                $n % 1000)
        ) {
            $fail();
        }
    }
    foreach ($tables["sale_items"] as $l) {
        $g = $num($l["line_gross"], 2);
        $n = $num($l["line_net"], 2);
        $v = $num($l["line_vat"], 2);
        $price = $num($l["unit_price"], 2);
        if (
            (int) $l["qty"] < 1 ||
            $price <= 0 ||
            $g !== $price * (int) $l["qty"] ||
            $n < 0 ||
            $v < 0 ||
            $g !== $n + $v
        ) {
            $fail();
        }
        $s = $saleSums[$l["sale_id"]] ?? [0, 0, 0];
        $s[0] += $g;
        $s[1] += $n;
        $s[2] += $v;
        $saleSums[$l["sale_id"]] = $s;
    }
    foreach ($tables["sales"] as $s) {
        $totals = array_map(fn($k) => $num($s[$k], 2), [
            "total_gross",
            "total_net",
            "total_vat",
        ]);
        if (
            $totals[0] <= 0 ||
            $totals[0] !== $totals[1] + $totals[2] ||
            $totals !== ($saleSums[$s["id"]] ?? [0, 0, 0]) ||
            $num($s["cash_received"], 2) < $totals[0] ||
            $num($s["change_due"], 2) !==
                $num($s["cash_received"], 2) - $totals[0] ||
            isset(
                $maps["checkout_rejections"]["transaction_key"][
                    $s["transaction_key"]
                ],
            )
        ) {
            $fail();
        }
    }
}
/**
 * Read-only stock/VAT/outcome invariant checks shared by restore validation
 * and the post-outage integrity check. Returns the names of failed checks.
 */
function integrity_violations(PDO $db): array
{
    $checks = [
        "negative or invalid ingredient stock" =>
            "SELECT COUNT(*) FROM ingredients WHERE current_stock<0 OR minimum_stock<0 OR (opened_shelf_life_hours IS NOT NULL AND opened_shelf_life_hours<1)",
        "invalid recipe" =>
            "SELECT COUNT(*) FROM recipes r JOIN ingredients i ON i.id=r.ingredient_id WHERE r.qty<=0 OR (i.unit='units' AND MOD(r.qty,1)<>0)",
        "invalid container" =>
            "SELECT COUNT(*) FROM stock_containers WHERE received_qty<=0 OR remaining_qty<0 OR remaining_qty>received_qty OR (opened_at IS NULL AND use_by IS NOT NULL) OR (use_by IS NOT NULL AND use_by<=opened_at)",
        "container units" =>
            "SELECT COUNT(*) FROM stock_containers c JOIN stock_batches b ON b.id=c.batch_id JOIN ingredients i ON i.id=b.ingredient_id WHERE i.unit='units' AND (MOD(c.remaining_qty,1)<>0 OR MOD(c.received_qty,1)<>0)",
        "batch totals" =>
            "SELECT COUNT(*) FROM stock_batches b WHERE b.received_qty<>(SELECT COALESCE(SUM(received_qty),0) FROM stock_containers c WHERE c.batch_id=b.id) OR b.remaining_qty<>(SELECT COALESCE(SUM(remaining_qty),0) FROM stock_containers c WHERE c.batch_id=b.id) OR b.qty_opened<>(SELECT COALESCE(SUM(remaining_qty),0) FROM stock_containers c WHERE c.batch_id=b.id AND c.opened_at IS NOT NULL) OR b.qty_sealed<>(SELECT COALESCE(SUM(remaining_qty),0) FROM stock_containers c WHERE c.batch_id=b.id AND c.opened_at IS NULL)",
        "ingredient totals" =>
            "SELECT COUNT(*) FROM ingredients i WHERE current_stock<>(SELECT COALESCE(SUM(remaining_qty),0) FROM stock_batches b WHERE b.ingredient_id=i.id) OR current_stock<>(SELECT COALESCE(SUM(quantity_delta),0) FROM stock_movements m WHERE m.ingredient_id=i.id)",
        "container movement totals" =>
            "SELECT COUNT(*) FROM stock_containers c WHERE remaining_qty<>(SELECT COALESCE(SUM(quantity_delta),0) FROM stock_movements m WHERE m.container_id=c.id)",
        "movement references" =>
            "SELECT COUNT(*) FROM stock_movements m JOIN stock_containers c ON c.id=m.container_id JOIN stock_batches b ON b.id=m.batch_id WHERE c.batch_id<>m.batch_id OR b.ingredient_id<>m.ingredient_id OR (m.type='SALE' AND (m.sale_id IS NULL OR m.quantity_delta>=0)) OR (m.type='WASTE' AND m.quantity_delta>=0) OR (m.type='RECEIVE' AND m.quantity_delta<=0)",
        "sale line totals" =>
            "SELECT COUNT(*) FROM sale_items WHERE qty<=0 OR unit_price<=0 OR line_gross<>qty*unit_price OR line_net<0 OR line_vat<0 OR line_gross<>line_net+line_vat",
        "sale totals" =>
            "SELECT COUNT(*) FROM sales s WHERE total_gross<=0 OR total_gross<>total_net+total_vat OR cash_received<total_gross OR change_due<>cash_received-total_gross OR total_gross<>(SELECT COALESCE(SUM(line_gross),0) FROM sale_items l WHERE l.sale_id=s.id) OR total_net<>(SELECT COALESCE(SUM(line_net),0) FROM sale_items l WHERE l.sale_id=s.id) OR total_vat<>(SELECT COALESCE(SUM(line_vat),0) FROM sale_items l WHERE l.sale_id=s.id)",
        "conflicting outcomes" =>
            "SELECT COUNT(*) FROM sales s JOIN checkout_rejections r ON r.transaction_key=s.transaction_key",
    ];
    $failed = [];
    foreach ($checks as $name => $sql) {
        if ((int) $db->query($sql)->fetchColumn() > 0) {
            $failed[] = $name;
        }
    }
    return $failed;
}
function validate_restored_totals(PDO $db): void
{
    $failed = integrity_violations($db);
    if ($failed) {
        problem(
            422,
            "BACKUP_INVARIANT",
            "Backup rejected: " .
                $failed[0] .
                ". All original records remain unchanged.",
        );
    }
}
function backup_action(
    PDO $db,
    array $session,
    array $body,
    string $ref,
    string $action,
): array {
    $password = backup_password($body);
    $db->beginTransaction();
    try {
        lock_state($db);
        owner_reauth($db, $session, $body, $ref);
        $uid = (int) $session["user"]["id"];
        if ($action === "export") {
            audit($db, $uid, "BACKUP_CREATED", $ref);
            $payload = [
                "format" => "venuevue-data",
                "schema_version" => VV_SCHEMA_VERSION,
                "created_at" => database_time($db),
                "tables" => backup_snapshot($db),
            ];
            validate_backup($db, $payload, $session["user"]);
            $file = encrypt_backup($payload, $password);
            $db->commit();
            return [
                "filename" =>
                    "VenueVue_backup_" . date("Ymd_His") . ".vvbackup",
                "file" => $file,
            ];
        }
        $payload = decrypt_backup($body["file"] ?? null, $password);
        $owner = query($db, "SELECT * FROM users WHERE id=?", [$uid])->fetch();
        validate_backup($db, $payload, $owner);
        $current = backup_snapshot($db);
        $fingerprint = snapshot_hash($current);
        $fileHash = hash(
            "sha256",
            json_encode($body["file"], JSON_THROW_ON_ERROR),
        );
        if ($action === "preview") {
            $db->commit();
            return [
                "current_fingerprint" => $fingerprint,
                "backup_hash" => $fileHash,
                "created_at" => $payload["created_at"] ?? "Unknown",
                "backup_counts" => array_map("count", $payload["tables"]),
                "current_counts" => array_map("count", $current),
            ];
        }
        if (
            ($body["confirmation"] ?? "") !== "RESTORE" ||
            !is_string($body["current_fingerprint"] ?? null) ||
            !hash_equals($fingerprint, $body["current_fingerprint"]) ||
            !is_string($body["backup_hash"] ?? null) ||
            !hash_equals($fileHash, $body["backup_hash"])
        ) {
            problem(
                409,
                "RESTORE_CHANGED",
                "Records or backup changed after preview. Preview again before restoring.",
            );
        }
        $ownerCode = query(
            $db,
            "SELECT * FROM owner_recovery WHERE user_id=?",
            [$uid],
        )->fetch();
        // No DDL, disabled constraints, or arbitrary SQL. InnoDB rolls back all deletes/inserts on failure.
        query($db, "DELETE FROM sessions");
        query($db, "DELETE FROM login_limits");
        foreach (array_reverse(BACKUP_TABLES) as $table) {
            $db->exec("DELETE FROM `$table`");
        }
        foreach (BACKUP_TABLES as $table) {
            $columns = array_column(
                $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(),
                "Field",
            );
            $names = implode(
                ",",
                array_map(fn($c) => "`" . $c . "`", $columns),
            );
            $placeholders = implode(",", array_fill(0, count($columns), "?"));
            $insert = $db->prepare(
                "INSERT INTO `$table`($names) VALUES($placeholders)",
            );
            foreach ($payload["tables"][$table] as $row) {
                $insert->execute(array_values($row));
                if ($db->query("SHOW WARNINGS")->fetch()) {
                    problem(
                        422,
                        "BACKUP_VALUE",
                        "The database rejected or coerced a backup value. Restore rolled back.",
                    );
                }
            }
        }
        // Keep the currently authenticated owner's credentials to avoid restoring an unknown password/code.
        query(
            $db,
            "UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?",
            [$owner["password_hash"], $uid],
        );
        query($db, "DELETE FROM owner_recovery WHERE user_id=?", [$uid]);
        if ($ownerCode) {
            query(
                $db,
                "INSERT INTO owner_recovery(user_id,code_hash,generated_at) VALUES(?,?,?)",
                array_values($ownerCode),
            );
        }
        validate_restored_totals($db);
        query(
            $db,
            "UPDATE runtime_meta SET epoch=?,restore_generation=restore_generation+1,restored_at=NOW() WHERE id=1",
            [bin2hex(random_bytes(16))],
        );
        audit($db, $uid, "BACKUP_RESTORED", $ref, [
            "backup_created_at" => $payload["created_at"] ?? null,
            "backup_hash" => $fileHash,
        ]);
        $db->commit();
        return ["restored" => true, "signed_out" => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof PDOException) {
            problem(
                422,
                "BACKUP_DATABASE",
                "Backup could not satisfy database constraints. Restore rolled back.",
            );
        }
        throw $e;
    }
}
