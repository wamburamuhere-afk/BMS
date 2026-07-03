<?php
/**
 * Employee Self-Service "My HR" (Tier 4, Phase 4.6 — D24) CLI test.
 *   php tests/test_my_hr_cli.php
 *
 * Proves the D24 security linchpin: the employee is resolved from the SESSION
 * link ONLY. An unlinked user gets 403/not_linked; a linked user sees ONLY
 * their own rows; a forged employee_id parameter is IGNORED (the API has no
 * such input); an ESS leave application lands in the SAME leaves table/workflow
 * the admin module uses, tagged to the session employee; and the page renders
 * for both linked and unlinked users without ever erroring.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)$argv[2]; $_SESSION['username'] = 'u'; $_SESSION['role_id'] = (int)($argv[3] ?? 1);
    $_SESSION['is_admin'] = ((int)($argv[3] ?? 1) === 1);
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/my_hr';
    require_once "$root/roots.php";
    if (!$_SESSION['is_admin']) loadUserPermissions((int)$_SESSION['role_id']);
    require "$root/app/bms/pos/my_hr.php";
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
    $f = tempnam(sys_get_temp_dir(), 'myh'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($uid, $rid) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $uid $rid 2>&1"); }
function noErr($h) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','Call to undefined'] as $e) if (stripos($h,$e)!==false) return false; return true; }

$emp = 0; $emp_other = 0; $u_linked = 0; $u_unlinked = 0; $leave_id = 0; $rid = 0;
try {
    // A non-admin role that has my_hr view (every role does — D24 seed)
    $rid = (int)$pdo->query("SELECT r.role_id FROM roles r WHERE r.is_admin = 0 LIMIT 1")->fetchColumn();

    // two employees; one linked to a user, one not
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__MH','Me','__MH-E1','active',NOW())");
    $emp = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__MH','Other','__MH-E2','active',NOW())");
    $emp_other = (int)$pdo->lastInsertId();
    // the OTHER employee has a leave row that must NEVER leak to our user
    $pdo->exec("INSERT INTO leaves (employee_id, leave_type, start_date, end_date, total_days, days_count, reason, status, created_by, applied_by, created_at)
                VALUES ($emp_other,'annual',CURDATE(),CURDATE(),1,1,'secret','pending',0,0,NOW())");

    $pdo->prepare("INSERT INTO users (username,email,first_name,last_name,role_id,employee_id,is_active,password,created_at) VALUES ('__mh_linked','mhl@x.test','L','U',?,?,1,'x',NOW())")->execute([$rid,$emp]);
    $u_linked = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (username,email,first_name,last_name,role_id,employee_id,is_active,password,created_at) VALUES ('__mh_unlinked','mhu@x.test','U','U',?,NULL,1,'x',NOW())")->execute([$rid]);
    $u_unlinked = (int)$pdo->lastInsertId();
    ok($emp && $emp_other && $u_linked && $u_unlinked, "fixtures ready (linked user #$u_linked → emp #$emp; unlinked user #$u_unlinked)");

    $LINKED = ['user_id'=>$u_linked,'username'=>'__mh_linked','is_admin'=>false,'role_id'=>$rid,
        'permissions'=>['my_hr'=>['view'=>true,'create'=>false,'edit'=>false,'delete'=>false]],
        'scope'=>['is_admin'=>false,'projects'=>['*'],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];
    $UNLINKED = $LINKED; $UNLINKED['user_id']=$u_unlinked; $UNLINKED['username']='__mh_unlinked';

    // ── 1. Unlinked user is blocked (D24) ───────────────────────────────────
    $r = call('my_hr_data', ['section'=>'profile'], $UNLINKED, 'GET');
    ok(empty($r['success']) && (($r['message'] ?? '') === 'not_linked'), "unlinked user gets 'not_linked' (no data)");

    // ── 2. Linked user sees own profile ─────────────────────────────────────
    $r = call('my_hr_data', ['section'=>'profile'], $LINKED, 'GET');
    ok(!empty($r['success']) && (int)$r['data']['employee_id'] === $emp, "linked user sees their OWN profile");

    // ── 3. Forged employee_id is ignored (no such input, D24) ───────────────
    $r = call('my_hr_data', ['section'=>'profile','employee_id'=>$emp_other], $LINKED, 'GET');
    ok(!empty($r['success']) && (int)$r['data']['employee_id'] === $emp, "a forged employee_id param is IGNORED — still returns only the session employee");

    // ── 4. Leave section shows only own rows ────────────────────────────────
    $r = call('my_hr_data', ['section'=>'leave'], $LINKED, 'GET');
    $reasons = array_column($r['history'] ?? [], 'reason');
    ok(!empty($r['success']) && !in_array('secret', $reasons, true), "leave history contains ONLY the user's own rows (no other employee's leave)");

    // ── 5. ESS leave application lands in the existing workflow ──────────────
    $r = call('my_leave_apply', ['leave_type'=>'Annual Leave','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+2 days')),'reason'=>'family event'], $LINKED);
    $leave_id = (int)($r['leave_id'] ?? 0);
    ok(!empty($r['success']) && $leave_id, "ESS leave application submitted");
    $row = $pdo->query("SELECT employee_id, status, leave_type FROM leaves WHERE leave_id=$leave_id")->fetch(PDO::FETCH_ASSOC);
    ok($row && (int)$row['employee_id'] === $emp && $row['status'] === 'pending', "the application landed in the SAME leaves table, employee_id forced from session, status pending (visible to admin approval)");

    // forging employee_id in the apply payload must NOT retarget it
    $r = call('my_leave_apply', ['employee_id'=>$emp_other,'leave_type'=>'Sick Leave','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d'),'reason'=>'x'], $LINKED);
    $forged_id = (int)($r['leave_id'] ?? 0);
    if ($forged_id) {
        $feid = (int)$pdo->query("SELECT employee_id FROM leaves WHERE leave_id=$forged_id")->fetchColumn();
        ok($feid === $emp, "forged employee_id in the apply payload is ignored — leave still tagged to the session employee");
        $pdo->exec("DELETE FROM leaves WHERE leave_id=$forged_id");
    } else { ok(false, "second apply failed unexpectedly: " . ($r['message'] ?? '')); }

    // unlinked user cannot apply
    $r = call('my_leave_apply', ['leave_type'=>'Annual Leave','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d'),'reason'=>'x'], $UNLINKED);
    ok(empty($r['success']), "unlinked user cannot apply for leave");

    // ── 6. Renders ───────────────────────────────────────────────────────────
    $html = render($u_linked, $rid);
    ok(noErr($html) && strpos($html,'My HR')!==false && strpos($html,'Payslips')!==false, "my_hr.php renders the tabs for a linked user");
    $html = render($u_unlinked, $rid);
    ok(noErr($html) && stripos($html,"isn't linked")!==false, "my_hr.php shows the friendly not-linked notice (never errors)");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($leave_id) $pdo->exec("DELETE FROM leaves WHERE leave_id=$leave_id");
    if ($emp) $pdo->exec("DELETE FROM leaves WHERE employee_id IN ($emp,$emp_other)");
    if ($u_linked) $pdo->exec("DELETE FROM users WHERE user_id IN ($u_linked,$u_unlinked)");
    if ($emp) $pdo->exec("DELETE FROM employees WHERE employee_id IN ($emp,$emp_other)");
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
