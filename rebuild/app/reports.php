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
function report_data(PDO $db, array $params, bool $csv = false): array
{
    [$from, $to, $end] = period($params);
    $page = positive_id($params["page"] ?? 1, "Page", 100000);
    $offset = ($page - 1) * 50;
    $totals = query(
        $db,
        "SELECT COUNT(*) AS count,COALESCE(SUM(total_gross),0) AS gross,COALESCE(SUM(total_net),0) AS net,COALESCE(SUM(total_vat),0) AS vat FROM sales WHERE sale_at>=? AND sale_at<?",
        [$from, $end],
    )->fetch();
    $totals["count"] = (int) $totals["count"];
    $daily = query(
        $db,
        "SELECT DATE(sale_at) AS date,COUNT(*) AS count,SUM(total_gross) AS gross,SUM(total_net) AS net,SUM(total_vat) AS vat FROM sales WHERE sale_at>=? AND sale_at<? GROUP BY DATE(sale_at) ORDER BY date",
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
                "SELECT s.*,u.display_name AS cashier FROM sales s JOIN users u ON u.id=s.user_id WHERE sale_at>=? AND sale_at<? ORDER BY sale_at DESC,s.id DESC LIMIT 50 OFFSET $offset",
                [$from, $end],
            )->fetchAll(),
        ];
    }
    $count = (int) query(
        $db,
        "SELECT COUNT(*) FROM sale_items i JOIN sales s ON s.id=i.sale_id WHERE s.sale_at>=? AND s.sale_at<?",
        [$from, $end],
    )->fetchColumn();
    if ($count > 50000) {
        problem(
            422,
            "EXPORT_LIMIT",
            "Choose a shorter period; this export exceeds 50,000 lines.",
        );
    }
    $rows = query(
        $db,
        "SELECT s.id AS sale_id,s.sale_at,u.display_name AS cashier,i.variant_label,i.qty,i.unit_price,i.line_gross AS gross,i.line_net AS net,i.line_vat AS vat,s.vat_rate FROM sale_items i JOIN sales s ON s.id=i.sale_id JOIN users u ON u.id=s.user_id WHERE s.sale_at>=? AND s.sale_at<? ORDER BY s.sale_at,s.id,i.id",
        [$from, $end],
    )->fetchAll();
    $stream = fopen("php://temp", "w+");
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv(
        $stream,
        [
            "sale_id",
            "sale_at",
            "cashier",
            "variant",
            "qty",
            "unit_price",
            "gross",
            "net",
            "vat",
            "vat_rate",
        ],
        ",",
        '"',
        "",
    );
    foreach ($rows as $row) {
        foreach (["cashier", "variant_label"] as $field) {
            if (preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $row[$field])) {
                $row[$field] = "'" . $row[$field];
            }
        }
        fputcsv($stream, array_values($row), ",", '"', "");
    }
    fputcsv(
        $stream,
        [
            "TOTAL",
            "",
            "",
            "",
            "",
            "",
            $totals["gross"],
            $totals["net"],
            $totals["vat"],
            "",
        ],
        ",",
        '"',
        "",
    );
    rewind($stream);
    $content = stream_get_contents($stream);
    fclose($stream);
    return [
        "filename" => "VenueVue_sales_{$from}_{$to}.csv",
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
