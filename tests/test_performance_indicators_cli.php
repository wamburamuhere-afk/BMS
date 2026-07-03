<?php
/**
 * Performance indicators & competency targets (Tier 3, Phase 3.2) CLI test.
 *   php tests/test_performance_indicators_cli.php
 *
 * Proves: category + indicator CRUD, the designation target upsert
 * (INSERT … ON DUPLICATE KEY UPDATE on uniq_desig_ind, 0 clears a row),
 * the soft-delete guard (a removed indicator keeps any appraisal-item
 * history via its snapshot), permission denials, and that the Indicators
 * tab renders for a designation with 0 and N targets.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[2] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/hr_performance';
    require "$root/app/bms/pos/hr_performance.php";
    exit;
}
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[3]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/api/{$argv[2]}.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function call($ep, $payload, $session, $method = 'POST') {
    global $root;
    $cfg = ['session' => $session, 'method' => $method, ($method === 'GET' ? 'get' : 'post') => $payload];
    $f = tempnam(sys_get_temp_dir(), 'pin'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($uid) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $uid 2>&1"); }
function noErr($h) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','Call to undefined'] as $e) if (stripos($h,$e)!==false) return false; return true; }

$cat_id = 0; $ind_id = 0; $ind2_id = 0; $desig_id = 0; $appr_id = 0; $emp_id = 0; $cycle_id = 0;
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $NOPERM = ['user_id' => 999910, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()]];
    $desig_id = (int)$pdo->query("SELECT designation_id FROM designations WHERE status='active' LIMIT 1")->fetchColumn();
    ok($desig_id > 0, "fixture designation available (#$desig_id)");

    // ── 1. Category CRUD ─────────────────────────────────────────────────────
    $r = call('manage_indicators', ['action' => 'add_category', 'category_name' => '__PI Cat', 'sort_order' => 99], $ADMIN);
    $cat_id = (int)($r['category_id'] ?? 0);
    ok(!empty($r['success']) && $cat_id, "category created");
    $r = call('manage_indicators', ['action' => 'add_category', 'category_name' => '__PI Cat'], $ADMIN);
    ok(empty($r['success']), "duplicate category rejected");
    $r = call('manage_indicators', ['action' => 'rename_category', 'category_id' => $cat_id, 'category_name' => '__PI Cat R'], $ADMIN);
    $nm = $pdo->query("SELECT category_name FROM performance_indicator_categories WHERE category_id=$cat_id")->fetchColumn();
    ok(!empty($r['success']) && $nm === '__PI Cat R', "category renamed");

    // ── 2. Indicator CRUD ────────────────────────────────────────────────────
    $r = call('manage_indicators', ['action' => 'add_indicator', 'category_id' => $cat_id, 'indicator_name' => '__PI Ind1', 'description' => 'desc'], $ADMIN);
    $ind_id = (int)($r['indicator_id'] ?? 0);
    ok(!empty($r['success']) && $ind_id, "indicator created");
    $r = call('manage_indicators', ['action' => 'add_indicator', 'category_id' => $cat_id, 'indicator_name' => '__PI Ind2'], $ADMIN);
    $ind2_id = (int)($r['indicator_id'] ?? 0);
    ok(!empty($r['success']) && $ind2_id, "second indicator created");
    $r = call('manage_indicators', ['action' => 'update_indicator', 'indicator_id' => $ind_id, 'category_id' => $cat_id, 'indicator_name' => '__PI Ind1x'], $ADMIN);
    $nm = $pdo->query("SELECT indicator_name FROM performance_indicators WHERE indicator_id=$ind_id")->fetchColumn();
    ok(!empty($r['success']) && $nm === '__PI Ind1x', "indicator updated");

    // can't delete a category that still has active indicators
    $r = call('manage_indicators', ['action' => 'delete_category', 'category_id' => $cat_id], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'], 'indicator') !== false, "category delete blocked while it has active indicators");

    // permission denial
    $r = call('manage_indicators', ['action' => 'add_category', 'category_name' => 'zz'], $NOPERM);
    ok(empty($r['success']), "manage denied without canEdit('hr_performance')");

    // ── 3. get_indicators returns our rows ───────────────────────────────────
    $r = call('get_indicators', [], $ADMIN, 'GET');
    $names = array_column($r['indicators'] ?? [], 'indicator_name');
    ok(!empty($r['success']) && in_array('__PI Ind1x', $names, true) && in_array('__PI Ind2', $names, true), "get_indicators lists active indicators");

    // ── 4. Target upsert matrix ──────────────────────────────────────────────
    $r = call('save_designation_targets', ['designation_id' => $desig_id, 'target' => [$ind_id => 4, $ind2_id => 2]], $ADMIN);
    ok(!empty($r['success']), "targets saved");
    $t1 = $pdo->query("SELECT expected_rating FROM designation_indicator_targets WHERE designation_id=$desig_id AND indicator_id=$ind_id")->fetchColumn();
    ok((int)$t1 === 4, "target for indicator1 = 4");
    // upsert: change one, clear the other (0)
    $r = call('save_designation_targets', ['designation_id' => $desig_id, 'target' => [$ind_id => 5, $ind2_id => 0]], $ADMIN);
    $t1b = $pdo->query("SELECT expected_rating FROM designation_indicator_targets WHERE designation_id=$desig_id AND indicator_id=$ind_id")->fetchColumn();
    $t2b = $pdo->query("SELECT COUNT(*) FROM designation_indicator_targets WHERE designation_id=$desig_id AND indicator_id=$ind2_id")->fetchColumn();
    ok((int)$t1b === 5, "upsert updated indicator1 to 5 (no duplicate row)");
    ok((int)$t2b === 0, "rating 0 cleared indicator2's target row");
    // out-of-range rating rejected/cleared, not stored
    $r = call('save_designation_targets', ['designation_id' => $desig_id, 'target' => [$ind_id => 9]], $ADMIN);
    $t1c = $pdo->query("SELECT COUNT(*) FROM designation_indicator_targets WHERE designation_id=$desig_id AND indicator_id=$ind_id")->fetchColumn();
    ok((int)$t1c === 0, "out-of-range rating (9) is treated as clear, never stored");
    $r = call('save_designation_targets', ['designation_id' => $desig_id, 'target' => [$ind_id => 3]], $ADMIN);  // restore for render test

    // ── 5. Soft-delete guard: indicator used by an appraisal item ────────────
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,designation_id,created_at) VALUES ('__PI','Emp','__PI-E1','active',$desig_id,NOW())");
    $emp_id = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO appraisal_cycles (cycle_name,period_from,period_to,created_by) VALUES ('__PI Cycle','2026-01-01','2026-12-31',$admin_uid)");
    $cycle_id = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO employee_appraisals (cycle_id,employee_id,designation_id,appraisal_date,status,created_by) VALUES ($cycle_id,$emp_id,$desig_id,CURDATE(),'approved',$admin_uid)");
    $appr_id = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO employee_appraisal_items (appraisal_id,indicator_id,expected_rating,actual_rating,comment) VALUES ($appr_id,$ind_id,3,4,'snap')");
    $r = call('manage_indicators', ['action' => 'delete_indicator', 'indicator_id' => $ind_id], $ADMIN);
    $st = $pdo->query("SELECT status FROM performance_indicators WHERE indicator_id=$ind_id")->fetchColumn();
    $itemStill = $pdo->query("SELECT actual_rating FROM employee_appraisal_items WHERE appraisal_id=$appr_id AND indicator_id=$ind_id")->fetchColumn();
    ok(!empty($r['success']) && $st === 'deleted', "referenced indicator is soft-deleted (status=deleted)");
    ok((int)$itemStill === 4, "appraisal item history preserved after indicator removal (snapshot intact)");
    $targGone = $pdo->query("SELECT COUNT(*) FROM designation_indicator_targets WHERE indicator_id=$ind_id")->fetchColumn();
    ok((int)$targGone === 0, "removed indicator's designation targets dropped (targets are not history)");

    // ── 6. Page render (N-target and 0-target designations both fine) ────────
    $html = render($admin_uid);
    ok(noErr($html), "hr_performance.php renders without errors");
    ok(strpos($html, 'Indicators &amp; Targets') !== false || strpos($html, 'Indicators & Targets') !== false, "Indicators tab present for editor");
    ok(strpos($html, 'Competency Targets by Designation') !== false, "target matrix panel renders");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($appr_id) $pdo->exec("DELETE FROM employee_appraisal_items WHERE appraisal_id=$appr_id");
    if ($appr_id) $pdo->exec("DELETE FROM employee_appraisals WHERE appraisal_id=$appr_id");
    if ($cycle_id) $pdo->exec("DELETE FROM appraisal_cycles WHERE cycle_id=$cycle_id");
    if ($emp_id) $pdo->exec("DELETE FROM employees WHERE employee_id=$emp_id");
    if ($desig_id) $pdo->exec("DELETE FROM designation_indicator_targets WHERE indicator_id IN ($ind_id,$ind2_id)");
    if ($ind_id) $pdo->exec("DELETE FROM performance_indicators WHERE indicator_id IN ($ind_id,$ind2_id)");
    if ($cat_id) $pdo->exec("DELETE FROM performance_indicator_categories WHERE category_id=$cat_id");
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
