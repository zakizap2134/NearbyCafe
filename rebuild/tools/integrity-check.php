<?php
declare(strict_types=1);
// Read-only post-outage integrity check. Never repairs, deletes or rewrites data.
// Exit codes: 0 = all checks passed, 1 = could not run (see FAIL line), 2 = integrity problem found.
require_once __DIR__ . "/cli.php";
require_once __DIR__ . "/../app/backup.php";
require_runtime();
$db = connection();
schema_ready($db);
$problems = 0;
$fail = function (string $text) use (&$problems): void {
    $problems++;
    echo "FAIL " . $text . "\n";
};

// 1. Server state: forced-recovery or read-only modes mean the shop must not trade.
$vars = $db
    ->query(
        "SELECT @@version AS version,@@innodb_force_recovery AS force_recovery,@@innodb_read_only AS innodb_ro,@@read_only AS ro,@@innodb_flush_log_at_trx_commit AS flush,@@innodb_doublewrite AS doublewrite",
    )
    ->fetch();
$uptime = (int) $db
    ->query("SHOW GLOBAL STATUS LIKE 'Uptime'")
    ->fetch()["Value"];
echo "INFO database server " .
    $vars["version"] .
    "; running for " .
    intdiv($uptime, 60) .
    " minute(s).\n";
if ((int) $vars["force_recovery"] !== 0) {
    $fail(
        "innodb_force_recovery=" .
            $vars["force_recovery"] .
            " is active. Do not take orders. Developer must remove it from my.ini after exporting data.",
    );
} elseif ((int) $vars["innodb_ro"] !== 0 || (int) $vars["ro"] !== 0) {
    $fail("Database is in read-only mode. Do not take orders.");
} else {
    echo "PASS normal InnoDB mode (no forced recovery, writable).\n";
}
// Durability settings behind "a confirmed sale survives a power cut" (warnings, not failures).
if ((int) $vars["flush"] === 1 && (int) $vars["doublewrite"] === 1) {
    echo "PASS durability: innodb_flush_log_at_trx_commit=1 and innodb_doublewrite=ON.\n";
} else {
    echo "WARN durability: innodb_flush_log_at_trx_commit=" .
        $vars["flush"] .
        ", innodb_doublewrite=" .
        $vars["doublewrite"] .
        ". Set both to 1 in my.ini so confirmed sales survive power loss.\n";
}

// 2. Physical table structure (CHECK TABLE is read-only for InnoDB).
$damaged = [];
foreach (VV_TABLES as $table) {
    $rows = $db->query("CHECK TABLE `" . $table . "`")->fetchAll();
    $last = end($rows);
    $ok =
        $last !== false &&
        strtolower((string) $last["Msg_type"]) === "status" &&
        strtoupper((string) $last["Msg_text"]) === "OK";
    foreach ($rows as $row) {
        if (in_array(strtolower((string) $row["Msg_type"]), ["error", "warning"], true)) {
            $ok = false;
        }
    }
    if (!$ok) {
        $damaged[] = $table;
    }
}
if ($damaged) {
    $fail("CHECK TABLE reported problems in: " . implode(", ", $damaged) . ".");
} else {
    echo "PASS CHECK TABLE on " . count(VV_TABLES) . " application tables.\n";
}

// 3. Business invariants from one consistent snapshot.
$db->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
$db->exec("START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT");
try {
    $violations = integrity_violations($db);
    $extra = [
        "duplicate sale transaction keys" =>
            "SELECT COUNT(*) FROM (SELECT transaction_key FROM sales GROUP BY transaction_key HAVING COUNT(*)>1) d",
        "sales without stock deductions" =>
            "SELECT COUNT(*) FROM sales s WHERE NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.sale_id=s.id AND m.type='SALE')",
    ];
    foreach ($extra as $name => $sql) {
        if ((int) $db->query($sql)->fetchColumn() > 0) {
            $violations[] = $name;
        }
    }
    $summary = $db
        ->query(
            "SELECT COUNT(*) AS sales,COALESCE(SUM(total_gross),0) AS gross,COALESCE(SUM(total_net),0) AS net,COALESCE(SUM(total_vat),0) AS vat,MAX(sale_at) AS last_sale FROM sales",
        )
        ->fetch();
    $movements = (int) $db
        ->query("SELECT COUNT(*) FROM stock_movements")
        ->fetchColumn();
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}
if ($violations) {
    foreach ($violations as $name) {
        $fail("invariant: " . $name . ".");
    }
} else {
    echo "PASS stock, batch, container, movement, VAT and checkout-outcome invariants.\n";
}
echo "INFO sales=" .
    $summary["sales"] .
    " gross=" .
    $summary["gross"] .
    " net=" .
    $summary["net"] .
    " vat=" .
    $summary["vat"] .
    " stock_movements=" .
    $movements .
    " last_sale=" .
    ($summary["last_sale"] ?? "none") .
    "\n";

if ($problems > 0) {
    echo "RESULT ATTENTION: " .
        $problems .
        " problem(s). Do not take orders. Keep devices and browser data untouched and contact the developer.\n";
    exit(2);
}
echo "RESULT HEALTHY: database is consistent. The shop can reopen after checking device pending orders.\n";
exit(0);
