<?php
/**
 * Phase 2.3 — General Ledger route + Trial Balance drill-down CLI test
 * ---------------------------------------------------------------------
 *   php tests/test_phase2_route_and_drilldown_cli.php
 *
 * Verifies:
 *   1. reports.php lint-clean.
 *   2. Dispatcher contains the general_ledger route branch.
 *   3. Financial Reports menu has a General Ledger tile linking to
 *      ?report=general_ledger and labelled "(audit trail)".
 *   4. General Ledger tile sits inside the Financial Reports group
 *      (positional check, same shape as Phase 1.3).
 *   5. Trial Balance partial contains drill-down anchors with:
 *      - report=general_ledger query string
 *      - account_id parameter
 *      - start_date set to Jan 1 of as_of_date.year (year-to-date)
 *      - end_date set to as_of_date
 *      - project_id passthrough when filter active
 *   6. Runtime: ?report=general_ledger dispatches to the GL partial
 *      with no account picked, returning the "Pick an account" prompt.
 *   7. Runtime: ?report=general_ledger&account_id=2 dispatches to the
 *      GL partial with the account loaded, showing Opening / Closing
 *      balance cards.
 *   8. Runtime: rendered TB HTML contains a real GL drill-down anchor
 *      for an existing account row.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$reports_file = "$root/app/bms/invoice/reports.php";
$tb_file      = "$root/app/bms/invoice/reps/trial_balance.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. reports.php lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$rc = 0; exec("php -l " . escapeshellarg($reports_file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('reports.php lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Route + menu tile present in source');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($reports_file);
$checks = [
    "\$report === 'general_ledger'"                     => 'route branch for general_ledger present',
    "include 'reps/general_ledger.php'"                 => 'dispatcher includes the GL partial',
    "?report=general_ledger"                            => 'menu tile links to the GL report',
    '<span>General Ledger'                              => 'tile span literal present',
    '(audit trail)'                                     => 'audit-trail hint on tile',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 40) . "`");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. General Ledger tile sits inside the Financial Reports group');
// ─────────────────────────────────────────────────────────────────────────
$fin_pos = strpos($src, 'Financial Reports');
$gl_pos  = strpos($src, '<span>General Ledger');
$inv_pos = strpos($src, 'Inventory Reports');

if ($fin_pos === false || $gl_pos === false) {
    fail('positional markers missing — cannot verify group placement');
} elseif ($gl_pos > $fin_pos && ($inv_pos === false || $gl_pos < $inv_pos)) {
    pass('General Ledger tile is located inside the Financial Reports group');
} else {
    fail('General Ledger tile is outside the Financial Reports group');
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Trial Balance partial has drill-down anchors with correct params');
// ─────────────────────────────────────────────────────────────────────────
$tbsrc = file_get_contents($tb_file);
$tb_checks = [
    "'report'     => 'general_ledger'"           => 'anchor builds report=general_ledger',
    "'account_id' => (int)\$a['account_id']"     => 'anchor passes account_id',
    "'start_date' => \$gl_start"                 => 'anchor passes start_date',
    "'end_date'   => \$gl_end"                   => 'anchor passes end_date',
    "'project_id' => \$project_id !== null"      => 'anchor passes project_id when set',
    "date('Y-01-01', strtotime(\$as_of_date))"   => 'start_date = Jan 1 of as_of_date.year (YTD)',
    "tb-drilldown"                               => 'drill-down anchor uses tb-drilldown class',
    'a.tb-drilldown:hover'                       => 'tb-drilldown hover style declared',
    'Click any account row to drill down'        => 'notes updated to mention drill-down',
];
foreach ($tb_checks as $needle => $label) {
    strpos($tbsrc, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime: ?report=general_ledger (no account) renders the prompt');
// ─────────────────────────────────────────────────────────────────────────
$_GET = ['report' => 'general_ledger'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $reports_file;
    $html = ob_get_clean();
    error_reporting($prevErr);

    strpos($html, 'General Ledger') !== false ? pass('GL page title rendered') : fail('page title missing');
    strpos($html, 'Pick an account') !== false ? pass('"Pick an account" prompt rendered') : fail('prompt missing');
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('reports.php?report=general_ledger threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime: ?report=general_ledger&account_id=2 renders balance cards');
// ─────────────────────────────────────────────────────────────────────────
$_GET = ['report' => 'general_ledger', 'account_id' => 2,
         'start_date' => '2026-01-01', 'end_date' => '2026-05-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $reports_file;
    $html = ob_get_clean();
    error_reporting($prevErr);

    $markers = [
        'Opening Balance Equity'      => 'account name rendered (account_id=2 resolves)',
        'Opening Balance'             => 'opening balance card heading',
        'Closing Balance'             => 'closing balance card heading',
        '12,000.00'                   => 'opening balance figure',
        '13,000.00'                   => 'closing balance figure',
    ];
    foreach ($markers as $needle => $label) {
        strpos($html, $needle) !== false ? pass("rendered HTML contains: $label") : fail("rendered HTML missing: $label");
    }
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('reports.php?report=general_ledger&account_id=2 threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Rendered TB HTML contains a real GL drill-down anchor');
// ─────────────────────────────────────────────────────────────────────────
$_GET = ['report' => 'trial_balance', 'as_of_date' => '2026-05-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $reports_file;
    $html = ob_get_clean();
    error_reporting($prevErr);

    // Should have at least one anchor like:
    //   href="...reports...report=general_ledger&account_id=...&start_date=2026-01-01..."
    $pattern = '/href="[^"]*report=general_ledger[^"]*account_id=\d+[^"]*start_date=2026-01-01[^"]*end_date=2026-05-31[^"]*"/';
    preg_match($pattern, $html) === 1
        ? pass('TB HTML contains a real drill-down anchor (report=GL, account_id=N, YTD start, as-of end)')
        : fail('expected drill-down anchor not found in rendered TB HTML');

    // And the anchor should use tb-drilldown class
    strpos($html, 'class="tb-drilldown') !== false
        || strpos($html, "class='tb-drilldown") !== false
        || strpos($html, ' tb-drilldown ') !== false
        || strpos($html, 'tb-drilldown text') !== false
        ? pass('rendered anchor uses tb-drilldown class')
        : fail('rendered anchor missing tb-drilldown class');
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('TB render for drill-down check threw: ' . $e->getMessage());
}

exit($failures === 0 ? 0 : 1);
