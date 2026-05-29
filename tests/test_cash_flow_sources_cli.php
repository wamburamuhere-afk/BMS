<?php
/**
 * Cash Flow — operational-source rewrite — CLI test
 * --------------------------------------------------
 *   php tests/test_cash_flow_sources_cli.php
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

// 1. Files
section('1. Files exist + lint clean');
foreach (['api/account/get_cash_flow.php', 'app/bms/invoice/reps/cash_flow.php'] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0;
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    if ($rc === 0) pass($f); else fail("php -l failed: $f");
}

// 2. API source
section('2. API source contains agreed Cash Flow patterns');
$apiSrc = readSrc($root, 'api/account/get_cash_flow.php');
$checks = [
    "FROM payments p"                                => 'queries payments for customer receipts',
    "FROM supplier_payments"                         => 'queries supplier_payments for supplier outflow',
    "FROM payroll"                                   => 'queries payroll for salaries paid',
    "payment_status = 'paid'"                        => 'salaries filter on payment_status=paid',
    "FROM expenses"                                  => 'queries expenses for opex paid',
    "FROM assets"                                    => 'queries assets for CapEx (investing)',
    "purchase_date BETWEEN"                          => 'investing windowed by purchase_date',
    "Financing"                                      => 'financing section comment/label',
    "scopeFilterSqlNullable('project'"               => 'uses canonical scope helper',
    "userCan('project'"                              => 'authorization via canonical userCan()',
    "'opening_cash'"                                 => 'meta exposes opening_cash',
    "'closing_cash'"                                 => 'meta exposes closing_cash',
    "'net_change_in_cash'"                           => 'totals expose net_change_in_cash',
];
foreach ($checks as $needle => $label) {
    if (strpos($apiSrc, $needle) !== false) pass($label);
    else fail("$label — missing `" . substr($needle, 0, 50) . "`");
}

// 3. Partial integration
section('3. Partial wires up the new API');
$partialSrc = readSrc($root, 'app/bms/invoice/reps/cash_flow.php');
$pchecks = [
    "require __DIR__ . '/../../../../api/account/get_cash_flow.php'" => 'partial requires new CF API',
    'name="project_id"'                              => 'project filter dropdown present',
    'name="start_date"'                              => 'period start input present',
    'name="end_date"'                                => 'period end input present',
    'OPERATING ACTIVITIES'                           => 'operating section label present',
    'INVESTING ACTIVITIES'                           => 'investing section label present',
    'FINANCING ACTIVITIES'                           => 'financing section label present',
    'No financing activity tracked'                  => 'financing section explains no loans tracking',
    'Closing Cash &amp; Bank Balance'                => 'closing cash line present',
];
foreach ($pchecks as $needle => $label) {
    if (strpos($partialSrc, $needle) !== false) pass($label);
    else fail("$label — missing `" . substr($needle, 0, 50) . "`");
}

// 4. Runtime
section('4. Runtime: API responds correctly against live DB');
$_GET = ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); require "$root/api/account/get_cash_flow.php";
$raw = ob_get_clean();
error_reporting($prevErr);
$r = json_decode($raw, true);

if (!$r || empty($r['success'])) {
    fail('admin run: non-success — ' . substr($raw, 0, 200));
} else {
    pass('admin: API success for 2026 full-year');
    $d = $r['data'];

    foreach (['meta','sections','totals'] as $k) {
        isset($d[$k]) ? pass("response.data.$k present") : fail("response.data.$k missing");
    }
    foreach (['operating','investing','financing'] as $s) {
        if (isset($d['sections'][$s]['lines'], $d['sections'][$s]['total'])) pass("section.$s shape ok");
        else fail("section.$s malformed");
    }

    // Math: net_change = operating + investing + financing
    $sum = $d['sections']['operating']['total']
         + $d['sections']['investing']['total']
         + $d['sections']['financing']['total'];
    if (abs($sum - $d['totals']['net_change_in_cash']) < 0.5) {
        pass('totals: net_change = operating + investing + financing');
    } else {
        fail('totals: net_change math wrong');
    }

    // Financing should be 0 (per user instruction excluding loans)
    if (abs($d['sections']['financing']['total']) < 0.001) {
        pass('financing section is 0 (loans excluded per user spec)');
    } else {
        fail('financing total non-zero — should be empty until borrowing tracking exists');
    }

    // Opening + net change = closing
    $oc = $d['meta']['opening_cash']; $cc = $d['meta']['closing_cash'];
    if (abs(($oc + $d['totals']['net_change_in_cash']) - $cc) < 1.0) {
        pass('integrity: opening_cash + net_change = closing_cash');
    } else {
        fail("integrity: opening($oc) + net(" . $d['totals']['net_change_in_cash'] . ") != closing($cc)");
    }
}

// Non-admin out-of-scope project → 403
$_SESSION['is_admin'] = false;
$_SESSION['scope']    = ['projects' => []];
$pid = (int)$pdo->query("SELECT project_id FROM projects ORDER BY project_id LIMIT 1")->fetchColumn();
if ($pid) {
    $_GET = ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'project_id' => $pid];
    $prevErr = error_reporting(error_reporting() & ~E_WARNING);
    ob_start(); @require "$root/api/account/get_cash_flow.php";
    $raw = ob_get_clean();
    error_reporting($prevErr);
    $r = json_decode($raw, true);
    if ($r && empty($r['success']) && stripos($r['message'] ?? '', 'not in your assigned scope') !== false) {
        pass("non-admin out-of-scope project_id=$pid → 403 enforced");
    } else {
        fail("non-admin out-of-scope project_id=$pid should have been rejected");
    }
}

exit($failures === 0 ? 0 : 1);
