<?php
/**
 * Phase 1.3 — Trial Balance route + menu tile CLI test
 * -----------------------------------------------------
 *   php tests/test_phase1_trial_balance_route_cli.php
 *
 * Verifies:
 *   1. reports.php is lint-clean.
 *   2. The dispatcher contains the trial_balance route branch.
 *   3. The Financial Reports menu has a Trial Balance tile linking to
 *      ?report=trial_balance.
 *   4. The "working doc" italic hint is present on the tile (CFI labelling).
 *   5. Runtime: including reports.php with ?report=trial_balance under an
 *      authenticated admin session renders the Trial Balance partial
 *      (proves route -> partial dispatch works).
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

// ─────────────────────────────────────────────────────────────────────────
section('1. reports.php lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$file = "$root/app/bms/invoice/reports.php";
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('reports.php lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Route + menu tile patterns present in source');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($file);

$checks = [
    "\$report === 'trial_balance'"                      => 'route branch for trial_balance present',
    "include 'reps/trial_balance.php'"                  => 'dispatcher includes the partial',
    "?report=trial_balance"                             => 'menu tile links to the report',
    '<span>Trial Balance'                               => 'tile span literal present',
    '(working doc)'                                     => 'CFI "working doc" hint on tile',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 40) . "`");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Trial Balance tile sits inside the Financial Reports group');
// ─────────────────────────────────────────────────────────────────────────
// Find the span of the "Financial Reports" card and assert the TB tile
// occurs inside it (not, say, under Sales Reports or Inventory Reports).
$fin_pos = strpos($src, 'Financial Reports');
$tb_pos  = strpos($src, '<span>Trial Balance');
$inv_pos = strpos($src, 'Inventory Reports');

if ($fin_pos === false) {
    fail('"Financial Reports" group header missing — reports.php structure changed');
} elseif ($tb_pos === false) {
    fail('Trial Balance tile not in reports.php');
} else {
    if ($tb_pos > $fin_pos && ($inv_pos === false || $tb_pos < $inv_pos)) {
        pass('Trial Balance tile is located inside the Financial Reports group');
    } else {
        fail('Trial Balance tile is outside the Financial Reports group (positional check failed)');
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime: ?report=trial_balance dispatches to the partial');
// ─────────────────────────────────────────────────────────────────────────
// We include reports.php directly with the report param set. The dispatcher
// should route through and render the TB partial's expected markers.
$_GET = ['report' => 'trial_balance', 'as_of_date' => '2026-05-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $file;
    $html = ob_get_clean();
    error_reporting($prevErr);

    $markers = [
        'Trial Balance'                  => 'heading rendered',
        'Internal Working Document'      => 'CFI labelling rendered',
        'As of Date'                     => 'date filter label rendered',
        'Account Name'                   => 'TB table column header rendered',
    ];
    foreach ($markers as $needle => $label) {
        strpos($html, $needle) !== false ? pass("rendered HTML contains: $label") : fail("rendered HTML missing: $label");
    }
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('reports.php?report=trial_balance threw during render: ' . $e->getMessage());
}

exit($failures === 0 ? 0 : 1);
