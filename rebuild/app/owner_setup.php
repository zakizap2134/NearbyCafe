<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function owner_setup_file(): string
{
    return getenv('VV_OWNER_SETUP_FILE') ?: __DIR__ . '/../storage/owner-setup.json';
}

function owner_setup_requires_code(): bool
{
    $env = getenv('VV_OWNER_SETUP_FILE');
    return $env !== false && trim($env) !== '';
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
    $reqCode = owner_setup_requires_code();
    return [
        'needed' => $needed,
        'ready' => $needed && (!$reqCode || owner_setup_credential($db) !== null),
        'requires_code' => $reqCode,
    ];
}

// Fixed windows use server-observed addresses, never client-supplied proxy headers.
function owner_setup_limits(PDO $db): array {
    $limits = [hash('sha256', 'owner-setup:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => 10, hash('sha256', 'owner-setup:global') => 50];
    foreach ($limits as $bucket => $max) {
        query($db, 'DELETE FROM login_limits WHERE bucket=? AND window_started<=DATE_SUB(NOW(),INTERVAL 15 MINUTE)', [$bucket]);
        $row = query($db, 'SELECT failures,GREATEST(1,900-TIMESTAMPDIFF(SECOND,window_started,NOW())) AS retry_after FROM login_limits WHERE bucket=?', [$bucket])->fetch();
        if ($row && (int)$row['failures'] >= $max) {
            header('Retry-After: ' . (int)$row['retry_after']); $db->commit();
            problem(429, 'SETUP_LIMIT', 'Too many setup attempts. Wait up to 15 minutes before trying again.');
        }
    }
    return $limits;
}
function owner_setup_failed(PDO $db, array $limits, string $requestId): void {
    foreach ($limits as $bucket => $max) query($db, 'INSERT INTO login_limits(bucket,failures,window_started) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE failures=failures+1', [$bucket]);
    audit($db, null, 'OWNER_SETUP_FAILED', $requestId); $db->commit();
}

function register_initial_owner(PDO $db, array $body, string $requestId): array
{
    ensure_void_and_image_schema($db);
    $db->beginTransaction();
    try {
        lock_state($db);
        if (!owner_setup_needed($db)) {
            problem(409, 'OWNER_EXISTS', 'Owner setup is closed because this workspace already has accounts. Sign in with your existing account.');
        }
        $limits = owner_setup_limits($db);
        $requiresCode = owner_setup_requires_code();

        if ($requiresCode) {
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
        }

        $rawShop = $body['shop_name'] ?? null;
        $shopName = is_string($rawShop) && trim($rawShop) !== '' ? trim($rawShop) : 'Nearby Café';
        if (strlen($shopName) > 120) {
            problem(422, 'VALIDATION', 'Shop name must contain 1–120 characters.');
        }

        $email = valid_email($body['email'] ?? null, true);
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

        query(
            $db,
            "INSERT INTO users(username,display_name,email,shop_name,password_hash,role,created_at,updated_at) VALUES(?,?,?,?,?,'OWNER',NOW(),NOW())",
            [$name, trim($display), $email, $shopName, $hash]
        );
        $id = (int) $db->lastInsertId();

        // Atomically record that the shop has been provisioned
        audit($db, $id, 'OWNER_PROVISIONED', $requestId, [
            'shop_name' => $shopName,
            'email' => $email,
            'username' => $name,
        ]);

        foreach ($limits as $bucket => $max) {
            query($db, 'DELETE FROM login_limits WHERE bucket=?', [$bucket]);
        }

        // Generate immediate session token to log the owner directly into the dashboard
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + settings()['session_hours'] * 3600);
        query(
            $db,
            'INSERT INTO sessions(token_hash,user_id,created_at,expires_at) VALUES(?,?,NOW(),?)',
            [hash('sha256', $token), $id, $expires]
        );

        $user = query($db, 'SELECT * FROM users WHERE id=?', [$id])->fetch();
        $db->commit();

        return [
            'created' => true,
            'username' => $name,
            'user' => public_user($user),
            'token' => $token,
            'expires_at' => $expires,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
