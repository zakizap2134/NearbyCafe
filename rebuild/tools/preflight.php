<?php
declare(strict_types=1);
require_once __DIR__ . "/cli.php";
require_once __DIR__ . "/../app/owner_setup.php";
require_runtime();
echo "PASS PHP " .
    PHP_VERSION .
    "; PDO MySQL, OpenSSL and JSON enabled; Unicode character counting available; log directory writable.\n";
$config = settings();
echo "PASS configuration loaded; database " . $config["db"]["name"] . ".\n";
$db = connection();
echo "PASS database connection; server " .
    $db->getAttribute(PDO::ATTR_SERVER_VERSION) .
    ".\n";
schema_ready($db);
// Check required columns so a partial or unrelated schema cannot appear ready.
$columns = [
    "app_state" => ["id", "schema_version", "installed_at"],
    "users" => [
        "id",
        "username",
        "display_name",
        "password_hash",
        "role",
        "is_active",
        "created_at",
        "updated_at",
    ],
    "sessions" => ["token_hash", "user_id", "created_at", "expires_at"],
    "login_limits" => ["bucket", "failures", "window_started"],
    "audit_events" => ["id", "user_id", "action", "request_id", "created_at"],
];
$columns += [
    "products" => ["id", "name", "category", "is_active", "is_vat_exempt"],
    "product_variants" => [
        "id",
        "product_id",
        "size",
        "temperature",
        "sku",
        "price",
        "is_active",
    ],
    "ingredients" => [
        "id",
        "name",
        "unit",
        "minimum_stock",
        "current_stock",
        "opened_shelf_life_hours",
        "is_active",
    ],
    "recipes" => ["variant_id", "ingredient_id", "qty"],
    "stock_batches" => [
        "id",
        "ingredient_id",
        "delivery_key",
        "request_hash",
        "remaining_qty",
        "qty_sealed",
        "qty_opened",
        "expiration_date",
        "user_id",
    ],
    "stock_containers" => [
        "id",
        "batch_id",
        "remaining_qty",
        "opened_at",
        "use_by",
    ],
    "sales" => [
        "id",
        "transaction_key",
        "request_hash",
        "user_id",
        "sale_at",
        "total_gross",
        "total_net",
        "total_vat",
        "vat_rate",
        "cash_received",
        "change_due",
    ],
    "sale_items" => [
        "id",
        "sale_id",
        "variant_id",
        "variant_label",
        "qty",
        "unit_price",
        "line_gross",
        "line_net",
        "line_vat",
    ],
    "stock_movements" => [
        "id",
        "ingredient_id",
        "batch_id",
        "container_id",
        "sale_id",
        "user_id",
        "type",
        "quantity_delta",
        "reason",
        "created_at",
    ],
];
foreach ($columns as $table => $required) {
    $found = array_column(
        $db->query("SHOW COLUMNS FROM `" . $table . "`")->fetchAll(),
        "Field",
    );
    if (array_diff($required, $found)) {
        problem(
            503,
            "SCHEMA_MISMATCH",
            "Required columns are missing from " . $table,
        );
    }
}
$engines = $db
    ->query(
        "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()",
    )
    ->fetchAll();
foreach ($engines as $row) {
    if (
        in_array($row["TABLE_NAME"], VV_TABLES, true) &&
        $row["ENGINE"] !== "InnoDB"
    ) {
        problem(503, "STORAGE_ENGINE", "Application tables must use InnoDB.");
    }
}
echo "PASS schema version " . VV_SCHEMA_VERSION . " and InnoDB tables.\n";
$hasOwner = (bool) $db
        ->query("SELECT COUNT(*) FROM users WHERE role='OWNER' AND is_active=1")
        ->fetchColumn();
$setupReady = !$hasOwner && in_array('--allow-owner-setup', $argv, true)
    && owner_setup_status($db)['ready'];
if (!$hasOwner && !$setupReady) {
    problem(
        503,
        "OWNER_MISSING",
        "Prepare browser setup with php tools/prepare-owner-setup.php, then start with --allow-owner-setup; existing owners must use the documented recovery path.",
    );
}
foreach (
    [
        "checkout_rejections" => [
            "transaction_key",
            "request_hash",
            "user_id",
            "status_code",
            "error_code",
            "message",
            "created_at",
        ],
        "owner_recovery" => ["user_id", "code_hash", "generated_at"],
        "runtime_meta" => ["id", "epoch", "restore_generation", "restored_at"],
        "stock_operations" => [
            "operation_key",
            "request_hash",
            "user_id",
            "result_json",
            "created_at",
        ],
        "audit_details" => ["audit_id", "details"],
    ]
    as $table => $required
) {
    $found = array_column(
        $db->query("SHOW COLUMNS FROM `" . $table . "`")->fetchAll(),
        "Field",
    );
    if (array_diff($required, $found)) {
        problem(
            503,
            "SCHEMA_MISMATCH",
            "Required columns are missing from " . $table,
        );
    }
}
runtime_meta($db);
if ($setupReady) {
    echo "PASS host ready for initial setup. Open the app and choose Set up owner account.\n";
    exit(0);
}
echo "PASS active owner exists. Stage 4 host is ready.\n";
if (!(int) $db->query("SELECT COUNT(*) FROM owner_recovery")->fetchColumn()) {
    echo "ACTION: sign in, open Management > Account security, and prepare an Owner recovery code.\n";
}
