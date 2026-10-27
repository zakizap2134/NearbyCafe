<?php
declare(strict_types=1);
require_once __DIR__ . '/recovery.php';

// Only this new public credential endpoint requires an explicit browser Origin.
// Do not derive the origin or rate-limit address from forwarded/proxy headers.
function recovery_request_origin(): void
{
    $normalize = static function (string $origin): ?string {
        $parts = parse_url($origin);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || array_diff(array_keys($parts), ['scheme', 'host', 'port'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        return $scheme . '://' . strtolower($parts['host']) . ':'
            . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    };
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $expected = preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/iD', $host)
        ? $normalize(($secure ? 'https://' : 'http://') . $host) : null;
    $received = $normalize($_SERVER['HTTP_ORIGIN'] ?? '');
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($expected === null || $received === null || !hash_equals($expected, $received)
        || ($site !== null && !in_array($site, ['same-origin', 'none'], true))) {
        problem(403, 'RECOVERY_ORIGIN', 'Open recovery from this shop’s sign-in page.');
    }
}

function browser_owner_recovery(PDO $db, array $body, string $requestId): array
{
    $password = $body['new_password'] ?? null;
    $confirm = $body['confirm_password'] ?? null;
    validate_new_password($password, $confirm);
    // Malformed/missing usernames and codes follow the same counted failure path.
    $input = $body['username'] ?? null;
    $name = is_string($input) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._@-]{2,63}$/D', trim($input))
        ? strtolower(trim($input)) : '';
    $input = $body['recovery_code'] ?? null;
    $code = is_string($input) && strlen($input) <= 100
        ? strtoupper(str_replace('-', '', trim($input))) : '';
    $formatValid = preg_match('/^[A-F0-9]{64}$/D', $code) === 1;
    $limits = [
        hash('sha256', 'web-recovery:user:' . $name) => 5,
        hash('sha256', 'web-recovery:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => 30,
        hash('sha256', 'web-recovery:global') => 100,
    ];
    $nameBucket = array_key_first($limits);
    $db->beginTransaction();
    try {
        // Shares the lock with CLI recovery, code rotation, login and other mutations.
        lock_state($db);
        $owner = query($db,
            "SELECT u.id,u.username,r.code_hash FROM users u LEFT JOIN owner_recovery r ON r.user_id=u.id WHERE u.username=? AND u.role='OWNER' AND u.is_active=1",
            [$name],
        )->fetch();
        if ($owner) {
            // The CLI cannot bypass five failed code checks by switching to the web.
            $limits[hash('sha256', 'offline-recovery:' . $owner['id'])] = 5;
        }
        $retryAfter = 0;
        foreach ($limits as $bucket => $maximum) {
            query($db, 'DELETE FROM login_limits WHERE bucket=? AND window_started<=DATE_SUB(NOW(),INTERVAL 15 MINUTE)', [$bucket]);
            $row = query($db,
                'SELECT failures,GREATEST(1,900-TIMESTAMPDIFF(SECOND,window_started,NOW())) AS retry_after FROM login_limits WHERE bucket=?',
                [$bucket],
            )->fetch();
            if ($row && (int) $row['failures'] >= $maximum) {
                $retryAfter = max($retryAfter, (int) $row['retry_after']);
            }
        }
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
            problem(429, 'RECOVERY_LIMIT', 'Too many recovery attempts. Wait up to 15 minutes before trying again.');
        }
        // Always compare hashes, including missing/inactive/non-owner accounts.
        $stored = $owner['code_hash'] ?? str_repeat('0', 64);
        $matches = hash_equals($stored, hash('sha256', $code));
        if (!$owner || !$formatValid || !$matches || $owner['code_hash'] === null) {
            foreach ($limits as $bucket => $maximum) {
                query($db,
                    'INSERT INTO login_limits(bucket,failures,window_started) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE failures=failures+1',
                    [$bucket],
                );
            }
            audit($db, null, 'OWNER_RECOVERY_FAILED', $requestId);
            $db->commit();
            problem(403, 'RECOVERY_INVALID', 'The username or recovery code is incorrect.');
        }
        $uid = (int) $owner['id'];
        // Hash only after credential validation and throttling; no expensive work for guesses.
        $hash = new_password($password, $confirm);
        query($db, 'UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?', [$hash, $uid]);
        query($db, 'DELETE FROM sessions WHERE user_id=?', [$uid]);
        $replacement = rotate_recovery_code($db, $uid);
        query($db, 'DELETE FROM login_limits WHERE bucket IN (?,?,?,?)', [
            $nameBucket,
            hash('sha256', 'offline-recovery:' . $uid),
            hash('sha256', 'user:' . $owner['username']),
            hash('sha256', 'owner-reauth:' . $uid),
        ]);
        audit($db, $uid, 'OWNER_RECOVERED', $requestId);
        $db->commit();
        // Intentionally no response replay cache or plaintext code persistence.
        return ['reset' => true, 'sessions_revoked' => true, 'recovery_code' => $replacement];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
