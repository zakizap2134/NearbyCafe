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

/**
 * Pure-PHP in-memory ZIP archive builder (no ext-zip required; works on all hosts).
 *
 * @param array<string, string> $files Map of internal path => raw file content
 */
function build_zip_bytes(array $files): string
{
    $localParts = [];
    $centralParts = [];
    $offset = 0;
    $count = 0;

    foreach ($files as $name => $data) {
        $name = str_replace("\\", "/", $name);
        $nameLen = strlen($name);
        $uncompressedSize = strlen($data);
        $crc = crc32($data);
        $compressed = gzdeflate($data, 6);
        $compressedSize = strlen($compressed);

        // Local file header
        $localHeader = pack(
            "VvvvvvVVVvv",
            0x04034b50,
            20,
            0,
            8,
            0,
            0,
            $crc,
            $compressedSize,
            $uncompressedSize,
            $nameLen,
            0,
        ) . $name . $compressed;

        // Central directory file header
        $centralParts[] = pack(
            "VvvvvvvVVVvvvvvVV",
            0x02014b50,
            20,
            20,
            0,
            8,
            0,
            0,
            $crc,
            $compressedSize,
            $uncompressedSize,
            $nameLen,
            0,
            0,
            0,
            0,
            32,
            $offset,
        ) . $name;

        $localParts[] = $localHeader;
        $offset += strlen($localHeader);
        $count++;
    }

    $centralDir = implode("", $centralParts);
    $centralSize = strlen($centralDir);
    $eocd = pack(
        "VvvvvVVv",
        0x06054b50,
        0,
        0,
        $count,
        $count,
        $centralSize,
        $offset,
        0,
    );

    return implode("", $localParts) . $centralDir . $eocd;
}

function xlsx_col_letter(int $colIndex1Based): string
{
    $letter = "";
    while ($colIndex1Based > 0) {
        $mod = ($colIndex1Based - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $colIndex1Based = intdiv($colIndex1Based - $mod, 26);
    }
    return $letter;
}

function xlsx_xml_escape(string $val): string
{
    $clean = preg_replace('/[^\x09\x0A\x0D\x20-\u{D7FF}\u{E000}-\u{FFFD}]/u', '', $val) ?? $val;
    return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, "UTF-8");
}

/**
 * Builds a styled OpenXML (.xlsx) workbook with auto-fitted wide columns,
 * Nearby Café brand colors, borders, and PHP currency formatting.
 */
