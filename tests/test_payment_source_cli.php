<?php
/**
 * Payment-source foundation — CLI test
 *   php tests/test_payment_source_cli.php
 *
 * Guards the consolidated-outflow foundation (core/payment_source.php):
 *   - cash/bank source list + Paid-From option rendering
 *   - default Accounts Payable + Petty Cash accounts/settings
 *   - postOutflow posts a balanced Dr/Cr to the consolidated transactions ledger
 *     with the right type, and reverseOutflow removes it
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function approx($a,$b){ return abs((float)$a-(float)$b) <= 0.01; }

try {
    // Foundation present
    ok(defaultPayableAccountId($pdo) > 0, "default Accounts Payable account configured");
    ok(pettyCashAccountId($pdo) > 0, "default Petty Cash account configured");
    $cb = cashBankAccounts($pdo);
    ok(count($cb) > 0, "cashBankAccounts returns cash/bank source(s)");
    ok(strpos(paidFromSelectOptions($pdo), '<option value=') !== false, "paidFromSelectOptions renders options");

    // transaction_type enum widened
    $ty = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC)['Type'];
    ok(strpos($ty, "'supplier_payment'") !== false && strpos($ty, "'petty_cash'") !== false, "transaction_type enum widened");

    // postOutflow → balanced Dr/Cr in the consolidated ledger
    $ap = defaultPayableAccountId($pdo);
    $paidFrom = (int)$cb[0]['account_id'];
    $txn = postOutflow($pdo, 'supplier_payment', $paidFrom, $ap, 250000, date('Y-m-d'), 'TEST-PS', 'Foundation test');
    ok($txn > 0, "postOutflow posted a transaction");
    $lines = $pdo->query("SELECT account_id,type,amount FROM books_transactions WHERE transaction_id=$txn")->fetchAll(PDO::FETCH_ASSOC);
    $dr = array_values(array_filter($lines, fn($l)=>$l['type']==='debit'));
    $cr = array_values(array_filter($lines, fn($l)=>$l['type']==='credit'));
    ok(count($lines) === 2, "two ledger lines written");
    ok($dr && (int)$dr[0]['account_id'] === $ap && approx($dr[0]['amount'],250000), "Dr = Accounts Payable 250,000");
    ok($cr && (int)$cr[0]['account_id'] === $paidFrom && approx($cr[0]['amount'],250000), "Cr = Paid-From cash/bank 250,000");
    ok($pdo->query("SELECT transaction_type FROM transactions WHERE transaction_id=$txn")->fetchColumn() === 'supplier_payment', "header tagged with the outflow type");

    // reverseOutflow removes everything
    reverseOutflow($pdo, $txn);
    $left = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE transaction_id=$txn")->fetchColumn()
          + (int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=$txn")->fetchColumn();
    ok($left === 0, "reverseOutflow removed all ledger rows");

    // guards
    ok(postOutflow($pdo,'supplier_payment',null,$ap,100,date('Y-m-d'),'x','x') === null, "postOutflow no-ops without a source account");
} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail===0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
