<?php
/**
 * Unit field auto-fill + lock — CLI test
 * ----------------------------------------
 *   php tests/test_unit_autofill_lock_cli.php
 *
 * A product's `unit` (kg, lt, pcs, ...) is fixed once at registration.
 * Several line-item forms used to show it as an independently-editable
 * dropdown/text field the user could re-pick after selecting a product —
 * this verifies the Unit field is now a locked (readonly), auto-filled
 * display everywhere a live product search exists, matching the pattern
 * already correct in dn_create.php / dn_outbound.php / invoice_create.php.
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;

function readSrc(string $path): string {
    global $root;
    $full = "$root/" . ltrim($path, '/');
    return file_exists($full) ? file_get_contents($full) : '';
}
function ok(string $m): void   { global $pass; $pass++; echo "\033[32m  OK\033[0m $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "\033[31m  XX\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m-- $t --\033[0m\n"; }
function has(string $src, string $needle, string $label): void { strpos($src, $needle) !== false ? ok($label) : bad("$label — missing `" . substr($needle, 0, 70) . "`"); }
function hasNot(string $src, string $needle, string $label): void { strpos($src, $needle) === false ? ok($label) : bad("$label — should be gone: `" . substr($needle, 0, 70) . "`"); }
function fileSyntaxOk(string $rel): void {
    global $root;
    $out = shell_exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1');
    strpos($out, 'No syntax errors') !== false ? ok("Syntax OK: $rel") : bad("Syntax ERROR: $rel — $out");
}

$files = [
    'app/bms/sales/sales_order_create.php',
    'app/bms/sales/sales_order_edit.php',
    'app/bms/sales/quotations/quotation_form.php',
    'app/bms/grn/grn_create.php',
    'app/bms/purchase/rfq_create.php',
];

section('1. PHP syntax');
foreach ($files as $f) { fileSyntaxOk($f); }

section('2. Sales Order Create — unit locked');
$soc = readSrc('app/bms/sales/sales_order_create.php');
hasNot($soc, '<select class="form-select item-unit"', 'no more editable unit dropdown');
has($soc, 'class="form-control item-unit" name="items[${index}][unit]"', 'unit is a plain text field');
has($soc, 'value="${product ? (product.unit || \'pcs\') : \'pcs\'}" readonly', 'unit input is readonly, value from product.unit');
has($soc, "row.find('.item-unit').val(product.unit || 'pcs')", 'selectProduct() still sets unit from product.unit');

section('3. Sales Order Edit — unit locked');
$soe = readSrc('app/bms/sales/sales_order_edit.php');
hasNot($soe, '<select class="form-select item-unit"', 'no more editable unit dropdown');
has($soe, 'class="form-control item-unit" name="items[${index}][unit]" value="pcs" readonly', 'unit input is readonly');
hasNot($soe, 'item-unit option[value=', 'dynamic option-append hack removed (no longer a <select>)');
has($soe, "\$row.find('.item-unit').val(product.unit || 'pcs')", 'edit-mode row-add sets unit from product.unit');

section('4. Quotation — unit locked');
$qf = readSrc('app/bms/sales/quotations/quotation_form.php');
hasNot($qf, '<select class="form-select item-unit"', 'no more editable unit dropdown');
has($qf, 'class="form-control item-unit" name="items[${index}][unit]" value="pcs" readonly', 'unit input is readonly');
hasNot($qf, 'item-unit option[value=', 'dynamic option-append hack removed');

section('5. GRN Create — unit locked');
$grn = readSrc('app/bms/grn/grn_create.php');
hasNot($grn, '<select class="form-select item-unit"', 'no more editable unit dropdown');
has($grn, 'class="form-control item-unit" name="items[${index}][unit]"', 'unit is a plain text field');
has($grn, "readonly", 'unit input carries readonly');
has($grn, "// Auto-fill unit from product registration", 'existing auto-fill comment/logic intact');

section('6. RFQ Create — locked only when a real catalog product is linked');
$rfq = readSrc('app/bms/purchase/rfq_create.php');
has($rfq, "if (unitInput && p.unit) { unitInput.value = p.unit; unitInput.readOnly = true; }", 'rfqSelectProduct() force-sets + locks unit for a real product');
hasNot($rfq, "if (unitInput && !unitInput.value && p.unit) unitInput.value = p.unit;", 'old conditional-only-if-empty fill removed');
has($rfq, "!empty(\$item['product_id']) ? 'readonly' : ''", 'edit-mode pre-existing rows lock unit only when linked to a real product');
has($rfq, 'placeholder="e.g. pcs, kg, m"', 'free-text (no product) rows remain editable — RFQ allows generic, non-catalog requests');

// ── Result ────────────────────────────────────────────────────────────────
echo "\n\033[1m" . str_repeat('=', 40) . "\033[0m\n";
if ($fail === 0) {
    echo "\033[32mAll $pass tests passed.\033[0m\n";
} else {
    echo "\033[31m$fail test(s) failed out of " . ($pass + $fail) . ".\033[0m\n";
}
echo "\033[1m" . str_repeat('=', 40) . "\033[0m\n\n";
exit($fail > 0 ? 1 : 0);
