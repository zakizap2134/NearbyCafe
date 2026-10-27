<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
function run_schema_file(PDO $db, string $file): void
{
    $sql = file_get_contents(__DIR__ . "/../database/" . $file);
    foreach (explode(";", $sql) as $statement) {
        if (trim($statement) !== "") {
            $db->exec($statement);
        }
    }
}
function apply_stage2(PDO $db): void
{
    $version = (int) $db
        ->query("SELECT schema_version FROM app_state WHERE id=1")
        ->fetchColumn();
    if ($version === 4) {
        schema_ready($db);
        echo "Stage 4 schema already installed; records preserved.\n";
        return;
    }
    if (!in_array($version, [1, 2, 3], true)) {
        problem(
            409,
            "SCHEMA_MISMATCH",
            "Stage 4 requires a rebuild Stage 1, 2 or 3 database. Old phase databases are not compatible.",
        );
    }
    run_schema_file($db, "002_sales_inventory.sql");
    run_schema_file($db, "003_reliability.sql");
    run_schema_file($db, "004_management.sql");
    query(
        $db,
        "INSERT IGNORE INTO runtime_meta(id,epoch,restore_generation) VALUES(1,?,0)",
        [bin2hex(random_bytes(16))],
    );
    foreach (VV_TABLES as $table) {
        $db->query("SELECT 1 FROM `" . $table . "` LIMIT 0");
    }
    query(
        $db,
        "UPDATE app_state SET schema_version=4 WHERE id=1 AND schema_version IN (1,2,3)",
    );
    schema_ready($db);
    echo "Stage 4 schema installed. Existing accounts and sales preserved.\n";
}
