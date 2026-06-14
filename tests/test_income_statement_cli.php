<?php
/**
 * Income Statement (GL-derived) — endpoint contract + ties to Balance Sheet — CLI test
 *   php tests/test_income_statement_cli.php
 *
 * The F3 flip (2026-06-14) replaced the document-hybrid P&L with a single-source,
 * GL-derived one (core/financial_reports.php::glProfitLoss). This guards the new
 * contract:
 *   - meta.source = 'general_ledger'; sections + totals shape preserved
 *   - professional totals math: gross_profit = revenue − cogs;
 *     operating_profit = gross_profit − expenses; net_profit consistent
 *   - section line totals reconcile to section totals
 *   - the P&L TIES to the Balance Sheet: all-time net profit == BS retained earnings
 *   - non-admin out-of-scope project → 403
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

if (session_status() === PHP_SESSION_NONE) @session_start();
$adminId = (int)($pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
$_SESSION['user_id'] = $adminId; $_SESSION['is_admin'] = true; $_SESSION['role'] = 'admin'; $_SESSION['role_id'] = 1;

section('1. Source pattern: derives from the GL engine, not documents');
$src = file_get_contents("$root/api/account/get_income_statement.php");
$present = [
    "core/financial_reports.php" => 'includes the single-source GL engine',
    "glProfitLoss("              => 'derives the P&L from glProfitLoss (posted journal only)',
    "scopeFilterSqlNullable('project'" => 'project scope via canonical helper',
    "userCan('project'"          => 'authorization via userCan()',
    "'general_ledger'"           => "meta.source marks the GL as the single source",
    "gross_profit"               => 'computes gross profit',
    "operating_profit"           => 'computes operating profit',
];
foreach ($present as $needle => $label) (strpos($src, $needle) !== false) ? pass($label) : fail("$label — missing");
$absent = ["FROM pos_sales" => 'no direct pos_sales read', "grand_total - tax_amount" => 'no document revenue computation', "LIKE '%Salaries%'" => 'no payroll LIKE hack'];
foreach ($absent as $needle => $label) (strpos($src, $needle) === false) ? pass($label) : fail("$label — old pattern present");

section('2. Endpoint runtime + professional totals math');
$_GET = ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); include "$root/api/account/get_income_statement.php"; $raw = ob_get_clean();
error_reporting($prevErr);
$r = json_decode($raw, true);
(!empty($r['success'])) ? pass('endpoint success') : fail('endpoint failed: '.substr($raw,0,200));
if (empty($r['success'])) return;
$d = $r['data']; $t = $d['totals'];
(($d['meta']['source'] ?? '') === 'general_ledger') ? pass("meta.source=general_ledger") : fail('not GL-sourced');
foreach (['revenue','cogs','expense','other_income','finance_costs'] as $s)
    isset($d['sections'][$s]) ? pass("section.$s present") : fail("section.$s missing");
foreach (['total_revenue','total_cogs','gross_profit','total_expenses','operating_profit','net_profit'] as $k)
    array_key_exists($k, $t) ? pass("totals.$k present") : fail("totals.$k missing");
(abs($t['gross_profit'] - ($t['total_revenue'] - $t['total_cogs'])) < 0.5)
    ? pass('gross_profit = revenue − cogs') : fail('gross_profit formula wrong');
(abs($t['operating_profit'] - ($t['gross_profit'] - $t['total_expenses'])) < 0.5)
    ? pass('operating_profit = gross_profit − expenses') : fail('operating_profit formula wrong');
(abs($t['net_profit'] - ($t['operating_profit'] + $t['other_income'] - $t['finance_costs'] - $t['income_tax'])) < 0.5)
    ? pass('net_profit = operating_profit + other_income − finance − tax') : fail('net_profit formula wrong');
isset($t['previous']['net_profit']) ? pass('comparative period present') : fail('comparative missing');

section('3. Section line totals reconcile');
foreach (['revenue','cogs','expense'] as $s) {
    $sum = 0.0; foreach ($d['sections'][$s]['lines'] as $l) $sum += (float)$l['current'];
    (abs($sum - (float)$d['sections'][$s]['total_current']) < 0.5)
        ? pass("$s lines sum to total_current") : fail("$s lines != total_current");
}

section('4. The P&L ties to the Balance Sheet');
$plAll = glProfitLoss($pdo, '2000-01-01', '2026-12-31');
$bs    = glBalanceSheet($pdo, '2026-12-31');
echo "   all-time net profit = " . money($plAll['net_profit']) . "   BS retained earnings = " . money($bs['retained_earnings']) . "\n";
(abs($plAll['net_profit'] - $bs['retained_earnings']) < 0.01)
    ? pass('all-time P&L net profit == Balance Sheet retained earnings') : fail('P&L does not tie to the BS');

section('5. Non-admin out-of-scope project → 403');
$_SESSION['is_admin'] = false; $_SESSION['scope'] = ['projects' => []];
$pid = (int)($pdo->query("SELECT project_id FROM projects ORDER BY project_id LIMIT 1")->fetchColumn() ?: 0);
if ($pid) {
    $_GET = ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'project_id' => $pid];
    $prevErr = error_reporting(error_reporting() & ~E_WARNING);
    ob_start(); @include "$root/api/account/get_income_statement.php"; $raw2 = ob_get_clean();
    error_reporting($prevErr);
    $r2 = json_decode($raw2, true);
    ($r2 && empty($r2['success']) && stripos($r2['message'] ?? '', 'not in your assigned scope') !== false)
        ? pass("out-of-scope project_id=$pid rejected") : fail('out-of-scope project not rejected');
} else { pass('no project to test scope (n/a)'); }
