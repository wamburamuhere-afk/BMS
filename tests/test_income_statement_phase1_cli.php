<?php
/**
 * Income Statement — Phase 1 (GL revenue recognition) CLI test.
 *   php tests/test_income_statement_phase1_cli.php
 *
 * Post-F3-flip: revenue on the P&L is the sum of POSTED journal entries to
 * revenue-category accounts in the period (accrual — recognised when the source
 * document is approved and posts to the GL), not a document scan. This proves the
 * revenue section equals the GL revenue total and excludes non-posted entries.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['user_id'] = (int)($pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
$_SESSION['is_admin'] = true; $_SESSION['role'] = 'admin'; $_SESSION['role_id'] = 1;

$from = '2026-01-01'; $to = '2026-12-31';
$_GET = ['start_date' => $from, 'end_date' => $to];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); include "$root/api/account/get_income_statement.php"; $raw = ob_get_clean();
error_reporting($prevErr);
$d = json_decode($raw, true);
(!empty($d['success'])) ? pass('endpoint success') : fail('endpoint failed: '.substr($raw,0,200));
if (empty($d['success'])) return;

$apiRevenue = (float)$d['data']['totals']['total_revenue'];
$glRevenue  = (float)glProfitLoss($pdo, $from, $to)['total_revenue'];
(abs($apiRevenue - $glRevenue) < 0.5) ? pass('API revenue == GL revenue total') : fail("API $apiRevenue != GL $glRevenue");

// Revenue counts POSTED journal entries only — a draft entry must not appear.
$postedRev = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END),0)
      FROM journal_entry_items jei
      JOIN journal_entries je ON je.entry_id=jei.entry_id AND je.status='posted'
      JOIN accounts a ON a.account_id=jei.account_id
      JOIN account_types at ON at.type_id=a.account_type_id AND at.category='revenue'
     WHERE je.entry_date BETWEEN '$from' AND '$to'")->fetchColumn();
(abs($apiRevenue - $postedRev) < 0.5) ? pass('revenue = Σ posted journal credits to revenue accounts') : fail("revenue $apiRevenue != posted $postedRev");
($d['data']['meta']['source'] ?? '') === 'general_ledger' ? pass('source = general_ledger') : fail('not GL-sourced');
