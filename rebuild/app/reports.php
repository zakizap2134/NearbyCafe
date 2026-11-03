<?php
declare(strict_types=1);
require_once __DIR__ . "/values.php";

function period(array $params): array
{
    $from = valid_date($params["from"] ?? date("Y-m-d"));
    $to = valid_date($params["to"] ?? $from);
    if (
        !$from ||
        !$to ||
        $from > $to ||
        (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >
            366
    ) {
        problem(
            422,
            "DATE_RANGE",
            "Choose an ordered date range of at most 367 days.",
        );
    }
    return [
        $from,
        $to,
        (new DateTimeImmutable($to))->modify("+1 day")->format("Y-m-d"),
    ];
}

function csv_safe_cell(mixed $value): string
{
    $str = (string) ($value ?? "");
    if ($str !== "" && preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $str)) {
        return "'" . $str;
    }
    return $str;
}

function csv_put_row($stream, array $row): void
{
    fputcsv($stream, $row, ",", '"', "");
}

function report_data(PDO $db, array $params, bool $csv = false): array
{
    [$from, $to, $end] = period($params);
    $page = positive_id($params["page"] ?? 1, "Page", 100000);
    $offset = ($page - 1) * 50;
    $totals = query(
        $db,
        "SELECT COUNT(*) AS count,COALESCE(SUM(total_gross),0) AS gross,COALESCE(SUM(total_net),0) AS net,COALESCE(SUM(total_vat),0) AS vat FROM sales WHERE status='completed' AND sale_at>=? AND sale_at<?",
        [$from, $end],
    )->fetch();
    $totals["count"] = (int) $totals["count"];
    $daily = query(
        $db,
        "SELECT DATE(sale_at) AS date,COUNT(*) AS count,SUM(total_gross) AS gross,SUM(total_net) AS net,SUM(total_vat) AS vat FROM sales WHERE status='completed' AND sale_at>=? AND sale_at<? GROUP BY DATE(sale_at) ORDER BY date",
        [$from, $end],
    )->fetchAll();
    if (!$csv) {
        return [
            "from" => $from,
            "to" => $to,
            "page" => $page,
            "totals" => $totals,
            "vat_remittance" => $totals["vat"],
            "daily" => $daily,
            "sales" => query(
                $db,
                "SELECT s.*,u.display_name AS cashier,v.display_name AS voided_by_name FROM sales s JOIN users u ON u.id=s.user_id LEFT JOIN users v ON v.id=s.voided_by WHERE sale_at>=? AND sale_at<? ORDER BY sale_at DESC,s.id DESC LIMIT 50 OFFSET $offset",
                [$from, $end],
            )->fetchAll(),
        ];
    }

    $count = (int) query(
        $db,
        "SELECT COUNT(*) FROM sale_items i JOIN sales s ON s.id=i.sale_id WHERE s.status='completed' AND s.sale_at>=? AND s.sale_at<?",
        [$from, $end],
    )->fetchColumn();
    if ($count > 50000) {
        problem(
            422,
            "EXPORT_LIMIT",
            "Choose a shorter period; this export exceeds 50,000 lines.",
        );
    }

    $format = strtolower(trim((string) ($params["format"] ?? "line_items")));
    if (!in_array($format, ["structured", "line_items", "daily_z", "products"], true)) {
        $format = "line_items";
    }
    $includeVoided = !isset($params["include_voided"]) || (string) $params["include_voided"] !== "0";

    $rows = query(
        $db,
        "SELECT s.id AS sale_id,s.sale_at,u.display_name AS cashier,COALESCE(v.sku,'-') AS sku,COALESCE(p.name,i.variant_label) AS product_name,COALESCE(p.category,'Uncategorized') AS category,i.variant_label,i.qty,i.unit_price,i.line_gross AS gross,i.line_net AS net,i.line_vat AS vat,s.vat_rate,s.cash_received,s.change_due FROM sale_items i JOIN sales s ON s.id=i.sale_id JOIN users u ON u.id=s.user_id LEFT JOIN product_variants v ON v.id=i.variant_id LEFT JOIN products p ON p.id=v.product_id WHERE s.status='completed' AND s.sale_at>=? AND s.sale_at<? ORDER BY s.sale_at,s.id,i.id",
        [$from, $end],
    )->fetchAll();

    $totalUnits = 0;
    foreach ($rows as $r) {
        $totalUnits += (int) $r["qty"];
    }

    $stream = fopen("php://temp", "w+");
    fwrite($stream, "\xEF\xBB\xBF");

    if ($format === "line_items") {
        csv_put_row($stream, [
            "sale_id",
            "sale_at",
            "cashier",
            "sku",
            "category",
            "variant",
            "qty",
            "unit_price",
            "gross",
            "net",
            "vat",
            "vat_rate",
            "cash_received",
            "change_due",
        ]);
        foreach ($rows as $row) {
            csv_put_row($stream, [
                $row["sale_id"],
                $row["sale_at"],
                csv_safe_cell($row["cashier"]),
                csv_safe_cell($row["sku"]),
                csv_safe_cell($row["category"]),
                csv_safe_cell($row["variant_label"]),
                $row["qty"],
                $row["unit_price"],
                $row["gross"],
                $row["net"],
                $row["vat"],
                $row["vat_rate"],
                $row["cash_received"],
                $row["change_due"],
            ]);
        }
        csv_put_row($stream, [
            "TOTAL",
            "",
            "",
            "",
            "",
            "",
            $totalUnits,
            "",
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
            "",
            "",
            "",
        ]);
        $filename = "VenueVue_sales_lines_{$from}_{$to}.csv";
    } elseif ($format === "daily_z") {
        $dailyFull = query(
            $db,
            "SELECT DATE(s.sale_at) AS date,
                    SUM(CASE WHEN s.status='completed' THEN 1 ELSE 0 END) AS completed_sales,
                    SUM(CASE WHEN s.status='voided' THEN 1 ELSE 0 END) AS voided_sales,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN si.sale_units ELSE 0 END),0) AS units_sold,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total_gross ELSE 0 END),0) AS gross,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total_net ELSE 0 END),0) AS net,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total_vat ELSE 0 END),0) AS vat
             FROM sales s
             LEFT JOIN (SELECT sale_id, SUM(qty) AS sale_units FROM sale_items GROUP BY sale_id) si ON si.sale_id=s.id
             WHERE s.sale_at>=? AND s.sale_at<?
             GROUP BY DATE(s.sale_at)
             ORDER BY date",
            [$from, $end],
        )->fetchAll();

        csv_put_row($stream, [
            "date",
            "completed_sales",
            "voided_sales",
            "units_sold",
            "gross",
            "net",
            "vat",
        ]);
        $voidCountTotal = 0;
        foreach ($dailyFull as $d) {
            $voidCountTotal += (int) $d["voided_sales"];
            csv_put_row($stream, [
                $d["date"],
                (int) $d["completed_sales"],
                (int) $d["voided_sales"],
                (int) $d["units_sold"],
                number_format((float) $d["gross"], 2, ".", ""),
                number_format((float) $d["net"], 2, ".", ""),
                number_format((float) $d["vat"], 2, ".", ""),
            ]);
        }
        csv_put_row($stream, [
            "TOTAL",
            $totals["count"],
            $voidCountTotal,
            $totalUnits,
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
        ]);
        $filename = "VenueVue_daily_z_reading_{$from}_{$to}.csv";
    } elseif ($format === "products") {
        $productRows = query(
            $db,
            "SELECT COALESCE(v.sku,'-') AS sku,
                    COALESCE(p.name,i.variant_label) AS product_name,
                    COALESCE(p.category,'Uncategorized') AS category,
                    i.variant_label,
                    SUM(i.qty) AS units_sold,
                    COUNT(DISTINCT s.id) AS orders_count,
                    SUM(i.line_gross) AS gross,
                    SUM(i.line_net) AS net,
                    SUM(i.line_vat) AS vat
             FROM sale_items i
             JOIN sales s ON s.id=i.sale_id
             LEFT JOIN product_variants v ON v.id=i.variant_id
             LEFT JOIN products p ON p.id=v.product_id
             WHERE s.status='completed' AND s.sale_at>=? AND s.sale_at<?
             GROUP BY v.sku, p.name, p.category, i.variant_label
             ORDER BY gross DESC, units_sold DESC",
            [$from, $end],
        )->fetchAll();

        csv_put_row($stream, [
            "sku",
            "product_name",
            "category",
            "variant",
            "units_sold",
            "orders_count",
            "gross",
            "net",
            "vat",
            "revenue_share_pct",
        ]);
        $grossTotalFloat = (float) $totals["gross"];
        foreach ($productRows as $pr) {
            $share = $grossTotalFloat > 0
                ? number_format(((float) $pr["gross"] / $grossTotalFloat) * 100, 2, ".", "") . "%"
                : "0.00%";
            csv_put_row($stream, [
                csv_safe_cell($pr["sku"]),
                csv_safe_cell($pr["product_name"]),
                csv_safe_cell($pr["category"]),
                csv_safe_cell($pr["variant_label"]),
                (int) $pr["units_sold"],
                (int) $pr["orders_count"],
                number_format((float) $pr["gross"], 2, ".", ""),
                number_format((float) $pr["net"], 2, ".", ""),
                number_format((float) $pr["vat"], 2, ".", ""),
                $share,
            ]);
        }
        csv_put_row($stream, [
            "TOTAL",
            "",
            "",
            "",
            $totalUnits,
            $totals["count"],
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
            $grossTotalFloat > 0 ? "100.00%" : "0.00%",
        ]);
        $filename = "VenueVue_product_sku_sales_{$from}_{$to}.csv";
    } else {
        // format === "structured" (Full Multi-Section Store Report)
        $voidTotals = query(
            $db,
            "SELECT COUNT(*) AS count,COALESCE(SUM(total_gross),0) AS gross,COALESCE(SUM(total_net),0) AS net,COALESCE(SUM(total_vat),0) AS vat FROM sales WHERE status='voided' AND sale_at>=? AND sale_at<?",
            [$from, $end],
        )->fetch();
        $voidCount = (int) $voidTotals["count"];

        $dailyFull = query(
            $db,
            "SELECT DATE(s.sale_at) AS date,
                    SUM(CASE WHEN s.status='completed' THEN 1 ELSE 0 END) AS completed_sales,
                    SUM(CASE WHEN s.status='voided' THEN 1 ELSE 0 END) AS voided_sales,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN si.sale_units ELSE 0 END),0) AS units_sold,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total_gross ELSE 0 END),0) AS gross,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total_net ELSE 0 END),0) AS net,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total_vat ELSE 0 END),0) AS vat
             FROM sales s
             LEFT JOIN (SELECT sale_id, SUM(qty) AS sale_units FROM sale_items GROUP BY sale_id) si ON si.sale_id=s.id
             WHERE s.sale_at>=? AND s.sale_at<?
             GROUP BY DATE(s.sale_at)
             ORDER BY date",
            [$from, $end],
        )->fetchAll();

        $productRows = query(
            $db,
            "SELECT COALESCE(v.sku,'-') AS sku,
                    COALESCE(p.name,i.variant_label) AS product_name,
                    COALESCE(p.category,'Uncategorized') AS category,
                    i.variant_label,
                    SUM(i.qty) AS units_sold,
                    COUNT(DISTINCT s.id) AS orders_count,
                    SUM(i.line_gross) AS gross,
                    SUM(i.line_net) AS net,
                    SUM(i.line_vat) AS vat
             FROM sale_items i
             JOIN sales s ON s.id=i.sale_id
             LEFT JOIN product_variants v ON v.id=i.variant_id
             LEFT JOIN products p ON p.id=v.product_id
             WHERE s.status='completed' AND s.sale_at>=? AND s.sale_at<?
             GROUP BY v.sku, p.name, p.category, i.variant_label
             ORDER BY gross DESC, units_sold DESC",
            [$from, $end],
        )->fetchAll();

        $cashierRows = query(
            $db,
            "SELECT u.display_name AS cashier,
                    u.role,
                    SUM(CASE WHEN s.status='completed' THEN 1 ELSE 0 END) AS completed_orders,
                    SUM(CASE WHEN s.status='voided' THEN 1 ELSE 0 END) AS voided_orders,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total_gross ELSE 0 END),0) AS gross,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total_net ELSE 0 END),0) AS net,
                    COALESCE(SUM(CASE WHEN s.status='completed' THEN s.total_vat ELSE 0 END),0) AS vat
             FROM sales s
             JOIN users u ON u.id=s.user_id
             WHERE s.sale_at>=? AND s.sale_at<?
             GROUP BY u.id, u.display_name, u.role
             ORDER BY gross DESC",
            [$from, $end],
        )->fetchAll();

        $aovGross = $totals["count"] > 0 ? number_format(((float) $totals["gross"]) / $totals["count"], 2, ".", "") : "0.00";
        $aovNet = $totals["count"] > 0 ? number_format(((float) $totals["net"]) / $totals["count"], 2, ".", "") : "0.00";
        $aovVat = $totals["count"] > 0 ? number_format(((float) $totals["vat"]) / $totals["count"], 2, ".", "") : "0.00";

        // Section 0: Report Title & Metadata
        csv_put_row($stream, ["NEARBY CAFE POS (VENUEVUE) - STRUCTURED SALES & Z-READING REPORT"]);
        csv_put_row($stream, ["Report Period", "{$from} through {$to}"]);
        csv_put_row($stream, ["Generated At (Asia/Manila)", date("Y-m-d H:i:s")]);
        csv_put_row($stream, ["Currency", "PHP"]);
        csv_put_row($stream, []);

        // Section 1: Executive Financial Summary
        csv_put_row($stream, ["[SECTION 1] EXECUTIVE FINANCIAL SUMMARY"]);
        csv_put_row($stream, ["metric", "count_or_units", "gross", "net", "vat", "notes"]);
        csv_put_row($stream, [
            "Completed Sales (Active Revenue)",
            $totals["count"],
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
            "Included in Z-Reading & VAT remittance",
        ]);
        csv_put_row($stream, [
            "Total Drink / Item Units Sold",
            $totalUnits,
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
            "Total quantity dispensed across completed sales",
        ]);
        csv_put_row($stream, [
            "Average Order Value (AOV)",
            $totals["count"],
            $aovGross,
            $aovNet,
            $aovVat,
            "Average gross, net, and VAT per completed order",
        ]);
        csv_put_row($stream, [
            "Voided Transactions",
            $voidCount,
            number_format((float) $voidTotals["gross"], 2, ".", ""),
            number_format((float) $voidTotals["net"], 2, ".", ""),
            number_format((float) $voidTotals["vat"], 2, ".", ""),
            "Excluded from active revenue totals",
        ]);
        csv_put_row($stream, []);

        // Section 2: Daily Summary / Z-Reading
        csv_put_row($stream, ["[SECTION 2] DAILY Z-READING BREAKDOWN"]);
        csv_put_row($stream, ["date", "completed_sales", "voided_sales", "units_sold", "gross", "net", "vat"]);
        foreach ($dailyFull as $d) {
            csv_put_row($stream, [
                $d["date"],
                (int) $d["completed_sales"],
                (int) $d["voided_sales"],
                (int) $d["units_sold"],
                number_format((float) $d["gross"], 2, ".", ""),
                number_format((float) $d["net"], 2, ".", ""),
                number_format((float) $d["vat"], 2, ".", ""),
            ]);
        }
        csv_put_row($stream, [
            "DAILY TOTAL",
            $totals["count"],
            $voidCount,
            $totalUnits,
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
        ]);
        csv_put_row($stream, []);

        // Section 3: Product & 5-Part SKU Breakdown
        csv_put_row($stream, ["[SECTION 3] PRODUCT & 5-PART SKU PERFORMANCE"]);
        csv_put_row($stream, ["sku", "product_name", "category", "variant", "units_sold", "orders_count", "gross", "net", "vat", "revenue_share_pct"]);
        $grossTotalFloat = (float) $totals["gross"];
        foreach ($productRows as $pr) {
            $share = $grossTotalFloat > 0
                ? number_format(((float) $pr["gross"] / $grossTotalFloat) * 100, 2, ".", "") . "%"
                : "0.00%";
            csv_put_row($stream, [
                csv_safe_cell($pr["sku"]),
                csv_safe_cell($pr["product_name"]),
                csv_safe_cell($pr["category"]),
                csv_safe_cell($pr["variant_label"]),
                (int) $pr["units_sold"],
                (int) $pr["orders_count"],
                number_format((float) $pr["gross"], 2, ".", ""),
                number_format((float) $pr["net"], 2, ".", ""),
                number_format((float) $pr["vat"], 2, ".", ""),
                $share,
            ]);
        }
        csv_put_row($stream, [
            "PRODUCT TOTAL",
            "",
            "",
            "",
            $totalUnits,
            $totals["count"],
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
            $grossTotalFloat > 0 ? "100.00%" : "0.00%",
        ]);
        csv_put_row($stream, []);

        // Section 4: Cashier Performance
        csv_put_row($stream, ["[SECTION 4] CASHIER / STAFF BREAKDOWN"]);
        csv_put_row($stream, ["cashier", "role", "completed_orders", "voided_orders", "gross", "net", "vat"]);
        foreach ($cashierRows as $cr) {
            csv_put_row($stream, [
                csv_safe_cell($cr["cashier"]),
                $cr["role"],
                (int) $cr["completed_orders"],
                (int) $cr["voided_orders"],
                number_format((float) $cr["gross"], 2, ".", ""),
                number_format((float) $cr["net"], 2, ".", ""),
                number_format((float) $cr["vat"], 2, ".", ""),
            ]);
        }
        csv_put_row($stream, [
            "CASHIER TOTAL",
            "",
            $totals["count"],
            $voidCount,
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
        ]);
        csv_put_row($stream, []);

        // Section 5: Detailed Line-Item Sales Ledger
        csv_put_row($stream, ["[SECTION 5] DETAILED LINE-ITEM TRANSACTIONS (COMPLETED)"]);
        csv_put_row($stream, [
            "sale_id",
            "sale_at",
            "cashier",
            "sku",
            "category",
            "variant",
            "qty",
            "unit_price",
            "gross",
            "net",
            "vat",
            "vat_rate",
            "cash_received",
            "change_due",
        ]);
        foreach ($rows as $row) {
            csv_put_row($stream, [
                $row["sale_id"],
                $row["sale_at"],
                csv_safe_cell($row["cashier"]),
                csv_safe_cell($row["sku"]),
                csv_safe_cell($row["category"]),
                csv_safe_cell($row["variant_label"]),
                $row["qty"],
                $row["unit_price"],
                $row["gross"],
                $row["net"],
                $row["vat"],
                $row["vat_rate"],
                $row["cash_received"],
                $row["change_due"],
            ]);
        }
        csv_put_row($stream, [
            "TOTAL",
            "",
            "",
            "",
            "",
            "",
            $totalUnits,
            "",
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
            "",
            "",
            "",
        ]);

        // Section 6: Voided Sales Audit (Optional)
        if ($includeVoided) {
            $voidRows = query(
                $db,
                "SELECT s.id AS sale_id,s.sale_at,u.display_name AS cashier,COALESCE(vb.display_name,'Owner') AS voided_by_name,s.voided_at,s.void_reason,s.total_gross AS gross,s.total_net AS net,s.total_vat AS vat FROM sales s JOIN users u ON u.id=s.user_id LEFT JOIN users vb ON vb.id=s.voided_by WHERE s.status='voided' AND s.sale_at>=? AND s.sale_at<? ORDER BY s.sale_at,s.id",
                [$from, $end],
            )->fetchAll();
            csv_put_row($stream, []);
            csv_put_row($stream, ["[SECTION 6] VOIDED TRANSACTIONS AUDIT"]);
            csv_put_row($stream, [
                "sale_id",
                "sale_at",
                "cashier",
                "voided_by",
                "voided_at",
                "void_reason",
                "voided_gross",
                "voided_net",
                "voided_vat",
            ]);
            foreach ($voidRows as $vr) {
                csv_put_row($stream, [
                    $vr["sale_id"],
                    $vr["sale_at"],
                    csv_safe_cell($vr["cashier"]),
                    csv_safe_cell($vr["voided_by_name"]),
                    $vr["voided_at"] ?? "",
                    csv_safe_cell($vr["void_reason"] ?? ""),
                    $vr["gross"],
                    $vr["net"],
                    $vr["vat"],
                ]);
            }
            csv_put_row($stream, [
                "VOIDED TOTAL",
                "",
                "",
                "",
                "",
                "",
                number_format((float) $voidTotals["gross"], 2, ".", ""),
                number_format((float) $voidTotals["net"], 2, ".", ""),
                number_format((float) $voidTotals["vat"], 2, ".", ""),
            ]);
        }

        $filename = "VenueVue_structured_report_{$from}_{$to}.csv";
    }

    rewind($stream);
    $content = stream_get_contents($stream);
    fclose($stream);
    return [
        "filename" => $filename,
        "content" => $content,
    ];
}

