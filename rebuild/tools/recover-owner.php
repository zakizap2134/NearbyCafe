<?php
declare(strict_types=1);
require_once __DIR__ . "/cli.php";
require_once __DIR__ . "/../app/recovery.php";
require_runtime();
$db = connection();
schema_ready($db);
echo "Owner recovery requires server console access and the current offline code. Input is visible.\n";
$name = username(prompt("Owner username"));
$code = strtoupper(str_replace("-", "", trim(prompt("Offline recovery code"))));
$password = prompt("New password (Use at least 12 characters.)");
$confirm = prompt("Confirm new password");
if (!hash_equals($password, $confirm)) {
    problem(422, "VALIDATION", "Passwords do not match.");
}
$hash = new_password($password, $confirm);
unset($password, $confirm);
$ref = bin2hex(random_bytes(8));
$db->beginTransaction();
try {
    lock_state($db);
    $owner = recovery_owner($db, $name);
    $uid = (int) $owner["id"];
    $stored = query(
        $db,
        "SELECT code_hash FROM owner_recovery WHERE user_id=?",
        [$uid],
    )->fetchColumn();
    credential_check(
        $db,
        $uid,
        "offline-recovery",
        is_string($stored) && hash_equals($stored, hash("sha256", $code)),
        $ref,
    );
    unset($code);
    query($db, "UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?", [
        $hash,
        $uid,
    ]);
    query($db, "DELETE FROM sessions WHERE user_id=?", [$uid]);
    query($db, "DELETE FROM login_limits WHERE bucket IN (?,?)", [
        hash("sha256", "user:" . $name),
        hash("sha256", "owner-reauth:" . $uid),
    ]);
    $replacement = rotate_recovery_code($db, $uid);
    audit($db, $uid, "OWNER_RECOVERED", $ref);
    $db->commit();
    echo "Owner password changed; all owner sessions revoked.\nRecovery code: $replacement\nThe used code is invalid. Store this replacement offline.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}
