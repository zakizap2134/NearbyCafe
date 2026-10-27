<?php
declare(strict_types=1);
require_once __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/owner_setup.php";
require_once __DIR__ . "/../../app/catalogue.php";
require_once __DIR__ . "/../../app/checkout.php";
require_once __DIR__ . "/../../app/recovery.php";
require_once __DIR__ . "/../../app/browser_recovery.php";
require_once __DIR__ . "/../../app/recovery_preparation.php";
require_once __DIR__ . "/../../app/reports.php";
require_once __DIR__ . "/../../app/backup.php";
$requestId = bin2hex(random_bytes(8));
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");
header("X-Request-ID: " . $requestId);
function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit();
}
try {
    $route = $_GET["route"] ?? "";
    $method = $_SERVER["REQUEST_METHOD"];
    $routes = [
        "health" => "GET",
        "login" => "POST",
        "recovery/owner" => "POST",
        "management/recovery-code/status" => "GET",
        "management/recovery-code/generate" => "POST",
        "setup/status" => "GET",
        "setup/owner" => "POST",
        "session" => "GET",
        "logout" => "POST",
        "register/status" => "GET",
        "management/status" => "GET",
        "catalogue" => "GET",
        "management" => "GET",
        "management/save" => "POST",
        "checkout" => "POST",
        "sales/receipt" => "GET",
        "management/staff" => "GET",
        "management/reset-password" => "POST",
        "management/inventory" => "POST",
        "management/staff-save" => "POST",
        "management/delete" => "POST",
        "management/reports" => "GET",
        "management/csv" => "GET",
        "management/audit" => "GET",
        "management/movements" => "GET",
        "management/backup" => "POST",
        "management/restore-preview" => "POST",
        "management/restore" => "POST",
    ];
    if (!is_string($route) || !isset($routes[$route])) {
        problem(404, "NOT_FOUND", "Endpoint not found.");
    }
    if ($method !== $routes[$route]) {
        header("Allow: " . $routes[$route]);
        problem(
            405,
            "METHOD_NOT_ALLOWED",
            "This request method is not supported.",
        );
    }
    $body = [];
    if (in_array($route, ["recovery/owner", "management/recovery-code/generate"], true)) {
        header("Referrer-Policy: no-referrer");
        recovery_request_origin();
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            problem(415, 'CONTENT_TYPE', 'Send JSON content.');
        }
    }
    if ($method === "POST") {
        if (
            !str_starts_with(
                strtolower($_SERVER["CONTENT_TYPE"] ?? ""),
                "application/json",
            )
        ) {
            problem(415, "CONTENT_TYPE", "Send JSON content.");
        }
        $limit = in_array(
            $route,
            ["management/restore-preview", "management/restore"],
            true,
        )
            ? 16777216
            : 8192;
        $raw = file_get_contents("php://input", false, null, 0, $limit + 1);
        if (strlen($raw) > $limit) {
            problem(413, "BODY_TOO_LARGE", "The request is too large.");
        }
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            problem(400, "INVALID_JSON", "The request could not be read.");
        }
        if (!is_array($body) || !str_starts_with(ltrim($raw), "{")) {
            problem(400, "INVALID_JSON", "Send a JSON object.");
        }
    }
    $db = connection();
    schema_ready($db);
    if ($route === "health") {
        respond(200, [
            "ok" => true,
            "data" => ["ready" => true, ...runtime_meta($db)],
            "request_id" => $requestId,
        ]);
    }
    if ($route === "login") {
        respond(200, [
            "ok" => true,
            "data" => login($db, $body, $requestId),
            "request_id" => $requestId,
        ]);
    }
    if ($route === "recovery/owner") {
        respond(200, [
            "ok" => true,
            "data" => browser_owner_recovery($db, $body, $requestId),
            "request_id" => $requestId,
        ]);
    }
    if ($route === "setup/status" || $route === "setup/owner") {
        respond($route === "setup/owner" ? 201 : 200, [
            "ok" => true,
            "data" => $route === "setup/owner"
                ? register_initial_owner($db, $body, $requestId)
                : owner_setup_status($db),
            "request_id" => $requestId,
        ]);
    }
    $session = session_user($db);
    $user = $session["user"];
    if (str_starts_with($route, "management") && $user["role"] !== "OWNER") {
        problem(403, "FORBIDDEN", "Owner / Admin access is required.");
    }
    if (in_array($route, ["management/recovery-code/status", "management/recovery-code/generate"], true)) {
        header("Referrer-Policy: no-referrer");
        respond(200, ["ok" => true, "data" => owner_recovery_preparation($db, $session,
            $route === "management/recovery-code/status" ? null : $body, $requestId), "request_id" => $requestId]);
    }
    if (
        in_array(
            $route,
            [
                "management/backup",
                "management/restore-preview",
                "management/restore",
            ],
            true,
        )
    ) {
        $action = match ($route) {
            "management/backup" => "export",
            "management/restore-preview" => "preview",
            default => "restore",
        };
        respond(200, [
            "ok" => true,
            "data" => backup_action($db, $session, $body, $requestId, $action),
            "request_id" => $requestId,
        ]);
    }
    if (
        in_array(
            $route,
            [
                "management/inventory",
                "management/staff-save",
                "management/delete",
            ],
            true,
        )
    ) {
        $handler = match ($route) {
            "management/inventory" => "inventory_operation",
            "management/staff-save" => "staff_save",
            default => "delete_catalogue",
        };
        respond(200, [
            "ok" => true,
            "data" => $handler($db, $session, $body, $requestId),
            "request_id" => $requestId,
        ]);
    }
    if (
        in_array(
            $route,
            [
                "management/reports",
                "management/csv",
                "management/audit",
                "management/movements",
            ],
            true,
        )
    ) {
        $db->beginTransaction();
        $data = in_array($route, ["management/reports", "management/csv"], true)
            ? report_data($db, $_GET, $route === "management/csv")
            : history_data($db, $_GET, $route === "management/movements");
        $db->commit();
        respond(200, [
            "ok" => true,
            "data" => $data,
            "request_id" => $requestId,
        ]);
    }
    if ($route === "management/staff") {
        respond(200, [
            "ok" => true,
            "data" => [
                "staff" => $db
                    ->query(
                        "SELECT id,username,display_name,is_active FROM users WHERE role='BARISTA' ORDER BY display_name",
                    )
                    ->fetchAll(),
            ],
            "request_id" => $requestId,
        ]);
    }
    if ($route === "management/reset-password") {
        respond(200, [
            "ok" => true,
            "data" => staff_password_reset($db, $session, $body, $requestId),
            "request_id" => $requestId,
        ]);
    }
    if ($route === "management/save") {
        respond(200, [
            "ok" => true,
            "data" => save_catalogue($db, $session, $body, $requestId),
            "request_id" => $requestId,
        ]);
    }
    if ($route === "checkout") {
        respond(200, [
            "ok" => true,
            "data" => checkout($db, $session, $body, $requestId),
            "request_id" => $requestId,
        ]);
    }
    if (in_array($route, ["catalogue", "management", "sales/receipt"], true)) {
        $db->beginTransaction();
        if ($route === "catalogue") {
            $data = catalogue_data($db);
        } elseif ($route === "management") {
            $data = management_data($db);
        } else {
            $sale = query($db, "SELECT * FROM sales WHERE id=?", [
                positive_id($_GET["id"] ?? null),
            ])->fetch();
            if (
                !$sale ||
                ($user["role"] !== "OWNER" &&
                    (int) $sale["user_id"] !== (int) $user["id"])
            ) {
                problem(404, "NOT_FOUND", "Sale not found.");
            }
            $data = sale_receipt($db, $sale);
        }
        $db->commit();
        respond(200, [
            "ok" => true,
            "data" => $data,
            "request_id" => $requestId,
        ]);
    }
    if ($route === "logout") {
        $db->beginTransaction();
        try {
            lock_state($db);
            query($db, "DELETE FROM sessions WHERE token_hash=?", [
                $session["hash"],
            ]);
            audit($db, (int) $user["id"], "LOGOUT", $requestId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $data = ["signed_out" => true];
    } elseif ($route === "session") {
        $data = [
            "user" => public_user($user),
            "expires_at" => $user["expires_at"],
        ];
    } else {
        $data = [
            "section" =>
                $route === "management/status" ? "management" : "register",
            "stage" => 4,
            "ready" => true,
        ];
    }
    respond(200, ["ok" => true, "data" => $data, "request_id" => $requestId]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
        $status = 409;
        $code = "DUPLICATE";
        $message =
            "That name, SKU or size/temperature combination already exists.";
    } elseif ($e instanceof AppError) {
        $status = $e->status;
        $code = $e->errorCode;
        $message = $e->getMessage();
    } else {
        $status = 500;
        $code = "SERVER_ERROR";
        $message =
            "The host could not complete this request. Give the owner the reference below.";
    }
    if ($status >= 500) {
        diagnostic($e, $requestId);
    }
    respond($status, [
        "ok" => false,
        "error" => [
            "code" => $code,
            "message" => $message,
            "reference" => $requestId,
        ],
    ]);
}
