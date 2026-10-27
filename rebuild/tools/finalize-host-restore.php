<?php
declare(strict_types=1);
require_once __DIR__ . "/cli.php";
require_once __DIR__ . "/../app/backup.php";
require_runtime();
$db = connection();
schema_ready($db);
echo "After an administrator's raw database restore: validate stock/VAT, revoke sessions and require review of older device requests. Keep the web server stopped. Input is visible.\n";
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
    validate_restored_totals($db);
    query($db, "DELETE FROM sessions");
    query($db, "DELETE FROM login_limits");
    query(
        $db,
        "UPDATE runtime_meta SET epoch=?,restore_generation=restore_generation+1,restored_at=NOW() WHERE id=1",
        [bin2hex(random_bytes(16))],
    );
    audit($db, (int) $owner["id"], "HOST_RESTORE_FINALIZED", $ref);
    $db->commit();
    echo "PASS restore finalized. Review all devices, physical stock and cash before reopening.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}
