<?php
/**
 * Balance Sheet — operational-source rewrite — CLI test
 * ------------------------------------------------------
 *   php tests/test_balance_sheet_sources_cli.php
 *
 * Verifies:
 *   1. Files exist + lint clean (API + partial).
 *   2. API source contains the agreed data sources, project filter,
 *      scope helpers, and balancing-plug logic.
 *   3. Partial wires up the new API and project filter.
 *   4. Runtime against live DB: API returns expected shape, totals
 *      balance, scope security works.
 *
 * Exit 0 = all pass. Exit 1 = failures found.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/actions/check_auth.php";
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
section('1. Files exist + lint clean');
// ─────────────────────────────────────────────────────────────────────────
$files = [
    'api/account/get_balance_sheet.php',
    'app/bms/invoice/reps/balance_sheet.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0;
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    if ($rc === 0) pass($f); else fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. API source contains agreed data-source patterns');
// ─────────────────────────────────────────────────────────────────────────
$apiSrc = readSrc($root, 'api/account/get_balance_sheet.php');
$checks = [
    "FROM invoices"                                  => 'queries invoices for AR',
    "status NOT IN ('paid','cancelled')"             => 'AR filter: unpaid + not cancelled',
    "FROM product_stocks"                            => 'queries product_stocks for inventory',
    "stock_quantity * COALESCE(p.cost_price"         => 'inventory = qty × cost_price',
    "FROM assets"                                    => 'queries assets table for fixed assets',
    "FROM supplier_invoices"                         => 'queries supplier_invoices for AP',
    "status = 'approved'\n               AND payment_date IS NULL" => "AP filter: approved + unpaid",
    "FROM payroll"                                   => 'queries payroll for salaries payable',
    "payment_status IS NULL OR payment_status != 'paid'" => "salaries payable filter on payment_status",
    "account_type_id = 1"                            => 'cash & bank from asset-typed accounts',
    "account_type_id = 3"                            => 'opening equity from equity-typed accounts',
    "Retained Earnings (computed)"                   => 'retained earnings as balancing plug',
    "scopeFilterSqlNullable('project'"               => 'uses canonical scope helper',
    "userCan('project'"                              => 'authorization via canonical userCan()',
    "'project_filter_active'"                        => 'meta exposes project_filter_active',
    "'is_admin'"                                     => 'meta exposes is_admin',
    "'balanced'"                                     => 'totals expose balanced flag',
];
foreach ($checks as $needle => $label) {
    if (strpos($apiSrc, $needle) !== false) pass($label);
    else fail("$label — missing `" . substr($needle, 0, 50) . "`");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Partial integrates with API + project filter');
// ─────────────────────────────────────────────────────────────────────────
$partialSrc = readSrc($root, 'app/bms/invoice/reps/balance_sheet.php');
$pchecks = [
    "require __DIR__ . '/../../../../api/account/get_balance_sheet.php'" => 'partial includes new BS API',
    'name="project_id"'                              => 'project filter dropdown present',
    'get_projects_for_filter.php'                    => 'projects loaded via scoped endpoint',
    'project_filter_active'                          => 'project filter banner uses meta flag',
];
foreach ($pchecks as $needle => $label) {
    if (strpos($partialSrc, $needle) !== false) pass($label);
    else fail("$label — missing `" . substr($needle, 0, 50) . "`");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime: API responds correctly against live DB');
// ─────────────────────────────────────────────────────────────────────────
$_GET = ['as_of_date' => '2026-05-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); require "$root/api/account/get_balance_sheet.php";
$raw = ob_get_clean();
error_reporting($prevErr);
$r = json_decode($raw, true);

if (!$r || empty($r['success'])) {
    fail('admin run: non-success — ' . substr($raw, 0, 200));
} else {
    pass('admin: API success for full company BS as of 2026-05-31');
    $d = $r['data'];

    foreach (['meta','sections','totals'] as $k) {
        isset($d[$k]) ? pass("response.data.$k present") : fail("response.data.$k missing");
    }
    foreach (['assets','liabilities','equity'] as $s) {
        if (isset($d['sections'][$s]['lines'], $d['sections'][$s]['total'])) pass("section.$s shape ok");
        else fail("section.$s malformed");
    }

    // The fundamental check: Total Assets must equal Total Liab+Equity (plug
    // makes this true). Difference under 1 TZS means rounding only.
    if (!empty($d['totals']['balanced']) && abs($d['totals']['balance_difference']) < 1.0) {
        pass('totals: Balance Sheet balances (Assets = Liab + Equity)');
    } else {
        fail('totals: Balance Sheet does not balance — diff=' . ($d['totals']['balance_difference'] ?? '?'));
    }
}

// Non-admin out-of-scope project → 403
$_SESSION['is_admin'] = false;
$_SESSION['scope']    = ['projects' => []];
// Pick a real project the test session doesn't own (empty scope = any project is out-of-scope)
$pid = (int)$pdo->query("SELECT project_id FROM projects ORDER BY project_id LIMIT 1")->fetchColumn();
if ($pid) {
    $_GET = ['as_of_date' => '2026-05-31', 'project_id' => $pid];
    $prevErr = error_reporting(error_reporting() & ~E_WARNING);
    ob_start(); @require "$root/api/account/get_balance_sheet.php";
    $raw = ob_get_clean();
    error_reporting($prevErr);
    $r = json_decode($raw, true);
    if ($r && empty($r['success']) && stripos($r['message'] ?? '', 'not in your assigned scope') !== false) {
        pass("non-admin out-of-scope project_id=$pid → 403 enforced");
    } else {
        fail("non-admin out-of-scope project_id=$pid should have been rejected");
    }
}

// Summary printed by shutdown handler.
exit($failures === 0 ? 0 : 1);
