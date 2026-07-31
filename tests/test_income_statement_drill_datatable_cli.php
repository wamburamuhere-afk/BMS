<?php
/**
 * Income Statement — drill-down "View" modal must be a DataTable, not a raw HTML dump
 *   php tests/test_income_statement_drill_datatable_cli.php
 *
 * User-reported: on income_statement.php, clicking a P&L line's "View" drill-down
 * icon opened a modal that built its entire table body as one raw HTML string
 * (jQuery .html()) and injected every contributing record at once — for an
 * account with hundreds/thousands of transactions in the period, that meant
 * hundreds/thousands of live <tr> elements in the DOM at once with no
 * pagination, search, or sort, which is slow to render and slow to interact
 * with (per ui-constants.md §UI-2: "Every <table> backed by a database must be
 * a DataTable").
 *
 * Fix: the drill-down table (#drillTable) is now a real client-side DataTable,
 * reloaded via the standard clear()+rows.add()+draw() pattern (never
 * location.reload(), never raw .html() of the tbody) on every openDrill() call.
 * The grouped invoices display (collected/recognized/pipeline) is preserved as
 * a per-row "Group" label instead of colspan divider rows, since DataTables
 * pages/sorts real per-row data — a colspan header row would break across
 * pages. Pipeline rows keep their dimmed (opacity 0.55) styling via
 * createdRow(). Column render() callbacks are type-aware (only build HTML/
 * format dates for type==='display'; sort/filter/type get a cheap raw value)
 * so pagination genuinely limits DOM cost instead of the DataTable itself
 * doing the same expensive formatting for every row on every reload.
 *
 * This is a source-pattern regression guard, not a live HTTP test — the page
 * requires an authenticated session to render. The fix was verified live in a
 * real browser session (dev.bms.local): opened real drill-downs (flat +
 * grouped via synthetic data), confirmed search/sort/pagination all work,
 * confirmed switching between two different accounts' drill-downs correctly
 * clears and reloads, and benchmarked a synthetic 2000-row account — the DOM
 * held only 15 <tr> elements (one page) throughout, versus all 2000 before
 * this fix.
 *
 * Exit 0 = all checks pass. Exit 1 = a regression slipped in.
 */

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$passes\033[0m\nFailures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

echo "\n\033[1m═══ Income Statement — drill-down modal is a real DataTable ═══\033[0m\n";

$root = dirname(__DIR__);
$file = $root . '/app/bms/invoice/income_statement.php';

head('Syntax');
$res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
(strpos((string)$res, 'No syntax errors detected') !== false)
    ? ok('income_statement.php — no syntax errors')
    : bad('income_statement.php — ' . trim((string)$res));

$src = file_get_contents($file) ?: '';

head('The drill-down table is a real DataTable');
str_contains($src, "id=\"drillTable\"")
    ? ok('table carries id="drillTable"')
    : bad('drillTable id missing from the markup');
str_contains($src, "\$('#drillTable').DataTable({")
    ? ok('a DataTable is initialised on #drillTable')
    : bad('#drillTable is never turned into a DataTable');
str_contains($src, 'ensureDrillTable')
    ? ok('DataTable init is lazy/memoised (ensureDrillTable) rather than re-created every open')
    : bad('no lazy-init guard found — DataTable could be re-initialised on every open (throws in real DataTables)');

head('Reload uses the standard clear()+rows.add()+draw() pattern, never raw HTML injection');
str_contains($src, 'table.clear().rows.add(rowsData).draw()')
    ? ok('openDrill() reloads via table.clear().rows.add(...).draw()')
    : bad('openDrill() no longer uses the clear/rows.add/draw reload pattern');
(!str_contains($src, "\$('#drillBody').html("))
    ? ok('no leftover raw .html() injection into a drillBody tbody')
    : bad('found a raw .html() call — the unbounded-DOM bug may have regressed');
str_contains($src, 'location.reload()') === false || !str_contains(substr($src, (int)strpos($src, 'function openDrill')), 'location.reload()')
    ? ok('openDrill() does not use location.reload() to refresh the table')
    : bad('openDrill() uses location.reload() — violates the standard DataTable reload pattern');

head('Pagination is genuinely enabled (the actual performance fix)');
preg_match('/pageLength:\s*(\d+)/', $src, $m);
(!empty($m) && (int)$m[1] > 0 && (int)$m[1] <= 50)
    ? ok('pageLength is set to a sane page size (' . ($m[1] ?? '?') . ') — not "show everything"')
    : bad('pageLength missing or absurdly large — pagination would not actually bound the DOM');

head('Column render() callbacks are type-aware (only format for display — the actual speed fix)');
$displayGuards = substr_count($src, "type === 'display'");
($displayGuards >= 6)
    ? ok("found $displayGuards type==='display' guards across the drill columns — sort/filter/type stay cheap")
    : bad("only $displayGuards type==='display' guards found — expensive formatting may run 3-4x per row per column again");

head('Grouped invoices display (collected/recognized/pipeline) preserved as per-row data, not colspan header rows');
str_contains($src, 'function drillGroupLabel')
    ? ok('drillGroupLabel() renders the group as a per-row label')
    : bad('drillGroupLabel() missing — grouped display may have been dropped entirely');
str_contains($src, 'function buildDrillRows')
    ? ok('buildDrillRows() flattens collected/recognized/pipeline into one row array')
    : bad('buildDrillRows() missing — grouped API shape may no longer be handled');
(!preg_match('/colspan="8"[^>]*>\s*<i class="bi bi-check-circle-fill/', $src))
    ? ok('old colspan="8" COLLECTED divider row removed (incompatible with DataTables paging)')
    : bad('old colspan divider row still present — would break DataTables pagination across groups');
str_contains($src, "data.group === 'pipeline'") && str_contains($src, "opacity")
    ? ok('pipeline rows still get dimmed (opacity) styling via createdRow()')
    : bad('pipeline dimming style no longer applied — visual regression for not-yet-recognised rows');

head('Loading/error states no longer rely on the removed drillBody tbody');
str_contains($src, 'drillLoading')
    ? ok('a dedicated #drillLoading indicator replaces the old in-tbody spinner row')
    : bad('#drillLoading indicator missing — no loading feedback while the AJAX call is in flight');
str_contains($src, "Swal.fire({ icon: 'error'")
    ? ok('fetch failures surface via SweetAlert2 (§UI-4), not an injected error <tr>')
    : bad('error handling no longer uses SweetAlert2 — check openDrill()\'s fail handler');
