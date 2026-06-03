<?php
/**
 * WHT Receivable on customer payments (Plan B / Phase B2) CLI test.
 *   php tests/test_wht_customer_payment_cli.php
 *
 * Drives api/account/record_payment.php in a subprocess against a real unpaid
 * invoice. Proves the customer payment stamps the WHT receivable, settles the
 * invoice in full, and feeds whtReceivablePosition(). Payment is deleted and the
 * invoice restored afterwards.
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
require_once "$root/core/wht.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function approx($a, $b) { return abs((float)$a - (float)$b) <= 0.05; }
function call($ep, $p) { global $root; $f = tempnam(sys_get_temp_dir(), 'wcp'); file_put_contents($f, json_encode($p));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f)); @unlink($f);
    $s = strpos($o, '{'); return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true); }

$pid = 0; $inv = null; $orig = null;
try {
    $r5  = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=5.00 LIMIT 1")->fetchColumn();
    $inv = $pdo->query("SELECT invoice_id, subtotal, grand_total, status, paid_amount, balance_due
                          FROM invoices WHERE subtotal > 0 AND COALESCE(paid_amount,0) = 0 AND status <> 'paid' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$r5 || !$inv) { ok($r5 && $inv, "have WHT 5% rate + an unpaid invoice with subtotal (skipped if none)"); }
    else {
        ok(true, "fixture: invoice #{$inv['invoice_id']} subtotal {$inv['subtotal']} / grand {$inv['grand_total']}");
        $orig = $inv;  // for restore
        $expectWht = round((float)$inv['subtotal'] * 0.05, 2);
        $pos0 = whtReceivablePosition($pdo)['receivable'];

        $r = call('account/record_payment', [
            'invoice_id' => (int)$inv['invoice_id'], 'amount' => (float)$inv['grand_total'],
            'payment_date' => date('Y-m-d'), 'payment_method' => 'bank_transfer',
            'reference_number' => '__wht_cust_test', 'status' => 'completed', 'wht_rate_id' => $r5,
        ]);
        $pid = (int)($r['payment_id'] ?? 0);
        ok(!empty($r['success']) && $pid, "customer payment recorded" . (empty($r['success']) ? ' (' . json_encode($r) . ')' : ''));

        $row = $pid ? $pdo->query("SELECT wht_rate_id, wht_amount, wht_posted FROM payments WHERE payment_id = $pid")->fetch(PDO::FETCH_ASSOC) : null;
        ok($row && approx($row['wht_amount'], $expectWht) && approx($row['wht_posted'], $expectWht), "payment stamped WHT = 5% of subtotal (" . number_format($expectWht, 2) . ")");
        ok($row && (int)$row['wht_rate_id'] === $r5, "payment wht_rate_id stored");
        ok(approx(whtReceivablePosition($pdo)['receivable'], $pos0 + $expectWht), "whtReceivablePosition rose by the withheld amount");
        $st = $pdo->query("SELECT status FROM invoices WHERE invoice_id = {$inv['invoice_id']}")->fetchColumn();
        ok($st === 'paid', "invoice settled in full (status=paid) despite WHT withheld");
    }

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($pid) { try { $pdo->prepare("DELETE FROM payments WHERE payment_id=?")->execute([$pid]); } catch (Throwable $e) {} }
    if ($orig) {
        try {
            $pdo->prepare("UPDATE invoices SET status=?, paid_amount=?, balance_due=? WHERE invoice_id=?")
                ->execute([$orig['status'], $orig['paid_amount'], $orig['balance_due'], $orig['invoice_id']]);
        } catch (Throwable $e) {}
    }
    try { $pdo->exec("DELETE FROM payments WHERE reference_number='__wht_cust_test'"); } catch (Throwable $e) {}
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
