<?php
/**
 * Zoom Integration — Phase 6 (notifications) CLI test.
 *   php tests/test_zoom_notifications_cli.php
 *
 * Verifies api/manage_meeting.php's notifyMeetingAttendees():
 *   - a Zoom meeting's attendees get a notification containing the Join link
 *   - a no-op re-save does not duplicate the notification (existing dedupe key)
 *   - a user who has muted the 'hr_meeting' event (now registered in
 *     notification_events, plan Phase 6) gets no notification at all
 * Same subprocess-worker + mocked-HTTP pattern as test_zoom_meeting_sync_cli.php.
 * Zoom settings are saved/restored around the run. Exit 0 = pass.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";

    $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) {
        if (strpos($url, 'oauth/token') !== false) return ['ok'=>true,'json'=>['access_token'=>'tok-test','expires_in'=>3600],'http_code'=>200,'error'=>null];
        if ($method === 'POST') return ['ok'=>true,'json'=>['id'=>445566778,'join_url'=>'https://zoom.us/j/445566778','start_url'=>'https://zoom.us/s/445566778','password'=>'nt12ab'],'http_code'=>201,'error'=>null];
        if ($method === 'PATCH') return ['ok'=>true,'json'=>[],'http_code'=>204,'error'=>null];
        return ['ok'=>true,'json'=>[],'http_code'=>204,'error'=>null];
    };

    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/api/{$cfg['endpoint']}.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function call($ep, $payload, $session) {
    global $root;
    $cfg = ['session' => $session, 'method' => 'POST', 'post' => $payload, 'endpoint' => $ep];
    $f = tempnam(sys_get_temp_dir(), 'znt'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}

$emp = 0; $attUser = 0; $m1 = 0; $m2 = 0; $origSettings = [];
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];

    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__ZNT','Attendee','__ZNT-E1','active',NOW())");
    $emp = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (username,password,email,is_admin,role_id,employee_id,first_name,last_name,is_active,created_at) VALUES ('__znt_user','x','znt@example.local',0,4,?,'ZNT','Attendee',1,NOW())")->execute([$emp]);
    $attUser = (int)$pdo->lastInsertId();
    ok($emp > 0 && $attUser > 0, "fixture attendee employee (#$emp) + linked user (#$attUser) ready");

    $keys = ['zoom_enabled','zoom_account_id','zoom_client_id','zoom_client_secret_enc'];
    foreach ($keys as $k) $origSettings[$k] = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='$k'")->fetchColumn();
    require_once "$root/core/crypto.php";
    $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute(['1','zoom_enabled']);
    $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute(['acct-test','zoom_account_id']);
    $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute(['client-test','zoom_client_id']);
    $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([encryptSecret('s3cr3t'),'zoom_client_secret_enc']);

    section('1. notification_events registration (Phase 6)');
    $ev = $pdo->query("SELECT is_active, page_key, required_verb FROM notification_events WHERE event_key='hr_meeting'")->fetch(PDO::FETCH_ASSOC);
    ok($ev && (int)$ev['is_active']===1, "'hr_meeting' is registered in notification_events and active");

    section('2. Create — attendee gets a notification with the Join link');
    $r = call('manage_meeting', ['action'=>'add','title'=>'ZNT Sync Call','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'meeting_type'=>'zoom','host_user_id'=>$admin_uid,'attendees'=>[$emp]], $ADMIN);
    $m1 = (int)($r['meeting_id'] ?? 0);
    ok(!empty($r['success']) && $m1, 'zoom meeting created with an attendee');
    $notifs = $pdo->query("SELECT * FROM notifications WHERE user_id=$attUser AND title LIKE 'Meeting scheduled: ZNT Sync Call%'")->fetchAll(PDO::FETCH_ASSOC);
    ok(count($notifs)===1, 'exactly one notification created for the attendee');
    ok(!empty($notifs) && strpos($notifs[0]['message'],'https://zoom.us/j/445566778')!==false, 'notification message includes the Zoom join link');
    ok(!empty($notifs) && $notifs[0]['event_key']==='hr_meeting', "notification tagged with event_key='hr_meeting'");

    section('3. No-op re-save — dedupe prevents a duplicate notification');
    $r = call('manage_meeting', ['action'=>'update','meeting_id'=>$m1,'title'=>'ZNT Sync Call','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'meeting_type'=>'zoom','host_user_id'=>$admin_uid,'attendees'=>[$emp]], $ADMIN);
    ok(!empty($r['success']), 're-save succeeds');
    $count2 = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=$attUser AND title LIKE 'Meeting scheduled: ZNT Sync Call%'")->fetchColumn();
    ok($count2===1, 'still exactly one notification after a no-op re-save (existing dedupe mechanism)');

    section('4. Muted attendee — no notification at all');
    $pdo->prepare("UPDATE users SET notification_preferences=? WHERE user_id=?")->execute([json_encode(['muted_events'=>['hr_meeting']]), $attUser]);
    $r = call('manage_meeting', ['action'=>'add','title'=>'ZNT Muted Call','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'meeting_type'=>'zoom','host_user_id'=>$admin_uid,'attendees'=>[$emp]], $ADMIN);
    $m2 = (int)($r['meeting_id'] ?? 0);
    ok(!empty($r['success']) && $m2, 'second zoom meeting created');
    $count3 = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=$attUser AND title LIKE 'Meeting scheduled: ZNT Muted Call%'")->fetchColumn();
    ok($count3===0, 'muted attendee receives no notification for the new meeting');

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    $pdo->exec("DELETE FROM notifications WHERE user_id=" . (int)$attUser);
    foreach ([$m1,$m2] as $mid) if ($mid) { $pdo->exec("DELETE FROM meeting_attendees WHERE meeting_id=$mid"); $pdo->exec("DELETE FROM meetings WHERE meeting_id=$mid"); }
    if ($attUser) $pdo->exec("DELETE FROM users WHERE user_id=$attUser");
    if ($emp) { $pdo->exec("DELETE FROM meeting_attendees WHERE employee_id=$emp"); $pdo->exec("DELETE FROM employees WHERE employee_id=$emp"); }
    $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE 'meeting_%'");
    foreach ($origSettings as $k => $v) $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([$v, $k]);
    echo "  (fixtures + zoom settings restored)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
