<?php
/**
 * Credit-note refund reverses Output VAT — CLI test
 *   php tests/test_credit_note_vat_reversal_cli.php
 *
 * Gap (account_financial.md #3): pay_credit_note.php debited Sales Returns by the
 * GROSS grand_total and never reduced Output VAT Payable — so after refunding a VAT
 * sale you still owed TRA the VAT. This verifies the VAT-aware refund posts the 3-leg
 * split: Dr Sales Returns (net) / Dr Output VAT (tax) / Cr Cash (gross), balanced.
 * Runtime drives the real helper in a ROLLED-BACK transaction.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";     // recordGlobalTransaction chain + applyAccountBalanceDelta
require_once "$root/core/sales_posting.php";       // postCreditNoteRefundVat
require_once "$root/core/vat.php";                 // outputVatAccountId
require_once "$root/core/financial_reports.php";   // assertLedgerBalanced
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'cli'; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses: \033[32m$pass\033[0m   Failures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

// ── 1. Source contract ───────────────────────────────────────────────────────
section('1. pay_credit_note.php — VAT-aware refund wired');
$src = file_get_contents("$root/api/sales/pay_credit_note.php");
ok(strpos($src, 'outputVatAccountId(') !== false, 'resolves the Output VAT account');
ok(strpos($src, 'postCreditNoteRefundVat(') !== false, 'posts the VAT split when total_tax > 0');
ok(strpos($src, "total_tax") !== false, "reads the credit note's total_tax");
ok(strpos($src, 'postOutflowOrFail(') !== false, 'keeps the 2-leg fallback for no-VAT notes');

// ── 2. Runtime — 3-leg split posts and balances (rolled back) ───────────────
section('2. Runtime — Dr Sales Returns (net) / Dr Output VAT (tax) / Cr Cash (gross)');
$outVat = (int)outputVatAccountId($pdo);
$sra    = (int)(getSetting('default_sales_returns_account_id', 0) ?: 0);
if ($sra <= 0) {
    $sra = (int)$pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
                              WHERE a.status='active' AND at.category='revenue'
                                AND NOT EXISTS(SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                              ORDER BY a.account_id LIMIT 1")->fetchColumn();
}
$cash = (int)$pdo->query("SELECT a.account_id FROM accounts a LEFT JOIN account_sub_types st ON a.sub_type_id=st.sub_type_id
                           WHERE a.status='active' AND a.account_type='asset' AND (st.is_bank=1 OR a.cash_flow_category='cash')
                             AND NOT EXISTS(SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                           ORDER BY a.account_id LIMIT 1")->fetchColumn();
ok($outVat > 0 && $sra > 0 && $cash > 0, "have Output VAT (#$outVat) + Sales Returns (#$sra) + cash (#$cash)");

$gross = 11800.00; $tax = 1800.00; $net = 10000.00;
$pdo->beginTransaction();
try {
    $txn = (int)postCreditNoteRefundVat($pdo, 'CN-VAT-TST', $cash, $sra, $outVat, $gross, $tax, date('Y-m-d'), 'credit note vat test', null, 4);
    ok($txn > 0, 'VAT refund posted');

    $legs = $pdo->query("SELECT account_id, type, amount FROM books_transactions WHERE transaction_id = $txn")->fetchAll(PDO::FETCH_ASSOC);
    ok(count($legs) === 3, 'three legs posted');
    $get = function ($acc, $type) use ($legs) { foreach ($legs as $l) if ((int)$l['account_id']===$acc && $l['type']===$type) return round((float)$l['amount'],2); return null; };
    ok($get($sra, 'debit') === $net,    "Sales Returns debited by NET ($net)");
    ok($get($outVat, 'debit') === $tax, "Output VAT debited by TAX ($tax) — VAT reversed");
    ok($get($cash, 'credit') === $gross,"Cash credited by GROSS ($gross)");

    $dr = 0; $cr = 0; foreach ($legs as $l) { if ($l['type']==='debit') $dr+=$l['amount']; else $cr+=$l['amount']; }
    ok(abs($dr - $cr) < 0.01, 'entry is balanced (Dr = Cr)');
    ok(!empty(assertLedgerBalanced($pdo, date('Y-m-d'))['ledger_balanced']), 'ledger balanced after the refund');
} finally {
    $pdo->rollBack();
}
