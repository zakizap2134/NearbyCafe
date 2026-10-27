<?php
declare(strict_types=1);
require_once __DIR__ . "/cli.php";
require_once __DIR__ . "/../app/recovery.php";
require_runtime();
$db = connection();
schema_ready($db);
echo "Issue/rotate an offline owner recovery code. Use a private server console; input is visible.\n";
$name = username(prompt("Owner username"));
$password = prompt("Current owner password");
$ref = bin2hex(random_bytes(8));
$db->beginTransaction();
try {
    lock_state($db);
    $owner = recovery_owner($db, $name);
    credential_check(
        $db,
        (int) $owner["id"],
        "owner-reauth",
        password_verify($password, $owner["password_hash"]),
        $ref,
    );
    unset($password);
    $code = rotate_recovery_code($db, (int) $owner["id"]);
    audit($db, (int) $owner["id"], "OWNER_RECOVERY_CODE_ROTATED", $ref);
    $db->commit();
    echo "Recovery code: $code\nStore this offline, separately from the server. The previous code no longer works.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}
