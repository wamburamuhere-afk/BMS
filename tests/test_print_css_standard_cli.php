<?php
/**
 * tests/test_print_css_standard_cli.php
 *
 * Verifies all BMS print pages comply with the i_e_print.md CSS standard.
 * Reference implementation: app/bms/sales/quotations/print_quotation.php
 *
 * Sections:
 *  1 — PHP syntax valid
 *  2 — Shared footer CSS included
 *  3 — Shared footer HTML included
 *  4 — Canonical @page margin (10mm 8mm 16mm 8mm)
 *  5 — No internal footer blocks
 *  6 — .box p margin: 3px 0 (not 5px)
 *  7 — tbody td: height 0.75cm + line-height 1.6 (not 0.9cm / 2.2)
 *  8 — Title-box h2 font-size: 16px (not 14px or 18px)
 *  9 — Reference file (print_quotation.php) integrity
 *
 * Run: php tests/test_print_css_standard_cli.php
 */

define('ROOT_DIR', dirname(__DIR__));

$PASS = 0;
$FAIL = 0;

function ok(string $label, bool $result): void {
    global $PASS, $FAIL;
    if ($result) {
        echo "  \033[32m✓\033[0m $label\n";
        $PASS++;
    } else {
        echo "  \033[31m✗\033[0m $label\n";
        $FAIL++;
    }
}

function src(string $rel): string {
    $p = ROOT_DIR . '/' . ltrim($rel, '/');
    return file_exists($p) ? file_get_contents($p) : '';
}

function syntax_ok(string $rel): bool {
    $p = ROOT_DIR . '/' . ltrim($rel, '/');
    exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $code);
    return $code === 0;
}

// ── FILE LISTS ───────────────────────────────────────────────────────────────

// All 12 print pages normalised to the i_e_print.md standard
$ALL = [
    'app/bms/sales/print_sales_order.php',
    'api/account/print_purchase_order.php',
    'api/account/print_rfq.php',
    'app/bms/invoice/invoice_print.php',
    'app/bms/purchase/print_purchase_return.php',
    'app/bms/sales/sales_returns/print_sales_return.php',
    'api/account/print_delivery_note.php',
    'app/bms/grn/grn_print.php',
    'app/bms/stock/adjustment_print.php',
    'app/bms/operations/print_ipc.php',
    'app/constant/accounts/payment_voucher_print.php',
    'app/constant/accounts/petty_cash_print.php',
];

// Files that use the standard .box panel CSS
$HAS_BOX = [
    'app/bms/sales/print_sales_order.php',
    'api/account/print_purchase_order.php',
    'api/account/print_rfq.php',
    'app/bms/invoice/invoice_print.php',
    'app/bms/purchase/print_purchase_return.php',
    'app/bms/sales/sales_returns/print_sales_return.php',
    'api/account/print_delivery_note.php',
    'app/bms/grn/grn_print.php',
    'app/bms/operations/print_ipc.php',
    'app/constant/accounts/payment_voucher_print.php',
];

// Files that have a standard items tbody table
$HAS_TABLE = [
    'app/bms/sales/print_sales_order.php',
    'api/account/print_purchase_order.php',
    'api/account/print_rfq.php',
    'app/bms/invoice/invoice_print.php',
    'app/bms/purchase/print_purchase_return.php',
    'app/bms/sales/sales_returns/print_sales_return.php',
    'api/account/print_delivery_note.php',
    'app/bms/grn/grn_print.php',
    'app/bms/operations/print_ipc.php',
];

// ── SECTION 1: PHP Syntax ────────────────────────────────────────────────────
echo "\n=== Section 1: PHP Syntax (" . count($ALL) . " files) ===\n";
foreach ($ALL as $f) {
    ok(basename($f) . ' — syntax valid', syntax_ok($f));
}

// ── SECTION 2: Shared footer CSS include ────────────────────────────────────
echo "\n=== Section 2: Shared footer CSS included ===\n";
foreach ($ALL as $f) {
    ok(basename($f) . ' — includes print_footer_css.php',
        str_contains(src($f), 'print_footer_css.php'));
}

// ── SECTION 3: Shared footer HTML include ───────────────────────────────────
echo "\n=== Section 3: Shared footer HTML included ===\n";
foreach ($ALL as $f) {
    ok(basename($f) . ' — includes print_footer_html.php',
        str_contains(src($f), 'print_footer_html.php'));
}

