<?php
/**
 * Expense delete reverses the accrual — CLI test
 *   php tests/test_expense_delete_reversal_cli.php
 *
 * Gap (account_financial.md #1): an APPROVED-but-unpaid expense posts an accrual
 * (Dr Expense / Cr Accrued Expenses) but has no transaction_id, so the old
 * delete_expense.php reversed nothing → the accrual was orphaned (P&L + Accrued
 * both overstated). This verifies the fix: delete now reverses the accrual.
 *
 * Strategy: drive the real helpers (postExpenseAccrual / the delete reversal call)
 * inside a ROLLED-BACK transaction so the live DB is untouched.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/expense_posting.php";
require_once "$root/core/financial_reports.php";
require_once "$root/core/gl_accounts.php";
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

// ── 1. The fix is wired into delete_expense.php ──────────────────────────────
section('1. delete_expense.php reverses the accrual on delete');
$src = file_get_contents("$root/api/account/delete_expense.php");
ok(strpos($src, 'expenseIsAccrued($pdo, $expense_id)') !== false, 'guards with expenseIsAccrued()');
ok(strpos($src, 'reverseExpenseAccrual($pdo, $expense_id') !== false, 'calls reverseExpenseAccrual() before delete');
ok(strpos($src, "core/expense_posting.php") !== false, 'includes core/expense_posting.php');
ok(strpos($src, "=== null) === 'paid'") !== false || strpos($src, "'paid'") !== false, 'still locks a PAID expense from delete');

// ── 2. Runtime: accrual posts, then the reversal nets it to zero (rolled back) ─
section('2. Runtime — approve posts accrual; delete reverses it (rolled back)');
$expAcc = (int)$pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
                             WHERE a.status='active' AND at.category IN ('expense','finance_cost') ORDER BY a.account_id LIMIT 1")->fetchColumn();
$accrued = (int)accruedExpensesAccountId($pdo);
ok($expAcc > 0, "have an expense account (#$expAcc)");
ok($accrued > 0, "have the Accrued Expenses account (#$accrued)");

$FAKE_EXPENSE_ID = 999000001;   // synthetic id, unique to this test
$amount = 4321.00;

$g0 = assertLedgerBalanced($pdo, date('Y-m-d'));
ok($g0['ledger_balanced'], 'ledger balanced before');

$pdo->beginTransaction();
try {
    // Approve → accrual posts (Dr Expense / Cr Accrued).
    $r = postExpenseAccrual($pdo, $FAKE_EXPENSE_ID, $expAcc, $amount, date('Y-m-d'), null, 4, 'EXP-TST', 'delete-reversal test');
    ok(!empty($r['posted']), 'accrual posted (Dr Expense / Cr Accrued)');

    $bal = function(int $acc): float {
        global $pdo, $FAKE_EXPENSE_ID;
        $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0)
            FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id AND je.status='posted'
            WHERE jei.account_id=? AND je.entity_id=? AND je.entity_type IN ('expense_accrual','expense_accrual_void')");
        $s->execute([$acc, $FAKE_EXPENSE_ID]);
        return round((float)$s->fetchColumn(), 2);
    };
    ok(abs($bal($expAcc) - $amount) < 0.01, 'Expense account carries +'.$amount.' after accrual');
    ok(abs($bal($accrued) + $amount) < 0.01, 'Accrued Expenses carries -'.$amount.' (credit) after accrual');
    ok(expenseIsAccrued($pdo, $FAKE_EXPENSE_ID), 'expenseIsAccrued() = true');

    // Delete → reverse the accrual (the exact call delete_expense.php now makes).
    $rev = reverseExpenseAccrual($pdo, $FAKE_EXPENSE_ID, 4);
    ok(!empty($rev['reversed']), 'reverseExpenseAccrual() posted the contra (Dr Accrued / Cr Expense)');

    $voidExists = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='expense_accrual_void' AND entity_id=$FAKE_EXPENSE_ID AND status='posted'")->fetchColumn();
    ok($voidExists === 1, 'an expense_accrual_void entry now exists');

    // Net effect on BOTH accounts must be zero (accrual + its reversal cancel).
    ok(abs($bal($expAcc)) < 0.01, 'Expense account nets to ZERO after delete (not overstated)');
    ok(abs($bal($accrued)) < 0.01, 'Accrued Expenses nets to ZERO after delete (not overstated)');

    // Idempotent: a second reverse does nothing new.
    $rev2 = reverseExpenseAccrual($pdo, $FAKE_EXPENSE_ID, 4);
    ok(($rev2['reason'] ?? '') === 'already_reversed', 'reverse is idempotent (already_reversed)');

    $g1 = assertLedgerBalanced($pdo, date('Y-m-d'));
    ok($g1['ledger_balanced'], 'ledger still balanced after accrual + reversal');
} finally {
    $pdo->rollBack();   // leave the live DB exactly as found
}

// confirm rollback left nothing behind
$leak = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id=$FAKE_EXPENSE_ID AND entity_type IN ('expense_accrual','expense_accrual_void')")->fetchColumn();
ok($leak === 0, 'rolled back cleanly — no test rows persisted');
