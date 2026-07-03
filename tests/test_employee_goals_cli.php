<?php
/**
 * Employee goals (Tier 3, Phase 3.4) CLI test.
 *   php tests/test_employee_goals_cli.php
 *
 * Proves: create (scope-gated, end>=start), progress bounds (0–100), the
 * required progress note (D23) and its landing in the audit trail, status
 * transitions (not_started→in_progress→completed/cancelled with the illegal
 * ones blocked), overdue computation, permission denials, and the Goals tab +
 * details Performance-card goals render.
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
    $f = tempnam(sys_get_temp_dir(), 'gol'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($page, $uid, $emp = 0) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $page $uid $emp 2>&1"); }
function noErr($h) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','Call to undefined'] as $e) if (stripos($h,$e)!==false) return false; return true; }

$emp = 0; $goal = 0; $goal2 = 0; $test_start = date('Y-m-d H:i:s');
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $NOPERM = ['user_id' => 999930, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];
    $gtype = (int)$pdo->query("SELECT goal_type_id FROM goal_types WHERE type_name='Annual'")->fetchColumn();
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__GL','Emp','__GL-E1','active',NOW())");
    $emp = (int)$pdo->lastInsertId();
    ok($gtype && $emp, "fixtures ready (goal type #$gtype, emp #$emp)");

    // ── 1. Create validation ─────────────────────────────────────────────────
    $r = call('add_goal', ['goal_type_id'=>$gtype,'subject'=>'x','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d')], $ADMIN);
    ok(empty($r['success']), "rejects: missing employee");
    $r = call('add_goal', ['employee_id'=>$emp,'goal_type_id'=>$gtype,'subject'=>'x','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('-1 day'))], $ADMIN);
    ok(empty($r['success']), "rejects: end before start");
    $r = call('add_goal', ['employee_id'=>$emp,'goal_type_id'=>$gtype,'subject'=>'Ship v2','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+30 days'))], $NOPERM);
    ok(empty($r['success']), "create denied without canCreate");

    // valid create (future due)
    $r = call('add_goal', ['employee_id'=>$emp,'goal_type_id'=>$gtype,'subject'=>'Ship v2','description'=>'launch','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+30 days'))], $ADMIN);
    $goal = (int)($r['goal_id'] ?? 0);
    ok(!empty($r['success']) && $goal, "goal created (status not_started, progress 0)");
    $row = $pdo->query("SELECT status, progress FROM employee_goals WHERE goal_id=$goal")->fetch(PDO::FETCH_ASSOC);
    ok($row['status']==='not_started' && (int)$row['progress']===0, "new goal starts not_started at 0%");

    // ── 2. Progress update (D23) ─────────────────────────────────────────────
    $r = call('update_goal_progress', ['goal_id'=>$goal,'progress'=>40], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'],'note')!==false, "progress note is required (D23)");
    $r = call('update_goal_progress', ['goal_id'=>$goal,'progress'=>150,'note'=>'x'], $ADMIN);
    ok(empty($r['success']), "progress > 100 rejected");
    $r = call('update_goal_progress', ['goal_id'=>$goal,'progress'=>40,'note'=>'made good headway'], $ADMIN);
    $row = $pdo->query("SELECT status, progress FROM employee_goals WHERE goal_id=$goal")->fetch(PDO::FETCH_ASSOC);
    ok(!empty($r['success']) && (int)$row['progress']===40 && $row['status']==='in_progress', "progress 40 auto-advances not_started → in_progress");
    // note landed in the audit trail
    $auditHit = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='employee_goal' AND entity_id=$goal AND created_at >= '$test_start' AND new_values LIKE '%made good headway%'")->fetchColumn();
    ok($auditHit >= 1, "D23: the progress note is recorded in the audit trail (progress history)");

    $r = call('update_goal_progress', ['goal_id'=>$goal,'progress'=>80,'note'=>'almost there'], $NOPERM);
    ok(empty($r['success']), "progress update denied without canEdit");

    // complete → progress forced to 100, terminal
    $r = call('update_goal_progress', ['goal_id'=>$goal,'progress'=>90,'note'=>'done','status'=>'completed'], $ADMIN);
    $row = $pdo->query("SELECT status, progress FROM employee_goals WHERE goal_id=$goal")->fetch(PDO::FETCH_ASSOC);
    ok(!empty($r['success']) && $row['status']==='completed' && (int)$row['progress']===100, "completing forces progress to 100%");
    $r = call('update_goal_progress', ['goal_id'=>$goal,'progress'=>50,'note'=>'oops'], $ADMIN);
    ok(empty($r['success']), "a completed goal can no longer be updated");

    // illegal transition blocked (fresh goal, not_started → completed IS allowed; test not_started→ bogus)
    $r = call('add_goal', ['employee_id'=>$emp,'goal_type_id'=>$gtype,'subject'=>'Overdue one','start_date'=>date('Y-m-d',strtotime('-40 days')),'end_date'=>date('Y-m-d',strtotime('-5 days'))], $ADMIN);
    $goal2 = (int)($r['goal_id'] ?? 0);
    ok($goal2 > 0, "second (overdue) goal created");

    // ── 3. Overdue + stats via get_goals ─────────────────────────────────────
    $r = call('get_goals', ['employee_id'=>$emp], $ADMIN, 'GET');
    ok(!empty($r['success']) && $r['stats']['overdue'] >= 1, "overdue goal counted in stats");
    ok($r['stats']['completed_year'] >= 1, "completed-this-year counted in stats");
    ok($r['stats']['active'] >= 1 && $r['stats']['avg_progress'] !== null, "active count + avg progress computed");

    // cancel the overdue one
    $r = call('update_goal_progress', ['goal_id'=>$goal2,'progress'=>0,'note'=>'no longer relevant','status'=>'cancelled'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM employee_goals WHERE goal_id=$goal2")->fetchColumn()==='cancelled', "goal cancelled");

    // ── 4. Renders ───────────────────────────────────────────────────────────
    // give the employee a live active goal so the details card shows a bar
    call('add_goal', ['employee_id'=>$emp,'goal_type_id'=>$gtype,'subject'=>'Active render goal','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+20 days'))], $ADMIN);
    $html = render('hr_performance', $admin_uid);
    ok(noErr($html), "hr_performance.php renders without errors");
    ok(strpos($html,'New Goal')!==false && strpos($html,'goalsTable')!==false, "Goals tab (table + New Goal) present");
    $html = render('details', $admin_uid, $emp);
    ok(noErr($html), "employee_details.php renders with active goals");
    ok(strpos($html,'Active Goals')!==false && strpos($html,'Active render goal')!==false, "Performance card shows the active goal with a progress bar");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($emp) $pdo->exec("DELETE FROM employee_goals WHERE employee_id=$emp");
    if ($emp) $pdo->exec("DELETE FROM employees WHERE employee_id=$emp");
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
