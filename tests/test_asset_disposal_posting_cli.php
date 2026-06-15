<?php
/**
 * OUT-14 — Asset disposal → GL posting (live-chart fallback) — CLI test
 *   php tests/test_asset_disposal_posting_cli.php
 *
 * Guards core/asset_gl_service.php::postAssetDisposalGl() AFTER the OUT-14 fix:
 * on the LIVE chart the asset_categories gl_* codes are empty and the settings
 * clearing/gain-loss codes are unset/placeholder, so the poster must fall back to
 * the canonical resolvers (Fixed Asset / Accumulated Depreciation / Disposal
 * Clearing / Gain→other income / Loss→other expense) and still post a balanced,
 * idempotent entry. Runs the real poster with EMPTY category+settings codes (forcing
 * the fallbacks) against a real asset, inside a ROLLED-BACK transaction.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/asset_gl_service.php";
require_once "$root/core/gl_accounts.php";
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

// ─────────────────────────────────────────────────────────────────────────
section('1. Fallback resolvers all resolve on the live chart');
$assetAcc = fixedAssetAccountId($pdo);
$accumAcc = accumulatedDepreciationAccountId($pdo);
$clearAcc = disposalClearingAccountId($pdo);
$gainAcc  = disposalGainAccountId($pdo);
$lossAcc  = disposalLossAccountId($pdo);
$assetAcc ? pass("fixedAssetAccountId → #$assetAcc")             : fail('fixedAssetAccountId null');
$accumAcc ? pass("accumulatedDepreciationAccountId → #$accumAcc") : fail('accumulatedDepreciationAccountId null');
$clearAcc ? pass("disposalClearingAccountId → #$clearAcc")        : fail('disposalClearingAccountId null');
$gainAcc  ? pass("disposalGainAccountId → #$gainAcc")             : fail('disposalGainAccountId null');
$lossAcc  ? pass("disposalLossAccountId → #$lossAcc")             : fail('disposalLossAccountId null');

$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$asset = $pdo->query("SELECT asset_id, asset_code, cost FROM assets WHERE status NOT IN ('deleted') AND cost > 0 ORDER BY asset_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$asset) { fail('no asset with cost found — cannot run the functional test'); return; }
$aid  = (int)$asset['asset_id'];
$code = (string)$asset['asset_code'];

$before = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='asset_disposal' AND status='posted'")->fetchColumn();

// ─────────────────────────────────────────────────────────────────────────
section('2. GAIN on disposal: balanced, asset removed, proceeds to clearing, gain to income');
// Synthetic snapshot: cost 1,000,000; accum 600,000; nbv 400,000; proceeds 500,000; gain 100,000.
$snapGain = ['original_cost'=>1000000.0, 'accum_dep_book'=>600000.0, 'nbv_at_disposal'=>400000.0, 'proceeds'=>500000.0, 'gain_loss'=>100000.0];
$pdo->beginTransaction();
try {
    // Empty category + settings codes → forces the canonical fallbacks (the live case).
    $eid = postAssetDisposalGl($pdo, $aid, $code, ['gl_asset_account'=>null,'gl_accum_account'=>null], ['gl_clearing_account'=>null,'gl_gain_loss_account'=>null], $snapGain, '2026-06-14', $uid);
    $eid ? pass("posted a GL entry (#$eid)") : fail('did not post (fallbacks failed)');
    if ($eid) {
        $lines = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$eid")->fetchAll(PDO::FETCH_ASSOC);
        $hdr = $pdo->query("SELECT entity_type FROM journal_entries WHERE entry_id=$eid")->fetch(PDO::FETCH_ASSOC);
        $dr = 0.0; $cr = 0.0; $byAcc = [];
        foreach ($lines as $l) { $t=(float)$l['amount']; if($l['type']==='debit')$dr+=$t; else $cr+=$t; $byAcc[(int)$l['account_id']][$l['type']] = ($byAcc[(int)$l['account_id']][$l['type']] ?? 0) + $t; }
        (abs($dr-$cr)<0.01) ? pass("balanced (Dr ".money($dr)." = Cr ".money($cr).")") : fail("unbalanced Dr $dr vs Cr $cr");
        (($hdr['entity_type']??'')==='asset_disposal') ? pass("entity_type='asset_disposal'") : fail("entity_type=".($hdr['entity_type']??'null'));
        (isset($byAcc[$assetAcc]['credit']) && abs($byAcc[$assetAcc]['credit']-1000000.0)<0.01) ? pass('Fixed Asset credited the full cost (asset removed)') : fail('asset cost not credited');
        (isset($byAcc[$accumAcc]['debit']) && abs($byAcc[$accumAcc]['debit']-600000.0)<0.01) ? pass('Accumulated Depreciation debited (removed)') : fail('accum dep not debited');
        (isset($byAcc[$clearAcc]['debit']) && abs($byAcc[$clearAcc]['debit']-500000.0)<0.01) ? pass('Proceeds debited to the clearing account') : fail('proceeds not to clearing');
        (isset($byAcc[$gainAcc]['credit']) && abs($byAcc[$gainAcc]['credit']-100000.0)<0.01) ? pass('Gain credited to other income') : fail('gain not credited to income');

        // Idempotent within the same tx
        $eid2 = postAssetDisposalGl($pdo, $aid, $code, ['gl_asset_account'=>null,'gl_accum_account'=>null], ['gl_clearing_account'=>null,'gl_gain_loss_account'=>null], $snapGain, '2026-06-14', $uid);
        ((int)$eid2 === (int)$eid) ? pass('idempotent: second call returns the same entry') : fail("not idempotent: #$eid2 != #$eid");
    }
} finally { $pdo->rollBack(); }

// ─────────────────────────────────────────────────────────────────────────
section('3. LOSS on disposal: loss routed to an expense account (separate tx)');
// cost 1,000,000; accum 200,000; nbv 800,000; proceeds 300,000; loss 500,000.
$snapLoss = ['original_cost'=>1000000.0, 'accum_dep_book'=>200000.0, 'nbv_at_disposal'=>800000.0, 'proceeds'=>300000.0, 'gain_loss'=>-500000.0];
$pdo->beginTransaction();
try {
    $eid = postAssetDisposalGl($pdo, $aid, $code, ['gl_asset_account'=>null,'gl_accum_account'=>null], ['gl_clearing_account'=>null,'gl_gain_loss_account'=>null], $snapLoss, '2026-06-14', $uid);
    $eid ? pass("posted a GL entry (#$eid)") : fail('did not post');
    if ($eid) {
        $lines = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$eid")->fetchAll(PDO::FETCH_ASSOC);
        $dr=0.0;$cr=0.0;$lossDebit=0.0;
        foreach ($lines as $l){ $t=(float)$l['amount']; if($l['type']==='debit')$dr+=$t; else $cr+=$t; if((int)$l['account_id']===(int)$lossAcc && $l['type']==='debit') $lossDebit+=$t; }
        (abs($dr-$cr)<0.01) ? pass("balanced (Dr ".money($dr)." = Cr ".money($cr).")") : fail("unbalanced Dr $dr vs Cr $cr");
        (abs($lossDebit-500000.0)<0.01) ? pass('Loss debited to an expense account (500,000)') : fail("loss not debited to expense (got $lossDebit)");
    }
} finally { $pdo->rollBack(); }

// ─────────────────────────────────────────────────────────────────────────
section('4. Scrapped (zero proceeds): full NBV is a loss, still balances');
$snapScrap = ['original_cost'=>1000000.0, 'accum_dep_book'=>700000.0, 'nbv_at_disposal'=>300000.0, 'proceeds'=>0.0, 'gain_loss'=>-300000.0];
$pdo->beginTransaction();
try {
    $eid = postAssetDisposalGl($pdo, $aid, $code, ['gl_asset_account'=>null,'gl_accum_account'=>null], ['gl_clearing_account'=>null,'gl_gain_loss_account'=>null], $snapScrap, '2026-06-14', $uid);
    $eid ? pass("posted a GL entry (#$eid)") : fail('did not post');
    if ($eid) {
        $d=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=$eid AND type='debit'")->fetchColumn();
        $c=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=$eid AND type='credit'")->fetchColumn();
        (abs($d-$c)<0.01) ? pass("balanced (Dr ".money($d)." = Cr ".money($c).") — no proceeds leg") : fail("unbalanced Dr $d vs Cr $c");
    }
} finally { $pdo->rollBack(); }

// ─────────────────────────────────────────────────────────────────────────
section('5. No rows leaked + ledger still balances');
$after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='asset_disposal' AND status='posted'")->fetchColumn();
($after === $before) ? pass("rolled back cleanly (asset_disposal entries: $before → $after)") : fail("LEAKED rows: $before → $after");
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok (Σ Dr = Σ Cr and Assets = Liab + Equity)') : fail('ledger out of balance: ' . json_encode($g));
