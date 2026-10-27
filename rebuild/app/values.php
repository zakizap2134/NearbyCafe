<?php
declare(strict_types=1);
require_once __DIR__ . "/bootstrap.php";
function positive_id(
    mixed $value,
    string $label = "ID",
    int $max = 2147483647,
): int {
    if (
        (!is_int($value) && !is_string($value)) ||
        !preg_match('/^[1-9][0-9]*$/D', (string) $value) ||
        (int) $value > $max
    ) {
        problem(422, "VALIDATION", "Invalid " . $label . ".");
    }
    return (int) $value;
}
function fixed(
    mixed $value,
    int $places,
    string $label,
    bool $allowZero = false,
): int {
    // Request decimals must be strings or integers: no float/exponent interpretation.
    if (
        (!is_string($value) && !is_int($value)) ||
        !preg_match(
            "/^([0-9]{1,9})(?:\.([0-9]{1," . $places . '}))?$/D',
            (string) $value,
            $m,
        )
    ) {
        problem(
            422,
            "VALIDATION",
            "$label must use up to $places decimal places.",
        );
    }
    $n = (int) $m[1] * 10 ** $places + (int) str_pad($m[2] ?? "", $places, "0");
    if ($n < ($allowZero ? 0 : 1)) {
        problem(422, "VALIDATION", $label . " must be positive.");
    }
    return $n;
}
function decimal(int $n, int $places = 2): string
{
    return ($n < 0 ? "-" : "") .
        intdiv(abs($n), 10 ** $places) .
        "." .
        str_pad((string) (abs($n) % 10 ** $places), $places, "0", STR_PAD_LEFT);
}
function label_text(mixed $value, string $label, int $max = 100): string
{
    if (
        !is_string($value) ||
        trim($value) === "" ||
        strlen(trim($value)) > $max
    ) {
        problem(422, "VALIDATION", "$label is required (maximum $max bytes).");
    }
    return trim($value);
}
function optional_text(mixed $value, string $label): ?string
{
    return $value === null || $value === "" ? null : label_text($value, $label);
}
function active_flag(array $body): int
{
    $value = $body["is_active"] ?? 1;
    if (!in_array($value, [0, 1, "0", "1"], true)) {
        problem(422, "VALIDATION", "Invalid active flag.");
    }
    return (int) $value;
}
function valid_date(mixed $value): ?string
{
    if ($value === null || $value === "") {
        return null;
    }
    if (
        !is_string($value) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ||
        !($date = DateTimeImmutable::createFromFormat("!Y-m-d", $value)) ||
        $date->format("Y-m-d") !== $value
    ) {
        problem(422, "VALIDATION", "Invalid expiration date.");
    }
    return $value;
}
function uuid_value(mixed $value): string
{
    if (
        !is_string($value) ||
        !preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',
            $value,
        )
    ) {
        problem(422, "VALIDATION", "A valid transaction key is required.");
    }
    return strtolower($value);
}
function database_time(PDO $db): string
{
    return (string) $db->query("SELECT NOW()")->fetchColumn();
}
function current_session(PDO $db, array $session, bool $owner = false): void
{
    $role = query(
        $db,
        "SELECT u.role FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.user_id=? AND s.expires_at>NOW() AND u.is_active=1 FOR UPDATE",
        [$session["hash"], $session["user"]["id"]],
    )->fetchColumn();
    if (!$role) {
        problem(
            401,
            "SESSION_EXPIRED",
            "Your session has ended. Sign in again.",
        );
    }
    if ($owner && $role !== "OWNER") {
        problem(403, "FORBIDDEN", "Owner / Admin access is required.");
    }
}
function whole_units(array $ingredient, int $qty): void
{
    if ($ingredient["unit"] === "units" && $qty % 1000 !== 0) {
        problem(
            422,
            "VALIDATION",
            "Whole units are required for " . $ingredient["name"] . ".",
        );
    }
}