function build_styled_xlsx(
    string $from,
    string $to,
    array $totals,
    int $totalUnits,
    array $voidTotals,
    array $dailyFull,
    array $productRows,
    array $cashierRows,
    array $rows,
    array $voidRows,
    bool $includeVoided,
): string {
    $sheetRows = [];
    $merges = [];
    $rowNum = 1;

    $addRow = function (array $cells, ?float $height = null) use (&$sheetRows, &$rowNum): int {
        $current = $rowNum++;
        $htAttr = $height !== null ? ' ht="' . $height . '" customHeight="1"' : '';
        $xml = '<row r="' . $current . '"' . $htAttr . '>';
        foreach ($cells as $idx => $cell) {
            $colRef = xlsx_col_letter($idx + 1) . $current;
            $style = (int) ($cell["s"] ?? 0);
            $type = $cell["t"] ?? "str";
            $val = $cell["v"] ?? "";
            if ($val === "" && $type === "blank") {
                $xml .= '<c r="' . $colRef . '" s="' . $style . '"/>';
            } elseif ($type === "n") {
                $num = is_numeric($val) ? (string) $val : "0";
                $xml .= '<c r="' . $colRef . '" s="' . $style . '"><v>' . $num . '</v></c>';
            } else {
                $safe = csv_safe_cell((string) $val);
                $xml .= '<c r="' . $colRef . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml_escape($safe) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
        $sheetRows[] = $xml;
        return $current;
    };

    $addBanner = function (string $title, int $styleIdx, int $spanCols = 10, float $height = 26.0) use ($addRow, &$merges): void {
        $cells = [["v" => "  " . $title, "s" => $styleIdx, "t" => "str"]];
        for ($i = 2; $i <= $spanCols; $i++) {
            $cells[] = ["v" => "", "s" => $styleIdx, "t" => "blank"];
        }
        $r = $addRow($cells, $height);
        $merges[] = "A{$r}:" . xlsx_col_letter($spanCols) . "{$r}";
    };

    $padTotalCells = function (array $cells, int $totalCols, int $styleIdx): array {
        while (count($cells) < $totalCols) {
            $cells[] = ["v" => "", "s" => $styleIdx, "t" => "blank"];
        }
        return $cells;
    };

    // Title Banner
    $addBanner("NEARBY CAFÉ POS (VENUEVUE) — STRUCTURED SALES & Z-READING REPORT", 1, 14, 34.0);

    // Metadata Rows
    $addRow([
        ["v" => "Report Period", "s" => 2, "t" => "str"],
        ["v" => "{$from} through {$to}", "s" => 3, "t" => "str"],
        ["v" => "Generated At (Manila)", "s" => 2, "t" => "str"],
        ["v" => date("M d, Y h:i:s A"), "s" => 3, "t" => "str"],
        ["v" => "Currency", "s" => 2, "t" => "str"],
        ["v" => "PHP (₱) · 12% VAT", "s" => 3, "t" => "str"],
    ], 22.0);
    $addRow([], 10.0);

    // Section 1: Executive Financial Summary
    $voidCount = (int) $voidTotals["count"];
    $aovGross = $totals["count"] > 0 ? round(((float) $totals["gross"]) / $totals["count"], 2) : 0.0;
    $aovNet = $totals["count"] > 0 ? round(((float) $totals["net"]) / $totals["count"], 2) : 0.0;
    $aovVat = $totals["count"] > 0 ? round(((float) $totals["vat"]) / $totals["count"], 2) : 0.0;

    $addBanner("SECTION 1 · EXECUTIVE FINANCIAL SUMMARY", 4, 6, 25.0);
    $addRow([
        ["v" => "Financial Metric", "s" => 5, "t" => "str"],
        ["v" => "Orders / Units", "s" => 6, "t" => "str"],
        ["v" => "Gross Sales (₱)", "s" => 6, "t" => "str"],
        ["v" => "VATable Net (₱)", "s" => 6, "t" => "str"],
        ["v" => "VAT 12% (₱)", "s" => 6, "t" => "str"],
        ["v" => "Audit Notes", "s" => 5, "t" => "str"],
    ], 22.0);
    $addRow([
        ["v" => "Completed Sales (Active Revenue)", "s" => 10, "t" => "str"],
        ["v" => $totals["count"], "s" => 11, "t" => "n"],
        ["v" => (float) $totals["gross"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["net"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["vat"], "s" => 12, "t" => "n"],
        ["v" => "Included in Z-Reading & VAT remittance", "s" => 7, "t" => "str"],
    ], 21.0);
    $addRow([
        ["v" => "Physical Cash in Drawer (Cash Sales)", "s" => 7, "t" => "str"],
        ["v" => (int) ($totals["cash_drawer_count"] ?? $totals["count"]), "s" => 8, "t" => "n"],
        ["v" => (float) ($totals["cash_drawer_gross"] ?? $totals["gross"]), "s" => 9, "t" => "n"],
        ["v" => (float) ($totals["cash_drawer_net"] ?? $totals["net"]), "s" => 9, "t" => "n"],
        ["v" => (float) ($totals["cash_drawer_vat"] ?? $totals["vat"]), "s" => 9, "t" => "n"],
        ["v" => "Expected physical paper cash from Cash orders", "s" => 7, "t" => "str"],
    ], 20.0);
    $addRow([
        ["v" => "Online / Cashless (GCash · Maya · QR/Card)", "s" => 7, "t" => "str"],
        ["v" => (int) ($totals["online_count"] ?? 0), "s" => 8, "t" => "n"],
        ["v" => (float) ($totals["online_gross"] ?? 0), "s" => 9, "t" => "n"],
        ["v" => (float) ($totals["online_net"] ?? 0), "s" => 9, "t" => "n"],
        ["v" => (float) ($totals["online_vat"] ?? 0), "s" => 9, "t" => "n"],
        ["v" => "Settled via GCash, Maya, QR Ph or Card", "s" => 7, "t" => "str"],
    ], 20.0);
    $addRow([
        ["v" => "Total Drink / Item Units Sold", "s" => 7, "t" => "str"],
        ["v" => $totalUnits, "s" => 8, "t" => "n"],
        ["v" => (float) $totals["gross"], "s" => 9, "t" => "n"],
        ["v" => (float) $totals["net"], "s" => 9, "t" => "n"],
        ["v" => (float) $totals["vat"], "s" => 9, "t" => "n"],
        ["v" => "Total quantity dispensed across completed sales", "s" => 7, "t" => "str"],
    ], 20.0);
    $addRow([
        ["v" => "Average Order Value (AOV)", "s" => 7, "t" => "str"],
        ["v" => $totals["count"], "s" => 8, "t" => "n"],
        ["v" => $aovGross, "s" => 9, "t" => "n"],
        ["v" => $aovNet, "s" => 9, "t" => "n"],
        ["v" => $aovVat, "s" => 9, "t" => "n"],
        ["v" => "Average gross, net, and VAT per completed order", "s" => 7, "t" => "str"],
    ], 20.0);
    $addRow([
        ["v" => "Voided Transactions", "s" => 7, "t" => "str"],
        ["v" => $voidCount, "s" => 8, "t" => "n"],
        ["v" => (float) $voidTotals["gross"], "s" => 13, "t" => "n"],
        ["v" => (float) $voidTotals["net"], "s" => 13, "t" => "n"],
        ["v" => (float) $voidTotals["vat"], "s" => 13, "t" => "n"],
        ["v" => "Excluded from active revenue totals", "s" => 7, "t" => "str"],
    ], 20.0);
    $addRow([], 12.0);

    // Section 2: Daily Z-Reading Breakdown
    $addBanner("SECTION 2 · DAILY Z-READING BREAKDOWN", 4, 7, 25.0);
    $addRow([
        ["v" => "Sale Date", "s" => 5, "t" => "str"],
        ["v" => "Completed Orders", "s" => 6, "t" => "str"],
        ["v" => "Voided Orders", "s" => 6, "t" => "str"],
        ["v" => "Units Sold", "s" => 6, "t" => "str"],
        ["v" => "Gross Sales (₱)", "s" => 6, "t" => "str"],
        ["v" => "VATable Net (₱)", "s" => 6, "t" => "str"],
        ["v" => "VAT 12% (₱)", "s" => 6, "t" => "str"],
    ], 22.0);
    foreach ($dailyFull as $d) {
        $addRow([
            ["v" => $d["date"], "s" => 7, "t" => "str"],
            ["v" => (int) $d["completed_sales"], "s" => 8, "t" => "n"],
            ["v" => (int) $d["voided_sales"], "s" => 8, "t" => "n"],
            ["v" => (int) $d["units_sold"], "s" => 8, "t" => "n"],
            ["v" => (float) $d["gross"], "s" => 9, "t" => "n"],
            ["v" => (float) $d["net"], "s" => 9, "t" => "n"],
            ["v" => (float) $d["vat"], "s" => 9, "t" => "n"],
        ], 20.0);
    }
    $addRow([
        ["v" => "DAILY TOTAL", "s" => 10, "t" => "str"],
        ["v" => $totals["count"], "s" => 11, "t" => "n"],
        ["v" => $voidCount, "s" => 11, "t" => "n"],
        ["v" => $totalUnits, "s" => 11, "t" => "n"],
        ["v" => (float) $totals["gross"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["net"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["vat"], "s" => 12, "t" => "n"],
    ], 22.0);
    $addRow([], 12.0);

    // Section 3: Product & 5-Part SKU Performance
    $addBanner("SECTION 3 · PRODUCT & 5-PART SKU PERFORMANCE", 4, 10, 25.0);
    $addRow([
        ["v" => "5-Part SKU", "s" => 5, "t" => "str"],
        ["v" => "Product Name", "s" => 5, "t" => "str"],
        ["v" => "Category", "s" => 5, "t" => "str"],
        ["v" => "Variant Choice", "s" => 5, "t" => "str"],
        ["v" => "Units Sold", "s" => 6, "t" => "str"],
        ["v" => "Orders Count", "s" => 6, "t" => "str"],
        ["v" => "Gross Sales (₱)", "s" => 6, "t" => "str"],
        ["v" => "VATable Net (₱)", "s" => 6, "t" => "str"],
        ["v" => "VAT 12% (₱)", "s" => 6, "t" => "str"],
        ["v" => "Revenue Share", "s" => 6, "t" => "str"],
    ], 22.0);
    $grossTotalFloat = (float) $totals["gross"];
    foreach ($productRows as $pr) {
        $share = $grossTotalFloat > 0
            ? number_format(((float) $pr["gross"] / $grossTotalFloat) * 100, 2, ".", "") . "%"
            : "0.00%";
        $addRow([
            ["v" => $pr["sku"], "s" => 7, "t" => "str"],
            ["v" => $pr["product_name"], "s" => 7, "t" => "str"],
            ["v" => $pr["category"], "s" => 7, "t" => "str"],
            ["v" => $pr["variant_label"], "s" => 7, "t" => "str"],
            ["v" => (int) $pr["units_sold"], "s" => 8, "t" => "n"],
            ["v" => (int) $pr["orders_count"], "s" => 8, "t" => "n"],
            ["v" => (float) $pr["gross"], "s" => 9, "t" => "n"],
            ["v" => (float) $pr["net"], "s" => 9, "t" => "n"],
            ["v" => (float) $pr["vat"], "s" => 9, "t" => "n"],
            ["v" => $share, "s" => 8, "t" => "str"],
        ], 20.0);
    }
    $addRow([
        ["v" => "PRODUCT TOTAL", "s" => 10, "t" => "str"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => $totalUnits, "s" => 11, "t" => "n"],
        ["v" => $totals["count"], "s" => 11, "t" => "n"],
        ["v" => (float) $totals["gross"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["net"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["vat"], "s" => 12, "t" => "n"],
        ["v" => $grossTotalFloat > 0 ? "100.00%" : "0.00%", "s" => 11, "t" => "str"],
    ], 22.0);
    $addRow([], 12.0);

    // Section 4: Cashier / Staff Breakdown
    $addBanner("SECTION 4 · CASHIER & STAFF PERFORMANCE", 4, 7, 25.0);
    $addRow([
        ["v" => "Cashier Name", "s" => 5, "t" => "str"],
        ["v" => "Role", "s" => 5, "t" => "str"],
        ["v" => "Completed Orders", "s" => 6, "t" => "str"],
        ["v" => "Voided Orders", "s" => 6, "t" => "str"],
        ["v" => "Gross Sales (₱)", "s" => 6, "t" => "str"],
        ["v" => "VATable Net (₱)", "s" => 6, "t" => "str"],
        ["v" => "VAT 12% (₱)", "s" => 6, "t" => "str"],
    ], 22.0);
    foreach ($cashierRows as $cr) {
        $addRow([
            ["v" => $cr["cashier"], "s" => 7, "t" => "str"],
            ["v" => $cr["role"], "s" => 7, "t" => "str"],
            ["v" => (int) $cr["completed_orders"], "s" => 8, "t" => "n"],
            ["v" => (int) $cr["voided_orders"], "s" => 8, "t" => "n"],
            ["v" => (float) $cr["gross"], "s" => 9, "t" => "n"],
            ["v" => (float) $cr["net"], "s" => 9, "t" => "n"],
            ["v" => (float) $cr["vat"], "s" => 9, "t" => "n"],
        ], 20.0);
    }
    $addRow([
        ["v" => "CASHIER TOTAL", "s" => 10, "t" => "str"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => $totals["count"], "s" => 11, "t" => "n"],
        ["v" => $voidCount, "s" => 11, "t" => "n"],
        ["v" => (float) $totals["gross"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["net"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["vat"], "s" => 12, "t" => "n"],
    ], 22.0);
    $addRow([], 12.0);

    // Section 5: Detailed Line-Item Transactions
    $addBanner("SECTION 5 · DETAILED LINE-ITEM TRANSACTIONS (COMPLETED)", 4, 14, 25.0);
    $addRow([
        ["v" => "Sale #", "s" => 5, "t" => "str"],
        ["v" => "Sale Date & Time", "s" => 5, "t" => "str"],
        ["v" => "Cashier", "s" => 5, "t" => "str"],
        ["v" => "5-Part SKU", "s" => 5, "t" => "str"],
        ["v" => "Category", "s" => 5, "t" => "str"],
        ["v" => "Variant Item", "s" => 5, "t" => "str"],
        ["v" => "Qty", "s" => 6, "t" => "str"],
        ["v" => "Unit Price (₱)", "s" => 6, "t" => "str"],
        ["v" => "Line Gross (₱)", "s" => 6, "t" => "str"],
        ["v" => "Line Net (₱)", "s" => 6, "t" => "str"],
        ["v" => "Line VAT (₱)", "s" => 6, "t" => "str"],
        ["v" => "VAT Rate", "s" => 6, "t" => "str"],
        ["v" => "Cash Tendered (₱)", "s" => 6, "t" => "str"],
        ["v" => "Change Given (₱)", "s" => 6, "t" => "str"],
    ], 22.0);
    foreach ($rows as $r) {
        $addRow([
            ["v" => "#" . $r["sale_id"], "s" => 7, "t" => "str"],
            ["v" => $r["sale_at"], "s" => 7, "t" => "str"],
            ["v" => $r["cashier"], "s" => 7, "t" => "str"],
            ["v" => $r["sku"], "s" => 7, "t" => "str"],
            ["v" => $r["category"], "s" => 7, "t" => "str"],
            ["v" => $r["variant_label"], "s" => 7, "t" => "str"],
            ["v" => (int) $r["qty"], "s" => 8, "t" => "n"],
            ["v" => (float) $r["unit_price"], "s" => 9, "t" => "n"],
            ["v" => (float) $r["gross"], "s" => 9, "t" => "n"],
            ["v" => (float) $r["net"], "s" => 9, "t" => "n"],
            ["v" => (float) $r["vat"], "s" => 9, "t" => "n"],
            ["v" => ((float) $r["vat_rate"] * 100) . "%", "s" => 8, "t" => "str"],
            ["v" => (float) $r["cash_received"], "s" => 9, "t" => "n"],
            ["v" => (float) $r["change_due"], "s" => 9, "t" => "n"],
        ], 20.0);
    }
    $addRow($padTotalCells([
        ["v" => "TOTAL", "s" => 10, "t" => "str"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => $totalUnits, "s" => 11, "t" => "n"],
        ["v" => "", "s" => 10, "t" => "blank"],
        ["v" => (float) $totals["gross"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["net"], "s" => 12, "t" => "n"],
        ["v" => (float) $totals["vat"], "s" => 12, "t" => "n"],
    ], 14, 10), 22.0);

    // Section 6: Voided Sales Audit
    if ($includeVoided) {
        $addRow([], 12.0);
        $addBanner("SECTION 6 · VOIDED TRANSACTIONS AUDIT LOG", 4, 9, 25.0);
        $addRow([
            ["v" => "Sale #", "s" => 5, "t" => "str"],
            ["v" => "Sale Date & Time", "s" => 5, "t" => "str"],
            ["v" => "Cashier", "s" => 5, "t" => "str"],
            ["v" => "Voided By", "s" => 5, "t" => "str"],
            ["v" => "Voided At", "s" => 5, "t" => "str"],
            ["v" => "Void Reason", "s" => 5, "t" => "str"],
            ["v" => "Voided Gross (₱)", "s" => 6, "t" => "str"],
            ["v" => "Voided Net (₱)", "s" => 6, "t" => "str"],
            ["v" => "Voided VAT (₱)", "s" => 6, "t" => "str"],
        ], 22.0);
        foreach ($voidRows as $vr) {
            $addRow([
                ["v" => "#" . $vr["sale_id"], "s" => 7, "t" => "str"],
                ["v" => $vr["sale_at"], "s" => 7, "t" => "str"],
                ["v" => $vr["cashier"], "s" => 7, "t" => "str"],
                ["v" => $vr["voided_by_name"], "s" => 7, "t" => "str"],
                ["v" => $vr["voided_at"] ?? "—", "s" => 7, "t" => "str"],
                ["v" => $vr["void_reason"] ?? "—", "s" => 7, "t" => "str"],
                ["v" => (float) $vr["gross"], "s" => 13, "t" => "n"],
                ["v" => (float) $vr["net"], "s" => 13, "t" => "n"],
                ["v" => (float) $vr["vat"], "s" => 13, "t" => "n"],
            ], 20.0);
        }
        $addRow([
            ["v" => "VOIDED TOTAL", "s" => 13, "t" => "str"],
            ["v" => "", "s" => 13, "t" => "blank"],
            ["v" => "", "s" => 13, "t" => "blank"],
            ["v" => "", "s" => 13, "t" => "blank"],
            ["v" => "", "s" => 13, "t" => "blank"],
            ["v" => "", "s" => 13, "t" => "blank"],
            ["v" => (float) $voidTotals["gross"], "s" => 13, "t" => "n"],
            ["v" => (float) $voidTotals["net"], "s" => 13, "t" => "n"],
            ["v" => (float) $voidTotals["vat"], "s" => 13, "t" => "n"],
        ], 22.0);
    }

    // Explicit generous column widths so Excel never shows ######## or cuts off text
    $colWidths = [
        1 => 34, // Col A: Metric / SKU / Sale #
        2 => 30, // Col B: Period / Date & Time / Product Name
        3 => 24, // Col C: Cashier / Category / Gross
        4 => 28, // Col D: Variant / SKU / Net
        5 => 22, // Col E: Units / Voided At / VAT
        6 => 46, // Col F: Notes / Variant Item / Void Reason
        7 => 18, // Col G: Gross Sales (₱) / Qty
        8 => 18, // Col H: Net Sales (₱) / Unit Price
        9 => 18, // Col I: VAT (₱) / Line Gross
        10 => 18, // Col J: Revenue Share / Line Net
        11 => 16, // Col K: Line VAT
        12 => 14, // Col L: VAT Rate
        13 => 20, // Col M: Cash Tendered
        14 => 20, // Col N: Change Given
    ];
    $colsXml = "<cols>";
    foreach ($colWidths as $cIdx => $w) {
        $colsXml .= '<col min="' . $cIdx . '" max="' . $cIdx . '" width="' . $w . '" customWidth="1"/>';
    }
    $colsXml .= "</cols>";

    $mergeXml = "";
    if (!empty($merges)) {
        $mergeXml = '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $ref) {
            $mergeXml .= '<mergeCell ref="' . $ref . '"/>';
        }
        $mergeXml .= '</mergeCells>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="1"/></sheetViews>'
        . $colsXml
        . '<sheetData>' . implode("", $sheetRows) . '</sheetData>'
        . $mergeXml
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2">'
        . '<numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/>'
        . '<numFmt numFmtId="165" formatCode="#,##0"/>'
        . '</numFmts>'
        . '<fonts count="6">'
        . '<font><sz val="11"/><color rgb="FF25352E"/><name val="Segoe UI"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FF39211B"/><name val="Segoe UI"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF1F6B45"/><name val="Segoe UI"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF8C302B"/><name val="Segoe UI"/></font>'
        . '</fonts>'
        . '<fills count="8">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFA93226"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF28483D"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF4ECE6"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF4EC"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFBEAE8"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFDF8"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="3">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border>'
        . '<left style="thin"><color rgb="FFDFCFC5"/></left>'
        . '<right style="thin"><color rgb="FFDFCFC5"/></right>'
        . '<top style="thin"><color rgb="FFDFCFC5"/></top>'
        . '<bottom style="thin"><color rgb="FFDFCFC5"/></bottom>'
        . '<diagonal/>'
        . '</border>'
        . '<border>'
        . '<left style="thin"><color rgb="FFDFCFC5"/></left>'
        . '<right style="thin"><color rgb="FFDFCFC5"/></right>'
        . '<top style="thin"><color rgb="FF28483D"/></top>'
        . '<bottom style="double"><color rgb="FF28483D"/></bottom>'
        . '<diagonal/>'
        . '</border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="14">'
        // 0: default
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        // 1: Main Title Banner (#A93226)
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        // 2: Metadata Label
        . '<xf numFmtId="0" fontId="3" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        // 3: Metadata Value
        . '<xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        // 4: Section Banner (#28483D)
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        // 5: Table Header Left (#F4ECE6)
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        // 6: Table Header Right (#F4ECE6)
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        // 7: Data Text Cell
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        // 8: Data Integer Cell
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        // 9: Data Currency Cell (₱#,##0.00)
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        // 10: Total Label Cell (#EAF4EC)
        . '<xf numFmtId="0" fontId="4" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        // 11: Total Integer Cell (#EAF4EC)
        . '<xf numFmtId="165" fontId="4" fillId="5" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        // 12: Total Currency Cell (#EAF4EC)
        . '<xf numFmtId="164" fontId="4" fillId="5" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        // 13: Voided Currency / Total Cell (#FBEAE8)
        . '<xf numFmtId="164" fontId="5" fillId="6" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '</cellXfs>'
        . '</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sales &amp; Z-Reading Report" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    return build_zip_bytes([
        "[Content_Types].xml" => $contentTypes,
        "_rels/.rels" => $rootRels,
        "xl/workbook.xml" => $workbookXml,
        "xl/_rels/workbook.xml.rels" => $workbookRels,
        "xl/styles.xml" => $stylesXml,
        "xl/worksheets/sheet1.xml" => $sheetXml,
    ]);
}

function report_data(PDO $db, array $params, bool $csv = false): array
{
    [$from, $to, $end] = period($params);
    $page = positive_id($params["page"] ?? 1, "Page", 100000);
    $offset = ($page - 1) * 50;
    $totals = query(
        $db,
        "SELECT COUNT(*) AS count,COALESCE(SUM(total_gross),0) AS gross,COALESCE(SUM(total_net),0) AS net,COALESCE(SUM(total_vat),0) AS vat,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')='CASH' THEN 1 ELSE 0 END),0) AS cash_drawer_count,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')='CASH' THEN total_gross ELSE 0 END),0) AS cash_drawer_gross,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')='CASH' THEN total_net ELSE 0 END),0) AS cash_drawer_net,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')='CASH' THEN total_vat ELSE 0 END),0) AS cash_drawer_vat,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')<>'CASH' THEN 1 ELSE 0 END),0) AS online_count,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')<>'CASH' THEN total_gross ELSE 0 END),0) AS online_gross,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')<>'CASH' THEN total_net ELSE 0 END),0) AS online_net,COALESCE(SUM(CASE WHEN COALESCE(payment_method,'CASH')<>'CASH' THEN total_vat ELSE 0 END),0) AS online_vat FROM sales WHERE status='completed' AND sale_at>=? AND sale_at<?",
        [$from, $end],
    )->fetch();
    $totals["count"] = (int) $totals["count"];
    $totals["cash_drawer_count"] = (int) $totals["cash_drawer_count"];
    $totals["online_count"] = (int) $totals["online_count"];
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
    if (!in_array($format, ["xlsx_structured", "structured", "line_items", "daily_z", "products"], true)) {
        $format = "line_items";
    }
    $includeVoided = !isset($params["include_voided"]) || (string) $params["include_voided"] !== "0";

    $rows = query(
        $db,
        "SELECT s.id AS sale_id,s.sale_at,u.display_name AS cashier,s.payment_method,s.payment_reference,s.customer_name,COALESCE(v.sku,'-') AS sku,COALESCE(p.name,i.variant_label) AS product_name,COALESCE(p.category,'Uncategorized') AS category,i.variant_label,i.qty,i.unit_price,i.line_gross AS gross,i.line_net AS net,i.line_vat AS vat,s.vat_rate,s.cash_received,s.change_due FROM sale_items i JOIN sales s ON s.id=i.sale_id JOIN users u ON u.id=s.user_id LEFT JOIN product_variants v ON v.id=i.variant_id LEFT JOIN products p ON p.id=v.product_id WHERE s.status='completed' AND s.sale_at>=? AND s.sale_at<? ORDER BY s.sale_at,s.id,i.id",
        [$from, $end],
    )->fetchAll();

    $totalUnits = 0;
    foreach ($rows as $r) {
        $totalUnits += (int) $r["qty"];
    }

    if ($format === "xlsx_structured") {
        $voidTotals = query(
            $db,
            "SELECT COUNT(*) AS count,COALESCE(SUM(total_gross),0) AS gross,COALESCE(SUM(total_net),0) AS net,COALESCE(SUM(total_vat),0) AS vat FROM sales WHERE status='voided' AND sale_at>=? AND sale_at<?",
            [$from, $end],
        )->fetch();
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
        $voidRows = $includeVoided
            ? query(
                $db,
                "SELECT s.id AS sale_id,s.sale_at,u.display_name AS cashier,COALESCE(vb.display_name,'Owner') AS voided_by_name,s.voided_at,s.void_reason,s.total_gross AS gross,s.total_net AS net,s.total_vat AS vat FROM sales s JOIN users u ON u.id=s.user_id LEFT JOIN users vb ON vb.id=s.voided_by WHERE s.status='voided' AND s.sale_at>=? AND s.sale_at<? ORDER BY s.sale_at,s.id",
                [$from, $end],
            )->fetchAll()
            : [];

        $xlsxBytes = build_styled_xlsx(
            $from,
            $to,
            $totals,
            $totalUnits,
            $voidTotals,
            $dailyFull,
            $productRows,
            $cashierRows,
            $rows,
            $voidRows,
            $includeVoided,
        );
        return [
            "filename" => "VenueVue_Structured_Report_{$from}_to_{$to}.xlsx",
            "content" => base64_encode($xlsxBytes),
            "encoding" => "base64",
            "mime" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ];
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
                " " . $row["sale_at"],
                csv_safe_cell($row["cashier"]),
                csv_safe_cell($row["sku"]),
                csv_safe_cell($row["category"]),
                csv_safe_cell($row["variant_label"]),
                $row["qty"],
                number_format((float) $row["unit_price"], 2, ".", ""),
                number_format((float) $row["gross"], 2, ".", ""),
                number_format((float) $row["net"], 2, ".", ""),
                number_format((float) $row["vat"], 2, ".", ""),
                $row["vat_rate"],
                number_format((float) $row["cash_received"], 2, ".", ""),
                number_format((float) $row["change_due"], 2, ".", ""),
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
            number_format((float) $totals["gross"], 2, ".", ""),
            number_format((float) $totals["net"], 2, ".", ""),
            number_format((float) $totals["vat"], 2, ".", ""),
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
            "Sale Date",
            "Completed Sales",
            "Voided Sales",
            "Units Sold",
            "gross",
            "net",
            "vat",
        ]);
        $voidCountTotal = 0;
        foreach ($dailyFull as $d) {
            $voidCountTotal += (int) $d["voided_sales"];
            csv_put_row($stream, [
                " " . $d["date"],
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
            number_format((float) $totals["gross"], 2, ".", ""),
            number_format((float) $totals["net"], 2, ".", ""),
            number_format((float) $totals["vat"], 2, ".", ""),
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
            "SKU",
            "Product Name",
            "Category",
            "Variant",
            "Units Sold",
            "Orders Count",
            "gross",
            "net",
            "vat",
            "Revenue Share",
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
            number_format((float) $totals["gross"], 2, ".", ""),
            number_format((float) $totals["net"], 2, ".", ""),
            number_format((float) $totals["vat"], 2, ".", ""),
            $grossTotalFloat > 0 ? "100.00%" : "0.00%",
        ]);
        $filename = "VenueVue_product_sku_sales_{$from}_{$to}.csv";
    } else {
        // format === "structured" (Plain-text CSV with formatted dates & decimals)
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

        csv_put_row($stream, ["NEARBY CAFE POS (VENUEVUE) - STRUCTURED SALES & Z-READING REPORT"]);
        csv_put_row($stream, ["Report Period", "{$from} through {$to}"]);
        csv_put_row($stream, ["Generated At (Asia/Manila)", " " . date("Y-m-d H:i:s")]);
        csv_put_row($stream, ["Currency", "PHP"]);
        csv_put_row($stream, []);

        csv_put_row($stream, ["[SECTION 1] EXECUTIVE FINANCIAL SUMMARY"]);
        csv_put_row($stream, ["Metric", "Orders / Units", "gross", "net", "vat", "Notes"]);
        csv_put_row($stream, [
            "Completed Sales (Active Revenue)",
            $totals["count"],
            number_format((float) $totals["gross"], 2, ".", ""),
            number_format((float) $totals["net"], 2, ".", ""),
            number_format((float) $totals["vat"], 2, ".", ""),
            "Included in Z-Reading & VAT remittance",
        ]);
        csv_put_row($stream, [
            "Physical Cash in Drawer (Cash Sales)",
            (int) ($totals["cash_drawer_count"] ?? $totals["count"]),
            number_format((float) ($totals["cash_drawer_gross"] ?? $totals["gross"]), 2, ".", ""),
            number_format((float) ($totals["cash_drawer_net"] ?? $totals["net"]), 2, ".", ""),
            number_format((float) ($totals["cash_drawer_vat"] ?? $totals["vat"]), 2, ".", ""),
            "Expected physical paper cash from Cash orders",
        ]);
        csv_put_row($stream, [
            "Online / Cashless (GCash / Maya / QR / Card)",
            (int) ($totals["online_count"] ?? 0),
            number_format((float) ($totals["online_gross"] ?? 0), 2, ".", ""),
            number_format((float) ($totals["online_net"] ?? 0), 2, ".", ""),
            number_format((float) ($totals["online_vat"] ?? 0), 2, ".", ""),
            "Settled via GCash, Maya, QR Ph or Card",
        ]);
        csv_put_row($stream, [
            "Total Drink / Item Units Sold",
            $totalUnits,
            number_format((float) $totals["gross"], 2, ".", ""),
            number_format((float) $totals["net"], 2, ".", ""),
            number_format((float) $totals["vat"], 2, ".", ""),
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

        csv_put_row($stream, ["[SECTION 2] DAILY Z-READING BREAKDOWN"]);
        csv_put_row($stream, ["Date", "Completed", "Voided", "Units Sold", "gross", "net", "vat"]);
        foreach ($dailyFull as $d) {
            csv_put_row($stream, [
                " " . $d["date"],
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
            number_format((float) $totals["gross"], 2, ".", ""),
            number_format((float) $totals["net"], 2, ".", ""),
            number_format((float) $totals["vat"], 2, ".", ""),
        ]);
        csv_put_row($stream, []);

        csv_put_row($stream, ["[SECTION 3] PRODUCT & 5-PART SKU PERFORMANCE"]);
        csv_put_row($stream, ["SKU", "Product", "Category", "Variant", "Units Sold", "Orders", "gross", "net", "vat", "Share"]);
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
            number_format((float) $totals["gross"], 2, ".", ""),
            number_format((float) $totals["net"], 2, ".", ""),
            number_format((float) $totals["vat"], 2, ".", ""),
            $grossTotalFloat > 0 ? "100.00%" : "0.00%",
        ]);
        csv_put_row($stream, []);

        csv_put_row($stream, ["[SECTION 4] CASHIER / STAFF BREAKDOWN"]);
        csv_put_row($stream, ["Cashier", "Role", "Completed", "Voided", "gross", "net", "vat"]);
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
            number_format((float) $totals["gross"], 2, ".", ""),
            number_format((float) $totals["net"], 2, ".", ""),
            number_format((float) $totals["vat"], 2, ".", ""),
        ]);
        csv_put_row($stream, []);

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
                " " . $row["sale_at"],
                csv_safe_cell($row["cashier"]),
                csv_safe_cell($row["sku"]),
                csv_safe_cell($row["category"]),
                csv_safe_cell($row["variant_label"]),
                $row["qty"],
                number_format((float) $row["unit_price"], 2, ".", ""),
                number_format((float) $row["gross"], 2, ".", ""),
                number_format((float) $row["net"], 2, ".", ""),
                number_format((float) $row["vat"], 2, ".", ""),
                $row["vat_rate"],
                number_format((float) $row["cash_received"], 2, ".", ""),
                number_format((float) $row["change_due"], 2, ".", ""),
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
            number_format((float) $totals["gross"], 2, ".", ""),
            number_format((float) $totals["net"], 2, ".", ""),
            number_format((float) $totals["vat"], 2, ".", ""),
            "",
            "",
            "",
        ]);

        if ($includeVoided) {
            $voidRows = query(
                $db,
                "SELECT s.id AS sale_id,s.sale_at,u.display_name AS cashier,COALESCE(vb.display_name,'Owner') AS voided_by_name,s.voided_at,s.void_reason,s.total_gross AS gross,s.total_net AS net,s.total_vat AS vat FROM sales s JOIN users u ON u.id=s.user_id LEFT JOIN users vb ON vb.id=s.voided_by WHERE s.status='voided' AND s.sale_at>=? AND s.sale_at<? ORDER BY s.sale_at,s.id",
                [$from, $end],
            )->fetchAll();
            csv_put_row($stream, []);
            csv_put_row($stream, ["[SECTION 6] VOIDED TRANSACTIONS AUDIT"]);
            csv_put_row($stream, [
                "Sale ID",
                "Sale At",
                "Cashier",
                "Voided By",
                "Voided At",
                "Reason",
                "Voided Gross",
                "Voided Net",
                "Voided VAT",
            ]);
            foreach ($voidRows as $vr) {
                csv_put_row($stream, [
                    $vr["sale_id"],
                    " " . $vr["sale_at"],
                    csv_safe_cell($vr["cashier"]),
                    csv_safe_cell($vr["voided_by_name"]),
                    !empty($vr["voided_at"]) ? " " . $vr["voided_at"] : "",
                    csv_safe_cell($vr["void_reason"] ?? ""),
                    number_format((float) $vr["gross"], 2, ".", ""),
                    number_format((float) $vr["net"], 2, ".", ""),
                    number_format((float) $vr["vat"], 2, ".", ""),
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
    $mime = $csv["mime"] ?? "text/csv; charset=utf-8";
    header("Content-Type: " . $mime);
    header("Content-Disposition: attachment; filename=\"" . $csv["filename"] . "\"");
    if (($csv["encoding"] ?? "") === "base64") {
        echo base64_decode($csv["content"], true);
    } else {
        echo $csv["content"];
    }
    exit();
}
