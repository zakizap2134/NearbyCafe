<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/recovery.php';

function get_smtp_config(): array
{
    $smtpFile = __DIR__ . '/../storage/smtp.json';
    if (is_file($smtpFile)) {
        $json = @file_get_contents($smtpFile);
        $data = $json ? json_decode($json, true) : null;
        if (is_array($data) && isset($data['enabled'])) {
            return $data;
        }
    }
    $cfg = settings();
    return $cfg['smtp'] ?? ['enabled' => false];
}

function test_smtp_socket_connection(array $smtp, string &$errorMessage = ''): bool
{
    $host = (string)($smtp['host'] ?? 'smtp.gmail.com');
    $port = (int)($smtp['port'] ?? 587);
    $encryption = strtolower((string)($smtp['encryption'] ?? 'tls'));
    $username = (string)($smtp['username'] ?? '');
    $password = str_replace(' ', '', (string)($smtp['password'] ?? ''));

    if ($host === '' || $username === '') {
        $errorMessage = 'Missing SMTP host or username.';
        return false;
    }

    $transport = ($encryption === 'ssl' || $port === 465) ? 'ssl://' : 'tcp://';
    $timeout = 10;
    $errno = 0;
    $errstr = '';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        $errorMessage = "Could not connect to $host on port $port ($errstr).";
        return false;
    }
    stream_set_timeout($socket, $timeout);

    $read = function () use ($socket): string {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $send = function (string $cmd) use ($socket, $read): string {
        fwrite($socket, $cmd . "\r\n");
        return $read();
    };

    $greeting = $read();
    if (!str_starts_with($greeting, '220')) {
        $errorMessage = "Server rejected initial connection: $greeting";
        fclose($socket);
        return false;
    }

    $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

    if ($encryption === 'tls' && $port !== 465) {
        $res = $send("STARTTLS");
        if (!str_starts_with($res, '220')) {
            $errorMessage = "STARTTLS failed: $res";
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            $errorMessage = "TLS handshake failed with $host";
            fclose($socket);
            return false;
        }
        $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }

    $res = $send("AUTH LOGIN");
    if (!str_starts_with($res, '334')) {
        $errorMessage = "AUTH LOGIN refused by server: $res";
        fclose($socket);
        return false;
    }
    $send(base64_encode($username));
    $res = $send(base64_encode($password));
    if (!str_starts_with($res, '235')) {
        $errorMessage = "Invalid credentials: Gmail/SMTP rejected your username or App Password ($res).";
        fclose($socket);
        return false;
    }

    $send("QUIT");
    fclose($socket);
    return true;
}

function send_smtp_socket_mail(array $smtp, string $to, string $subject, string $htmlBody): bool
{
    $host = (string)($smtp['host'] ?? 'smtp.gmail.com');
    $port = (int)($smtp['port'] ?? 587);
    $encryption = strtolower((string)($smtp['encryption'] ?? 'tls'));
    $username = (string)($smtp['username'] ?? '');
    $password = str_replace(' ', '', (string)($smtp['password'] ?? ''));
    $fromEmail = (string)($smtp['from_email'] ?? $username);
    $fromName = (string)($smtp['from_name'] ?? 'Nearby Café POS');

    if ($host === '' || $username === '') {
        return false;
    }

    $transport = ($encryption === 'ssl' || $port === 465) ? 'ssl://' : 'tcp://';
    $timeout = 10;
    $errno = 0;
    $errstr = '';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return false;
    }

    stream_set_timeout($socket, $timeout);

    $read = function () use ($socket): string {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };

    $send = function (string $cmd) use ($socket, $read): string {
        fwrite($socket, $cmd . "\r\n");
        return $read();
    };

    $read();
    $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

    if ($encryption === 'tls' && $port !== 465) {
        $res = $send("STARTTLS");
        if (!str_starts_with($res, '220')) {
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            fclose($socket);
            return false;
        }
        $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }

    $res = $send("AUTH LOGIN");
    if (!str_starts_with($res, '334')) {
        fclose($socket);
        return false;
    }
    $send(base64_encode($username));
    $res = $send(base64_encode($password));
    if (!str_starts_with($res, '235')) {
        fclose($socket);
        return false;
    }

    $send("MAIL FROM:<$fromEmail>");
    $send("RCPT TO:<$to>");
    $res = $send("DATA");
    if (!str_starts_with($res, '354')) {
        fclose($socket);
        return false;
    }

    $headers = [
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>",
        "To: <$to>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "Date: " . date(DATE_RFC2822),
        "X-Mailer: NearbyCafe-CloudAuth/2.0",
    ];

    $emailData = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
    $res = $send($emailData);
    $send("QUIT");
    fclose($socket);

    return str_starts_with($res, '250');
}

