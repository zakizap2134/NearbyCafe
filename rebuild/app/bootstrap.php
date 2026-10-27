<?php
declare(strict_types=1);
date_default_timezone_set("Asia/Manila");
ini_set("display_errors", "0");
const VV_SCHEMA_VERSION = 4;
const VV_FOUNDATION_TABLES = [
    "app_state",
    "users",
    "sessions",
    "login_limits",
    "audit_events",
];
const VV_DOMAIN_TABLES = [
    "products",
    "product_variants",
    "ingredients",
    "recipes",
    "stock_batches",
    "stock_containers",
    "sales",
    "sale_items",
    "stock_movements",
];
const VV_RELIABILITY_TABLES = ["checkout_rejections", "owner_recovery"];
const VV_MANAGEMENT_TABLES = [
    "runtime_meta",
    "stock_operations",
    "audit_details",
];
const VV_TABLES = [
    ...VV_FOUNDATION_TABLES,
    ...VV_DOMAIN_TABLES,
    ...VV_RELIABILITY_TABLES,
    ...VV_MANAGEMENT_TABLES,
];
class AppError extends RuntimeException
{
    public function __construct(
        public int $status,
        public string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
function problem(int $status, string $code, string $message): never
{
    throw new AppError($status, $code, $message);
}
function settings(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }
    $file = getenv("VV_REBUILD_CONFIG") ?: __DIR__ . "/../config/local.php";
    if (!is_file($file)) {
        problem(
            503,
            "CONFIG_MISSING",
            "Host setup is incomplete. Ask the owner to run the preflight check.",
        );
    }
    $value = require $file;
    if (!is_array($value) || !is_array($value["db"] ?? null)) {
        problem(
            503,
            "CONFIG_INVALID",
            "Host configuration needs attention. Run the preflight check.",
        );
    }
    $d = $value["db"];
    foreach (["host", "name", "user", "password"] as $key) {
        if (!is_string($d[$key] ?? null)) {
            problem(
                503,
                "CONFIG_INVALID",
                "Database configuration is incomplete.",
            );
        }
    }
    if (
        !preg_match('/^[a-zA-Z0-9_]+$/D', $d["name"]) ||
        !preg_match('/^[a-zA-Z0-9.:-]+$/D', $d["host"]) ||
        $d["user"] === "" ||
        !is_int($d["port"] ?? null) ||
        $d["port"] < 1 ||
        $d["port"] > 65535
    ) {
        problem(503, "CONFIG_INVALID", "Database configuration is invalid.");
    }
    if (
        !is_int($value["session_hours"] ?? null) ||
        $value["session_hours"] < 1 ||
        $value["session_hours"] > 24
    ) {
        problem(
            503,
            "CONFIG_INVALID",
            "Session lifetime must be between 1 and 24 hours.",
        );
    }
    return $config = $value;
}
function connection(bool $withDatabase = true): PDO
{
    if (!extension_loaded("pdo_mysql")) {
        problem(
            503,
            "DB_DRIVER_MISSING",
            "The host needs the PDO MySQL extension. Run the preflight check.",
        );
    }
    $d = settings()["db"];
    $dsn =
        "mysql:host=" .
        $d["host"] .
        ";port=" .
        $d["port"] .
        ";charset=utf8mb4" .
        ($withDatabase ? ";dbname=" . $d["name"] : "");
    try {
        $db = new PDO($dsn, $d["user"], $d["password"], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec("SET time_zone = '+08:00'");
        return $db;
    } catch (PDOException $e) {
        $driver = (int) ($e->errorInfo[1] ?? 0);
        $code = match ($driver) {
            1049 => "DB_NOT_INSTALLED",
            1045 => "DB_ACCESS_DENIED",
            default => "DB_UNAVAILABLE",
        };
        problem(
            503,
            $code,
            "The host database is unavailable or not configured. Ask the owner to run the preflight check.",
        );
    }
}
function query(PDO $db, string $sql, array $args = []): PDOStatement
{
    $q = $db->prepare($sql);
    $q->execute($args);
    return $q;
}
function schema_ready(PDO $db): void
{
    try {
        $version = $db
            ->query("SELECT schema_version FROM app_state WHERE id=1")
            ->fetchColumn();
        if ((int) $version !== VV_SCHEMA_VERSION) {
            problem(
                503,
                "SCHEMA_MISMATCH",
                "The installed database does not match this application. Run the preflight check.",
            );
        }
        foreach (VV_TABLES as $table) {
            $db->query("SELECT 1 FROM `" . $table . "` LIMIT 0");
        }
    } catch (PDOException $e) {
        problem(
            503,
            "SCHEMA_MISSING",
            "Database setup is incomplete. Run the preflight check.",
        );
    }
}
function lock_state(PDO $db): void
{
    if (
        !$db->query("SELECT id FROM app_state WHERE id=1 FOR UPDATE")->fetch()
    ) {
        problem(
            503,
            "SCHEMA_MISSING",
            "The workflow lock is missing. Run preflight.",
        );
    }
}
function audit(
    PDO $db,
    ?int $id,
    string $action,
    string $requestId,
    ?array $details = null,
): void {
    query(
        $db,
        "INSERT INTO audit_events(user_id,action,request_id,created_at) VALUES(?,?,?,NOW())",
        [$id, $action, $requestId],
    );
    if ($details !== null) {
        query($db, "INSERT INTO audit_details(audit_id,details) VALUES(?,?)", [
            (int) $db->lastInsertId(),
            json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
function runtime_meta(PDO $db): array
{
    $row = $db->query("SELECT * FROM runtime_meta WHERE id=1")->fetch();
    if (!$row) {
        problem(
            503,
            "SCHEMA_MISSING",
            "Run the Stage 4 migration to initialize restore metadata.",
        );
    }
    return $row;
}
function assert_epoch(PDO $db, array $body): void
{
    $meta = runtime_meta($db);
    $epoch = $body["expected_epoch"] ?? null;
    if (
        ($epoch === null && (int) $meta["restore_generation"] > 0) ||
        ($epoch !== null &&
            (!is_string($epoch) || !hash_equals($meta["epoch"], $epoch)))
    ) {
        problem(
            409,
            "RESTORE_REVIEW",
            "The host database was restored. Reconcile this saved request with the owner before creating a new order or stock entry.",
        );
    }
}
function username(mixed $value): string
{
    if (
        !is_string($value) ||
        !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._@-]{2,63}$/D', trim($value))
    ) {
        problem(
            422,
            "VALIDATION",
            "Username must be 3–64 characters using letters, numbers, dots, @, underscores or hyphens.",
        );
    }
    return strtolower(trim($value));
}
// New credentials only: sign-in and current-password reauthentication must not
// apply this policy to existing hashes. PCRE /u counts Unicode code points,
// including combining marks separately, without requiring mbstring.
function validate_new_password(mixed $value, mixed $confirmation): void
{
    if (!is_string($value)) problem(422, 'VALIDATION', 'Use at least 12 characters.');
    if (str_contains($value, "\0")) problem(422, 'VALIDATION', 'Passwords cannot contain NUL characters.');
    if (strlen($value) > 72) {
        problem(422, 'VALIDATION', 'This password is too long. Use fewer characters; accented letters and emoji may take more space.');
    }
    $count = preg_match_all('/./us', $value);
    if ($count === false) problem(422, 'VALIDATION', 'Enter a password using valid Unicode characters.');
    if ($count < 12) problem(422, 'VALIDATION', 'Use at least 12 characters.');
    if (!is_string($confirmation) || !hash_equals($value, $confirmation)) {
        problem(422, 'VALIDATION', 'Passwords do not match.');
    }
}
function new_password(string $value, mixed $confirmation): string
{
    validate_new_password($value, $confirmation);
    return password_hash($value, PASSWORD_BCRYPT);
}
function public_user(array $row): array
{
    return [
        "id" => (int) $row["id"],
        "username" => $row["username"],
        "display_name" => $row["display_name"],
        "role" => $row["role"],
    ];
}
function session_user(PDO $db): array
{
    $header =
        $_SERVER["HTTP_AUTHORIZATION"] ??
        ($_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "");
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $m)) {
        problem(401, "AUTH_REQUIRED", "Sign in to continue.");
    }
    $hash = hash("sha256", $m[1]);
    $user = query(
        $db,
        "SELECT u.*,s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>NOW() AND u.is_active=1",
        [$hash],
    )->fetch();
    if (!$user || !in_array($user["role"], ["OWNER", "BARISTA"], true)) {
        problem(
            401,
            "SESSION_EXPIRED",
            "Your session has ended. Sign in again.",
        );
    }
    return ["user" => $user, "hash" => $hash];
}
function diagnostic(Throwable $e, string $requestId): void
{
    // Never log exception messages: database errors can contain credentials or SQL values.
    $record = [
        "time" => date(DATE_ATOM),
        "request_id" => $requestId,
        "type" => get_class($e),
        "code" => $e instanceof AppError ? $e->errorCode : "UNEXPECTED",
        "file" => basename($e->getFile()),
        "line" => $e->getLine(),
    ];
    if ($e instanceof PDOException) {
        $record["database_code"] = $e->errorInfo[1] ?? $e->getCode();
    }
    $line = json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (
        @file_put_contents(
            __DIR__ . "/../storage/app.log",
            $line,
            FILE_APPEND | LOCK_EX,
        ) === false
    ) {
        error_log(trim($line));
    }
}
