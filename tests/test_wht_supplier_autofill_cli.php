<?php
/**
 * Supplier default-WHT (Phase 5b autofill source) CLI test.
 *   php tests/test_wht_supplier_autofill_cli.php
 *
 * Verifies the supplier's default WHT category persists through add/get/update
 * (the data that drives the payment-modal auto-fill). API calls run in isolated
 * subprocesses. Fixture supplier removed afterwards.
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $p = json_decode(file_get_contents($argv[3]), true);
    $_POST = $p; $_GET = $p;                 // serve POST endpoints and the GET lookup
    require "$root/api/{$argv[2]}.php";
    exit;
}
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function call($ep, $p) { global $root; $f = tempnam(sys_get_temp_dir(), 'wsa'); file_put_contents($f, json_encode($p));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f)); @unlink($f);
    $s = strpos($o, '{'); return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true); }
function whtOf(PDO $pdo, int $id) { return $pdo->query("SELECT default_wht_rate_id FROM suppliers WHERE supplier_id = $id")->fetchColumn(); }

$supId = 0;
try {
    $r5 = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=5.00 LIMIT 1")->fetchColumn();
    $r2 = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=2.00 LIMIT 1")->fetchColumn();
    ok($r5 && $r2, "have WHT 5% and 2% rates");
    $pdo->exec("DELETE FROM suppliers WHERE supplier_name='__wht_sup_test'");   // clear any leftover

    // 1. ADD supplier with a default WHT of 5%
    $a = call('add_supplier', ['supplier_name'=>'__wht_sup_test','default_wht_rate_id'=>$r5]);
    $supId = (int)($a['supplier_id'] ?? 0);
    ok(!empty($a['success']) && $supId, "supplier created with default WHT" . (empty($a['success']) ? ' (' . json_encode($a) . ')' : ''));
    ok((int)whtOf($pdo, $supId) === $r5, "default_wht_rate_id stored = 5% rate");

    // 2. GET returns it (this is what the payment modal reads)
    $g = call('get_supplier', ['id'=>$supId]);
    ok(!empty($g['success']) && (int)($g['data']['default_wht_rate_id'] ?? 0) === $r5, "get_supplier returns default WHT");

    // 3. UPDATE to 2%
    $u = call('update_supplier', ['supplier_id'=>$supId,'supplier_name'=>'__wht_sup_test','default_wht_rate_id'=>$r2]);
    ok(!empty($u['success']) && (int)whtOf($pdo, $supId) === $r2, "update changed default WHT to 2%");

    // 4. UPDATE to none (cleared)
    $u2 = call('update_supplier', ['supplier_id'=>$supId,'supplier_name'=>'__wht_sup_test','default_wht_rate_id'=>'']);
    ok(!empty($u2['success']) && whtOf($pdo, $supId) === null, "update cleared default WHT to none");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($supId) { try { $pdo->exec("DELETE FROM suppliers WHERE supplier_id=$supId"); } catch (Throwable $e) {} }
    try { $pdo->exec("DELETE FROM suppliers WHERE supplier_name='__wht_sup_test'"); } catch (Throwable $e) {}
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