function send_reset_code_email(string $toEmail, string $displayName, string $code): bool
{
    $subject = "Your Nearby Café Password Reset Code: " . $code;
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Reset Your Password</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #fdfaf6; color: #2d1810; margin: 0; padding: 24px; }
    .box { max-width: 480px; margin: 0 auto; background: #ffffff; border: 1px solid #ebdcd0; border-radius: 12px; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .logo { color: #a93226; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 20px; }
    .code-box { background: #fdf2ed; border: 2px dashed #a93226; border-radius: 8px; text-align: center; padding: 18px; margin: 24px 0; font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #a93226; font-family: monospace, Courier; }
    .hint { font-size: 13px; color: #7f6055; line-height: 1.5; }
    .footer { font-size: 11px; color: #a1887f; margin-top: 32px; border-top: 1px solid #f0e6df; padding-top: 16px; }
  </style>
</head>
<body>
  <div class="box">
    <div class="logo">NEARBY CAFÉ</div>
    <h2>Password Reset Verification</h2>
    <p>Hi <strong>{$displayName}</strong>,</p>
    <p>Use the 6-digit verification code below to reset your account password. This code will expire in <strong>15 minutes</strong>:</p>
    <div class="code-box">{$code}</div>
    <p class="hint">Enter this code on the Nearby Café password reset screen along with your new password.</p>
    <p class="hint">If you did not request a password reset, you can safely ignore this email.</p>
    <div class="footer">Nearby Café POS · Cloud Web Recovery</div>
  </div>
</body>
</html>
HTML;

    // Log to local outbox for auditing and development troubleshooting
    $logLine = sprintf(
        "[%s] RECOVERY_CODE to: %s | Code: %s\n",
        date(DATE_ATOM),
        $toEmail,
        $code
    );
    @file_put_contents(__DIR__ . '/../storage/mail-outbox.log', $logLine, FILE_APPEND | LOCK_EX);

    $smtp = get_smtp_config();
    if (!empty($smtp['enabled']) && !empty($smtp['host']) && !empty($smtp['username'])) {
        $sent = send_smtp_socket_mail($smtp, $toEmail, $subject, $htmlBody);
        if ($sent) {
            return true;
        }
    }

    // Fallback to PHP mail()
    $headers = [
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "From: " . ($smtp['from_name'] ?? 'Nearby Café') . " <" . ($smtp['from_email'] ?? 'no-reply@nearbycafe.local') . ">",
    ];
    return @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));
}

function request_email_code(PDO $db, array $body, string $requestId): array
{
    $email = valid_email($body['email'] ?? null, true);

    $limits = [
        hash('sha256', 'reset-req:email:' . $email) => 6,
        hash('sha256', 'reset-req:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => 25,
        hash('sha256', 'reset-req:global') => 80,
    ];

    $db->beginTransaction();
    try {
        lock_state($db);
        foreach ($limits as $bucket => $max) {
            query($db, 'DELETE FROM login_limits WHERE bucket=? AND window_started<=DATE_SUB(NOW(),INTERVAL 15 MINUTE)', [$bucket]);
            $row = query($db, 'SELECT failures FROM login_limits WHERE bucket=?', [$bucket])->fetch();
            if ($row && (int)$row['failures'] >= $max) {
                $db->commit();
                problem(429, 'REQUEST_LIMIT', 'Too many verification code requests. Wait 15 minutes before trying again.');
            }
        }

        foreach ($limits as $bucket => $max) {
            query($db, 'INSERT INTO login_limits(bucket,failures,window_started) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE failures=failures+1', [$bucket]);
        }

        $user = query($db, "SELECT id, username, display_name, email FROM users WHERE email=? AND is_active=1 LIMIT 1", [$email])->fetch();

        if (!$user) {
            $db->commit();
            problem(404, 'ACCOUNT_NOT_FOUND', "No account is registered with '$email'. Please check the spelling or ask the shop owner.");
        }

        $code = (string) random_int(100000, 999999);
        $tokenHash = hash('sha256', $code);

        // Invalidate older unused reset codes for this user
        query($db, "DELETE FROM password_resets WHERE user_id=?", [$user['id']]);

        query(
            $db,
            "INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW())",
            [$user['id'], $tokenHash]
        );

        $sent = send_reset_code_email($email, (string)$user['display_name'], $code);
        audit($db, (int)$user['id'], 'RECOVERY_CODE_SENT', $requestId, ['email' => $email, 'sent' => $sent]);
        $db->commit();

        $smtp = get_smtp_config();
        if (empty($smtp['enabled']) && !$sent) {
            problem(
                503,
                'EMAIL_NOT_CONFIGURED',
                'Email delivery is not configured on this store yet. The store owner must configure Gmail SMTP in Management → Account security to enable password resets.'
            );
        }

        return [
            'sent' => true,
            'email' => $email,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function verify_email_code_and_reset(PDO $db, array $body, string $requestId): array
{
    $email = valid_email($body['email'] ?? null, true);
    $rawCode = trim((string)($body['code'] ?? ''));
    $cleanCode = preg_replace('/\s+/', '', $rawCode);

    if (!preg_match('/^[0-9]{6}$/D', $cleanCode)) {
        problem(422, 'VALIDATION', 'Enter the 6-digit verification code.');
    }

    $password = $body['new_password'] ?? null;
    $confirm = $body['confirm_password'] ?? null;
    validate_new_password($password, $confirm);

    $limits = [
        hash('sha256', 'reset-verify:email:' . $email) => 6,
        hash('sha256', 'reset-verify:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => 25,
    ];

    $db->beginTransaction();
    try {
        lock_state($db);

        foreach ($limits as $bucket => $max) {
            query($db, 'DELETE FROM login_limits WHERE bucket=? AND window_started<=DATE_SUB(NOW(),INTERVAL 15 MINUTE)', [$bucket]);
            $row = query($db, 'SELECT failures FROM login_limits WHERE bucket=?', [$bucket])->fetch();
            if ($row && (int)$row['failures'] >= $max) {
                $db->commit();
                problem(429, 'RESET_LIMIT', 'Too many incorrect attempts. Wait 15 minutes before trying again.');
            }
        }

        $user = query($db, "SELECT * FROM users WHERE email=? AND is_active=1 LIMIT 1", [$email])->fetch();
        $codeHash = hash('sha256', $cleanCode);

        $reset = null;
        if ($user) {
            $reset = query(
                $db,
                "SELECT * FROM password_resets WHERE user_id=? AND token_hash=? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1",
                [$user['id'], $codeHash]
            )->fetch();
        }

        if (!$user || !$reset) {
            foreach ($limits as $bucket => $max) {
                query($db, 'INSERT INTO login_limits(bucket,failures,window_started) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE failures=failures+1', [$bucket]);
            }
            audit($db, null, 'RECOVERY_CODE_FAILED', $requestId, ['email' => $email]);
            $db->commit();
            problem(400, 'INVALID_CODE', 'Invalid or expired 6-digit code. Please request a new code.');
        }

        $uid = (int)$user['id'];
        $hash = new_password($password, $confirm);

        query($db, "UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?", [$hash, $uid]);
        query($db, "UPDATE password_resets SET used_at=NOW() WHERE id=?", [$reset['id']]);
        query($db, "DELETE FROM sessions WHERE user_id=?", [$uid]);

        foreach ($limits as $bucket => $max) {
            query($db, 'DELETE FROM login_limits WHERE bucket=?', [$bucket]);
        }

        audit($db, $uid, 'PASSWORD_RESET_SUCCESS', $requestId, ['email' => $email]);
        $db->commit();

        return [
            'reset' => true,
            'username' => $user['username'],
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// Preserve backward-compatible legacy offline recovery for CLI test suite
function browser_owner_recovery(PDO $db, array $body, string $requestId): array
{
    $password = $body['new_password'] ?? null;
    $confirm = $body['confirm_password'] ?? null;
    validate_new_password($password, $confirm);
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
        lock_state($db);
        $owner = query($db,
            "SELECT u.id,u.username,r.code_hash FROM users u LEFT JOIN owner_recovery r ON r.user_id=u.id WHERE u.username=? AND u.role='OWNER' AND u.is_active=1",
            [$name],
        )->fetch();
        if ($owner) {
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
        return ['reset' => true, 'sessions_revoked' => true, 'recovery_code' => $replacement];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function request_email_password_reset(PDO $db, array $body, string $requestId): array
{
    return request_email_code($db, $body, $requestId);
}

function complete_email_password_reset(PDO $db, array $body, string $requestId): array
{
    return verify_email_code_and_reset($db, $body, $requestId);
}
