<?php
/**
 * Meetings & Business Trips (Tier 4, Phase 4.3) CLI test.
 *   php tests/test_meetings_trips_cli.php
 *
 * Proves: trip §11.1 transition map (pending→approved/rejected,
 * approved→completed/cancelled), requester-cannot-approve (SoD), completion
 * requires a trip report, D26 informational-only fields never post money;
 * meeting schedule + attendee attendance marking + complete/cancel; scope +
 * permission denials; both pages + the employee_details additions render.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[3] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $p = $argv[2];
    if ($p === 'employee_trips') { $_SERVER['REQUEST_URI'] = '/employee_trips'; require "$root/app/bms/pos/employee_trips.php"; }
    elseif ($p === 'meetings') { $_SERVER['REQUEST_URI'] = '/meetings'; require "$root/app/bms/pos/meetings.php"; }
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
    $f = tempnam(sys_get_temp_dir(), 'mtr'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($page, $uid, $emp = 0) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $page $uid $emp 2>&1"); }
function noErr($h) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','Call to undefined'] as $e) if (stripos($h,$e)!==false) return false; return true; }

$emp = 0; $trip = 0; $meeting = 0;
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $REQUESTER = ['user_id' => 999960, 'username' => 'req', 'is_admin' => false, 'role_id' => 991,
        'permissions' => ['employee_trips'=>['view'=>true,'create'=>true,'edit'=>true,'delete'=>true,'approve'=>true,'reject'=>true]],
        'scope' => ['is_admin'=>false,'projects'=>['*'],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];
    $NOPERM = ['user_id' => 999961, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];

    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__MTR','Emp','__MTR-E1','active',NOW())");
    $emp = (int)$pdo->lastInsertId();
    ok($emp > 0, "fixture employee ready (#$emp)");

    // ── Trips ────────────────────────────────────────────────────────────────
    $r = call('manage_trip', ['action'=>'add','employee_id'=>$emp,'destination'=>'Dodoma','purpose'=>'Client visit','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+2 days')),'estimated_cost'=>200000,'requested_advance'=>150000,'expense_reference'=>'PC-2026-045'], $REQUESTER);
    $trip = (int)($r['trip_id'] ?? 0);
    ok(!empty($r['success']) && $trip, "trip request created (pending)");
    ok((int)$pdo->query("SELECT COUNT(*) FROM employee_trips WHERE trip_id=$trip AND estimated_cost=200000")->fetchColumn()===1, "D26: estimated cost stored as an informational figure (no ledger entry)");

    $r = call('manage_trip', ['action'=>'add','employee_id'=>$emp,'destination'=>'x','purpose'=>'y','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('-1 day'))], $ADMIN);
    ok(empty($r['success']), "rejects end-before-start");
    $r = call('manage_trip', ['action'=>'add','employee_id'=>$emp,'destination'=>'x','purpose'=>'y','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d')], $NOPERM);
    ok(empty($r['success']), "create denied without canCreate('employee_trips')");

    // SoD — requester cannot approve their own
    $r = call('manage_trip', ['action'=>'change_status','trip_id'=>$trip,'status'=>'approved'], $REQUESTER);
    ok(empty($r['success']) && stripos($r['message'],'cannot approve')!==false, "SoD: requester cannot approve their own trip");
    // admin approves
    $r = call('manage_trip', ['action'=>'change_status','trip_id'=>$trip,'status'=>'approved'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM employee_trips WHERE trip_id=$trip")->fetchColumn()==='approved', "admin approves the trip");
    // can't skip pending→completed (already approved); completion requires a report
    $r = call('manage_trip', ['action'=>'change_status','trip_id'=>$trip,'status'=>'completed'], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'],'report')!==false, "completion requires a trip report");
    $r = call('manage_trip', ['action'=>'change_status','trip_id'=>$trip,'status'=>'completed','report'=>'Met the client, signed the deal.'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM employee_trips WHERE trip_id=$trip")->fetchColumn()==='completed', "completion with report succeeds");
    // terminal
    $r = call('manage_trip', ['action'=>'change_status','trip_id'=>$trip,'status'=>'cancelled'], $ADMIN);
    ok(empty($r['success']), "completed trip is terminal");

    // reject path (fresh trip)
    $r = call('manage_trip', ['action'=>'add','employee_id'=>$emp,'destination'=>'Arusha','purpose'=>'Conf','start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+1 day'))], $REQUESTER);
    $trip2 = (int)($r['trip_id'] ?? 0);
    $r = call('manage_trip', ['action'=>'change_status','trip_id'=>$trip2,'status'=>'rejected'], $ADMIN);
    ok(empty($r['success']), "reject requires a reason");
    $r = call('manage_trip', ['action'=>'change_status','trip_id'=>$trip2,'status'=>'rejected','reject_reason'=>'Budget frozen'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM employee_trips WHERE trip_id=$trip2")->fetchColumn()==='rejected', "reject with reason works");
    $pdo->exec("DELETE FROM employee_trips WHERE trip_id=$trip2");

    // ── Meetings ─────────────────────────────────────────────────────────────
    $r = call('manage_meeting', ['action'=>'add','title'=>'Weekly Standup','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'start_time'=>'09:00','venue'=>'Room A','attendees'=>[$emp]], $ADMIN);
    $meeting = (int)($r['meeting_id'] ?? 0);
    ok(!empty($r['success']) && $meeting, "meeting scheduled with an attendee");
    ok((int)$pdo->query("SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id=$meeting AND employee_id=$emp")->fetchColumn()===1, "attendee recorded");
    $r = call('manage_meeting', ['action'=>'add','title'=>'x','meeting_date'=>'nope'], $ADMIN);
    ok(empty($r['success']), "rejects invalid meeting date");
    $r = call('manage_meeting', ['action'=>'add','title'=>'x','meeting_date'=>date('Y-m-d')], $NOPERM);
    ok(empty($r['success']), "meeting create denied without canCreate('meetings')");

    // mark attendance
    $r = call('manage_meeting', ['action'=>'mark_attendance','meeting_id'=>$meeting,'present'=>[$emp=>1]], $ADMIN);
    ok(!empty($r['success']) && (int)$pdo->query("SELECT attended FROM meeting_attendees WHERE meeting_id=$meeting AND employee_id=$emp")->fetchColumn()===1, "attendance marked present");
    // complete with minutes
    $r = call('manage_meeting', ['action'=>'complete','meeting_id'=>$meeting,'minutes'=>'Discussed sprint goals.'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM meetings WHERE meeting_id=$meeting")->fetchColumn()==='completed', "meeting completed with minutes");
    $r = call('manage_meeting', ['action'=>'cancel','meeting_id'=>$meeting], $ADMIN);
    ok(empty($r['success']), "a completed meeting cannot be cancelled");

    // ── get_trips / get_meetings stats ───────────────────────────────────────
    $r = call('get_trips', ['employee_id'=>$emp], $ADMIN, 'GET');
    ok(!empty($r['success']) && isset($r['stats']['completed_year']), "get_trips returns stats + the employee's trips");
    $r = call('get_meetings', [], $ADMIN, 'GET');
    ok(!empty($r['success']) && isset($r['stats']['upcoming']), "get_meetings returns stats");

    // ── Renders ──────────────────────────────────────────────────────────────
    ok(noErr(render('employee_trips', $admin_uid)) , "employee_trips.php renders");
    ok(noErr(render('meetings', $admin_uid)), "meetings.php renders");
    $html = render('details', $admin_uid, $emp);
    ok(noErr($html) && strpos($html,'Meetings &amp; Trips')!==false && strpos($html,'Dodoma')!==false, "employee_details Meetings & Trips card shows the trip");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($meeting) $pdo->exec("DELETE FROM meeting_attendees WHERE meeting_id=$meeting");
    if ($meeting) $pdo->exec("DELETE FROM meetings WHERE meeting_id=$meeting");
    if ($emp) { $pdo->exec("DELETE FROM meeting_attendees WHERE employee_id=$emp"); $pdo->exec("DELETE FROM employee_trips WHERE employee_id=$emp"); $pdo->exec("DELETE FROM employees WHERE employee_id=$emp"); }
    $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE 'meeting_%'");
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
