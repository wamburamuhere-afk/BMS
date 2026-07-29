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
 * That first pass used table-layout: auto (the browser default), which
 * caused a NEW, separate bug: with 12 richly-styled columns and long
 * unbreakable strings (currency figures, "PARTIALLY DELIVERED" badges),
 * auto layout let the table's own intrinsic width exceed the physical page
 * width. Landscape had enough spare width to absorb it; portrait didn't, so
 * the rightmost columns ran off the page edge, and word-break: break-word
 * squeezed some narrow columns down to one letter per line. Root-caused and
 * fixed with table-layout: fixed (guarantees the table can never exceed the
 * page width) PLUS explicit, content-proportional percentage widths on
 * every column summing to 100% (equal-split fixed columns were what caused
 * the original bleeding bug) — one set for when the Project column is
 * enabled, one for when it isn't.
 *
 * Also asked to remove the "Prepared By / Management Review / Authorised
 * Signature" print-only footer block entirely.
 *
 * A further round of user feedback caught two more bugs in that fix:
 *   - Header labels (WAREHOUSE, TOTAL AMOUNT, PAYMENT, DELIVERY) were
 *     visibly clipped ("WAREHO", "TOTAL AMO"). Root cause: a general,
 *     always-on rule further down this stylesheet
 *     (#salesOrdersTable thead th) forces header text to white-space:
 *     nowrap; the earlier fix only reset white-space on CELL CHILDREN
 *     (th *), never on the <th> itself, so header text (a direct text
 *     node, not a child element) stayed nowrap and got clipped by the
 *     overflow: hidden backstop.
 *   - Requested outright: Items, Payment and Delivery should not print at
 *     all, to free up room for the remaining columns.
 *
 * Fix applied to app/bms/sales/sales_orders.php:
 *   1. #salesOrdersTableCard (new id on the table's wrapping .card)
 *      overrides page-break-inside back to auto so the table flows
 *      starting on page 1.
 *   2. table.dataTable { table-layout: fixed } + explicit per-column
 *      nth-child percentage widths (summing to 100%, two variants for
 *      enable_projects on/off) so every column gets room proportional to
 *      its real content, in both orientations.
 *   3. overflow: hidden + word-wrap: break-word (gentle fallback only,
 *      NOT word-break: break-word) + max-width: 100% on cell children as a
 *      backstop against the remaining inline on-screen pixel styles.
 *   4. @page { margin: 10mm 8mm 16mm 8mm } — the same asymmetric bottom
 *      clearance already proven correct on Customer/Supplier/Sub-Contractor/
 *      Delivery-Notes/Quotations print — keeps the last row clear of the
 *      shared fixed-position footer.
 *   5. Removed the "Prepared By / Management Review / Authorised Signature"
 *      print-only signature block.
 *   6. Items/Payment/Delivery th+td now carry d-print-none (both the
 *      static thead HTML and the DataTables columns className config);
 *      the freed width was redistributed across the remaining 9 (with
 *      Project)/8 (without) visible columns, still summing to 100%.
 *   7. #salesOrdersTable thead th { white-space: normal !important; }
 *      overrides the general nowrap rule for print, so headers wrap
 *      instead of clipping.
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

section('4. Fixed layout + explicit proportional column widths (fits both orientations, no bleeding)');
check(preg_match('/table-layout:\s*fixed\s*!important/', $src) === 1,
    'table.dataTable forced to table-layout: fixed (guarantees the table never exceeds page width in portrait)',
    'table-layout: fixed is missing — auto layout can let the table overflow a narrow portrait page');
check(preg_match('/#salesOrdersTable th, #salesOrdersTable td \{ width: auto !important/', $src) === 1,
    '#salesOrdersTable th/td width reset to auto first (clears DataTables\' inline pixel widths before the % widths apply)',
    'the th/td width: auto reset is missing');

// Every column must get an explicit, non-equal percentage width in BOTH the
// enable_projects and non-enable_projects variants — equal-split fixed
// columns were exactly what caused the original bleeding bug, so this test
// checks real proportional variety exists, not just that widths are set.
// Items/Payment/Delivery are print-hidden now, so only 9 (with Project) and
// 8 (without) columns should have an explicit print width rule left.
preg_match_all('/#salesOrdersTable th:nth-child\((\d+)\),\s*#salesOrdersTable td:nth-child\(\d+\)\s*\{\s*width:\s*(\d+)%\s*!important/', $src, $m);
$nthPositions = array_map('intval', $m[1] ?? []);
$widths       = array_map('intval', $m[2] ?? []);
check(count($widths) === 17, // 9 (with project) + 8 (without) = 17 nth-child rules
    'both column-width variants (with/without Project column) are present, 9+8 columns (' . count($widths) . ' nth-child width rules found)',
    'expected 17 nth-child width rules (9 + 8, since Items/Payment/Delivery no longer get one), found ' . count($widths));
check(count(array_unique($widths)) > 3,
    'column widths are genuinely proportional, not an equal split (' . count(array_unique($widths)) . ' distinct percentages used)',
    'STILL BROKEN: widths look like an equal split again, not proportioned to content');

// First 9 values = the enable_projects branch (appears first in the file),
// remaining 8 = the else branch — each set must sum to 100 so table-layout:
// fixed distributes the full page width with nothing left over/short.
if (count($widths) === 17) {
    $withProjectsSum = array_sum(array_slice($widths, 0, 9));
    $noProjectsSum    = array_sum(array_slice($widths, 9, 8));
    check($withProjectsSum === 100, "enable_projects column widths sum to exactly 100% (got {$withProjectsSum}%)", "enable_projects widths sum to {$withProjectsSum}%, not 100% — columns won't fill/will overflow the page width");
    check($noProjectsSum === 100, "no-projects column widths sum to exactly 100% (got {$noProjectsSum}%)", "no-projects widths sum to {$noProjectsSum}%, not 100% — columns won't fill/will overflow the page width");

    // Neither branch should assign a width to nth-child positions 8, 10, 11
    // (with project) / 7, 9, 10 (without) — those are Items/Payment/Delivery,
    // now print-hidden, and must not silently get a width rule back.
    $withProjectPositions = array_slice($nthPositions, 0, 9);
    $noProjectPositions   = array_slice($nthPositions, 9, 8);
    check(!array_intersect([8, 10, 11], $withProjectPositions),
        'enable_projects branch has no width rule for the hidden Items/Payment/Delivery positions (8, 10, 11)',
        'a hidden column (Items/Payment/Delivery) still has an explicit print width rule in the enable_projects branch');
    check(!array_intersect([7, 9, 10], $noProjectPositions),
        'no-projects branch has no width rule for the hidden Items/Payment/Delivery positions (7, 9, 10)',
        'a hidden column (Items/Payment/Delivery) still has an explicit print width rule in the no-projects branch');
} else {
    fail('cannot verify width sums — did not find exactly 17 nth-child rules to split into the two 9/8 branches');
}

section('4a. Items/Payment/Delivery no longer print at all');
check(substr_count($src, '<th class="text-center d-print-none">Items</th>') === 1,
    'Items header carries d-print-none',
    'Items header is missing d-print-none — will still print');
check(substr_count($src, '<th class="text-center d-print-none">Payment</th>') === 1,
    'Payment header carries d-print-none',
    'Payment header is missing d-print-none — will still print');
check(substr_count($src, '<th class="text-center d-print-none">Delivery</th>') === 1,
    'Delivery header carries d-print-none',
    'Delivery header is missing d-print-none — will still print');
check(substr_count($src, "className: 'text-center d-print-none'") >= 3,
    'the Items/Payment/Delivery DataTables column configs all carry d-print-none in their className (found ' . substr_count($src, "className: 'text-center d-print-none'") . ')',
    'one or more of the Items/Payment/Delivery body-cell className configs is missing d-print-none — that column\'s data would still print');

section('4b. Rich cell content (inline max-width / nowrap) can no longer bleed into the next column, and text no longer breaks vertically');
check(preg_match('/#salesOrdersTable td, #salesOrdersTable th \{[^}]*overflow:\s*hidden\s*!important/s', $src) === 1,
    'td/th clip overflow as a hard backstop',
    'the overflow: hidden backstop on td/th is missing');
check(preg_match('/#salesOrdersTable td, #salesOrdersTable th \{[^}]*word-break:\s*normal\s*!important/s', $src) === 1,
    'word-break is "normal", not "break-word" (only wraps at word boundaries — break-word/break-all is what squeezed narrow columns to one letter per line)',
    'word-break: break-word (or break-all) is still forcing mid-word breaks — will look vertical in a narrow column again');
check(preg_match('/#salesOrdersTable td \*, #salesOrdersTable th \* \{[^}]*max-width:\s*100%\s*!important/s', $src) === 1,
    'every cell child\'s inline max-width (e.g. customer name\'s 150px) is reset to 100% of its own column',
    'the max-width: 100% reset for cell children is missing — hard-coded on-screen pixel widths could still overflow');
check(preg_match('/#salesOrdersTable td \*, #salesOrdersTable th \* \{[^}]*white-space:\s*normal\s*!important/s', $src) === 1,
    'Bootstrap .text-truncate\'s white-space: nowrap is overridden to wrap instead of overflow',
    'nowrap-forcing classes (.text-truncate) could still force single-line overflow past the cell');
check(preg_match('/#salesOrdersTable thead th \{\s*white-space:\s*normal\s*!important/s', $src) === 1,
    'header cells (#salesOrdersTable thead th) themselves are reset to white-space: normal for print (not just their children)',
    'STILL BROKEN: header <th> text nodes are direct children, not matched by "th *" — the general nowrap rule elsewhere will keep clipping header labels');

section('4c. Signature footer removed');
check(!preg_match('/Prepared By|Management Review|Authorised Signature/', $src),
    '"Prepared By / Management Review / Authorised Signature" block is gone',
    'the signature footer block is still present');

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
