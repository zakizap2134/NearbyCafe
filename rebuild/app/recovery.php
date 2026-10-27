<?php
declare(strict_types=1);
require_once __DIR__ . "/values.php";

// Call only while holding app_state in a transaction. Failures are committed deliberately.
function credential_check(
    PDO $db,
    int $uid,
    string $purpose,
    bool $valid,
    string $requestId,
): void {
    $bucket = hash("sha256", $purpose . ":" . $uid);
    query(
        $db,
        "DELETE FROM login_limits WHERE bucket=? AND window_started<DATE_SUB(NOW(),INTERVAL 15 MINUTE)",
        [$bucket],
    );
    query(
        $db,
        "INSERT IGNORE INTO login_limits(bucket,failures,window_started) VALUES(?,0,NOW())",
        [$bucket],
    );
    if (
        (int) query($db, "SELECT failures FROM login_limits WHERE bucket=?", [
            $bucket,
        ])->fetchColumn() >= 5
    ) {
        problem(
            429,
            "RECOVERY_LIMIT",
            "Too many credential attempts. Wait 15 minutes.",
        );
    }
    if (!$valid) {
        query(
            $db,
            "UPDATE login_limits SET failures=failures+1 WHERE bucket=?",
            [$bucket],
        );
        audit($db, $uid, "CREDENTIAL_CHECK_FAILED", $requestId);
        $db->commit();
        problem(
            403,
            "CREDENTIAL_INVALID",
            "The supplied credential is incorrect.",
        );
    }
    query($db, "DELETE FROM login_limits WHERE bucket=?", [$bucket]);
}
function staff_password_reset(
    PDO $db,
    array $session,
    array $body,
    string $requestId,
): array {
    $target = positive_id($body["user_id"] ?? null);
    $password = $body["owner_password"] ?? null;
    if (!is_string($password) || strlen($password) > 72) {
        problem(422, "VALIDATION", "Enter your owner password.");
    }
    if (!is_string($body["new_password"] ?? null)) {
        problem(422, "VALIDATION", "Enter a new staff password.");
    }
    $hash = new_password($body["new_password"], $body["confirm_password"] ?? null);
    $db->beginTransaction();
    try {
        lock_state($db);
        current_session($db, $session, true);
        $uid = (int) $session["user"]["id"];
        $owner = query($db, "SELECT password_hash FROM users WHERE id=?", [
            $uid,
        ])->fetchColumn();
        credential_check(
            $db,
            $uid,
            "owner-reauth",
            password_verify($password, $owner),
            $requestId,
        );
        $staff = query(
            $db,
            "SELECT * FROM users WHERE id=? AND role='BARISTA'",
            [$target],
        )->fetch();
        if (!$staff) {
            problem(
                422,
                "STAFF_REQUIRED",
                "Choose a Barista account. For Owner recovery, use Forgot password? on the sign-in page.",
            );
        }
        query(
            $db,
            "UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?",
            [$hash, $target],
        );
        query($db, "DELETE FROM sessions WHERE user_id=?", [$target]);
        query($db, "DELETE FROM login_limits WHERE bucket=?", [
            hash("sha256", "user:" . $staff["username"]),
        ]);
        audit($db, $uid, "BARISTA_PASSWORD_RESET:" . $target, $requestId);
        $db->commit();
        return ["reset" => true, "user_id" => $target];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function rotate_recovery_code(PDO $db, int $uid): string
{
    $code = strtoupper(bin2hex(random_bytes(32)));
    query(
        $db,
        "INSERT INTO owner_recovery(user_id,code_hash,generated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE code_hash=VALUES(code_hash),generated_at=NOW()",
        [$uid, hash("sha256", $code)],
    );
    return implode("-", str_split($code, 8));
}
function recovery_owner(PDO $db, string $name): array
{
    $owner = query(
        $db,
        "SELECT * FROM users WHERE username=? AND role='OWNER' AND is_active=1",
        [$name],
    )->fetch();
    if (!$owner) {
        problem(403, "OWNER_REQUIRED", "An active Owner account is required.");
    }
    return $owner;
}