// ── SECTION 4: Canonical @page margin ───────────────────────────────────────
echo "\n=== Section 4: Canonical @page margin (10mm 8mm 16mm 8mm) ===\n";
foreach ($ALL as $f) {
    ok(basename($f) . ' — @page margin canonical',
        str_contains(src($f), 'margin: 10mm 8mm 16mm 8mm'));
}

// ── SECTION 5: No internal footer blocks ─────────────────────────────────────
// Each file must not contain any locally-defined footer block.
// Patterns banned: bms-print-footer class, .footer { CSS rule, <div class="footer">
echo "\n=== Section 5: No internal footer blocks ===\n";
foreach ($ALL as $f) {
    $s = src($f);
    $clean = !str_contains($s, 'bms-print-footer')
          && !preg_match('/\.footer\s*\{/', $s)
          && !str_contains($s, '<div class="footer">');
    ok(basename($f) . ' — no internal footer', $clean);
}

// ── SECTION 6: .box p margin: 3px 0 ─────────────────────────────────────────
echo "\n=== Section 6: .box p margin 3px 0 (not 5px) ===\n";
foreach ($HAS_BOX as $f) {
    $s = src($f);
    ok(basename($f) . ' — .box p margin is 3px 0',
        str_contains($s, 'margin: 3px 0') &&
        !preg_match('/\.box\s+p\s*\{[^}]*margin:\s*5px/', $s));
}

// ── SECTION 7: tbody td canonical row values ──────────────────────────────────
echo "\n=== Section 7: tbody td height 0.75cm + line-height 1.6 ===\n";
foreach ($HAS_TABLE as $f) {
    $s = src($f);
    ok(basename($f) . ' — td has height 0.75cm and line-height 1.6',
        str_contains($s, 'height: 0.75cm') && str_contains($s, 'line-height: 1.6'));
    ok(basename($f) . ' — td has no old 0.9cm or 2.2',
        !str_contains($s, 'height: 0.9cm') && !str_contains($s, 'line-height: 2.2'));
}

// ── SECTION 8: Title-box h2 font-size = 16px ─────────────────────────────────
// PO and RFQ use .po-title h2 (were 18px); IPC uses .doc-title-box h2 (was 14px).
echo "\n=== Section 8: Title-box h2 font-size 16px (not 14px or 18px) ===\n";

foreach (['api/account/print_purchase_order.php', 'api/account/print_rfq.php'] as $f) {
    $s = src($f);
    preg_match('/\.po-title\s+h2\s*\{([^}]+)\}/s', $s, $m);
    $h2 = $m[1] ?? '';
    ok(basename($f) . ' — .po-title h2 is 16px',  str_contains($h2, 'font-size: 16px'));
    ok(basename($f) . ' — .po-title h2 NOT 18px', !str_contains($h2, 'font-size: 18px'));
}

$s = src('app/bms/operations/print_ipc.php');
preg_match('/\.doc-title-box\s+h2\s*\{([^}]+)\}/s', $s, $m);
$h2 = $m[1] ?? '';
ok('print_ipc.php — .doc-title-box h2 is 16px',  str_contains($h2, 'font-size: 16px'));
ok('print_ipc.php — .doc-title-box h2 NOT 14px', !str_contains($h2, 'font-size: 14px'));

// ── SECTION 9: Reference file integrity ──────────────────────────────────────
// print_quotation.php is the canonical reference — verify it has not been altered.
echo "\n=== Section 9: Reference file (print_quotation.php) integrity ===\n";
$ref = src('app/bms/sales/quotations/print_quotation.php');
ok('Reference — @page margin canonical',  str_contains($ref, 'margin: 10mm 8mm 16mm 8mm'));
ok('Reference — .box p margin 3px',       str_contains($ref, 'margin: 3px 0'));
ok('Reference — td height 0.75cm',        str_contains($ref, 'height: 0.75cm'));
ok('Reference — td line-height 1.6',      str_contains($ref, 'line-height: 1.6'));
ok('Reference — footer CSS included',     str_contains($ref, 'print_footer_css.php'));
ok('Reference — footer HTML included',    str_contains($ref, 'print_footer_html.php'));
ok('Reference — doc-title-box h2 16px',   str_contains($ref, 'font-size: 16px'));

// ── RESULT ───────────────────────────────────────────────────────────────────
$total = $PASS + $FAIL;
echo "\n" . str_repeat('─', 60) . "\n";
if ($FAIL > 0) {
    echo "Result: {$PASS}/{$total} passed — \033[31m{$FAIL} FAILED\033[0m\n";
    exit(1);
}
echo "Result: {$PASS}/{$total} passed — \033[32mall print pages comply with i_e_print.md standard\033[0m\n";
exit(0);
