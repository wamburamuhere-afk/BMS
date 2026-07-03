<?php
/**
 * Employee appraisals (Tier 3, Phase 3.3) CLI test.
 *   php tests/test_employee_appraisals_cli.php
 *
 * Proves: cycle CRUD + close blocks new appraisals, one-appraisal-per-cycle,
 * expected_rating snapshot at creation (later target change doesn't rewrite
 * it — D19), submit→approve workflow with overall = AVG(actual) stored (D17),
 * segregation of duties (creator can't approve; admin exempt), reject with
 * reason, scope + permission denials, and page/details renders.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[3] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    if ($argv[2] === 'hr_performance') { $_SERVER['REQUEST_URI'] = '/hr_performance'; require "$root/app/bms/pos/hr_performance.php"; }
    else { $_SERVER['REQUEST_URI'] = '/employee_details'; $_GET['id'] = (int)($argv[4] ?? 0); require "$root/app/bms/pos/employee_details.php"; }
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
    $f = tempnam(sys_get_temp_dir(), 'app'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($page, $uid, $emp = 0) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $page $uid $emp 2>&1"); }
function noErr($h) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','Call to undefined'] as $e) if (stripos($h,$e)!==false) return false; return true; }

$cat = 0; $i1 = 0; $i2 = 0; $desig = 0; $emp = 0; $cycle = 0; $cycle2 = 0; $appr = 0;
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    // A non-admin creator who has create+submit but whose approve is exercised against SoD
    $CREATOR = ['user_id' => 999920, 'username' => 'creator', 'is_admin' => false, 'role_id' => 991,
        'permissions' => ['hr_performance' => ['view'=>true,'create'=>true,'edit'=>true,'delete'=>true,'submit'=>true,'approve'=>true,'reject'=>true]],
        'scope' => ['is_admin'=>false,'projects'=>['*'],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];
    $NOPERM = ['user_id' => 999921, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];

    $desig = (int)$pdo->query("SELECT designation_id FROM designations WHERE status='active' LIMIT 1")->fetchColumn();
    // indicators + targets
    $pdo->exec("INSERT INTO performance_indicator_categories (category_name, sort_order) VALUES ('__AP Cat', 5)");
    $cat = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO performance_indicators (category_id, indicator_name) VALUES ($cat, '__AP I1')"); $i1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO performance_indicators (category_id, indicator_name) VALUES ($cat, '__AP I2')"); $i2 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO designation_indicator_targets (designation_id, indicator_id, expected_rating) VALUES ($desig, $i1, 4)");
    $pdo->exec("INSERT INTO designation_indicator_targets (designation_id, indicator_id, expected_rating) VALUES ($desig, $i2, 3)");
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,designation_id,created_at) VALUES ('__AP','Emp','__AP-E1','active',$desig,NOW())");
    $emp = (int)$pdo->lastInsertId();
    ok($desig && $i1 && $i2 && $emp, "fixtures ready (desig #$desig, inds $i1/$i2, emp #$emp)");

    // ── 1. Cycle CRUD ────────────────────────────────────────────────────────
    $r = call('manage_appraisal_cycles', ['action'=>'add','cycle_name'=>'__AP Cycle','period_from'=>'2026-01-01','period_to'=>'2026-12-31'], $ADMIN);
    $cycle = (int)($r['cycle_id'] ?? 0);
    ok(!empty($r['success']) && $cycle, "cycle created");
    $r = call('manage_appraisal_cycles', ['action'=>'add','cycle_name'=>'__AP Cycle','period_from'=>'2026-01-01','period_to'=>'2026-12-31'], $ADMIN);
    ok(empty($r['success']), "duplicate cycle name rejected");
    $r = call('manage_appraisal_cycles', ['action'=>'add','cycle_name'=>'__AP Closed','period_from'=>'2025-01-01','period_to'=>'2025-12-31'], $ADMIN);
    $cycle2 = (int)($r['cycle_id'] ?? 0);
    call('manage_appraisal_cycles', ['action'=>'close','cycle_id'=>$cycle2], $ADMIN);
    ok($pdo->query("SELECT status FROM appraisal_cycles WHERE cycle_id=$cycle2")->fetchColumn() === 'closed', "second cycle closed");

    // ── 2. Create appraisal (draft) with snapshots ───────────────────────────
    $r = call('add_appraisal', ['cycle_id'=>$cycle,'employee_id'=>$emp,'appraisal_date'=>'2026-06-01',
        'remarks'=>'good','rating'=>[$i1=>5,$i2=>3],'comment'=>[$i1=>'excellent'],'mode'=>'draft'], $CREATOR);
    $appr = (int)($r['appraisal_id'] ?? 0);
    ok(!empty($r['success']) && $appr, "appraisal created as draft by creator");
    $snap = $pdo->query("SELECT expected_rating, actual_rating FROM employee_appraisal_items WHERE appraisal_id=$appr AND indicator_id=$i1")->fetch(PDO::FETCH_ASSOC);
    ok((int)$snap['expected_rating'] === 4 && (int)$snap['actual_rating'] === 5, "D19: expected_rating snapshotted (4) + actual stored (5)");
    $dsnap = $pdo->query("SELECT designation_id FROM employee_appraisals WHERE appraisal_id=$appr")->fetchColumn();
    ok((int)$dsnap === $desig, "designation snapshotted on the appraisal");

    // D19 proof: change the target now — the snapshot must NOT move
    $pdo->exec("UPDATE designation_indicator_targets SET expected_rating=1 WHERE designation_id=$desig AND indicator_id=$i1");
    $snap2 = $pdo->query("SELECT expected_rating FROM employee_appraisal_items WHERE appraisal_id=$appr AND indicator_id=$i1")->fetchColumn();
    ok((int)$snap2 === 4, "D19: later target change does not rewrite the appraisal's snapshot");

    // one appraisal per employee per cycle
    $r = call('add_appraisal', ['cycle_id'=>$cycle,'employee_id'=>$emp,'rating'=>[$i1=>2],'mode'=>'draft'], $CREATOR);
    ok(empty($r['success']) && stripos($r['message'],'already exists')!==false, "one appraisal per employee per cycle enforced");

    // closed cycle blocks new appraisals
    $r = call('add_appraisal', ['cycle_id'=>$cycle2,'employee_id'=>$emp,'rating'=>[$i1=>3],'mode'=>'draft'], $CREATOR);
    ok(empty($r['success']) && stripos($r['message'],'closed')!==false, "closed cycle blocks new appraisals");

    // permission denial
    $r = call('add_appraisal', ['cycle_id'=>$cycle,'employee_id'=>$emp,'rating'=>[$i1=>3],'mode'=>'draft'], $NOPERM);
    ok(empty($r['success']), "create denied without canCreate('hr_performance')");

    // ── 3. Workflow: submit → approve, SoD ───────────────────────────────────
    $r = call('change_appraisal_status', ['appraisal_id'=>$appr,'action'=>'submit'], $CREATOR);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM employee_appraisals WHERE appraisal_id=$appr")->fetchColumn()==='submitted', "draft → submitted");

    // creator cannot approve their own (non-admin)
    $r = call('change_appraisal_status', ['appraisal_id'=>$appr,'action'=>'approve'], $CREATOR);
    ok(empty($r['success']) && stripos($r['message'],'cannot approve')!==false, "SoD: creator cannot approve own appraisal");

    // admin approves → overall stored = AVG(5,3)=4.00
    $r = call('change_appraisal_status', ['appraisal_id'=>$appr,'action'=>'approve'], $ADMIN);
    ok(!empty($r['success']), "admin approves the appraisal");
    $ov = $pdo->query("SELECT overall_rating, status FROM employee_appraisals WHERE appraisal_id=$appr")->fetch(PDO::FETCH_ASSOC);
    ok($ov['status']==='approved' && abs((float)$ov['overall_rating'] - 4.00) < 0.001, "D17: overall_rating = AVG(actual) = 4.00 stored on approval");

    // terminal: can't re-submit/re-approve
    $r = call('change_appraisal_status', ['appraisal_id'=>$appr,'action'=>'submit'], $ADMIN);
    ok(empty($r['success']), "approved is terminal (cannot submit again)");

    // ── 4. get_appraisal / get_appraisals ────────────────────────────────────
    $r = call('get_appraisal', ['appraisal_id'=>$appr], $ADMIN, 'GET');
    ok(!empty($r['success']) && count($r['items'])===2 && $r['data']['status']==='approved', "get_appraisal returns the scorecard (2 items)");
    $r = call('get_appraisals', ['cycle_id'=>$cycle], $ADMIN, 'GET');
    ok(!empty($r['success']) && $r['stats']['approved']>=1 && $r['stats']['avg']!==null, "get_appraisals stat cards compute approved + avg");

    // ── 5. Reject path (fresh appraisal) ─────────────────────────────────────
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,designation_id,created_at) VALUES ('__AP','Emp2','__AP-E2','active',$desig,NOW())");
    $emp2 = (int)$pdo->lastInsertId();
    $r = call('add_appraisal', ['cycle_id'=>$cycle,'employee_id'=>$emp2,'rating'=>[$i1=>2,$i2=>2],'mode'=>'submit'], $CREATOR);
    $appr2 = (int)($r['appraisal_id'] ?? 0);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM employee_appraisals WHERE appraisal_id=$appr2")->fetchColumn()==='submitted', "second appraisal submitted directly");
    $r = call('change_appraisal_status', ['appraisal_id'=>$appr2,'action'=>'reject'], $ADMIN);
    ok(empty($r['success']), "reject requires a reason");
    $r = call('change_appraisal_status', ['appraisal_id'=>$appr2,'action'=>'reject','reject_reason'=>'insufficient evidence'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM employee_appraisals WHERE appraisal_id=$appr2")->fetchColumn()==='rejected', "reject with reason works");
    $pdo->exec("DELETE FROM employee_appraisal_items WHERE appraisal_id=$appr2");
    $pdo->exec("DELETE FROM employee_appraisals WHERE appraisal_id=$appr2");
    $pdo->exec("DELETE FROM employees WHERE employee_id=$emp2");

    // ── 6. Renders ───────────────────────────────────────────────────────────
    $html = render('hr_performance', $admin_uid);
    ok(noErr($html), "hr_performance.php renders without errors");
    ok(strpos($html,'Appraisals')!==false && strpos($html,'New Appraisal')!==false, "Appraisals tab + New Appraisal present");
    $html = render('details', $admin_uid, $emp);
    ok(noErr($html), "employee_details.php renders with a Performance card");
    ok(strpos($html,'Performance')!==false && strpos($html,'/5')!==false, "Performance card shows the approved overall rating");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($appr) { $pdo->exec("DELETE FROM employee_appraisal_items WHERE appraisal_id=$appr"); $pdo->exec("DELETE FROM employee_appraisals WHERE appraisal_id=$appr"); }
    if ($emp) $pdo->exec("DELETE FROM employee_appraisals WHERE employee_id=$emp");
    if ($cycle) $pdo->exec("DELETE FROM appraisal_cycles WHERE cycle_id IN ($cycle,$cycle2)");
    if ($desig) $pdo->exec("DELETE FROM designation_indicator_targets WHERE indicator_id IN ($i1,$i2)");
    if ($i1) $pdo->exec("DELETE FROM performance_indicators WHERE indicator_id IN ($i1,$i2)");
    if ($cat) $pdo->exec("DELETE FROM performance_indicator_categories WHERE category_id=$cat");
    if ($emp) $pdo->exec("DELETE FROM employees WHERE employee_id=$emp");
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
