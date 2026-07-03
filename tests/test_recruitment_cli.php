<?php
/**
 * Recruitment / internal ATS (Tier 4, Phase 4.5) CLI test.
 *   php tests/test_recruitment_cli.php
 *
 * Proves: opening CRUD + status; candidate add + CV validation; the stage map
 * (forward one-at-a-time, no backward/skip, rejected-from-any, terminal states);
 * hire requires an open opening; the D28a hired_employee_id linkage; interview
 * schedule + rating record; CV gatekeeper + permission denials; page render.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[2] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/recruitment';
    require "$root/app/bms/pos/recruitment.php";
    exit;
}
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[3]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? []; $_FILES = $cfg['files'] ?? [];
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
    $f = tempnam(sys_get_temp_dir(), 'rec'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($uid) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $uid 2>&1"); }
function noErr($h) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','Call to undefined'] as $e) if (stripos($h,$e)!==false) return false; return true; }

$opening = 0; $opening2 = 0; $cand = 0; $emp = 0;
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $NOPERM = ['user_id' => 999980, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];

    // ── 1. Opening CRUD ─────────────────────────────────────────────────────
    $r = call('manage_opening', ['action'=>'add','job_title'=>'__REC Accountant','openings_count'=>2], $ADMIN);
    $opening = (int)($r['opening_id'] ?? 0);
    ok(!empty($r['success']) && $opening, "opening created");
    $r = call('manage_opening', ['action'=>'add','job_title'=>'x'], $NOPERM);
    ok(empty($r['success']), "opening create denied without canCreate('recruitment')");
    $r = call('manage_opening', ['action'=>'add','job_title'=>'__REC Closed one'], $ADMIN);
    $opening2 = (int)($r['opening_id'] ?? 0);
    call('manage_opening', ['action'=>'change_status','opening_id'=>$opening2,'status'=>'closed'], $ADMIN);
    ok($pdo->query("SELECT status FROM job_openings WHERE opening_id=$opening2")->fetchColumn()==='closed', "opening closed");

    // ── 2. Candidate add + CV validation ────────────────────────────────────
    $r = call('manage_candidate', ['action'=>'add','opening_id'=>$opening,'full_name'=>'Jane Doe','email'=>'jane@x.test','phone'=>'0700'], $ADMIN);
    $cand = (int)($r['candidate_id'] ?? 0);
    ok(!empty($r['success']) && $cand, "candidate added (stage applied)");
    ok($pdo->query("SELECT stage FROM candidates WHERE candidate_id=$cand")->fetchColumn()==='applied', "new candidate starts at 'applied'");
    $r = call('manage_candidate', ['action'=>'add','opening_id'=>$opening,'full_name'=>'Bad','email'=>'not-an-email'], $ADMIN);
    ok(empty($r['success']), "rejects invalid email");
    // bad CV extension
    $tmp = tempnam(sys_get_temp_dir(),'cv'); file_put_contents($tmp,'MZ');
    $r = call('manage_candidate', ['action'=>'add','opening_id'=>$opening,'full_name'=>'Exe Guy'], $ADMIN, 'POST');
    // (file passed via a manual FILES payload)
    $cfg = ['session'=>$ADMIN,'method'=>'POST','post'=>['action'=>'add','opening_id'=>$opening,'full_name'=>'Exe Guy'],'files'=>['cv'=>['name'=>'evil.exe','type'=>'application/octet-stream','tmp_name'=>$tmp,'error'=>0,'size'=>2]]];
    $f=tempnam(sys_get_temp_dir(),'rc'); file_put_contents($f,json_encode($cfg));
    $o=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__)." worker manage_candidate ".escapeshellarg($f)); @unlink($f); @unlink($tmp);
    $rr = json_decode(substr((string)$o, strpos((string)$o,'{') ?: 0), true);
    ok(empty($rr['success']), "rejects a .exe CV upload");

    // ── 3. Stage map ─────────────────────────────────────────────────────────
    $r = call('change_candidate_stage', ['candidate_id'=>$cand,'stage'=>'interview','note'=>'skip attempt'], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'],'skip')!==false, "cannot skip stages (applied → interview blocked)");
    $r = call('change_candidate_stage', ['candidate_id'=>$cand,'stage'=>'shortlisted'], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'],'note')!==false, "a stage move requires a note");
    $r = call('change_candidate_stage', ['candidate_id'=>$cand,'stage'=>'shortlisted','note'=>'good CV'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT stage FROM candidates WHERE candidate_id=$cand")->fetchColumn()==='shortlisted', "applied → shortlisted");
    $r = call('change_candidate_stage', ['candidate_id'=>$cand,'stage'=>'applied','note'=>'back'], $ADMIN);
    ok(empty($r['success']), "cannot move backward");
    call('change_candidate_stage', ['candidate_id'=>$cand,'stage'=>'interview','note'=>'sched'], $ADMIN);

    // ── 4. Interviews ────────────────────────────────────────────────────────
    $r = call('manage_interview', ['action'=>'schedule','candidate_id'=>$cand,'interview_date'=>date('Y-m-d'),'interview_time'=>'10:00','interviewers'=>'Panel A'], $ADMIN);
    $iid = (int)($r['interview_id'] ?? 0);
    ok(!empty($r['success']) && $iid, "interview scheduled");
    $r = call('manage_interview', ['action'=>'record','interview_id'=>$iid,'rating'=>4,'feedback'=>'Strong'], $ADMIN);
    ok(!empty($r['success']) && (int)$pdo->query("SELECT rating FROM candidate_interviews WHERE interview_id=$iid")->fetchColumn()===4, "interview rating recorded");
    $r = call('manage_interview', ['action'=>'record','interview_id'=>$iid,'rating'=>9], $ADMIN);
    ok(empty($r['success']), "rating out of 1–5 rejected");

    // ── 5. Hire requires open opening + linkage (D28a) ──────────────────────
    call('change_candidate_stage', ['candidate_id'=>$cand,'stage'=>'offered','note'=>'offer sent'], $ADMIN);
    // temporarily hold the opening → hire blocked
    call('manage_opening', ['action'=>'change_status','opening_id'=>$opening,'status'=>'on_hold'], $ADMIN);
    $r = call('change_candidate_stage', ['candidate_id'=>$cand,'stage'=>'hired','note'=>'hire'], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'],'open')!==false, "hire blocked while the opening is not open");
    call('manage_opening', ['action'=>'change_status','opening_id'=>$opening,'status'=>'open'], $ADMIN);
    $r = call('change_candidate_stage', ['candidate_id'=>$cand,'stage'=>'hired','note'=>'hired!'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT stage FROM candidates WHERE candidate_id=$cand")->fetchColumn()==='hired', "hired once the opening is open");
    // link the created employee
    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('Jane','Doe','__REC-E1','active',NOW())");
    $emp = (int)$pdo->lastInsertId();
    $r = call('change_candidate_stage', ['candidate_id'=>$cand,'action'=>'link_employee','employee_id'=>$emp], $ADMIN);
    ok(!empty($r['success']) && (int)$pdo->query("SELECT hired_employee_id FROM candidates WHERE candidate_id=$cand")->fetchColumn()===$emp, "D28a: hired candidate links to the created employee");
    // terminal
    $r = call('change_candidate_stage', ['candidate_id'=>$cand,'stage'=>'rejected','note'=>'x'], $ADMIN);
    ok(empty($r['success']), "hired is terminal (cannot reject after)");

    // rejected-from-any (fresh candidate)
    $r = call('manage_candidate', ['action'=>'add','opening_id'=>$opening,'full_name'=>'Reject Me'], $ADMIN);
    $cand2 = (int)($r['candidate_id'] ?? 0);
    $r = call('change_candidate_stage', ['candidate_id'=>$cand2,'stage'=>'rejected','note'=>'not a fit'], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT stage FROM candidates WHERE candidate_id=$cand2")->fetchColumn()==='rejected', "rejected allowed straight from 'applied'");
    $pdo->exec("DELETE FROM candidates WHERE candidate_id=$cand2");

    // ── 6. get_candidates / get_openings + render ───────────────────────────
    $r = call('get_openings', [], $ADMIN, 'GET');
    ok(!empty($r['success']) && isset($r['stats']['open_positions']), "get_openings returns stats");
    $r = call('get_candidates', ['opening_id'=>$opening], $ADMIN, 'GET');
    ok(!empty($r['success']) && count($r['data'])>=1, "get_candidates lists candidates for the opening");
    $r = call('get_openings', [], $NOPERM, 'GET');
    ok(empty($r['success']), "get_openings denied without canView('recruitment')");

    $html = render($admin_uid);
    ok(noErr($html) && strpos($html,'Recruitment')!==false && strpos($html,'Candidates')!==false, "recruitment.php renders (Openings + Candidates tabs)");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($cand) { $pdo->exec("DELETE FROM candidate_interviews WHERE candidate_id=$cand"); }
    if ($opening) $pdo->exec("DELETE FROM candidate_interviews WHERE candidate_id IN (SELECT candidate_id FROM candidates WHERE opening_id=$opening)");
    foreach ([$opening,$opening2] as $oid) if ($oid) { $pdo->exec("DELETE FROM candidates WHERE opening_id=$oid"); $pdo->exec("DELETE FROM job_openings WHERE opening_id=$oid"); }
    if ($emp) $pdo->exec("DELETE FROM employees WHERE employee_id=$emp");
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
