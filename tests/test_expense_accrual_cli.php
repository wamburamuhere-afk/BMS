<?php
/**
 * OUT-1 — Expense accrual (approve → accrue, pay → settle) — CLI test
 *   php tests/test_expense_accrual_cli.php
 *
 * Guards core/expense_posting.php: an approved expense is recognised
 * Dr Expense / Cr Accrued Expenses; the payment settles it Dr Accrued / Cr Bank
 * (so the expense hits the P&L once, at approval); rejection before payment
 * reverses the accrual. All posts run inside ROLLED-BACK transactions.
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

function netByAcct(PDO $pdo, int $eid): array { // account_id => signed (debit +, credit -)
    $m=[]; foreach($pdo->query("SELECT account_id,type,amount FROM journal_entry_items WHERE entry_id=$eid") as $r){
        $a=(int)$r['account_id']; $m[$a]=($m[$a]??0)+($r['type']==='debit'?(float)$r['amount']:-(float)$r['amount']); } return $m;
}

$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$expAcc = (int)($pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON at.type_id=a.account_type_id WHERE at.category='expense' AND a.status='active' ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
$bank   = (int)($pdo->query("SELECT a.account_id FROM accounts a LEFT JOIN account_sub_types st ON a.sub_type_id=st.sub_type_id WHERE a.status='active' AND a.account_type='asset' AND (st.is_bank=1 OR a.cash_flow_category='cash') AND NOT EXISTS(SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id) ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);

// ─────────────────────────────────────────────────────────────────────────
section('1. Accrued Expenses account resolves, distinct from Trade Creditors (AP)');
$accrued = accruedExpensesAccountId($pdo);
$ap = apAccountId($pdo);
$accrued ? pass("accruedExpensesAccountId → #$accrued") : fail('accruedExpensesAccountId null');
($accrued && $ap && (int)$accrued !== (int)$ap) ? pass("isolated from AP (#$ap)") : fail('accrued == AP — not isolated');
($expAcc && $bank) ? pass("test expense acct #$expAcc, bank #$bank") : fail('missing expense/bank account for test');

$EXP = 990101; $AMT = 250000.00;

// ─────────────────────────────────────────────────────────────────────────
section('2. Approve → Dr Expense / Cr Accrued Expenses (rolled back)');
$pdo->beginTransaction();
try {
    $r = postExpenseAccrual($pdo, $EXP, $expAcc, $AMT, '2026-04-10', null, $uid, 'EXP-TST', 'Test utility bill');
    (!empty($r['posted']) && !empty($r['entry_id'])) ? pass("accrued (entry #{$r['entry_id']})") : fail('did not accrue: '.($r['reason']??'?'));
    if (!empty($r['entry_id'])) {
        $net = netByAcct($pdo, (int)$r['entry_id']);
        (abs(($net[$expAcc]??0) - $AMT) < 0.01)  ? pass('Expense debited '.money($AMT))      : fail('expense debit wrong: '.json_encode($net));
        (abs(($net[$accrued]??0) + $AMT) < 0.01) ? pass('Accrued Expenses credited '.money($AMT)) : fail('accrued credit wrong');
        expenseIsAccrued($pdo, $EXP) ? pass('expenseIsAccrued() = true') : fail('expenseIsAccrued false after accrual');
        $r2 = postExpenseAccrual($pdo, $EXP, $expAcc, $AMT, '2026-04-10', null, $uid, 'EXP-TST', 'dup');
        (($r2['reason']??'')==='already_posted') ? pass('idempotent') : fail('not idempotent: '.($r2['reason']??'?'));
    }
} finally { $pdo->rollBack(); }

// ─────────────────────────────────────────────────────────────────────────
section('3. Two-step nets to Dr Expense / Cr Bank (expense recognised ONCE)');
$pdo->beginTransaction();
try {
    $a = postExpenseAccrual($pdo, $EXP, $expAcc, $AMT, '2026-04-10', null, $uid, 'EXP-TST', 'bill');
    // Settlement the endpoint would post when accrued: Dr Accrued / Cr Bank.
    $settleDebit = accruedExpensesAccountId($pdo);
    $sid = postLedgerEntry($pdo, 'Expense settle', [
        ['account_id'=>(int)$settleDebit,'type'=>'debit','amount'=>$AMT,'description'=>'settle accrual'],
        ['account_id'=>$bank,'type'=>'credit','amount'=>$AMT,'description'=>'cash out'],
    ], null, $EXP, 'expense', '2026-05-01', $uid);
    $n1 = netByAcct($pdo, (int)$a['entry_id']); $n2 = netByAcct($pdo, $sid);
    $expNet     = ($n1[$expAcc]??0) + ($n2[$expAcc]??0);
    $accruedNet = ($n1[$accrued]??0) + ($n2[$accrued]??0);
    $bankNet    = ($n1[$bank]??0) + ($n2[$bank]??0);
    (abs($expNet - $AMT) < 0.01)  ? pass('Expense net = '.money($AMT).' (recognised once)') : fail('expense net '.money($expNet));
    (abs($accruedNet) < 0.01)     ? pass('Accrued Expenses nets to 0 (cleared)')            : fail('accrued net '.money($accruedNet));
    (abs($bankNet + $AMT) < 0.01) ? pass('Bank credited '.money($AMT))                       : fail('bank net '.money($bankNet));
} finally { $pdo->rollBack(); }

// ─────────────────────────────────────────────────────────────────────────
section('4. Reject before payment → accrual reversed, expense leaves P&L (rolled back)');
$pdo->beginTransaction();
try {
    $a = postExpenseAccrual($pdo, $EXP, $expAcc, $AMT, '2026-04-10', null, $uid, 'EXP-TST', 'bill');
    $rev = reverseExpenseAccrual($pdo, $EXP, $uid);
    (!empty($rev['reversed']) && !empty($rev['entry_id'])) ? pass("reversed (entry #{$rev['entry_id']})") : fail('did not reverse: '.($rev['reason']??'?'));
    if (!empty($rev['entry_id'])) {
        $n1 = netByAcct($pdo, (int)$a['entry_id']); $n2 = netByAcct($pdo, (int)$rev['entry_id']);
        (abs((($n1[$expAcc]??0)+($n2[$expAcc]??0))) < 0.01) ? pass('Expense nets to 0 after reversal') : fail('expense not zeroed');
        (!expenseIsAccrued($pdo, $EXP)) ? pass('expenseIsAccrued() = false after reversal') : fail('still accrued after reversal');
        $rev2 = reverseExpenseAccrual($pdo, $EXP, $uid);
        (($rev2['reason']??'')==='already_reversed') ? pass('reversal idempotent') : fail('reversal not idempotent: '.($rev2['reason']??'?'));
    }
} finally { $pdo->rollBack(); }

// ─────────────────────────────────────────────────────────────────────────
section('5. Ledger still balances');
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok') : fail('ledger out of balance: '.json_encode($g));
