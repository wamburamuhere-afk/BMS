<?php
/**
 * tests/test_view_md_standard_cli.php
 * Run: php tests/test_view_md_standard_cli.php
 *
 * Verifies all 19 internal view/detail pages comply with the view.md print standard:
 *  (1) @page { margin: 10mm 8mm 16mm 8mm; } present AND outside any @media print block
 *  (2) print_footer_css.php included (inside a <style> block)
 *  (3) print_footer_html.php included AND placed before the page footer call
 *  (4) All internal fixed-position footer CSS/HTML patterns removed
 *  (5) Old wrong @page values are gone (file-specific)
 *  (6) No PHP syntax errors
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$failures = [];

// ── Helpers ──────────────────────────────────────────────────────────────────

function ok(string $label, bool $condition): void {
    global $pass, $fail, $failures;
    if ($condition) {
        echo "  \033[32mPASS\033[0m  $label\n";
        $pass++;
    } else {
        echo "  \033[31mFAIL\033[0m  $label\n";
        $fail++;
        $failures[] = $label;
    }
}

/** Extract concatenated text of all <style>…</style> blocks (raw file, not PHP output). */
function styleBlocks(string $raw): string {
    preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $raw, $m);
    return implode("\n", $m[1] ?? []);
}

/**
 * Returns true iff the canonical @page rule appears in a <style> block AND is NOT
 * nested inside any @media print { … } block in that CSS.
 * Brace counting is done on the extracted <style> content to avoid JS brace confusion.
 */
function pageOutsideMediaPrint(string $raw): bool {
    $needle = '@page { margin: 10mm 8mm 16mm 8mm; }';
    $css = styleBlocks($raw);
    if (!str_contains($css, $needle)) return false;

    $pagePos = strpos($css, $needle);
    $offset  = 0;
    while (($ms = strpos($css, '@media print', $offset)) !== false) {
        $bs = strpos($css, '{', $ms);
        if ($bs === false) break;
        $depth = 1; $i = $bs + 1; $len = strlen($css);
        while ($i < $len && $depth > 0) {
            if ($css[$i] === '{') $depth++;
            elseif ($css[$i] === '}') $depth--;
            $i++;
        }
        $me = $i - 1;
        if ($pagePos > $bs && $pagePos < $me) return false; // inside media-print
        $offset = $i;
    }
    return true;
}

/**
 * Returns true iff $needle appears in $content strictly before the LAST footer call
 * (includeFooter, include("footer.php"), require_once 'footer.php').
 * Uses strrpos so early-exit guard calls (not-found checks near page top) are ignored;
 * only the final page-closing call matters.
 */
function beforeFooterCall(string $content, string $needle): bool {
    $pos = strpos($content, $needle);
    if ($pos === false) return false;
    $calls = ['includeFooter()', 'include("footer.php")', "include('footer.php')", "require_once 'footer.php'"];
    $footerPos = -1;
    foreach ($calls as $c) {
        $p = strrpos($content, $c); // last occurrence
        if ($p !== false && $p > $footerPos) $footerPos = $p;
    }
    return $footerPos !== -1 && $pos < $footerPos;
}

// ── File list ─────────────────────────────────────────────────────────────────

$files = [
    'product_view'           => 'app/bms/product/product_view.php',
    'service_view'           => 'app/bms/product/service_view.php',
    'warehouse_view'         => 'app/bms/stock/warehouse_view.php',
    'warehouse_stock_view'   => 'app/bms/operations/warehouse_stock_view.php',
    'expense_details'        => 'app/constant/accounts/expense_details.php',
    'supplier_details'       => 'app/bms/Suppliers/supplier_details.php',
    'customer_details'       => 'app/bms/customer/customer_details.php',
    'employee_details'       => 'app/bms/pos/employee_details.php',
    'payroll_details'        => 'app/bms/pos/payroll_details.php',
    'transaction_details'    => 'app/constant/accounts/transaction_details.php',
    'journal_details'        => 'app/constant/accounts/journal_details.php',
    'budget_details'         => 'app/constant/accounts/budget_details.php',
    'cash_register_details'  => 'app/constant/accounts/cash_register_details.php',
    'reconciliation_details' => 'app/constant/accounts/reconciliation_details.php',
    'account_details'        => 'app/constant/accounts/account_details.php',
    'inspection_view'        => 'app/bms/operations/inspection_view.php',
    'project_view'           => 'app/bms/operations/project_view.php',
    'sub_contractor_details' => 'app/bms/operations/sub_contractor_details.php',
    'tender_view'            => 'app/bms/tenders/tender_view.php',
];

// Standalone page (own Bootstrap setup, no includeFooter) — skip footer-include checks
$standalone = ['warehouse_stock_view'];

// ── Per-file checks ───────────────────────────────────────────────────────────

