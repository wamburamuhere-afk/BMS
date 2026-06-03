<?php
/**
 * Customer default-WHT (Plan B / B3 autofill source) CLI test.
 *   php tests/test_wht_customer_autofill_cli.php
 *
 * Verifies the customer's default WHT persists through add / get / update — and
 * that an edit form which omits the field PRESERVES it (the process_edit_customer
 * guard). API calls run in isolated subprocesses. Fixture customer removed.
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $p = json_decode(file_get_contents($argv[3]), true);
    $_POST = $p; $_GET = $p;
    require "$root/api/{$argv[2]}.php";
    exit;
}
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function call($ep, $p) { global $root; $f = tempnam(sys_get_temp_dir(), 'wca'); file_put_contents($f, json_encode($p));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f)); @unlink($f);
    $s = strpos($o, '{'); return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true); }
function whtOf(PDO $pdo, int $id) { return $pdo->query("SELECT default_wht_rate_id FROM customers WHERE customer_id = $id")->fetchColumn(); }

$cid = 0;
try {
    $r5  = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=5.00 LIMIT 1")->fetchColumn();
    $r2  = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=2.00 LIMIT 1")->fetchColumn();
    $cat = (int)$pdo->query("SELECT category_id FROM customer_categories WHERE status='active' LIMIT 1")->fetchColumn();
    ok($r5 && $r2, "have WHT 5% and 2% rates");
    $pdo->exec("DELETE FROM customers WHERE customer_name='__wht_cust_test'");

    // 1. ADD customer with a default WHT of 5%
    $a = call('add_customer', ['customer_name'=>'__wht_cust_test','customer_type'=>'business','category_id'=>$cat ?: 1,'default_wht_rate_id'=>$r5]);
    $cid = (int)$pdo->query("SELECT customer_id FROM customers WHERE customer_name='__wht_cust_test' ORDER BY customer_id DESC LIMIT 1")->fetchColumn();
    ok(!empty($a['success']) && $cid, "customer created" . (empty($a['success']) ? ' (' . json_encode($a) . ')' : ''));
    ok((int)whtOf($pdo, $cid) === $r5, "default_wht_rate_id stored = 5% rate");

    // 2. GET returns it (drives the modal autofill + payment_create preselect)
    $g = call('account/get_customer', ['id'=>$cid]);
    ok(!empty($g['success']) && (int)($g['data']['default_wht_rate_id'] ?? 0) === $r5, "get_customer returns default WHT");

    // 3. UPDATE (with field) → 2%
    $u = call('process_edit_customer', ['customer_id'=>$cid,'customer_name'=>'__wht_cust_test','default_wht_rate_id'=>$r2]);
    ok(!empty($u['success']) && (int)whtOf($pdo, $cid) === $r2, "update with field changed default WHT to 2%");

    // 4. UPDATE WITHOUT the field → preserved (guard against the standalone edit page wiping it)
    $u2 = call('process_edit_customer', ['customer_id'=>$cid,'customer_name'=>'__wht_cust_test']);
    ok(!empty($u2['success']) && (int)whtOf($pdo, $cid) === $r2, "update WITHOUT field preserves default WHT (guard)");

    // 5. UPDATE with empty → cleared to none
    $u3 = call('process_edit_customer', ['customer_id'=>$cid,'customer_name'=>'__wht_cust_test','default_wht_rate_id'=>'']);
    ok(!empty($u3['success']) && whtOf($pdo, $cid) === null, "update with empty clears default WHT to none");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($cid) { try { $pdo->exec("DELETE FROM customers WHERE customer_id=$cid"); } catch (Throwable $e) {} }
    try { $pdo->exec("DELETE FROM customers WHERE customer_name='__wht_cust_test'"); } catch (Throwable $e) {}
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
