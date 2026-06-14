<?php
/**
 * OUT-12 / OUT-13 — Asset acquisition + depreciation GL posting — CLI test
 *   php tests/test_asset_posting_cli.php
 *
 * Guards core/asset_gl_service.php + the depreciation run hook:
 *   - acquisition 'new'      → Dr Fixed Asset / Cr Accounts Payable
 *   - acquisition 'existing' → Dr Fixed Asset / Cr Accum Dep (b/f) / Cr Take-on Equity (NBV)
 *   - depreciation           → Dr Depreciation Expense / Cr Accumulated Depreciation
 *     (resolves accounts even when the asset category has no GL codes)
 * All posts run inside ROLLED-BACK transactions — the database is left untouched.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/asset_gl_service.php";
require_once "$root/core/financial_reports.php";
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

/** Fetch a posted entry's lines as [account_id => signed], plus dr/cr totals. */
function lines(PDO $pdo, int $eid): array {
    $rows = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$eid")->fetchAll(PDO::FETCH_ASSOC);
    $dr = 0.0; $cr = 0.0; $byAcc = [];
    foreach ($rows as $r) { $a=(int)$r['account_id']; $m=(float)$r['amount'];
        if ($r['type']==='debit'){$dr+=$m;$byAcc[$a]=($byAcc[$a]??0)+$m;} else {$cr+=$m;$byAcc[$a]=($byAcc[$a]??0)-$m;} }
    return ['dr'=>$dr,'cr'=>$cr,'n'=>count($rows),'byAcc'=>$byAcc];
}

$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

// ─────────────────────────────────────────────────────────────────────────
section('1. Control accounts resolve');
$fa  = fixedAssetAccountId($pdo);
$ad  = accumulatedDepreciationAccountId($pdo);
$de  = depreciationExpenseAccountId($pdo);
$eq  = takeOnEquityAccountId($pdo);
$ap  = apAccountId($pdo);
$fa ? pass("fixedAssetAccountId → #$fa")               : fail('fixedAssetAccountId null');
$ad ? pass("accumulatedDepreciationAccountId → #$ad")  : fail('accumulatedDepreciationAccountId null');
$de ? pass("depreciationExpenseAccountId → #$de")      : fail('depreciationExpenseAccountId null');
$eq ? pass("takeOnEquityAccountId → #$eq")             : fail('takeOnEquityAccountId null');
$ap ? pass("apAccountId → #$ap")                       : fail('apAccountId null');

// ─────────────────────────────────────────────────────────────────────────
section("2. Acquisition 'new' → Dr Fixed Asset / Cr AP (rolled back)");
$pdo->beginTransaction();
try {
    $r = postAssetAcquisition($pdo, 990001, 'TST-NEW', 500000.0, 'new', 0.0, null, '2026-03-10', null, $uid);
    (!empty($r['posted']) && !empty($r['entry_id'])) ? pass("posted (entry #{$r['entry_id']})") : fail('did not post: '.($r['reason']??'?'));
    if (!empty($r['entry_id'])) {
        $L = lines($pdo, (int)$r['entry_id']);
        ($L['n']===2 && abs($L['dr']-$L['cr'])<0.01 && abs($L['dr']-500000.0)<0.01) ? pass('2 lines, balanced @ 500,000') : fail('shape wrong: '.json_encode($L));
        (($L['byAcc'][(int)$fa] ?? 0) > 0) ? pass('Fixed Asset debited') : fail('Fixed Asset not debited');
        (($L['byAcc'][(int)$ap] ?? 0) < 0) ? pass('Accounts Payable credited') : fail('AP not credited');
        $r2 = postAssetAcquisition($pdo, 990001, 'TST-NEW', 500000.0, 'new', 0.0, null, '2026-03-10', null, $uid);
        (($r2['reason']??'')==='already_posted') ? pass('idempotent (already_posted)') : fail('not idempotent: '.($r2['reason']??'?'));
    }
} finally { $pdo->rollBack(); }

// ─────────────────────────────────────────────────────────────────────────
section("3. Acquisition 'existing' take-on → Dr Asset / Cr Accum Dep / Cr Equity (rolled back)");
$pdo->beginTransaction();
try {
    // cost 120,000, opening accum b/f 30,000 → NBV 90,000
    $r = postAssetAcquisition($pdo, 990002, 'TST-OLD', 120000.0, 'existing', 30000.0, null, '2026-01-01', null, $uid);
    (!empty($r['posted']) && !empty($r['entry_id'])) ? pass("posted (entry #{$r['entry_id']})") : fail('did not post: '.($r['reason']??'?'));
    if (!empty($r['entry_id'])) {
        $L = lines($pdo, (int)$r['entry_id']);
        ($L['n']===3 && abs($L['dr']-$L['cr'])<0.01 && abs($L['dr']-120000.0)<0.01) ? pass('3 lines, balanced @ 120,000') : fail('shape wrong: '.json_encode($L));
        (abs(($L['byAcc'][(int)$fa] ?? 0) - 120000.0)<0.01) ? pass('Fixed Asset Dr 120,000') : fail('Fixed Asset wrong');
        (abs(($L['byAcc'][(int)$ad] ?? 0) + 30000.0)<0.01)  ? pass('Accum Dep Cr 30,000')   : fail('Accum Dep wrong');
        (abs(($L['byAcc'][(int)$eq] ?? 0) + 90000.0)<0.01)  ? pass('Take-on Equity Cr 90,000 (NBV)') : fail('Equity wrong');
    }
} finally { $pdo->rollBack(); }

// ─────────────────────────────────────────────────────────────────────────
section('4. Depreciation poster falls back to resolvers when category codes empty (rolled back)');
$pdo->beginTransaction();
try {
    // null expense/accum codes → must fall back to 6-1300 / 1-3900
    $eid = postAssetDepreciationGl($pdo, 990003, 'TST-DEP', null, null, 29970.0, '2026-12-31', $uid);
    $eid ? pass("posted (entry #$eid)") : fail('did not post (resolver fallback failed)');
    if ($eid) {
        $L = lines($pdo, (int)$eid);
        ($L['n']===2 && abs($L['dr']-$L['cr'])<0.01 && abs($L['dr']-29970.0)<0.01) ? pass('2 lines, balanced @ 29,970') : fail('shape wrong: '.json_encode($L));
        (($L['byAcc'][(int)$de] ?? 0) > 0) ? pass('Depreciation Expense debited') : fail('Dep Expense not debited');
        (($L['byAcc'][(int)$ad] ?? 0) < 0) ? pass('Accumulated Depreciation credited') : fail('Accum Dep not credited');
    }
} finally { $pdo->rollBack(); }

// ─────────────────────────────────────────────────────────────────────────
section('5. Ledger still balances (nothing persisted by this test)');
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok') : fail('ledger out of balance: '.json_encode($g));
