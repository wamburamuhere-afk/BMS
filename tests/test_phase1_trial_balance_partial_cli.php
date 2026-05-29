<?php
/**
 * Phase 1.2 — Trial Balance UI partial CLI test
 * ----------------------------------------------
 *   php tests/test_phase1_trial_balance_partial_cli.php
 *
 * Verifies:
 *   1. File exists + lint-clean.
 *   2. Source contains the agreed structural patterns (canonical-ledger
 *      consumption via internal require, project filter dropdown, scope
 *      banner, BALANCED / OUT OF BALANCE state badges, IFRS labelling).
 *   3. The "Internal Working Document" CFI wording is present.
 *   4. No drill-down links to general_ledger yet (deliberately absent
 *      until Phase 2 — would 404 otherwise).
 *   5. Runtime render: include the partial under an authenticated session
 *      and verify the rendered HTML contains expected anchor text from
 *      the live API response.
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
function readSrc(string $root, string $rel): string {
    $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : '';
}

// ─────────────────────────────────────────────────────────────────────────
section('1. File exists + lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$file = "$root/app/bms/invoice/reps/trial_balance.php";
file_exists($file) ? pass('app/bms/invoice/reps/trial_balance.php exists') : fail('file missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Source contains agreed structural patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = readSrc($root, 'app/bms/invoice/reps/trial_balance.php');
$checks = [
    "canView('reports')"                                        => 'permission gate at top',
    "require __DIR__ . '/../../../../api/account/get_trial_balance.php'" => 'consumes Trial Balance API internally',
    "get_projects_for_filter.php"                               => 'loads projects via the scoped endpoint',
    'name="as_of_date"'                                         => 'date picker present',
    'name="project_id"'                                         => 'project dropdown present',
    'name="report" value="trial_balance"'                       => 'route key set on form',
    'project_filter_active'                                     => 'shows banner when filter active',
    'scoped_project_ids'                                        => 'non-admin scope banner uses meta key',
    "All My Projects"                                           => 'non-admin sees "All My Projects" default',
    "All Projects (Consolidated)"                               => 'admin sees "All Projects (Consolidated)" default',
    'BALANCED'                                                  => 'BALANCED badge for Dr=Cr case',
    'OUT OF BALANCE'                                            => 'OUT OF BALANCE badge for Dr!=Cr',
    'BALANCE SHEET ACCOUNTS'                                    => 'section header for BS',
    'INCOME STATEMENT ACCOUNTS'                                 => 'section header for IS',
    'Subtotal —'                                                => 'subtotal rows per category',
    'Grand Total'                                               => 'grand total row',
    'logReportAction'                                           => 'logs view action',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. CFI wording "Internal Working Document" present');
// ─────────────────────────────────────────────────────────────────────────
$cfi_phrases = [
    'Internal Working Document',
    'not a formal financial statement',
];
foreach ($cfi_phrases as $p) {
    strpos($src, $p) !== false ? pass("phrase present: \"$p\"") : fail("phrase missing: \"$p\"");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. No drill-down links to general_ledger yet (Phase 2 will add)');
// ─────────────────────────────────────────────────────────────────────────
$has_gl_link = (strpos($src, 'general_ledger') !== false || strpos($src, 'getUrl(\'general_ledger') !== false);
$has_gl_link ? fail('found general_ledger references — drill-down should not be wired yet')
             : pass('no general_ledger links yet (correct for Phase 1.2)');

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime render — include the partial and verify HTML');
// ─────────────────────────────────────────────────────────────────────────
$_GET = ['as_of_date' => '2026-05-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $file;
    $html = ob_get_clean();
    error_reporting($prevErr);

    // Markers we expect in the rendered HTML
    $marker_checks = [
        'Trial Balance'                          => 'page heading',
        'Internal Working Document'              => 'CFI labelling rendered',
        'As of Date'                             => 'date filter label',
        'Project'                                => 'project filter label',
        'Account Name'                           => 'table column header',
        'Generate Report'                        => 'submit button',
    ];
    foreach ($marker_checks as $needle => $label) {
        strpos($html, $needle) !== false ? pass("rendered HTML contains: $label") : fail("rendered HTML missing: $label");
    }

    // Either "BALANCED" or "OUT OF BALANCE" should appear (one of the two,
    // depending on live-DB state). Both are valid; one must be present.
    $has_balanced     = strpos($html, '>BALANCED<')           !== false || strpos($html, 'BALANCED</strong>') !== false;
    $has_out_balance  = strpos($html, '>OUT OF BALANCE<')     !== false || strpos($html, 'OUT OF BALANCE</strong>') !== false;
    ($has_balanced || $has_out_balance)
        ? pass('balance-status badge rendered (' . ($has_balanced ? 'BALANCED' : 'OUT OF BALANCE') . ')')
        : fail('neither BALANCED nor OUT OF BALANCE badge found in HTML');
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('partial threw during render: ' . $e->getMessage());
}

exit($failures === 0 ? 0 : 1);
