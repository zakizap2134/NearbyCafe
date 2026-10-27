<?php
declare(strict_types=1);
// Isolated test helper, outside the web root. Never accepts SQL or a target database.
require_once __DIR__ . "/../app/backup.php";
if (
    PHP_SAPI !== "cli" ||
    settings()["db"]["name"] !== "venuevue_rebuild_test"
) {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$p = decrypt_backup($input["file"], $input["password"]);
switch ($input["mode"]) {
    case "stock":
        $p["tables"]["ingredients"][0]["current_stock"] = "123.000";
        break;
    case "foreign":
        $p["tables"]["sale_items"][0]["variant_id"] = 2147483647;
        break;
    case "owner":
        $p["tables"]["users"][0]["username"] = "another-owner";
        break;
    case "columns":
        $p["tables"]["users"][0]["unexpected"] = "SQL";
        break;
    case "vat":
        $p["tables"]["sale_items"][0]["line_vat"] = "0.00";
        break;
    case "date":
        $p["tables"]["sales"][0]["sale_at"] = "2026-02-30 12:00:00";
        break;
    default:
        exit(2);
}
echo json_encode(encrypt_backup($p, $input["password"]), JSON_THROW_ON_ERROR);
