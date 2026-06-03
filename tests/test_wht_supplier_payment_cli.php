<?php
/**
 * WHT on ad-hoc supplier payments (Phase 5a) CLI test.
 *   php tests/test_wht_supplier_payment_cli.php
 *
 * Drives add/update/delete_supplier_payment.php in isolated subprocesses. Proves:
 * add with WHT pays NET + parks WHT Payable + stamps the row; update RE-SYNCS WHT
 * (amount change) and can REMOVE it; delete reverses everything. Fixtures cleaned.
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = json_decode(file_get_contents($argv[3]), true);
    require "$root/api/{$argv[2]}.php";
    exit;
}
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
require_once "$root/core/wht.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function approx($a, $b) { return abs((float)$a - (float)$b) <= 0.01; }
function bal(PDO $pdo, int $id) { return (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id = $id")->fetchColumn(); }
function call($ep, $p) { global $root; $f = tempnam(sys_get_temp_dir(), 'wsp'); file_put_contents($f, json_encode($p));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f)); @unlink($f);
    $s = strpos($o, '{'); return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true); }
function payrow(PDO $pdo, int $id) { return $pdo->query("SELECT amount, wht_rate_id, wht_amount, wht_posted, transaction_id FROM supplier_payments WHERE payment_id = $id")->fetch(PDO::FETCH_ASSOC); }
function lines(PDO $pdo, $txn) { return $txn ? $pdo->query("SELECT account_id,type,amount FROM books_transactions WHERE transaction_id=" . (int)$txn)->fetchAll(PDO::FETCH_ASSOC) : []; }

$pid = 0;
try {
    $sid    = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status!='deleted' LIMIT 1")->fetchColumn();
    $cb     = cashBankAccounts($pdo); $cash = $cb ? (int)$cb[0]['account_id'] : 0;
    $ap     = (int)defaultPayableAccountId($pdo);
    $whtAcc = (int)whtPayableAccountId($pdo);
    $r5     = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=5.00 LIMIT 1")->fetchColumn();
    ok($sid && $cash && $ap && $whtAcc && $r5, "have supplier, cash, AP, WHT account, WHT 5% rate");
    $cash0 = bal($pdo, $cash); $wht0 = bal($pdo, $whtAcc);

    // 1. ADD with WHT 5% on 1,000,000 → withhold 50,000, pay 950,000
    $r = call('add_supplier_payment', ['supplier_id'=>$sid,'amount'=>'1000000','payment_method'=>'cash','paid_from_account_id'=>$cash,'payment_date'=>date('Y-m-d'),'reference_number'=>'__wht_sp_test','wht_rate_id'=>$r5]);
    $pid = (int)($r['payment_id'] ?? 0);
    ok(!empty($r['success']) && $pid, "add with WHT succeeded" . (empty($r['success']) ? ' (' . json_encode($r) . ')' : ''));
    $row = payrow($pdo, $pid);
    ok($row && approx($row['wht_amount'], 50000) && approx($row['wht_posted'], 50000) && (int)$row['wht_rate_id'] === $r5, "row stamped wht 50,000 @ rate 5%");
    $ln = lines($pdo, $row['transaction_id']);
    $crCash = array_values(array_filter($ln, fn($l)=>$l['type']==='credit' && (int)$l['account_id']===$cash));
    $crWht  = array_values(array_filter($ln, fn($l)=>$l['type']==='credit' && (int)$l['account_id']===$whtAcc));
    ok(count($ln) === 3 && $crCash && approx($crCash[0]['amount'],950000) && $crWht && approx($crWht[0]['amount'],50000), "3-line entry: Cr Cash 950,000 + Cr WHT 50,000");
    ok(approx(bal($pdo,$cash),$cash0-950000) && approx(bal($pdo,$whtAcc),$wht0+50000), "balances: cash −950,000, WHT Payable +50,000");

    // 2. UPDATE amount → 2,000,000 (still WHT 5%): re-sync to withhold 100,000
    $u = call('update_supplier_payment', ['payment_id'=>$pid,'supplier_id'=>$sid,'amount'=>'2000000','currency'=>'TZS','payment_method'=>'cash','paid_from_account_id'=>$cash,'payment_date'=>date('Y-m-d'),'reference_number'=>'__wht_sp_test','wht_rate_id'=>$r5]);
    ok(!empty($u['success']), "update (amount→2,000,000) succeeded");
    $row = payrow($pdo, $pid);
    ok($row && approx($row['wht_amount'],100000) && approx($row['wht_posted'],100000), "re-synced wht to 100,000");
    ok(approx(bal($pdo,$cash),$cash0-1900000) && approx(bal($pdo,$whtAcc),$wht0+100000), "balances re-synced: cash −1,900,000, WHT Payable +100,000");

    // 3. UPDATE removing WHT (rate cleared): plain payment, WHT back to baseline
    $u2 = call('update_supplier_payment', ['payment_id'=>$pid,'supplier_id'=>$sid,'amount'=>'2000000','currency'=>'TZS','payment_method'=>'cash','paid_from_account_id'=>$cash,'payment_date'=>date('Y-m-d'),'reference_number'=>'__wht_sp_test','wht_rate_id'=>'']);
    ok(!empty($u2['success']), "update (remove WHT) succeeded");
    $row = payrow($pdo, $pid);
    ok($row && $row['wht_posted'] === null, "wht_posted cleared on removal");
    ok((int)count(lines($pdo,$row['transaction_id'])) === 2, "ledger back to plain 2-line");
    ok(approx(bal($pdo,$cash),$cash0-2000000) && approx(bal($pdo,$whtAcc),$wht0), "cash −2,000,000 (full), WHT Payable back to baseline");

    // 4. DELETE reverses everything
    $d = call('delete_supplier_payment', ['payment_id'=>$pid]);
    ok(!empty($d['success']), "delete succeeded");
    ok(approx(bal($pdo,$cash),$cash0) && approx(bal($pdo,$whtAcc),$wht0), "delete restored both balances to baseline");
    $pid = 0;

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    // Sweep any leftover test fixtures (reverse their outflow, then remove).
    foreach ($pdo->query("SELECT payment_id, transaction_id FROM supplier_payments WHERE reference_number='__wht_sp_test'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        try { if (!empty($p['transaction_id'])) reverseOutflow($pdo, (int)$p['transaction_id']); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM supplier_payments WHERE payment_id=?")->execute([$p['payment_id']]); } catch (Throwable $e) {}
    }
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
