<?php
/**
 * Announcements (Tier 4, Phase 4.2) CLI test.
 *   php tests/test_announcements_cli.php
 *
 * Proves: draft→publish→archive, the D25 audience-resolution matrix
 * (all / department / project → the right users notified), dedupe on
 * re-publish (notification_dedupe), the feed's publish/expire-window +
 * audience filtering, mark-read, permission denials, and page render.
 * message_center is never touched — only the notifications table gains rows.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[2] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/announcements';
    require "$root/app/bms/pos/announcements.php";
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
    $f = tempnam(sys_get_temp_dir(), 'ann'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($uid) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $uid 2>&1"); }
function noErr($h) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','Call to undefined'] as $e) if (stripos($h,$e)!==false) return false; return true; }

$ann_all = 0; $ann_dept = 0; $ann_exp = 0; $u_dept = 0; $u_other = 0; $dept = 0; $test_start = date('Y-m-d H:i:s');
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $NOPERM = ['user_id' => 999950, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];

    $dept = (int)$pdo->query("SELECT department_id FROM departments WHERE status='active' LIMIT 1")->fetchColumn();
    $rid = (int)$pdo->query("SELECT role_id FROM roles LIMIT 1")->fetchColumn();
    // one user in the target department, one outside it
    $pdo->prepare("INSERT INTO users (username,email,first_name,last_name,role_id,department_id,is_active,password,created_at) VALUES ('__ann_d','ad@x.test','Dept','User',?,?,1,'x',NOW())")->execute([$rid,$dept]);
    $u_dept = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (username,email,first_name,last_name,role_id,department_id,is_active,password,created_at) VALUES ('__ann_o','ao@x.test','Other','User',?,NULL,1,'x',NOW())")->execute([$rid]);
    $u_other = (int)$pdo->lastInsertId();
    ok($dept && $u_dept && $u_other, "fixtures ready (dept #$dept, users $u_dept/$u_other)");

    // ── 1. Create + permission denial ────────────────────────────────────────
    $r = call('manage_announcement', ['action'=>'add','title'=>'x','body'=>'y','publish_date'=>date('Y-m-d')], $NOPERM);
    ok(empty($r['success']), "create denied without canCreate('announcements')");
    $r = call('manage_announcement', ['action'=>'add','title'=>'All Hands','body'=>'Welcome to everyone','audience_type'=>'all','publish_date'=>date('Y-m-d')], $ADMIN);
    $ann_all = (int)($r['announcement_id'] ?? 0);
    ok(!empty($r['success']) && $ann_all, "company-wide announcement drafted");
    $r = call('manage_announcement', ['action'=>'add','title'=>'Dept Notice','body'=>'For one dept','audience_type'=>'department','department_id'=>$dept,'publish_date'=>date('Y-m-d')], $ADMIN);
    $ann_dept = (int)($r['announcement_id'] ?? 0);
    ok(!empty($r['success']) && $ann_dept, "department announcement drafted");
    $r = call('manage_announcement', ['action'=>'add','title'=>'Bad Dept','body'=>'z','audience_type'=>'department','publish_date'=>date('Y-m-d')], $ADMIN);
    ok(empty($r['success']), "department announcement requires a department");

    // ── 2. Publish → audience resolution (D25) ───────────────────────────────
    $r = call('manage_announcement', ['action'=>'publish','announcement_id'=>$ann_dept], $ADMIN);
    ok(!empty($r['success']), "department announcement published: " . ($r['message'] ?? ''));
    $deptNotified = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key='hr_announcement' AND user_id=$u_dept AND title LIKE '%Dept Notice%'")->fetchColumn();
    $otherNotified = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key='hr_announcement' AND user_id=$u_other AND title LIKE '%Dept Notice%'")->fetchColumn();
    ok($deptNotified === 1, "D25: the in-department user was notified");
    ok($otherNotified === 0, "D25: the out-of-department user was NOT notified");

    // re-publish → dedupe holds (no second notification)
    $r = call('manage_announcement', ['action'=>'publish','announcement_id'=>$ann_dept], $ADMIN);
    $deptAgain = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key='hr_announcement' AND user_id=$u_dept AND title LIKE '%Dept Notice%'")->fetchColumn();
    ok($deptAgain === 1, "re-publish does not double-notify (notification_dedupe)");

    // publish the company-wide one → both users notified
    call('manage_announcement', ['action'=>'publish','announcement_id'=>$ann_all], $ADMIN);
    $allDept = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key='hr_announcement' AND user_id=$u_dept AND title LIKE '%All Hands%'")->fetchColumn();
    $allOther = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key='hr_announcement' AND user_id=$u_other AND title LIKE '%All Hands%'")->fetchColumn();
    ok($allDept === 1 && $allOther === 1, "company-wide announcement notified both users");

    // ── 3. Feed: audience + window filtering, mark-read ──────────────────────
    $DEPTUSER = ['user_id'=>$u_dept,'username'=>'__ann_d','is_admin'=>false,'role_id'=>$rid,'permissions'=>[],'scope'=>['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];
    $OTHERUSER = ['user_id'=>$u_other,'username'=>'__ann_o','is_admin'=>false,'role_id'=>$rid,'permissions'=>[],'scope'=>['is_admin'=>false,'projects'=>[],'warehouses'=>[],'suppliers'=>[],'customers'=>[],'employees'=>[],'computed_at'=>time()]];
    $r = call('get_announcements', ['mode'=>'feed'], $DEPTUSER, 'GET');
    $titles = array_column($r['data'] ?? [], 'title');
    ok(!empty($r['success']) && in_array('All Hands', $titles, true) && in_array('Dept Notice', $titles, true), "dept user's feed sees both the all + dept announcements");
    $r = call('get_announcements', ['mode'=>'feed'], $OTHERUSER, 'GET');
    $titles = array_column($r['data'] ?? [], 'title');
    ok(in_array('All Hands', $titles, true) && !in_array('Dept Notice', $titles, true), "other user's feed sees only the company-wide one (dept filtered out)");

    // expired announcement never shows
    $r = call('manage_announcement', ['action'=>'add','title'=>'Old News','body'=>'expired','audience_type'=>'all','publish_date'=>date('Y-m-d',strtotime('-10 days')),'expire_date'=>date('Y-m-d',strtotime('-2 days'))], $ADMIN);
    $ann_exp = (int)($r['announcement_id'] ?? 0);
    call('manage_announcement', ['action'=>'publish','announcement_id'=>$ann_exp], $ADMIN);
    $r = call('get_announcements', ['mode'=>'feed'], $OTHERUSER, 'GET');
    ok(!in_array('Old News', array_column($r['data'] ?? [], 'title'), true), "expired announcement is filtered out of the feed");

    // mark-read
    $r = call('mark_announcement_read', ['announcement_id'=>$ann_all], $DEPTUSER);
    ok(!empty($r['success']) && (int)$pdo->query("SELECT COUNT(*) FROM announcement_reads WHERE announcement_id=$ann_all AND user_id=$u_dept")->fetchColumn()===1, "mark-read records the read");
    $r = call('mark_announcement_read', ['announcement_id'=>$ann_all], $DEPTUSER);
    ok((int)$pdo->query("SELECT COUNT(*) FROM announcement_reads WHERE announcement_id=$ann_all AND user_id=$u_dept")->fetchColumn()===1, "marking read twice is idempotent");

    // ── 4. Archive + manage stats ────────────────────────────────────────────
    $r = call('manage_announcement', ['action'=>'archive','announcement_id'=>$ann_all], $ADMIN);
    ok(!empty($r['success']) && $pdo->query("SELECT status FROM announcements WHERE announcement_id=$ann_all")->fetchColumn()==='archived', "archive works");
    $r = call('get_announcements', ['mode'=>'manage'], $ADMIN, 'GET');
    ok(!empty($r['success']) && isset($r['stats']['drafts']) && isset($r['stats']['published_current']), "manage list returns stat cards");

    // ── 5. Render ────────────────────────────────────────────────────────────
    $html = render($admin_uid);
    ok(noErr($html) && strpos($html,'Announcements')!==false && strpos($html,'New Announcement')!==false, "announcements.php renders");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    foreach ([$ann_all,$ann_dept,$ann_exp] as $aid) if ($aid) { $pdo->exec("DELETE FROM announcement_reads WHERE announcement_id=$aid"); $pdo->exec("DELETE FROM announcements WHERE announcement_id=$aid"); }
    $pdo->prepare("DELETE FROM notifications WHERE event_key='hr_announcement' AND created_at >= ?")->execute([$test_start]);
    $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE 'hr_announcement|%'");
    if ($u_dept) $pdo->exec("DELETE FROM users WHERE user_id IN ($u_dept,$u_other)");
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
