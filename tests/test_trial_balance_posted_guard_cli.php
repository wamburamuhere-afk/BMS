<?php
/**
 * Trial Balance — POSTED-items guard (the balance fix)
 *   php tests/test_trial_balance_posted_guard_cli.php
 *
 * Root cause this guards against: the three Trial Balance code paths each summed
 * journal_entry_items off a LEFT JOIN to journal_entries WITHOUT checking
 * `je.entry_id IS NOT NULL`. When an item's entry is draft / un-posted /
 * future-dated, the join leaves je.* NULL but jei.type/jei.amount are still
 * summed — so un-posted lines (which do NOT balance) leaked into the totals and
 * the trial balance reported a phantom out-of-balance figure (~627M on live).
 *
 * Adding `je.entry_id IS NOT NULL AND` inside each SUM(CASE …) restricts the
 * totals to POSTED entries on/before the as-of date, and the ledger balances
 * (Σ Debits == Σ Credits) by the double-entry identity.
 *
 * Locks in: (1) the guard is present in all three files, (2) the menu page's
 * fixed query balances on the live data, (3) the menu page renders without a
 * fatal and does NOT show the "does not balance" banner.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
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

$reports_page  = "$root/app/constant/reports/trial_balance.php";   // menu route 'trial_balance'
$accounts_page = "$root/app/constant/accounts/trial_balance.php";  // routes 'trial-balance' etc.
$api           = "$root/api/account/get_trial_balance.php";        // reports-hub GL variant

section('1. Files lint + carry the posted-items guard');
foreach ([$reports_page, $accounts_page, $api] as $f) {
    $rc=0;$o=[]; exec('php -l '.escapeshellarg($f).' 2>&1',$o,$rc);
    $rc===0 ? pass(basename(dirname($f)).'/'.basename($f).' lints') : fail('php -l '.basename($f).': '.implode(' | ',$o));
}
// Every SUM(CASE …) over journal_entry_items must be gated on je.entry_id NOT NULL.
foreach ([$reports_page, $accounts_page, $api] as $f) {
    $src = file_get_contents($f);
    // count SUM(CASE WHEN ... jei.type ...) blocks and how many carry the guard
    $total = preg_match_all('/SUM\(CASE WHEN[^)]*jei\.type/i', $src);
    $guarded = preg_match_all('/SUM\(CASE WHEN\s+je\.entry_id\s+IS\s+NOT\s+NULL\s+AND[^)]*jei\.type/i', $src);
    ($total > 0 && $guarded === $total)
        ? pass(basename($f).": all $total Dr/Cr SUM(CASE) carry the je.entry_id IS NOT NULL guard")
        : fail(basename($f).": $guarded/$total SUM(CASE) guarded — un-posted items can still leak");
}

section('2. Runtime — the fixed menu-page query BALANCES on the live ledger');
// Replicate the exact net-balance computation the menu page performs, with the
// posted guard in place, and assert Σ Debits == Σ Credits.
$as_of = date('Y-m-d');
$sql = "
    SELECT a.opening_balance, COALESCE(at.normal_side,'debit') AS normal_side,
           COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='debit'  THEN jei.amount ELSE 0 END),0) AS pdr,
           COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='credit' THEN jei.amount ELSE 0 END),0) AS pcr
    FROM accounts a
    LEFT JOIN account_types at ON a.account_type_id = at.type_id
    LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
    LEFT JOIN journal_entries je ON je.entry_id = jei.entry_id
            AND je.status='posted' AND je.entry_date <= ?
    WHERE a.status='active'
    GROUP BY a.account_id, a.opening_balance, at.normal_side";
$st = $pdo->prepare($sql); $st->execute([$as_of]);
$dr=0.0; $cr=0.0;
foreach ($st as $r) {
    $op=(float)$r['opening_balance'];
    $tdr=($r['normal_side']==='debit'?$op:0.0)+(float)$r['pdr'];
    $tcr=($r['normal_side']==='credit'?$op:0.0)+(float)$r['pcr'];
    $net=$tdr-$tcr; $dr+=$net>0?$net:0; $cr+=$net<0?-$net:0;
}
$diff = $dr-$cr;
(abs($diff) < 0.5)
    ? pass(sprintf('trial balance balances: Dr=%0.2f Cr=%0.2f diff=%0.2f', $dr, $cr, $diff))
    : fail(sprintf('OUT OF BALANCE: Dr=%0.2f Cr=%0.2f diff=%0.2f', $dr, $cr, $diff));

section('3. Runtime — the menu page renders without fatal / does not show the imbalance banner');
if (session_status()===PHP_SESSION_NONE) @session_start();
$_SESSION['user_id']=(int)($pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$_SESSION['is_admin']=true; $_SESSION['role_id']=1;
$_GET=['as_of_date'=>$as_of];
$pe=error_reporting(error_reporting() & ~E_WARNING & ~E_DEPRECATED);
ob_start(); include $reports_page; $html=ob_get_clean(); error_reporting($pe);
(strlen($html)>2000) ? pass('menu page renders a full page ('.strlen($html).' bytes)') : fail('page too small / blank');
(stripos($html,'Fatal error')===false) ? pass('no fatal error') : fail('page shows a fatal error');
(stripos($html,'TRIAL BALANCE DOES NOT BALANCE')===false) ? pass('no "does not balance" banner (ledger balances)') : fail('imbalance banner shown — ledger is out of balance');
