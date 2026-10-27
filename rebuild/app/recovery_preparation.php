<?php
declare(strict_types=1);
require_once __DIR__ . '/recovery.php';
function recovery_preparation_state(PDO $db, int $uid): array {
    $hash = query($db, 'SELECT code_hash FROM owner_recovery WHERE user_id=?', [$uid])->fetchColumn();
    $epoch = runtime_meta($db)['epoch'];
    return ['prepared' => is_string($hash), 'version' => hash_hmac('sha256', 'owner-recovery-version:' . $uid . ':' . $epoch, is_string($hash) ? $hash : hash('sha256', 'no-recovery-code:' . $epoch))];
}
function owner_recovery_preparation(PDO $db, array $session, ?array $body, string $requestId): array {
    if ($body !== null) {
        $password = $body['owner_password'] ?? null; $version = $body['expected_version'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) problem(422, 'VALIDATION', 'Enter your current Owner password.');
        if (!is_string($version) || !preg_match('/^[a-f0-9]{64}$/D', $version)) problem(422, 'VALIDATION', 'Check the recovery-code status before continuing.');
    }
    $db->beginTransaction();
    try {
        lock_state($db); current_session($db, $session, true); $uid = (int) $session['user']['id'];
        $state = recovery_preparation_state($db, $uid);
        if ($body === null) { $db->commit(); return $state; }
        $hash = query($db, 'SELECT password_hash FROM users WHERE id=?', [$uid])->fetchColumn();
        credential_check($db, $uid, 'owner-reauth', password_verify($password, $hash), $requestId);
        if (!hash_equals($state['version'], $version)) problem(409, 'RECOVERY_CHANGED', 'The recovery code changed after this screen was loaded. Check its status and confirm again.');
        if ($state['prepared'] && ($body['confirm_replace'] ?? false) !== true) problem(422, 'RECOVERY_CONFIRM_REQUIRED', 'Confirm that replacing the code will invalidate your previous code.');
        $code = rotate_recovery_code($db, $uid); audit($db, $uid, 'OWNER_RECOVERY_CODE_ROTATED', $requestId);
        $next = recovery_preparation_state($db, $uid); $db->commit();
        return [...$next, 'recovery_code' => $code]; // Never cache plaintext responses.
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
