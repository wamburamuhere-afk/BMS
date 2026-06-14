<?php
/**
 * OUT-2 — Payment voucher accrual — CLI test
 *   php tests/test_voucher_accrual_cli.php
 *
 * Guards the voucher wrappers in core/expense_posting.php: a voucher is recognised
 * Dr Expense / Cr Accrued Expenses at approval (entity_type='voucher_accrual',
 * isolated from expense accruals), idempotent, reversible. Rolled-back transactions.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/expense_posting.php";
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
function netByAcct(PDO $pdo, int $eid): array {
    $m=[]; foreach($pdo->query("SELECT account_id,type,amount FROM journal_entry_items WHERE entry_id=$eid") as $r){
        $a=(int)$r['account_id']; $m[$a]=($m[$a]??0)+($r['type']==='debit'?(float)$r['amount']:-(float)$r['amount']); } return $m;
}

$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$expAcc = (int)($pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON at.type_id=a.account_type_id WHERE at.category='expense' AND a.status='active' ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
$accrued = accruedExpensesAccountId($pdo);
$VCH = 990201; $AMT = 175000.00;

section('1. Voucher accrual posts Dr Expense / Cr Accrued, entity=voucher_accrual (rolled back)');
$pdo->beginTransaction();
try {
    $r = postVoucherAccrual($pdo, $VCH, $expAcc, $AMT, '2026-04-15', null, $uid, 'PV-TST', 'Vendor payout');
    (!empty($r['posted']) && !empty($r['entry_id'])) ? pass("accrued (entry #{$r['entry_id']})") : fail('did not accrue: '.($r['reason']??'?'));
    if (!empty($r['entry_id'])) {
        $eid = (int)$r['entry_id'];
        $net = netByAcct($pdo, $eid);
        $et  = $pdo->query("SELECT entity_type FROM journal_entries WHERE entry_id=$eid")->fetchColumn();
        (abs(($net[$expAcc]??0) - $AMT) < 0.01)   ? pass('Expense debited '.money($AMT))      : fail('expense debit wrong');
        (abs(($net[(int)$accrued]??0) + $AMT) < 0.01) ? pass('Accrued Expenses credited '.money($AMT)) : fail('accrued credit wrong');
        ($et === 'voucher_accrual') ? pass("entity_type='voucher_accrual'") : fail("entity_type=$et");
        voucherIsAccrued($pdo, $VCH) ? pass('voucherIsAccrued() = true') : fail('voucherIsAccrued false');
        // Isolation: this must NOT look like an expense accrual.
        (!expenseIsAccrued($pdo, $VCH)) ? pass('isolated from expense_accrual (same id, different base)') : fail('collided with expense accrual');
        $r2 = postVoucherAccrual($pdo, $VCH, $expAcc, $AMT, '2026-04-15', null, $uid, 'PV-TST', 'dup');
        (($r2['reason']??'')==='already_posted') ? pass('idempotent') : fail('not idempotent: '.($r2['reason']??'?'));
    }
} finally { $pdo->rollBack(); }

section('2. Cancel before payment → accrual reversed (rolled back)');
$pdo->beginTransaction();
try {
    $a = postVoucherAccrual($pdo, $VCH, $expAcc, $AMT, '2026-04-15', null, $uid, 'PV-TST', 'payout');
    $rev = reverseVoucherAccrual($pdo, $VCH, $uid);
    (!empty($rev['reversed']) && !empty($rev['entry_id'])) ? pass("reversed (entry #{$rev['entry_id']})") : fail('did not reverse: '.($rev['reason']??'?'));
    if (!empty($rev['entry_id'])) {
        $n1 = netByAcct($pdo, (int)$a['entry_id']); $n2 = netByAcct($pdo, (int)$rev['entry_id']);
        (abs((($n1[$expAcc]??0)+($n2[$expAcc]??0))) < 0.01) ? pass('Expense nets to 0 after reversal') : fail('expense not zeroed');
        (!voucherIsAccrued($pdo, $VCH)) ? pass('voucherIsAccrued() = false after reversal') : fail('still accrued');
    }
} finally { $pdo->rollBack(); }

section('3. Ledger still balances');
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok') : fail('ledger out of balance');
