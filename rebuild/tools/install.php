<?php
declare(strict_types=1);
require_once __DIR__ . "/cli.php";
require_once __DIR__ . "/schema.php";
require_runtime();
$db = connection(false);
$name = settings()["db"]["name"];
$lock = "vv-install-" . hash("sha256", $name);
if ((int) query($db, "SELECT GET_LOCK(?,10)", [$lock])->fetchColumn() !== 1) {
    problem(409, "INSTALL_BUSY", "Another installation is running.");
}
try {
    $db->exec(
        "CREATE DATABASE IF NOT EXISTS `" .
            $name .
            "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    );
    $db->exec("USE `" . $name . "`");
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if ($tables) {
        if (
            !array_diff(VV_FOUNDATION_TABLES, $tables) &&
            !array_diff($tables, VV_TABLES)
        ) {
            apply_stage2($db);
        } else {
            problem(
                409,
                "DATABASE_NOT_EMPTY",
                "The configured database is not empty or contains an interrupted installation. Use a new empty rebuild database; do not import over existing data.",
            );
        }
    } else {
        run_schema_file($db, "001_foundation.sql");
        query(
            $db,
            "INSERT INTO app_state(id,schema_version,installed_at) VALUES(1,1,NOW())",
        );
        apply_stage2($db);
        echo "Next: php tools/prepare-owner-setup.php, then open the app to register the owner.\n";
    }
} finally {
    query($db, "SELECT RELEASE_LOCK(?)", [$lock]);
}
