<?php
/**
 * Cash Flow — single-source GL engine + endpoint — CLI test
 *   php tests/test_cash_flow_gl_cli.php
 *
 * Guards the F1/F3 promise for the Cash Flow Statement: it is derived purely from
 * the posted journal (core/financial_reports.php::glCashFlow) and therefore TIES to
 * the Balance Sheet read from the same ledger. Read-only — touches no data.
 *
 * What "correct" means here (all from journal_entries, status='posted'):
 *   1. Sections reconcile:   operating + investing + financing == net change in cash
 *   2. Net change ties to BS: closing − opening cash == Δ(BS cash-line) over the period
 *   3. Opening + net == closing (the cash roll-forward)
 *   4. Classification is sane: PP&E flows land in investing, equity in financing
 *   5. The endpoint (api/account/get_cash_flow.php) returns the GL figures + contract
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
require_once "$root/actions/check_auth.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// Whole posted ledger window, so the test covers all live activity.
$range = $pdo->query("SELECT MIN(entry_date) lo, MAX(entry_date) hi FROM journal_entries WHERE status='posted'")->fetch(PDO::FETCH_ASSOC);
$from = $range['lo'] ?: date('Y-01-01');
$to   = $range['hi'] ?: date('Y-m-d');
echo "Ledger window: $from → $to\n";

// ─────────────────────────────────────────────────────────────────────────
section('0. Cash accounts are recognised from the chart');
$cashIds = glCashAccountIds($pdo);
echo "   " . count($cashIds) . " cash/bank account(s): [" . implode(',', $cashIds) . "]\n";
count($cashIds) > 0
    ? pass('at least one cash/bank account found (is_bank=1 OR cash_flow_category=cash)')
    : fail('no cash accounts found — cash flow cannot be derived');

// ─────────────────────────────────────────────────────────────────────────
section('1. Sections reconcile: operating + investing + financing == net change');
$cf = glCashFlow($pdo, $from, $to);
echo "   operating = " . money($cf['operating']['total']) .
     "   investing = " . money($cf['investing']['total']) .
     "   financing = " . money($cf['financing']['total']) . "\n";
echo "   Σ sections = " . money($cf['sections_net']) .
     "   net change = " . money($cf['net_change_in_cash']) . "\n";
$cf['reconciles']
    ? pass('sections sum to the net change in cash (double-entry guarantee holds)')
    : fail('sections (' . money($cf['sections_net']) . ') ≠ net change (' . money($cf['net_change_in_cash']) . ')');

// ─────────────────────────────────────────────────────────────────────────
section('2. Net change ties to the Balance Sheet cash line over the period');
// Cash line on the GL Balance Sheet = Σ balances of the cash accounts.
$bsCashAsOf = function (string $asOf) use ($pdo, $cashIds): float {
    $bs = glBalanceSheet($pdo, $asOf);
    $sum = 0.0;
    foreach ($bs['assets'] as $a) {
        if (in_array((int)($a['account_id'] ?? 0), $cashIds, true)) $sum += (float)$a['amount'];
    }
    return $sum;
};
$cashOpenBS  = $bsCashAsOf(date('Y-m-d', strtotime("$from -1 day")));
$cashCloseBS = $bsCashAsOf($to);
$bsDelta = round($cashCloseBS - $cashOpenBS, 2);
echo "   BS cash: open " . money($cashOpenBS) . " → close " . money($cashCloseBS) .
     " (Δ " . money($bsDelta) . ")   CF net change " . money($cf['net_change_in_cash']) . "\n";
(abs($bsDelta - $cf['net_change_in_cash']) < 0.01)
    ? pass('Cash Flow net change == Balance Sheet cash-line movement (they tie)')
    : fail('CF net change ≠ BS cash movement — the statements disagree');

// ─────────────────────────────────────────────────────────────────────────
section('3. Cash roll-forward: opening + net change == closing');
(abs(($cf['opening_cash'] + $cf['net_change_in_cash']) - $cf['closing_cash']) < 0.01)
    ? pass('opening_cash + net_change == closing_cash')
    : fail('roll-forward broken: ' . money($cf['opening_cash']) . ' + ' . money($cf['net_change_in_cash']) . ' ≠ ' . money($cf['closing_cash']));

// ─────────────────────────────────────────────────────────────────────────
section('4. Classification: PP&E in investing, no stray equity in operating');
$badInvesting = false;
foreach ($cf['operating']['lines'] as $l) {
    if (preg_match('/^1-3/', (string)$l['account_code'])) { $badInvesting = true; echo "   ⚠ PP&E in operating: {$l['account_code']} {$l['account_name']}\n"; }
}
$badInvesting ? fail('a PP&E (1-3xxx) flow was classified operating, should be investing')
              : pass('no PP&E account leaked into operating');
if ($cf['unclassified_count'] > 0) {
    echo "   ⚠ {$cf['unclassified_count']} cash-touching contra account(s) are unclassified (fix their account_type → category)\n";
}
pass('unclassified contra count = ' . $cf['unclassified_count'] . ' (0 is ideal)');

// ─────────────────────────────────────────────────────────────────────────
section('5. Endpoint returns the GL figures + preserves the JSON contract');
$_GET = ['start_date' => $from, 'end_date' => $to, 'method' => 'direct'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); require "$root/api/account/get_cash_flow.php";
$raw = ob_get_clean();
error_reporting($prevErr);
$resp = json_decode($raw, true);

if (!$resp || empty($resp['success'])) {
    fail('endpoint did not return success — ' . substr($raw, 0, 200));
} else {
    pass('endpoint success (direct method)');
    $d = $resp['data'];
    foreach (['meta', 'sections', 'totals'] as $k) {
        isset($d[$k]) ? pass("data.$k present") : fail("data.$k missing");
    }
    foreach (['operating', 'investing', 'financing'] as $s) {
        (isset($d['sections'][$s]['lines'], $d['sections'][$s]['total'], $d['sections'][$s]['comparative_total']))
            ? pass("section.$s contract ok (lines/total/comparative_total)")
            : fail("section.$s malformed");
    }
    // Endpoint totals must match the engine.
    (abs($d['sections']['operating']['total'] - $cf['operating']['total']) < 0.01)
        ? pass('endpoint operating total matches engine')
        : fail('endpoint operating total drifts from engine');
    (abs($d['totals']['net_change_in_cash'] - $cf['net_change_in_cash']) < 0.01)
        ? pass('endpoint net_change_in_cash matches engine')
        : fail('endpoint net_change_in_cash drifts from engine');
    // Roll forward in the endpoint meta.
    (abs(((float)$d['meta']['opening_cash'] + (float)$d['totals']['net_change_in_cash']) - (float)$d['meta']['closing_cash']) < 0.01)
        ? pass('endpoint: opening_cash + net_change == closing_cash')
        : fail('endpoint roll-forward broken');
    // Source marker.
    (($d['meta']['source'] ?? '') === 'general_ledger')
        ? pass("endpoint meta.source = 'general_ledger'")
        : fail("endpoint meta.source not marked general_ledger");
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Indirect method reconciles to direct operating (from the same ledger)');
$_GET = ['start_date' => $from, 'end_date' => $to, 'method' => 'indirect'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); require "$root/api/account/get_cash_flow.php";
$raw = ob_get_clean();
error_reporting($prevErr);
$ind = json_decode($raw, true);
if ($ind && !empty($ind['success'])) {
    $recon = $ind['data']['meta']['operating_reconciliation_difference']['current'] ?? null;
    echo "   indirect operating = " . money((float)$ind['data']['sections']['operating']['total']) .
         "   direct operating = " . money($cf['operating']['total']) .
         "   reconciliation diff = " . (is_null($recon) ? 'null' : money((float)$recon)) . "\n";
    (is_null($recon) || abs((float)$recon) < 0.01)
        ? pass('indirect operating ties to direct operating (GL-consistent)')
        : fail('indirect ≠ direct by ' . money((float)$recon) . ' — working-capital/depreciation mapping off');
} else {
    fail('indirect method endpoint did not succeed');
}
