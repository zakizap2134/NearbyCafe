<?php
declare(strict_types=1);
require_once __DIR__ . "/cli.php";
require_once __DIR__ . "/schema.php";
require_runtime();
$db = connection();
$lock = "vv-install-" . hash("sha256", settings()["db"]["name"]);
if ((int) query($db, "SELECT GET_LOCK(?,10)", [$lock])->fetchColumn() !== 1) {
    problem(409, "INSTALL_BUSY", "Another installation is running.");
}
try {
    apply_stage2($db);
} finally {
    query($db, "SELECT RELEASE_LOCK(?)", [$lock]);
}
