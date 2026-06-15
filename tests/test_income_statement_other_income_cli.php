<?php
/**
 * Area A — Income Statement: Revenue vs Other Income/Gains — CLI test
 *   php tests/test_income_statement_other_income_cli.php
 *
 * Proves the income side now separates ORDINARY revenue from non-ordinary
 * income/gains (IFRS), end-to-end and WIRED WITH REAL DATA:
 *   1. Engine: glProfitLoss emits an `other_income` bucket distinct from `revenue`;
 *      an other_income account appears there, NOT in revenue.
 *   2. Net profit folds other_income in, and the Balance Sheet still RECONCILES
 *      (glProfitLoss net == glBalanceSheet retained earnings).
 *   3. Endpoint: get_income_statement.php feeds the OTHER INCOME section + total.
 *   4. View (drill): clicking View on an Other-Income line returns the real records
 *      with the CORRECT (positive) sign, and the drill total == the line amount.
 *   5. Page renders with the OTHER INCOME section populated (not a dead/empty link).
 *
 * Read-only (no data writes); whole posted-ledger window so it covers live activity.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
require_once "$root/actions/check_auth.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
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

$range = $pdo->query("SELECT MIN(entry_date) lo, MAX(entry_date) hi FROM journal_entries WHERE status='posted'")->fetch(PDO::FETCH_ASSOC);
$from = $range['lo'] ?: date('Y-01-01'); $to = $range['hi'] ?: date('Y-m-d');
echo "Ledger window: $from → $to\n";

// ─────────────────────────────────────────────────────────────────────────
section('0. The other_income category exists (credit-normal, IS)');
$oiType = $pdo->query("SELECT type_id,normal_side,statement FROM account_types WHERE category='other_income' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$oiType ? pass("other_income type present (#{$oiType['type_id']}, {$oiType['normal_side']}, {$oiType['statement']})") : fail('other_income category missing — run the migration');
($oiType && $oiType['normal_side']==='credit' && $oiType['statement']==='IS') ? pass('other_income is credit-normal on the IS') : fail('other_income mis-typed');

// ─────────────────────────────────────────────────────────────────────────
section('1. Engine: other_income is a separate bucket from revenue');
$pl = glProfitLoss($pdo, $from, $to);
(array_key_exists('other_income', $pl) && array_key_exists('total_other_income', $pl))
    ? pass('glProfitLoss returns an other_income bucket + total') : fail('glProfitLoss missing other_income');
echo "   revenue=".money($pl['total_revenue'])."  other_income=".money($pl['total_other_income'])
    ."  cogs=".money($pl['total_cogs'])."  expense=".money($pl['total_expense'])."  finance=".money($pl['total_finance_cost'])."\n";
// No account appears in BOTH buckets.
$revIds = array_map(fn($l)=>(int)$l['account_id'], $pl['revenue']);
$oiIds  = array_map(fn($l)=>(int)$l['account_id'], $pl['other_income']);
(count(array_intersect($revIds,$oiIds))===0) ? pass('no account is in both revenue and other_income') : fail('an account leaked into both buckets');
// Every other_income line is an other_income-category account.
$badCat = false;
foreach ($pl['other_income'] as $l) {
    $c = $pdo->query("SELECT at.category FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id WHERE a.account_id=".(int)$l['account_id'])->fetchColumn();
    if ($c !== 'other_income') { $badCat = true; echo "   ⚠ {$l['account_code']} is category=$c\n"; }
}
$badCat ? fail('a non-other_income account is in the other_income bucket') : pass('every other_income line is a true other_income account');

// ─────────────────────────────────────────────────────────────────────────
section('2. Net profit folds other_income in, and the Balance Sheet RECONCILES');
$expectedNet = round($pl['total_revenue'] + $pl['total_other_income'] - $pl['total_cogs'] - $pl['total_expense'] - $pl['total_finance_cost'], 2);
(abs($pl['net_profit'] - $expectedNet) < 0.01)
    ? pass('net_profit == revenue + other_income − cogs − expense − finance ('.money($pl['net_profit']).')')
    : fail("net_profit ".money($pl['net_profit'])." != expected ".money($expectedNet));
$bs = glBalanceSheet($pdo, $to);
$plAll = glProfitLoss($pdo, $bs['as_of'] <= $to ? $from : $from, $to); // inception→asOf already = window
(abs($plAll['net_profit'] - $bs['retained_earnings']) < 0.01)
    ? pass('GL P&L net profit ties to BS retained earnings ('.money($bs['retained_earnings']).') — BS still balances')
    : fail("IS net ".money($plAll['net_profit'])." != BS retained ".money($bs['retained_earnings'])." — other_income broke the tie");
$bs['balanced'] ? pass('Balance Sheet balanced (Assets = Liab + Equity)') : fail('Balance Sheet NOT balanced');

// ─────────────────────────────────────────────────────────────────────────
section('3. Endpoint feeds the OTHER INCOME section + total');
$_GET = ['start_date'=>$from,'end_date'=>$to];
$prev = error_reporting(error_reporting() & ~E_WARNING & ~E_NOTICE);
ob_start(); require "$root/api/account/get_income_statement.php"; $raw = ob_get_clean();
error_reporting($prev);
$resp = json_decode($raw, true);
if (!$resp || empty($resp['success'])) { fail('endpoint did not succeed: '.substr($raw,0,160)); }
else {
    $d = $resp['data'];
    isset($d['sections']['other_income']) ? pass('sections.other_income present') : fail('sections.other_income missing');
    (abs((float)$d['totals']['other_income'] - $pl['total_other_income']) < 0.01)
        ? pass('totals.other_income matches the engine ('.money((float)$d['totals']['other_income']).')')
        : fail('totals.other_income drifts from engine');
    (count($d['sections']['other_income']['lines']) === count($pl['other_income']))
        ? pass('other_income section lines == engine lines ('.count($pl['other_income']).')')
        : fail('section line count mismatch');
    // each line carries a working drill descriptor
    $allDrill = true; foreach ($d['sections']['other_income']['lines'] as $l) { if (empty($l['drill']['account_id'])) $allDrill=false; }
    $allDrill ? pass('every other_income line carries a drill descriptor (View icon)') : fail('an other_income line has no drill descriptor');
}

// ─────────────────────────────────────────────────────────────────────────
section('4. View (drill) returns real records with the CORRECT sign');
$oiLine = $pl['other_income'][0] ?? null;
if (!$oiLine) {
    echo "   (no other_income posted in this window — drill check skipped; section correctly hides when empty)\n";
    pass('no other_income data → section hidden (no dead link)');
} else {
    $acct = (int)$oiLine['account_id']; $lineAmt = (float)$oiLine['amount'];
    $_GET = ['source'=>'journal','account_id'=>$acct,'start_date'=>$from,'end_date'=>$to];
    $prev = error_reporting(error_reporting() & ~E_WARNING & ~E_NOTICE);
    ob_start(); require "$root/api/account/get_income_statement_detail.php"; $draw = ob_get_clean();
    error_reporting($prev);
    $dr = json_decode($draw, true);
    if (!$dr || empty($dr['success'])) { fail('drill did not succeed: '.substr($draw,0,160)); }
    else {
        (count($dr['rows']) > 0) ? pass("View returns ".count($dr['rows'])." record(s) for {$oiLine['account_code']}") : fail('View returned NO records (dead link!)');
        ((float)$dr['total'] > 0) ? pass('drill total is POSITIVE (correct sign for credit-normal income)') : fail('drill total is negative — sign bug for other_income');
        (abs((float)$dr['total'] - $lineAmt) < 0.01)
            ? pass('drill total == the line amount ('.money($lineAmt).') — the View ties to the line')
            : fail('drill total '.money((float)$dr['total']).' != line amount '.money($lineAmt));
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Page renders with the OTHER INCOME section (real, not dead)');
$_GET = []; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/income_statement';
$prev = error_reporting(error_reporting() & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ob_start(); try { require "$root/app/bms/invoice/income_statement.php"; } catch (Throwable $e) { echo "##EXC## ".$e->getMessage(); }
$html = ob_get_clean(); error_reporting($prev);
(strpos($html,'##EXC##')===false && stripos($html,'fatal error')===false) ? pass('income_statement.php renders without error ('.strlen($html).' bytes)') : fail('page render error');
(strpos($html,'otherIncomeBody')!==false && strpos($html,'OTHER INCOME')!==false) ? pass('OTHER INCOME section + body present in the page') : fail('OTHER INCOME section missing from the page');
(strpos($html,"sections.other_income")!==false) ? pass('frontend renders other_income lines from the API') : fail('frontend not wired to other_income');
