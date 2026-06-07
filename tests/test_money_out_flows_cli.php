<?php
/**
 * TP-B — MONEY OUT flows: dropdowns + account effect (end-to-end)
 * ---------------------------------------------------------------
 *   php tests/test_money_out_flows_cli.php
 *
 * (A) the pickers used by money-OUT pages offer the RIGHT accounts
 *     (paid-from = cash leaves; expense = expense/finance_cost leaves), and
 * (B) posting money out (postOutflow) actually DECREASES the chosen cash/bank
 *     account by the amount, with a balanced Dr-expense / Cr-cash ledger.
 *
 * All writes happen inside a transaction that is always rolled back.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a, $b){ return abs((float)$a - (float)$b) < 0.01; }
function src(string $root, string $rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }
function bal(PDO $pdo, int $id){ $s=$pdo->prepare("SELECT current_balance FROM accounts WHERE account_id=?"); $s->execute([$id]); return (float)$s->fetchColumn(); }
function hasKids(PDO $pdo, int $id){ $s=$pdo->prepare("SELECT 1 FROM accounts WHERE parent_account_id=? LIMIT 1"); $s->execute([$id]); return (bool)$s->fetchColumn(); }

register_shutdown_function(function(){ global $pass,$fail,$pdo; if($pdo && $pdo->inTransaction()) $pdo->rollBack(); echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Dropdowns offer the right accounts');
    // ─────────────────────────────────────────────────────────────────────
    $cb = cashBankAccounts($pdo);
    ok(count($cb) > 0, 'paid-from picker (cash/bank) non-empty (' . count($cb) . ')');
    $exp = expenseAccounts($pdo);
    ok(count($exp) > 0, 'expense picker non-empty (' . count($exp) . ')');
    $expOk = true;
    foreach ($exp as $a) {
        $r = $pdo->prepare("SELECT 1 FROM accounts x JOIN account_types t ON x.account_type_id=t.type_id WHERE x.account_id=? AND t.category IN ('expense','finance_cost') AND x.status='active'");
        $r->execute([$a['account_id']]);
        if (!$r->fetchColumn() || hasKids($pdo, (int)$a['account_id'])) $expOk = false;
    }
    ok($expOk, 'every expense option is an ACTIVE expense/finance_cost LEAF');
    ok((int)(pettyCashAccountId($pdo) ?? 0) > 0, 'petty cash source account is configured (pettyCashAccountId)');

    // ─────────────────────────────────────────────────────────────────────
    section('2. postOutflow takes money OUT of the chosen cash/bank account');
    // ─────────────────────────────────────────────────────────────────────
    $cash    = (int)$cb[0]['account_id'];
    $expense = (int)$exp[0]['account_id'];
    $pdo->beginTransaction();
    try {
        $before = bal($pdo, $cash);
        $txn = postOutflow($pdo, 'expense', $cash, $expense, 750.00, date('Y-m-d'), 'TP-B-OUT', 'money-out test', null);
        ok($txn > 0, 'postOutflow posted a transaction');
        $after = bal($pdo, $cash);
        ok(approx($before - $after, 750.00), "cash/bank account DECREASED by 750 (was $before, now $after)");

        $legs = $pdo->query("SELECT account_id, type, amount FROM books_transactions WHERE transaction_id=$txn")->fetchAll(PDO::FETCH_ASSOC);
        ok(count($legs) === 2, 'two balanced ledger legs written');
        $dr = array_values(array_filter($legs, fn($l) => $l['type'] === 'debit'));
        $cr = array_values(array_filter($legs, fn($l) => $l['type'] === 'credit'));
        ok($dr && (int)$dr[0]['account_id'] === $expense && approx($dr[0]['amount'], 750), 'Dr leg = expense account, 750');
        ok($cr && (int)$cr[0]['account_id'] === $cash && approx($cr[0]['amount'], 750), 'Cr leg = cash/bank account, 750');

        reverseOutflow($pdo, $txn);
        ok(approx(bal($pdo, $cash), $before), 'reverseOutflow restores the cash/bank balance exactly');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ok(false, 'money-out posting threw: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. Money-OUT pages wire the right pickers');
    // ─────────────────────────────────────────────────────────────────────
    $checks = [
        'app/constant/accounts/expenses.php'        => ['cashBankAccounts($pdo)', 'expenseAccounts($pdo)'],
        'app/constant/accounts/bank_transfers.php'  => ['expenseAccounts($pdo)'],   // charge account
        'app/constant/accounts/recurring.php'       => ['cashBankAccounts($pdo)', 'expenseAccounts($pdo)'],
    ];
    foreach ($checks as $rel => $needles) {
        $s = src($root, $rel);
        foreach ($needles as $n) ok(strpos($s, $n) !== false, "$rel uses $n");
    }
    $pv = src($root, 'app/constant/accounts/payment_vouchers.php');
    ok(strpos($pv, 'paidFromSelectOptions') !== false || strpos($pv, 'cashBankAccounts') !== false, 'payment_vouchers.php paid-from = cash/bank helper');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
