<?php
/**
 * Books Health Check page — CLI test
 *   php tests/test_books_health_cli.php
 *
 * Guards app/constant/reports/books_health.php:
 *   - it is READ-ONLY (no INSERT/UPDATE/DELETE — pure diagnostics)
 *   - it runs the canonical GL diagnostics from core/financial_reports.php
 *   - it renders for an admin and its verdict is consistent with assertLedgerBalanced
 *     and the P&L↔BS tie (so the page can be trusted to verify production)
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

$file = "$root/app/constant/reports/books_health.php";

section('1. Exists, lint-clean, and READ-ONLY (no write SQL)');
file_exists($file) ? pass('page exists') : fail('page missing');
$rc=0; exec('php -l ' . escapeshellarg($file) . ' 2>&1', $o, $rc); $rc===0 ? pass('lint-clean') : fail('lint failed');
$src = file_get_contents($file);
$writes = preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w|DELETE\s+FROM|TRUNCATE|ALTER\s+TABLE|DROP\s+)\b/i', $src);
!$writes ? pass('no write SQL in the page (read-only)') : fail('page contains write SQL — NOT read-only');

section('2. Runs the canonical GL diagnostics');
foreach ([
    'assertLedgerBalanced(' => 'double-entry + BS guardrail',
    'glBalanceSheet('       => 'Balance Sheet',
    'glProfitLoss('         => 'P&L (for the BS tie)',
    'glStrandedInactiveAccounts(' => 'stranded inactive accounts',
    'glOpeningBalanceImbalance('   => 'opening-balance health',
] as $needle => $label) (strpos($src, $needle) !== false) ? pass("uses $label") : fail("missing $label");
(strpos($src, "autoEnforcePermission('financial_reports')") !== false) ? pass('gated by financial_reports permission') : fail('not permission-gated');

section('3. Renders for admin; verdict consistent with the diagnostics');
if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['user_id'] = (int)($pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
$_SESSION['is_admin'] = true; $_SESSION['role'] = 'admin'; $_SESSION['role_id'] = 1;
$_GET = ['page' => 'books_health', 'as_of_date' => '2026-12-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ob_start(); include $file; $html = ob_get_clean();
error_reporting($prevErr);
(strlen($html) > 2000 && strpos($html, 'Books Health Check') !== false) ? pass('page rendered') : fail('page did not render');

// Independently compute the expected verdict and confirm the page agrees.
$g = assertLedgerBalanced($pdo, '2026-12-31');
$bs = glBalanceSheet($pdo, '2026-12-31');
$inc = $pdo->query("SELECT COALESCE(MIN(entry_date),'2000-01-01') FROM journal_entries WHERE status='posted'")->fetchColumn();
$pl = glProfitLoss($pdo, $inc, '2026-12-31');
$tie = abs($pl['net_profit'] - $bs['retained_earnings']) < 0.01;
$expectHealthy = $g['ledger_balanced'] && $g['bs_balanced'] && $tie
    && !count(glStrandedInactiveAccounts($pdo)) && glOpeningBalanceImbalance($pdo)['balanced'] && !count($bs['unclassified']);
$pageHealthy = strpos($html, 'Healthy — the books balance') !== false;
($pageHealthy === $expectHealthy) ? pass('page verdict matches the diagnostics (' . ($expectHealthy?'healthy':'issues found') . ')') : fail('page verdict disagrees with the diagnostics');

// The three CRITICAL checks must be reported truthfully.
($g['ledger_balanced'] ? strpos($html,'bi-check-circle-fill text-success') !== false : true) ? pass('double-entry status shown') : fail('double-entry status not shown');
