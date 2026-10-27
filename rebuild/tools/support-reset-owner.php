<?php
declare(strict_types=1);
// Authorized host-administrator recovery when both password and code are lost.
// Run from the private tools folder, never deploy this as a web endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/cli.php';
require_once __DIR__ . '/../app/recovery.php';
require_runtime();
$db = connection();
schema_ready($db);
echo "AUTHORIZED HOST SUPPORT: reset an existing active Owner only.\n";
echo "This uses server-console access instead of the lost password/code.\n";
echo "Password entry is VISIBLE. Use a private console; do not screen-share.\n";
$name = username(prompt('Existing Owner username'));
$observed = recovery_owner($db, $name);
echo 'Account: ' . $observed['username'] . ' (' . $observed['display_name'] . ")\n";
echo "Sales, stock and staff accounts will be preserved. Old Owner sessions will end.\n";
$approval = prompt('Type RESET ' . $observed['username'] . ' to confirm, or leave blank to cancel');
if (!hash_equals('RESET ' . $observed['username'], $approval)) {
    echo "Cancelled. No changes made.\n";
    exit(0);
}
$password = prompt('New Owner password (Use at least 12 characters.)');
$confirmation = prompt('Confirm new Owner password');
if (!hash_equals($password, $confirmation)) problem(422, 'VALIDATION', 'Passwords do not match.');
$hash = new_password($password, $confirmation);
unset($password, $confirmation, $approval);
$requestId = bin2hex(random_bytes(8));
$db->beginTransaction();
try {
    lock_state($db);
    $owner = recovery_owner($db, $name);
    if ((int) $owner['id'] !== (int) $observed['id'] || !hash_equals($observed['password_hash'], $owner['password_hash'])) {
        problem(409, 'OWNER_CHANGED', 'The account changed during this operation. Stop and check its current status.');
    }
    $uid = (int) $owner['id'];
    query($db, 'UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?', [$hash, $uid]);
    query($db, 'DELETE FROM sessions WHERE user_id=?', [$uid]);
    $code = rotate_recovery_code($db, $uid);
    foreach (['user:' . $owner['username'], 'owner-reauth:' . $uid, 'offline-recovery:' . $uid, 'web-recovery:user:' . $owner['username']] as $bucket) {
        query($db, 'DELETE FROM login_limits WHERE bucket=?', [hash('sha256', $bucket)]);
    }
    audit($db, $uid, 'OWNER_SUPPORT_RECOVERED', $requestId);
    $db->commit();
    echo "SUCCESS: Owner password changed; old Owner sessions revoked.\n";
    echo "Recovery code: $code\n";
    echo "Save this code privately NOW. Any previous recovery code no longer works.\n";
    echo "Sign in with the same username and your new password.\n";
    echo "Remove tools/support-reset-owner.php after this support operation.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