function history_data(PDO $db, array $params, bool $stock = false): array
{
    [$from, $to, $end] = period($params);
    $page = positive_id($params["page"] ?? 1, "Page", 100000);
    $offset = ($page - 1) * 50;
    $args = [$from, $end];
    if ($stock) {
        $where = "m.created_at>=? AND m.created_at<?";
        if (!empty($params["ingredient_id"])) {
            $where .= " AND m.ingredient_id=?";
            $args[] = positive_id($params["ingredient_id"]);
        }
        if (!empty($params["type"])) {
            if (
                !in_array(
                    $params["type"],
                    ["SALE", "RECEIVE", "WASTE", "ADJUSTMENT"],
                    true,
                )
            ) {
                problem(422, "VALIDATION", "Invalid movement type.");
            }
            $where .= " AND m.type=?";
            $args[] = $params["type"];
        }
        $total = (int) query(
            $db,
            "SELECT COUNT(*) FROM stock_movements m WHERE $where",
            $args,
        )->fetchColumn();
        $rows = query(
            $db,
            "SELECT m.*,i.name AS ingredient_name,i.unit,u.display_name AS actor FROM stock_movements m JOIN ingredients i ON i.id=m.ingredient_id JOIN users u ON u.id=m.user_id WHERE $where ORDER BY m.id DESC LIMIT 50 OFFSET $offset",
            $args,
        )->fetchAll();
    } else {
        $where = "a.created_at>=? AND a.created_at<?";
        if (!empty($params["action"])) {
            $where .= " AND a.action LIKE ?";
            $args[] =
                "%" .
                str_replace(
                    ["\\", "%", "_"],
                    ["\\\\", "\\%", "\\_"],
                    label_text($params["action"], "Action", 64),
                ) .
                "%";
        }
        $total = (int) query(
            $db,
            "SELECT COUNT(*) FROM audit_events a WHERE $where",
            $args,
        )->fetchColumn();
        $rows = query(
            $db,
            "SELECT a.*,u.display_name AS actor,d.details FROM audit_events a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN audit_details d ON d.audit_id=a.id WHERE $where ORDER BY a.id DESC LIMIT 50 OFFSET $offset",
            $args,
        )->fetchAll();
    }
    return [
        "from" => $from,
        "to" => $to,
        "page" => $page,
        "total" => $total,
        "rows" => $rows,
    ];
}

function reports_data(PDO $db, mixed $params): array
{
    $p = is_array($params) ? $params : (is_string($params) ? ['view' => $params] : []);
    return report_data($db, $p, false);
}

function serve_csv_export(PDO $db, string $type = ""): never
{
    $csv = report_data($db, $_GET, true);
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . $csv["filename"] . "\"");
    echo $csv["content"];
    exit();
}
