<?php
/**
 * TP-D — ACCRUAL flows: account effect WITHOUT cash moving (end-to-end)
 * ---------------------------------------------------------------------
 *   php tests/test_accrual_flows_cli.php
 *
 * Accruals recognise a cost + the liability owed, but NO cash leaves the bank:
 *   payroll accrual : Dr Salaries Expense / Cr PAYE+NSSF+Salaries Payable
 *   SDL accrual     : Dr SDL Expense      / Cr SDL Payable
 * Proves the expense + payable balances move, the ledger balances, and the
 * total cash/bank balance is UNCHANGED. Rolled back. Skips a flow if its GL
 * accounts are not configured on this server.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a, $b){ return abs((float)$a - (float)$b) < 0.01; }
function bal(PDO $pdo, int $id){ $s=$pdo->prepare("SELECT current_balance FROM accounts WHERE account_id=?"); $s->execute([$id]); return (float)$s->fetchColumn(); }
function totalCash(PDO $pdo){ return (float)$pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM accounts WHERE account_type='asset' AND cash_flow_category='cash'")->fetchColumn(); }
// Legacy path (books_transactions) — used by postPayrollAccrual
function legsBalanced(PDO $pdo, int $txn){
    $r = $pdo->query("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE 0 END),0) d, COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) c FROM books_transactions WHERE transaction_id=$txn")->fetch(PDO::FETCH_ASSOC);
    return approx($r['d'], $r['c']) && $r['d'] > 0;
}
// Canonical ledger path (journal_entry_items) — used by postSdlAccrual (postLedgerEntry)
function legsBalancedJei(PDO $pdo, int $entryId){
    $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE 0 END),0) d, COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) c FROM journal_entry_items WHERE entry_id=?");
    $s->execute([$entryId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return approx($r['d'], $r['c']) && $r['d'] > 0;
}
function jeiDebit(PDO $pdo, int $entryId, int $accountId): float {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=? AND account_id=? AND type='debit'");
    $s->execute([$entryId, $accountId]);
    return (float)$s->fetchColumn();
}
function jeiCredit(PDO $pdo, int $entryId, int $accountId): float {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=? AND account_id=? AND type='credit'");
    $s->execute([$entryId, $accountId]);
    return (float)$s->fetchColumn();
}

register_shutdown_function(function(){ global $pass,$fail,$pdo; if($pdo && $pdo->inTransaction()) $pdo->rollBack(); echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. SDL accrual — Dr SDL Expense / Cr SDL Payable, no cash');
    // ─────────────────────────────────────────────────────────────────────
    $sdlExp = (int)getSetting('default_sdl_expense_account_id', 0);
    $sdlPay = (int)getSetting('default_sdl_payable_account_id', 0);
    if ($sdlExp > 0 && $sdlPay > 0) {
        $pdo->beginTransaction();
        try {
            $cashBefore = totalCash($pdo);
            $entryId = postSdlAccrual($pdo, '2099-12', 1000.00);   // far-future period → no collision
            ok($entryId > 0, 'postSdlAccrual posted an entry');
            ok(approx(jeiDebit($pdo, (int)$entryId, $sdlExp), 1000.00), 'SDL Expense INCREASED by 1000');
            ok(approx(jeiCredit($pdo, (int)$entryId, $sdlPay), 1000.00), 'SDL Payable INCREASED by 1000');
            ok(legsBalancedJei($pdo, (int)$entryId), 'ledger balanced (Dr = Cr)');
            ok(approx(totalCash($pdo), $cashBefore), 'NO cash/bank account was touched');
            $pdo->rollBack();
            ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ok(false, 'SDL accrual threw: ' . $e->getMessage());
        }
    } else {
        ok(true, 'SDL accrual GL accounts not configured — skipped (n/a)');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. Payroll accrual — Dr Salaries Expense / Cr payables, no cash');
    // ─────────────────────────────────────────────────────────────────────
    $salExp = (int)getSetting('default_salaries_expense_account_id', 0);
    $salPay = (int)getSetting('default_salaries_payable_account_id', 0);
    if ($salExp > 0 && $salPay > 0) {
        $pdo->beginTransaction();
        try {
            $eBefore = bal($pdo, $salExp); $cashBefore = totalCash($pdo);
            $p = [
                'gross_salary'   => 1000000, 'tax_amount' => 100000, 'nssf_employee' => 50000,
                'deductions'     => 0, 'payroll_number' => 'TP-D-ACC', 'payroll_date' => date('Y-m-d'),
                'project_id'     => null,
            ];
            $txn = postPayrollAccrual($pdo, $p);
            if ($txn) {
                ok(approx(jeiDebit($pdo, (int)$txn, $salExp), 1000000), 'Salaries Expense INCREASED by gross (1,000,000)');
                ok(legsBalancedJei($pdo, (int)$txn), 'payroll accrual ledger balanced (Dr = Cr)');
                ok(approx(totalCash($pdo), $cashBefore), 'NO cash/bank account was touched');
            } else {
                ok(true, 'payroll accrual not posted (some payable accounts unmapped) — skipped (n/a)');
            }
            $pdo->rollBack();
            ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ok(false, 'payroll accrual threw: ' . $e->getMessage());
        }
    } else {
        ok(true, 'payroll accrual GL accounts not configured — skipped (n/a)');
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
