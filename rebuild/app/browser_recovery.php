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

function send_password_reset_email(
    string $toEmail,
    string $displayName,
    string $resetLink,
    string $formattedToken,
): bool {
    $cfg = settings()['smtp'] ?? [];
    $fromEmail = is_string($cfg['from_email'] ?? null) && $cfg['from_email'] !== ''
        ? $cfg['from_email']
        : ('noreply@' . (preg_replace('/:[0-9]+$/', '', $_SERVER['HTTP_HOST'] ?? 'nearbycafe.local') ?: 'nearbycafe.local'));
    $fromName = is_string($cfg['from_name'] ?? null) && $cfg['from_name'] !== ''
        ? $cfg['from_name']
        : 'Nearby Café POS';
    $subject = 'Nearby Café — Password Reset Request';
    $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
    $safeToken = htmlspecialchars($formattedToken, ENT_QUOTES, 'UTF-8');

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background: #fff9f0; color: #442d25; padding: 24px;">
  <div style="max-width: 540px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px; border: 1px solid #ead9c8;">
    <p style="font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #a93226; font-weight: 700; margin: 0 0 8px;">Nearby Café · VenueVue</p>
    <h2 style="margin: 0 0 16px; color: #442d25;">Reset your password</h2>
    <p>Hello <strong>{$safeName}</strong>,</p>
    <p>We received a request to reset the password for your Nearby Café account. Click the button below to choose a new password (valid for <strong>15 minutes</strong>):</p>
    <p style="margin: 24px 0;">
      <a href="{$safeLink}" style="display: inline-block; background: #a93226; color: #ffffff; text-decoration: none; padding: 12px 22px; border-radius: 8px; font-weight: 600;">Reset Password</a>
    </p>
    <p style="font-size: 13px; color: #60483e;">Or copy and paste this link into your browser:<br><a href="{$safeLink}" style="color: #a93226; word-break: break-all;">{$safeLink}</a></p>
    <p style="font-size: 13px; color: #60483e;">Reset Token (if prompted manually):<br><code style="background: #f8efe4; padding: 4px 8px; border-radius: 4px; font-weight: 700;">{$safeToken}</code></p>
    <hr style="border: none; border-top: 1px solid #ead9c8; margin: 20px 0;">
    <p style="font-size: 12px; color: #7a6258; margin: 0;">If you did not request a password reset, you can safely ignore this email. Your existing password remains unchanged.</p>
  </div>
</body>
</html>
HTML;

    // Record to private server outbox log for local/diagnostic verification
    $outboxLine = json_encode([
        'time' => date(DATE_ATOM),
        'to' => $toEmail,
        'subject' => $subject,
        'reset_link' => $resetLink,
        'token' => $formattedToken,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents(__DIR__ . '/../storage/mail-outbox.log', $outboxLine, FILE_APPEND | LOCK_EX);

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: VenueVue-POS',
    ];

    if (!empty($cfg['enabled']) && !empty($cfg['host'])) {
        try {
            if (send_smtp_mail($cfg, $fromEmail, $fromName, $toEmail, $subject, $htmlBody)) {
                return true;
            }
        } catch (Throwable) {
            // Fall through to native PHP mail()
        }
    }

    return @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));
}

function send_smtp_mail(
    array $smtp,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $htmlBody,
): bool {
    $host = (string) ($smtp['host'] ?? '');
    $port = (int) ($smtp['port'] ?? 587);
    $enc = strtolower((string) ($smtp['encryption'] ?? 'tls'));
    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 10);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 10);
    $read = static function () use ($fp): string {
        $data = '';
        while ($str = fgets($fp, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $data;
    };
    $cmd = static function (string $line) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        return $read();
    };
    $read();
    $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $cmd('EHLO ' . $ehloHost);
    if ($enc === 'tls') {
        $res = $cmd('STARTTLS');
        if (!str_starts_with($res, '220')) {
            fclose($fp);
            return false;
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        $cmd('EHLO ' . $ehloHost);
    }
    if (!empty($smtp['username'])) {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode((string) $smtp['username']));
        $authRes = $cmd(base64_encode((string) ($smtp['password'] ?? '')));
        if (!str_starts_with($authRes, '235')) {
            fclose($fp);
            return false;
        }
    }
    $cmd('MAIL FROM:<' . $fromEmail . '>');
    $rcptRes = $cmd('RCPT TO:<' . $toEmail . '>');
    if (!str_starts_with($rcptRes, '250') && !str_starts_with($rcptRes, '251')) {
        fclose($fp);
        return false;
    }
    $cmd('DATA');
    $msg = 'Date: ' . date('r') . "\r\n"
        . 'To: <' . $toEmail . ">\r\n"
        . 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n"
        . 'Subject: ' . $subject . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $htmlBody . "\r\n.";
    $dataRes = $cmd($msg);
    $cmd('QUIT');
    fclose($fp);
    return str_starts_with($dataRes, '250');
}

