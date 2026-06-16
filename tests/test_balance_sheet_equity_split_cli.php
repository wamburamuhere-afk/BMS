<?php
/**
 * Balance Sheet — Retained Earnings split + current/non-current liabilities — CLI test
 *   php tests/test_balance_sheet_equity_split_cli.php
 *
 * Guards the professional-presentation upgrade of the Statement of Financial Position:
 *   - EQUITY: the single opaque "Retained Earnings" lump is split into
 *       "Retained Earnings (brought forward)" + "Profit for the Year", which sum to
 *       the engine's retained figure (total equity unchanged).
 *   - LIABILITIES: split current vs non-current by the chart convention (code 2-2xxx),
 *       replacing the hardcoded-empty non-current section; the template hides it when empty.
 *   - Stale "balancing plug" / "cash basis" notes removed from the report.
 *   - The statement still balances (A = L + E).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $h, string $n, string $l): void { strpos($h,$n)!==false ? pass($l) : fail("$l — missing"); }
function hasnt(string $h, string $n, string $l): void { strpos($h,$n)===false ? pass($l) : fail("$l — still present"); }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

$api = "$root/api/account/get_balance_sheet.php";
$tpl = "$root/app/bms/invoice/reps/balance_sheet.php";

section('1. Files lint clean');
foreach ([$api, $tpl] as $f) { $rc=0;$o=[]; exec('php -l '.escapeshellarg($f).' 2>&1',$o,$rc); $rc===0?pass(basename($f).' lints'):fail('php -l '.basename($f)); }

section('2. API source — split logic present, no hardcoded empty non-current');
$src = file_get_contents($api);
has($src, "Retained Earnings (brought forward)", 'equity: brought-forward retained line');
has($src, "Profit for the Year (per Income Statement)", 'equity: current-year profit line');
has($src, "preg_match('/^2-2/'", 'liabilities split by the 2-2xxx convention');
hasnt($src, '$non_current_liabilities_lines = []', 'non-current liabilities no longer hardcoded empty');
has($src, '$cur_non_current_liabilities', 'non-current liabilities total is computed');

section('3. Template — non-current liabilities hide-when-empty; stale text gone');
$t = file_get_contents($tpl);
has($t, "!empty(\$d['sections']['non_current_liabilities']['lines'])", 'non-current liabilities section hidden when empty');
hasnt($t, 'balancing plug', 'removed the "balancing plug" claim');
hasnt($t, 'Cash basis', 'removed the stale "Cash basis" note');
has($t, 'Accrual basis', 'notes state accrual basis');

section('4. Runtime — equity split ties; liabilities split; still balances');
if (session_status()===PHP_SESSION_NONE) @session_start();
$uid=(int)($pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
$_SESSION['user_id']=$uid; $_SESSION['is_admin']=true; $_SESSION['role']='admin'; $_SESSION['role_id']=1;
$_GET=['as_of_date'=>date('Y-m-d')];
$pe=error_reporting(error_reporting() & ~E_WARNING);
ob_start(); include $api; $raw=ob_get_clean();
error_reporting($pe);
$d=json_decode($raw,true)['data'] ?? null;
if (!$d) { fail('API did not return data'); return; }

// Equity lines carry both split lines and sum to the equity total.
$names = array_column($d['sections']['equity']['lines'], 'name');
in_array('Retained Earnings (brought forward)', $names, true) ? pass('b/f retained line rendered') : fail('b/f line missing');
in_array('Profit for the Year (per Income Statement)', $names, true) ? pass('profit-for-year line rendered') : fail('profit line missing');
$eqsum=0.0; $eqcmp=0.0;
foreach ($d['sections']['equity']['lines'] as $l) { $eqsum+=(float)$l['amount']; $eqcmp+=(float)$l['comparative_amount']; }
(abs($eqsum-(float)$d['sections']['equity']['total'])<0.5) ? pass('equity lines sum to equity total (current)') : fail("equity lines $eqsum != total ".$d['sections']['equity']['total']);
(abs($eqcmp-(float)$d['sections']['equity']['comparative_total'])<0.5) ? pass('equity lines sum to equity total (comparative)') : fail('equity comparative does not tie');

// The two retained lines sum to the engine's single retained figure.
$bf=null;$pfy=null;
foreach ($d['sections']['equity']['lines'] as $l) {
    if ($l['name']==='Retained Earnings (brought forward)') $bf=(float)$l['amount'];
    if ($l['name']==='Profit for the Year (per Income Statement)') $pfy=(float)$l['amount'];
}
$engineRetained = glBalanceSheet($pdo, date('Y-m-d'))['retained_earnings'];
($bf!==null && $pfy!==null && abs(($bf+$pfy)-$engineRetained)<0.5) ? pass('b/f + profit = engine retained earnings') : fail("split $bf + $pfy != engine $engineRetained");

// Non-current liabilities total is now real (0 + empty here → section hides).
array_key_exists('total', $d['sections']['non_current_liabilities']) ? pass('non-current liabilities exposes a real total') : fail('non-current total missing');
(empty($d['sections']['non_current_liabilities']['lines'])) ? pass('non-current liabilities empty → hidden (no 2-2xxx balance)') : pass('non-current liabilities present (2-2xxx has a balance)');

// Current liabilities exclude any 2-2xxx code.
$badCur = array_filter($d['sections']['current_liabilities']['lines'], fn($l)=>preg_match('/^2-2/', (string)$l['account_code']));
empty($badCur) ? pass('current liabilities exclude 2-2xxx (non-current) codes') : fail('a 2-2xxx account leaked into current liabilities');

// The statement still balances.
!empty($d['totals']['balanced']) ? pass('Balance Sheet still balances (A = L + E)') : fail('Balance Sheet does not balance');
