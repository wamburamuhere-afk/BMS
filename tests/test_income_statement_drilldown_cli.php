<?php
/**
 * tests/test_income_statement_drilldown_cli.php
 * ---------------------------------------------
 * Post-F3-flip: every P&L line is a GL account that drills to the general ledger.
 * Guards that the Income Statement emits a `journal` drill (source + account_id)
 * per line, and that the detail endpoint returns the posted journal entries behind
 * that account for the period.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
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

// Detail endpoint must still handle the 'journal' drill source.
$detailSrc = file_get_contents("$root/api/account/get_income_statement_detail.php");
(strpos($detailSrc, "case 'journal':") !== false) ? pass("detail endpoint handles the 'journal' drill source") : fail("detail endpoint missing 'journal' case");

// Run the IS and find a populated revenue/expense line.
$from = '2026-01-01'; $to = '2026-12-31';
$_GET = ['start_date' => $from, 'end_date' => $to];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); include "$root/api/account/get_income_statement.php"; $raw = ob_get_clean();
error_reporting($prevErr);
$d = json_decode($raw, true);
(!empty($d['success'])) ? pass('IS endpoint success') : fail('IS endpoint failed');
if (empty($d['success'])) return;

$line = null;
foreach (['revenue','expense','cogs'] as $s) {
    foreach ($d['data']['sections'][$s]['lines'] as $l) { if (abs((float)$l['current']) > 0.005) { $line = $l; break 2; } }
}
if (!$line) { pass('no populated P&L line to drill (n/a on this data)'); return; }

(($line['drill']['source'] ?? '') === 'journal') ? pass("line carries a 'journal' drill") : fail('line drill not journal');
$acct = (int)($line['drill']['account_id'] ?? 0);
($acct > 0) ? pass("drill carries account_id #$acct") : fail('drill missing account_id');

// Hit the detail endpoint for that account and confirm it returns rows that sum to the line.
$_GET = ['source' => 'journal', 'account_id' => $acct, 'start_date' => $from, 'end_date' => $to];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); include "$root/api/account/get_income_statement_detail.php"; $rawD = ob_get_clean();
error_reporting($prevErr);
$dd = json_decode($rawD, true);
(!empty($dd['success'])) ? pass('detail endpoint success for the drilled account') : fail('detail failed: '.substr($rawD,0,150));
if (!empty($dd['success'])) {
    (abs((float)$dd['total'] - (float)$line['current']) < 0.5)
        ? pass('detail total == the P&L line amount (drill reconciles)')
        : fail("detail total {$dd['total']} != line {$line['current']}");
}
