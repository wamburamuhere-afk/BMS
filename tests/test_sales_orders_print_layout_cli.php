<?php
/**
 * Sales Orders list print — regression guard.
 *
 * User-reported: printing sales_orders.php left the first page almost
 * entirely blank, with the whole table pushed to page 2. Same root cause
 * already fixed on Quotations/Delivery Notes/Warehouses/Supplier print:
 * the global responsive.css rule `p, .card, section { page-break-inside:
 * avoid }` treats the Sales Orders Table Card as one unsplittable block —
 * since it's many times taller than a printed page, the whole thing gets
 * deferred to the next page instead of starting to flow on page 1.
 *
 * Also asked to match print-customers.php's column alignment (DataTables'
 * inline pixel column widths, sized for the wide on-screen container,
 * don't shrink for a narrower printed page) and to make sure the shared
 * fixed-position print footer never overlaps the last row of content.
 *
 * Fix applied to app/bms/sales/sales_orders.php:
 *   1. #salesOrdersTableCard (new id on the table's wrapping .card)
 *      overrides page-break-inside back to auto so the table flows
 *      starting on page 1.
 *   2. table.dataTable { table-layout: fixed } + #salesOrdersTable th/td
 *      { width: auto } strips the inline pixel widths so columns fit the
 *      real print width in both orientations, matching print-customers.php.
 *   3. @page { margin: 10mm 8mm 16mm 8mm } — the same asymmetric bottom
 *      clearance already proven correct on Customer/Supplier/Sub-Contractor/
 *      Delivery-Notes/Quotations print — keeps the last row clear of the
 *      shared fixed-position footer.
 *
 * Run: php tests/test_sales_orders_print_layout_cli.php
 *   Exit 0 = all pass · Exit 1 = a regression slipped in.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
    require_once "$root/core/project_scope.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ Sales Orders list print layout fix ═══\033[0m\n";

$file = "$root/app/bms/sales/sales_orders.php";
$src  = file_exists($file) ? file_get_contents($file) : '';

section('1. php -l');
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
check($rc === 0, 'sales_orders.php — no syntax errors', 'sales_orders.php — php -l failed: ' . implode(' ', $out));

section('2. The table card carries the new id, and only once');
check(substr_count($src, 'id="salesOrdersTableCard"') === 1,
    'id="salesOrdersTableCard" appears exactly once (no duplicate ids)',
    'id="salesOrdersTableCard" is missing or duplicated');

// The id must sit on the actual wrapping .card of #salesOrdersTable, not some
// unrelated element — check the id appears immediately before the table markup.
$cardPos  = strpos($src, 'id="salesOrdersTableCard"');
$tablePos = strpos($src, 'id="salesOrdersTable"');
check($cardPos !== false && $tablePos !== false && $tablePos > $cardPos && ($tablePos - $cardPos) < 300,
    '#salesOrdersTableCard wraps #salesOrdersTable (id sits right before the table markup)',
    '#salesOrdersTableCard does not appear to wrap the actual table');

section('3. page-break-inside override targets the id, beating the global .card rule');
check(preg_match('/#salesOrdersTableCard\s*\{[^}]*page-break-inside:\s*auto\s*!important/s', $src) === 1,
    '#salesOrdersTableCard overrides page-break-inside: auto (beats the class-level "avoid" rule)',
    'the id-specific page-break-inside override is missing');
check(preg_match('/#salesOrdersTableCard\s*\{[^}]*break-inside:\s*auto\s*!important/s', $src) === 1,
    '#salesOrdersTableCard also overrides the unprefixed break-inside',
    'the unprefixed break-inside override is missing');

section('4. Column widths stripped so print matches print-customers.php alignment');
check(str_contains($src, 'table.dataTable { table-layout: fixed !important; }'),
    'table.dataTable forced to table-layout: fixed for print',
    'table-layout: fixed override is missing');
check(preg_match('/#salesOrdersTable th, #salesOrdersTable td \{ width: auto !important/', $src) === 1,
    '#salesOrdersTable th/td width reset to auto (overrides DataTables\' inline pixel widths)',
    'the th/td width: auto override is missing');

section('5. @page margin matches the canonical, already-proven-correct value');
check(str_contains($src, '@page { margin: 10mm 8mm 16mm 8mm; size: auto; }'),
    '@page margin is 10mm 8mm 16mm 8mm (same as Customer/Supplier/Delivery-Notes/Quotations print)',
    '@page margin override is missing or does not match the canonical value');

section('6. DataTables columns config still carries the fixed pixel widths (confirms the bug precondition, and that CSS — not JS — is the fix layer)');
check(preg_match_all("/width: '\\d+px'/", $src) >= 3,
    'columns config still sets fixed pixel widths on-screen (expected — the print override strips them via CSS only, JS config untouched)',
    'expected on-screen column widths are gone — investigate whether the JS config changed unexpectedly');

if (!$isLive) {
    echo "\n  \033[33m⊘\033[0m  Skipping live section (no includes/config.php — not a live install)\n";
} else {
    section('7. Live sanity — the AJAX data source behind the table still responds cleanly');
    global $pdo;
    try {
        $_SESSION['user_id'] = 4; // seeded admin, per earlier session's live checks
        $_SESSION['role_id'] = (int)$pdo->query("SELECT role_id FROM users WHERE user_id = 4")->fetchColumn();
        unset($_SESSION['is_admin'], $_SESSION['scope']);
        loadUserScope(4);

        $_GET = ['draw' => 1, 'start' => 0, 'length' => 10];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        require "$root/api/account/get_sales_orders.php";
        $out = ob_get_clean();

        check(strpos($out, 'Warning') === false && strpos($out, 'Fatal error') === false,
            'get_sales_orders.php produced no PHP warnings/fatals (CSS-only change did not disturb the page pipeline)',
            'get_sales_orders.php emitted warnings/errors: ' . substr($out, 0, 300));

        $res = json_decode($out, true);
        check(is_array($res) && array_key_exists('data', $res),
            'get_sales_orders.php still returns a valid DataTables JSON payload',
            'get_sales_orders.php did not return the expected JSON shape');
    } catch (Throwable $e) {
        fail('Live sanity check threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
