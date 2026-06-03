<?php
/**
 * Sub-contractor default-WHT (SC autofill source) CLI test.
 *   php tests/test_wht_subcontractor_autofill_cli.php
 *
 * Verifies a sub-contractor's default WHT persists through add / get / update —
 * the data that auto-fills the WHT dropdown when paying their invoices (which
 * already flow through the supplier_invoices WHT path). Fixture removed after.
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
function call($ep, $p) { global $root; $f = tempnam(sys_get_temp_dir(), 'wsc'); file_put_contents($f, json_encode($p));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f)); @unlink($f);
    $s = strpos($o, '{'); return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true); }
function whtOf(PDO $pdo, int $id) { return $pdo->query("SELECT default_wht_rate_id FROM sub_contractors WHERE supplier_id = $id")->fetchColumn(); }

$scId = 0;
try {
    $r5 = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=5.00 LIMIT 1")->fetchColumn();
    $r2 = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=2.00 LIMIT 1")->fetchColumn();
    ok($r5 && $r2, "have WHT 5% and 2% rates");
    $pdo->exec("DELETE FROM sub_contractors WHERE supplier_name='__wht_sc_test'");

    // 1. ADD sub-contractor with default WHT 5%
    $a = call('add_sub_contractor', ['supplier_name'=>'__wht_sc_test','default_wht_rate_id'=>$r5]);
    $scId = (int)($a['sub_contractor_id'] ?? 0);
    if (!$scId) $scId = (int)$pdo->query("SELECT supplier_id FROM sub_contractors WHERE supplier_name='__wht_sc_test' ORDER BY supplier_id DESC LIMIT 1")->fetchColumn();
    ok(!empty($a['success']) && $scId, "sub-contractor created" . (empty($a['success']) ? ' (' . json_encode($a) . ')' : ''));
    ok((int)whtOf($pdo, $scId) === $r5, "default_wht_rate_id stored = 5% rate");

    // 2. GET returns it (drives the Record-Payment autofill for their invoices)
    $g = call('get_sub_contractor', ['id'=>$scId]);
    $gv = $g['data']['default_wht_rate_id'] ?? ($g['default_wht_rate_id'] ?? null);
    ok((int)$gv === $r5, "get_sub_contractor returns default WHT");

    // 3. UPDATE → 2%
    $u = call('update_sub_contractor', ['supplier_id'=>$scId,'supplier_name'=>'__wht_sc_test','default_wht_rate_id'=>$r2]);
    ok(!empty($u['success']) && (int)whtOf($pdo, $scId) === $r2, "update changed default WHT to 2%");

    // 4. UPDATE empty → cleared
    $u2 = call('update_sub_contractor', ['supplier_id'=>$scId,'supplier_name'=>'__wht_sc_test','default_wht_rate_id'=>'']);
    ok(!empty($u2['success']) && whtOf($pdo, $scId) === null, "update with empty clears default WHT");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($scId) { try { $pdo->exec("DELETE FROM sub_contractors WHERE supplier_id=$scId"); } catch (Throwable $e) {} }
    try { $pdo->exec("DELETE FROM sub_contractors WHERE supplier_name='__wht_sc_test'"); } catch (Throwable $e) {}
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
