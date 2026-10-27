<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function owner_setup_file(): string
{
    return getenv('VV_OWNER_SETUP_FILE') ?: __DIR__ . '/../storage/owner-setup.json';
}

function owner_setup_needed(PDO $db): bool
{
    // A disabled/deleted account must not reopen initial setup. The existing CLI
    // and browser provisioning both record OWNER_PROVISIONED in the same transaction.
    return !(bool) $db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn()
        && !(bool) $db->query("SELECT 1 FROM audit_events WHERE action='OWNER_PROVISIONED' LIMIT 1")->fetchColumn();
}

function owner_setup_credential(PDO $db): ?array
{
    $raw = @file_get_contents(owner_setup_file());
    $value = $raw === false ? null : json_decode($raw, true);
    if (!is_array($value)
        || !is_string($value['code_hash'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $value['code_hash'])
        || !is_int($value['expires_at'] ?? null)
        || $value['expires_at'] <= time()
        || !is_string($value['epoch'] ?? null)
        || !hash_equals(runtime_meta($db)['epoch'], $value['epoch'])) {
        return null;
    }
    return $value;
}

function owner_setup_status(PDO $db): array
{
    $needed = owner_setup_needed($db);
    return ['needed' => $needed, 'ready' => $needed && owner_setup_credential($db) !== null];
}

// Fixed windows use server-observed addresses, never client-supplied proxy headers.
function owner_setup_limits(PDO $db): array {
    $limits = [hash('sha256', 'owner-setup:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => 5, hash('sha256', 'owner-setup:global') => 30];
    foreach ($limits as $bucket => $max) {
        query($db, 'DELETE FROM login_limits WHERE bucket=? AND window_started<=DATE_SUB(NOW(),INTERVAL 15 MINUTE)', [$bucket]);
        $row = query($db, 'SELECT failures,GREATEST(1,900-TIMESTAMPDIFF(SECOND,window_started,NOW())) AS retry_after FROM login_limits WHERE bucket=?', [$bucket])->fetch();
        if ($row && (int)$row['failures'] >= $max) {
            header('Retry-After: ' . (int)$row['retry_after']); $db->commit();
            problem(429, 'SETUP_LIMIT', 'Too many setup-code attempts. Wait up to 15 minutes before trying again.');
        }
    }
    return $limits;
}
function owner_setup_failed(PDO $db, array $limits, string $requestId): void {
    foreach ($limits as $bucket => $max) query($db, 'INSERT INTO login_limits(bucket,failures,window_started) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE failures=failures+1', [$bucket]);
    audit($db, null, 'OWNER_SETUP_CODE_FAILED', $requestId); $db->commit();
}

function register_initial_owner(PDO $db, array $body, string $requestId): array
{
    // Reuse the workflow lock used by CLI owner creation. UI visibility is never authorization.
    $db->beginTransaction();
    try {
        lock_state($db);
        if (!owner_setup_needed($db)) {
            problem(409, 'OWNER_EXISTS', 'Owner setup is closed because this workspace already has accounts. Sign in with your existing account.');
        }
        $limits = owner_setup_limits($db);
        $credential = owner_setup_credential($db);
        if ($credential === null) {
            problem(403, 'SETUP_NOT_READY', 'The setup code is unavailable or expired. Ask your development team for a new setup code.');
        }
        $code = $body['setup_code'] ?? null;
        if (!is_string($code) || strlen($code) > 100) {
            owner_setup_failed($db, $limits, $requestId);
            problem(422, 'VALIDATION', 'Enter the setup code supplied by your development team.');
        }
        $code = strtoupper(str_replace('-', '', trim($code)));
        if (!preg_match('/^[A-F0-9]{64}$/D', $code)
            || !hash_equals($credential['code_hash'], hash('sha256', $code))) {
            owner_setup_failed($db, $limits, $requestId);
            problem(403, 'SETUP_CODE_INVALID', 'The setup code is incorrect. Check the code supplied by your development team.');
        }
        $name = username($body['username'] ?? null);
        $display = $body['display_name'] ?? null;
        if (!is_string($display) || trim($display) === '' || strlen(trim($display)) > 100) {
            problem(422, 'VALIDATION', 'Display name must contain 1–100 bytes.');
        }
        $password = $body['password'] ?? null;
        $confirm = $body['confirm_password'] ?? null;
        if (!is_string($password) || !is_string($confirm)) {
            problem(422, 'VALIDATION', 'Enter and confirm your password.');
        }
        if (!hash_equals($password, $confirm)) {
            problem(422, 'VALIDATION', 'Passwords do not match.');
        }
        $hash = new_password($password, $confirm);
        query($db, "INSERT INTO users(username,display_name,password_hash,role,created_at,updated_at) VALUES(?,?,?,'OWNER',NOW(),NOW())", [$name, trim($display), $hash]);
        $id = (int) $db->lastInsertId();
        // This durable marker consumes initial setup authorization atomically with the account.
        audit($db, $id, 'OWNER_PROVISIONED', $requestId);
        foreach ($limits as $bucket => $max) query($db, 'DELETE FROM login_limits WHERE bucket=?', [$bucket]);
        $db->commit();
        return ['created' => true, 'username' => $name];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
