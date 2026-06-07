<?php
/**
 * TP-A — MONEY IN flows: dropdowns + account effect (end-to-end)
 * --------------------------------------------------------------
 *   php tests/test_money_in_flows_cli.php
 *
 * (A) the account pickers used by money-IN pages offer the RIGHT accounts
 *     (cash/bank = cash leaves; income = revenue leaves), and
 * (B) posting money in (postInflow) actually INCREASES the chosen cash/bank
 *     account by the amount, with a balanced Dr-cash / Cr-income ledger.
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
    ok(count($cb) > 0, 'cash/bank picker (Received-Into) is non-empty (' . count($cb) . ')');
    $cbOk = true;
    foreach ($cb as $a) {
        $r = $pdo->prepare("SELECT 1 FROM accounts WHERE account_id=? AND account_type='asset' AND cash_flow_category='cash' AND status='active'");
        $r->execute([$a['account_id']]);
        if (!$r->fetchColumn() || hasKids($pdo, (int)$a['account_id'])) $cbOk = false;
    }
    ok($cbOk, 'every Received-Into option is an ACTIVE cash/bank LEAF');

    $inc = incomeAccounts($pdo);
    ok(count($inc) > 0, 'income picker is non-empty (' . count($inc) . ')');
    $incOk = true;
    foreach ($inc as $a) {
        $r = $pdo->prepare("SELECT 1 FROM accounts x JOIN account_types t ON x.account_type_id=t.type_id WHERE x.account_id=? AND t.category='revenue' AND x.status='active'");
        $r->execute([$a['account_id']]);
        if (!$r->fetchColumn() || hasKids($pdo, (int)$a['account_id'])) $incOk = false;
    }
    ok($incOk, 'every income option is an ACTIVE revenue LEAF');

    // ─────────────────────────────────────────────────────────────────────
    section('2. postInflow puts money INTO the chosen cash/bank account');
    // ─────────────────────────────────────────────────────────────────────
    $cash   = (int)$cb[0]['account_id'];
    $income = (int)$inc[0]['account_id'];
    $pdo->beginTransaction();
    try {
        $before = bal($pdo, $cash);
        $txn = postInflow($pdo, 'revenue', $cash, $income, 1000.00, date('Y-m-d'), 'TP-A-IN', 'money-in test', null);
        ok($txn > 0, 'postInflow posted a transaction');
        $after = bal($pdo, $cash);
        ok(approx($after - $before, 1000.00), "cash/bank account INCREASED by 1000 (was $before, now $after)");

        $legs = $pdo->query("SELECT account_id, type, amount FROM books_transactions WHERE transaction_id=$txn")->fetchAll(PDO::FETCH_ASSOC);
        ok(count($legs) === 2, 'two balanced ledger legs written');
        $dr = array_values(array_filter($legs, fn($l) => $l['type'] === 'debit'));
        $cr = array_values(array_filter($legs, fn($l) => $l['type'] === 'credit'));
        ok($dr && (int)$dr[0]['account_id'] === $cash && approx($dr[0]['amount'], 1000), 'Dr leg = cash/bank account, 1000');
        ok($cr && (int)$cr[0]['account_id'] === $income && approx($cr[0]['amount'], 1000), 'Cr leg = income account, 1000');

        reverseInflow($pdo, $txn);
        ok(approx(bal($pdo, $cash), $before), 'reverseInflow restores the cash/bank balance exactly');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ok(false, 'money-in posting threw: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. Money-IN pages wire the right pickers');
    // ─────────────────────────────────────────────────────────────────────
    $rev = src($root, 'app/constant/accounts/revenue.php');
    ok(strpos($rev, 'incomeAccounts($pdo)') !== false, 'revenue.php income dropdown = incomeAccounts()');
    ok(strpos($rev, 'cashBankAccounts($pdo)') !== false, 'revenue.php received-into = cashBankAccounts()');
    $rcv = src($root, 'app/constant/accounts/receive_payment.php');
    ok(strpos($rcv, 'cashBankAccounts($pdo)') !== false, 'receive_payment.php received-into = cashBankAccounts()');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