function request_email_password_reset(PDO $db, array $body, string $requestId): array
{
    $email = valid_email($body['email'] ?? null, true);
    $limits = [
        hash('sha256', 'email-reset-addr:' . $email) => 5,
        hash('sha256', 'email-reset-ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => 15,
    ];
    $db->beginTransaction();
    try {
        lock_state($db);
        foreach ($limits as $bucket => $maximum) {
            query($db, 'DELETE FROM login_limits WHERE bucket=? AND window_started<=DATE_SUB(NOW(),INTERVAL 15 MINUTE)', [$bucket]);
            $row = query($db, 'SELECT failures,GREATEST(1,900-TIMESTAMPDIFF(SECOND,window_started,NOW())) AS retry_after FROM login_limits WHERE bucket=?', [$bucket])->fetch();
            if ($row && (int) $row['failures'] >= $maximum) {
                header('Retry-After: ' . (int) $row['retry_after']);
                $db->commit();
                problem(429, 'RECOVERY_LIMIT', 'Too many password reset requests. Wait up to 15 minutes before trying again.');
            }
            query($db, 'INSERT INTO login_limits(bucket,failures,window_started) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE failures=failures+1', [$bucket]);
        }

        query($db, 'DELETE FROM password_resets WHERE expires_at<=NOW() OR used_at IS NOT NULL');
        $user = query(
            $db,
            'SELECT id,username,display_name,email FROM users WHERE email IS NOT NULL AND LOWER(email)=? AND is_active=1 LIMIT 1',
            [$email],
        )->fetch();

        if ($user) {
            $uid = (int) $user['id'];
            query($db, 'DELETE FROM password_resets WHERE user_id=? AND used_at IS NULL', [$uid]);
            $rawToken = strtoupper(bin2hex(random_bytes(32)));
            $formattedToken = implode('-', str_split($rawToken, 8));
            $tokenHash = hash('sha256', $rawToken);
            query(
                $db,
                'INSERT INTO password_resets(user_id,token_hash,expires_at,created_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NOW())',
                [$uid, $tokenHash],
            );

            $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
            $scheme = $secure ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost:5174';
            $scriptDir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
            if (str_ends_with($scriptDir, '/public')) {
                $scriptDir = substr($scriptDir, 0, -7);
            }
            $baseUrl = $scheme . $host . ($scriptDir !== '' && $scriptDir !== '/' ? $scriptDir : '');
            $resetLink = $baseUrl . '/#/reset-password?token=' . urlencode($formattedToken);

            send_password_reset_email($user['email'], $user['display_name'], $resetLink, $formattedToken);
            audit($db, $uid, 'PASSWORD_RESET_EMAIL_SENT', $requestId);
        } else {
            audit($db, null, 'PASSWORD_RESET_EMAIL_UNKNOWN', $requestId);
        }

        $db->commit();
        return [
            'sent' => true,
            'message' => 'If an active account matches that email address, a password reset link has been sent. Please check your inbox and spam folder (valid for 15 minutes).',
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function complete_email_password_reset(PDO $db, array $body, string $requestId): array
{
    $password = $body['new_password'] ?? null;
    $confirm = $body['confirm_password'] ?? null;
    validate_new_password($password, $confirm);

    $rawInput = $body['token'] ?? null;
    $cleanToken = is_string($rawInput) && strlen($rawInput) <= 100
        ? strtoupper(str_replace('-', '', trim($rawInput)))
        : '';
    $formatValid = preg_match('/^[A-F0-9]{64}$/D', $cleanToken) === 1;

    $limits = [
        hash('sha256', 'email-reset-verify-ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => 10,
        hash('sha256', 'email-reset-verify-global') => 60,
    ];

    $db->beginTransaction();
    try {
        lock_state($db);
        foreach ($limits as $bucket => $maximum) {
            query($db, 'DELETE FROM login_limits WHERE bucket=? AND window_started<=DATE_SUB(NOW(),INTERVAL 15 MINUTE)', [$bucket]);
            $row = query($db, 'SELECT failures,GREATEST(1,900-TIMESTAMPDIFF(SECOND,window_started,NOW())) AS retry_after FROM login_limits WHERE bucket=?', [$bucket])->fetch();
            if ($row && (int) $row['failures'] >= $maximum) {
                header('Retry-After: ' . (int) $row['retry_after']);
                $db->commit();
                problem(429, 'RECOVERY_LIMIT', 'Too many reset attempts. Wait up to 15 minutes before trying again.');
            }
        }

        $tokenHash = hash('sha256', $cleanToken);
        $resetRow = $formatValid
            ? query(
                $db,
                'SELECT r.id AS reset_id, r.user_id, u.username, u.email FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>NOW() AND u.is_active=1 LIMIT 1',
                [$tokenHash],
            )->fetch()
            : false;

        if (!$resetRow) {
            foreach ($limits as $bucket => $maximum) {
                query($db, 'INSERT INTO login_limits(bucket,failures,window_started) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE failures=failures+1', [$bucket]);
            }
            audit($db, null, 'PASSWORD_RESET_TOKEN_FAILED', $requestId);
            $db->commit();
            problem(403, 'RESET_TOKEN_INVALID', 'This password reset link or token is invalid or has expired. Please request a new reset email.');
        }

        $uid = (int) $resetRow['user_id'];
        $hash = new_password($password, $confirm);
        query($db, 'UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?', [$hash, $uid]);
        query($db, 'UPDATE password_resets SET used_at=NOW() WHERE id=?', [(int) $resetRow['reset_id']]);
        query($db, 'DELETE FROM password_resets WHERE user_id=? AND used_at IS NULL', [$uid]);
        query($db, 'DELETE FROM sessions WHERE user_id=?', [$uid]);
        query($db, 'DELETE FROM login_limits WHERE bucket IN (?,?,?)', [
            hash('sha256', 'user:' . $resetRow['username']),
            hash('sha256', 'user:' . strtolower((string) ($resetRow['email'] ?? ''))),
            hash('sha256', 'owner-reauth:' . $uid),
        ]);
        audit($db, $uid, 'PASSWORD_RESET_COMPLETED', $requestId);
        $db->commit();

        return [
            'reset' => true,
            'sessions_revoked' => true,
            'username' => $resetRow['username'],
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