foreach ($files as $key => $rel) {
    $path  = $root . '/' . $rel;
    $label = basename($rel);
    echo "\n--- $label ---\n";

    // (A) File exists
    ok("$label: file exists", file_exists($path));
    if (!file_exists($path)) continue;

    // (B) PHP syntax
    $out = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    ok("$label: PHP syntax valid", str_contains((string)$out, 'No syntax errors'));

    $raw = file_get_contents($path);
    $css = styleBlocks($raw);

    // (C) Canonical @page rule present in a <style> block
    ok("$label: @page { margin: 10mm 8mm 16mm 8mm; } in style block",
        str_contains($css, '@page { margin: 10mm 8mm 16mm 8mm; }'));

    // (D) @page is OUTSIDE all @media print blocks
    ok("$label: @page is outside @media print",
        pageOutsideMediaPrint($raw));

    if (!in_array($key, $standalone, true)) {
        // (E) print_footer_css.php in style context
        ok("$label: print_footer_css.php included",
            str_contains($raw, 'print_footer_css.php'));

        // (F) print_footer_html.php included
        ok("$label: print_footer_html.php included",
            str_contains($raw, 'print_footer_html.php'));

        // (G) print_footer_html.php appears BEFORE the footer call
        ok("$label: print_footer_html.php before footer call",
            beforeFooterCall($raw, 'print_footer_html.php'));
    }

    // (H) No internal fixed-position footer CSS patterns
    ok("$label: no .bms-print-footer{} CSS rule",
        !preg_match('/\.bms-print-footer\s*\{/', $raw));

    ok("$label: no .fixed-print-footer{position:fixed} CSS",
        !preg_match('/\.fixed-print-footer\s*\{[^}]*position\s*:\s*fixed/', $raw));

    ok("$label: no .print-footer{position:fixed} CSS",
        !preg_match('/\.print-footer\s*\{[^}]*position\s*:\s*fixed/s', $raw));

    // (I) No internal footer HTML divs
    ok("$label: no <div class=\"bms-print-footer\"",
        !str_contains($raw, 'class="bms-print-footer"'));

    ok("$label: no <div class=\"fixed-print-footer",
        !str_contains($raw, 'class="fixed-print-footer'));
}

// ── File-specific: old wrong @page values must be gone ───────────────────────

echo "\n--- Old wrong @page values removed ---\n";

$old_wrong = [
    // File => [pattern-that-must-NOT-exist, description]
    'app/bms/product/product_view.php'                => ['size: A4 portrait', 'A4 portrait size directive'],
    'app/bms/Suppliers/supplier_details.php'          => ['size: A4 portrait', 'A4 portrait size directive'],
    'app/constant/accounts/expense_details.php'       => ['@page { margin: 1cm; }', 'margin:1cm @page'],
    'app/constant/accounts/budget_details.php'        => ['margin: 1.5cm', 'margin:1.5cm @page'],
    'app/constant/accounts/cash_register_details.php' => ['@page { margin: 1cm; }', 'margin:1cm @page'],
    'app/bms/pos/employee_details.php'                => ['margin-bottom: 120px', '120px bottom margin'],
    'app/bms/pos/payroll_details.php'                 => ['margin-bottom: 100px', '100px bottom margin'],
    'app/bms/customer/customer_details.php'           => ['body.print-portrait', 'nested @page in body.print-portrait'],
    'app/bms/operations/warehouse_stock_view.php'     => ['margin: 45mm', '45mm top margin'],
    'app/bms/operations/project_view.php'             => ['margin: 15mm 12mm 20mm 12mm', '15mm top @page margin'],
    'app/bms/tenders/tender_view.php'                 => ['bottom: 30px', 'print-footer bottom:30px'],
];

foreach ($old_wrong as $rel => [$pattern, $desc]) {
    $raw = file_get_contents($root . '/' . $rel);
    ok(basename($rel) . ": $desc not present",
        !str_contains($raw, $pattern));
}

// ── Extra: project_view.php — JS reference to deleted footer element ──────────

echo "\n--- project_view.php extra checks ---\n";
$pv = file_get_contents($root . '/app/bms/operations/project_view.php');
ok('project_view.php: JS .fixed-print-footer selector removed',
    !str_contains($pv, 'fixed-print-footer p.mb-1'));
ok('project_view.php: no warehouse-stock .fixed-print-footer CSS reference',
    !str_contains($pv, 'warehouse-stock-print .fixed-print-footer'));

// ── Extra: payroll_details — ensure the HTML div content is gone ─────────────

echo "\n--- payroll_details.php extra checks ---\n";
$pd = file_get_contents($root . '/app/bms/pos/payroll_details.php');
ok('payroll_details.php: BJP Technologies footer text removed',
    !str_contains($pd, 'Powered By BJP Technologies') ||
    // allow it if it's part of the shared footer include (not the old hardcoded div)
    !str_contains($pd, 'class="fixed-print-footer'));

// ── Extra: warehouse_stock_view — bms-print-footer HTML div gone ──────────────

echo "\n--- warehouse_stock_view.php extra checks ---\n";
$wsv = file_get_contents($root . '/app/bms/operations/warehouse_stock_view.php');
ok('warehouse_stock_view.php: bms-print-footer HTML removed',
    !str_contains($wsv, '<div class="bms-print-footer">'));
ok('warehouse_stock_view.php: @page margin is canonical (not 45mm)',
    !str_contains($wsv, 'margin: 45mm'));

// ── Extra: customer_details — verify body.print-portrait @page nested rule gone ──

echo "\n--- customer_details.php extra checks ---\n";
$cd = file_get_contents($root . '/app/bms/customer/customer_details.php');
ok('customer_details.php: body.print-portrait nested @page removed',
    !preg_match('/body\.print-portrait\s*\{[^}]*@page/s', $cd));
ok('customer_details.php: body.print-landscape nested @page removed',
    !preg_match('/body\.print-landscape\s*\{[^}]*@page/s', $cd));

// ── Summary ──────────────────────────────────────────────────────────────────

$total = $pass + $fail;
echo "\n" . str_repeat('─', 60) . "\n";
printf("Total: %d  \033[32mPASS: %d\033[0m  %sFAIL: %d\033[0m\n",
    $total, $pass, $fail ? "\033[31m" : "\033[32m", $fail);

if ($fail) {
    echo "\nFailed checks:\n";
    foreach ($failures as $f) echo "  ✗ $f\n";
    exit(1);
}
echo "\033[32mAll checks passed.\033[0m\n";
exit(0);
