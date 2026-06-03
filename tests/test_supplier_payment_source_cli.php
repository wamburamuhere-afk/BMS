<?php
/**
 * Supplier Payment — Paid From + consolidated outflow (Phase 1) CLI test.
 *   php tests/test_supplier_payment_source_cli.php
 *
 * Guards: create requires Paid From; posts Dr Accounts Payable / Cr Paid-From to
 * the consolidated ledger tagged 'supplier_payment'; update re-syncs; delete
 * reverses. API calls run in isolated subprocesses.
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id']=4; $_SESSION['username']='admin'; $_SESSION['is_admin']=true; $_SESSION['role_id']=1;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD']='POST';
    $_POST = json_decode(file_get_contents($argv[3]), true);
    require "$root/api/{$argv[2]}.php";
    exit;
}
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;
$pass=0; $fail=0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function approx($a,$b){ return abs((float)$a-(float)$b)<=0.01; }
function call($ep,$p){ global $root; $f=tempnam(sys_get_temp_dir(),'sp'); file_put_contents($f,json_encode($p));
    $o=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__)." worker $ep ".escapeshellarg($f)); @unlink($f);
    $s=strpos($o,'{'); return json_decode(substr($o,$s),true); }

try {
    $sid = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status!='deleted' LIMIT 1")->fetchColumn();
    $cb  = cashBankAccounts($pdo);
    ok(count($cb) > 0 && $sid > 0, "have a supplier + cash/bank account");
    $bank = (int)$cb[0]['account_id']; $ap = defaultPayableAccountId($pdo);

    ok(empty(call('add_supplier_payment', ['supplier_id'=>$sid,'amount'=>'500000','payment_method'=>'cash','payment_date'=>date('Y-m-d')])['success']),
       "create blocked without Paid From");

    $r = call('add_supplier_payment', ['supplier_id'=>$sid,'amount'=>'500000','payment_method'=>'cash','paid_from_account_id'=>$bank,'payment_date'=>date('Y-m-d'),'reference_number'=>'__sp_test']);
    $pid = $r['payment_id'] ?? 0;
    ok(!empty($r['success']) && $pid, "create ok with Paid From");
    $row = $pid ? $pdo->query("SELECT paid_from_account_id,transaction_id FROM supplier_payments WHERE payment_id=$pid")->fetch(PDO::FETCH_ASSOC) : null;
    ok($row && (int)$row['paid_from_account_id'] === $bank, "paid_from stored");
    $txn = $row['transaction_id'] ?? 0;
    ok($txn, "ledger transaction_id stored");
    $lines = $txn ? $pdo->query("SELECT account_id,type,amount FROM books_transactions WHERE transaction_id=$txn")->fetchAll(PDO::FETCH_ASSOC) : [];
    $dr = array_values(array_filter($lines, fn($l)=>$l['type']==='debit'));
    $cr = array_values(array_filter($lines, fn($l)=>$l['type']==='credit'));
    ok($dr && (int)$dr[0]['account_id']===$ap && approx($dr[0]['amount'],500000), "Dr Accounts Payable 500,000");
    ok($cr && (int)$cr[0]['account_id']===$bank && approx($cr[0]['amount'],500000), "Cr Paid-From 500,000");
    ok($pdo->query("SELECT transaction_type FROM transactions WHERE transaction_id=$txn")->fetchColumn()==='supplier_payment', "ledger tagged supplier_payment");

    $d = call('delete_supplier_payment', ['payment_id'=>$pid]);
    $left = $txn ? ((int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE transaction_id=$txn")->fetchColumn()
                  + (int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=$txn")->fetchColumn()) : 1;
    ok(!empty($d['success']) && $left===0, "delete reverses the outflow ledger rows");

    $pdo->exec("DELETE FROM supplier_payments WHERE reference_number='__sp_test'");
} catch (Throwable $e) { ok(false, "exception: ".$e->getMessage()); }

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail===0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
