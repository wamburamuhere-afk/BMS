<?php
/**
 * Zoom Integration — Phase 4 (api/manage_meeting.php orchestration) CLI test.
 *   php tests/test_zoom_meeting_sync_cli.php
 *
 * Each meeting action runs in its own subprocess (mirrors tests/test_meetings_trips_cli.php)
 * so core/zoom_service.php's real curl path is never hit — the worker installs
 * $GLOBALS['ZOOM_HTTP_MOCK'] from a simple mode string before requiring
 * api/manage_meeting.php. Verifies:
 *   - create/update populate zoom_meeting_id/join_url/start_url/password + 'synced'
 *   - a Zoom failure never blocks the local save (4.3) — 'failed' + Retry works
 *   - cancel deletes the Zoom-side meeting first (0.4); a delete failure still cancels locally
 *   - switching a meeting from zoom -> in_person removes the now-orphaned Zoom meeting
 *   - guardrails: missing host, Zoom disabled
 * Zoom settings are saved/restored around the run (subprocess isolation means this
 * can't be a rolled-back transaction). Exit 0 = pass.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";

    $mode = $cfg['zoom_mock'] ?? '';
    if ($mode !== '') {
        $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) use ($mode) {
            if (strpos($url, 'oauth/token') !== false) {
                if ($mode === 'token_fail') return ['ok'=>false,'json'=>null,'http_code'=>401,'error'=>'Invalid client_id or client_secret'];
                return ['ok'=>true,'json'=>['access_token'=>'tok-test','expires_in'=>3600],'http_code'=>200,'error'=>null];
            }
            if ($method === 'POST') { // create
                if ($mode === 'create_fail') return ['ok'=>false,'json'=>null,'http_code'=>500,'error'=>'Zoom is having issues, please try again later'];
                return ['ok'=>true,'json'=>['id'=>778899001,'join_url'=>'https://zoom.us/j/778899001','start_url'=>'https://zoom.us/s/778899001','password'=>'zx12ab'],'http_code'=>201,'error'=>null];
            }
            if ($method === 'PATCH') { // update
                if ($mode === 'update_fail') return ['ok'=>false,'json'=>null,'http_code'=>500,'error'=>'Update failed'];
                return ['ok'=>true,'json'=>[],'http_code'=>204,'error'=>null];
            }
            if ($method === 'DELETE') { // delete
                if ($mode === 'delete_fail') return ['ok'=>false,'json'=>null,'http_code'=>500,'error'=>'Delete failed'];
                return ['ok'=>true,'json'=>[],'http_code'=>204,'error'=>null];
            }
            return ['ok'=>false,'json'=>null,'http_code'=>0,'error'=>'unexpected mock call'];
        };
    }

    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/api/{$cfg['endpoint']}.php";
    exit;
}

require_once "$root/roots.php";
require_once "$root/core/crypto.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function call($ep, $payload, $session, $zoomMock = '') {
    global $root;
    $cfg = ['session' => $session, 'method' => 'POST', 'post' => $payload, 'endpoint' => $ep, 'zoom_mock' => $zoomMock];
    $f = tempnam(sys_get_temp_dir(), 'zms'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}

$emp = 0; $m1 = 0; $m2 = 0; $m3 = 0; $m4 = 0;
$origSettings = [];
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.is_admin=1 LIMIT 1")->fetchColumn();
    $hostEmail = (string)$pdo->query("SELECT email FROM users WHERE user_id=$admin_uid")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    ok($admin_uid > 0 && $hostEmail !== '', "fixture admin/host ready (#$admin_uid, $hostEmail)");

    $pdo->exec("INSERT INTO employees (first_name,last_name,employee_number,employment_status,created_at) VALUES ('__ZMS','Emp','__ZMS-E1','active',NOW())");
    $emp = (int)$pdo->lastInsertId();

    section('Setup — enable Zoom with fake credentials');
    $keys = ['zoom_enabled','zoom_account_id','zoom_client_id','zoom_client_secret_enc'];
    foreach ($keys as $k) $origSettings[$k] = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='$k'")->fetchColumn();
    $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute(['1','zoom_enabled']);
    $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute(['acct-test','zoom_account_id']);
    $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute(['client-test','zoom_client_id']);
    $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([encryptSecret('s3cr3t'),'zoom_client_secret_enc']);
    ok(true, 'zoom_* settings switched on for this run (will be restored)');

    section('1. Create — Zoom API succeeds');
    $r = call('manage_meeting', ['action'=>'add','title'=>'Board Sync','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'start_time'=>'09:00','end_time'=>'10:00','meeting_type'=>'zoom','host_user_id'=>$admin_uid,'zoom_waiting_room'=>1,'attendees'=>[$emp]], $ADMIN, 'ok');
    $m1 = (int)($r['meeting_id'] ?? 0);
    ok(!empty($r['success']) && $m1, 'meeting saved successfully');
    ok(stripos($r['message'] ?? '','failed')===false, 'no failure text in response message');
    $row = $pdo->query("SELECT * FROM meetings WHERE meeting_id=$m1")->fetch(PDO::FETCH_ASSOC);
    ok($row['zoom_sync_status']==='synced', "zoom_sync_status='synced'");
    ok($row['zoom_meeting_id']==='778899001', 'zoom_meeting_id stored');
    ok($row['zoom_join_url']==='https://zoom.us/j/778899001', 'zoom_join_url stored');
    ok($row['zoom_start_url']==='https://zoom.us/s/778899001', 'zoom_start_url stored');
    ok($row['zoom_password']==='zx12ab', 'zoom_password stored');

    section('2. Create — Zoom API fails (graceful degradation, plan 4.3)');
    $r = call('manage_meeting', ['action'=>'add','title'=>'Failing Meeting','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'meeting_type'=>'zoom','host_user_id'=>$admin_uid], $ADMIN, 'create_fail');
    $m2 = (int)($r['meeting_id'] ?? 0);
    ok(!empty($r['success']) && $m2, 'local save still succeeds despite Zoom failure');
    ok(stripos($r['message'] ?? '','zoom sync failed')!==false, 'response message surfaces the Zoom failure (never silent)');
    $row2 = $pdo->query("SELECT * FROM meetings WHERE meeting_id=$m2")->fetch(PDO::FETCH_ASSOC);
    ok($row2['zoom_sync_status']==='failed', "zoom_sync_status='failed'");
    ok($row2['zoom_meeting_id']===null, 'no zoom_meeting_id when create failed');

    section('3. Retry — succeeds the second time');
    $r = call('manage_meeting', ['action'=>'retry_zoom','meeting_id'=>$m2], $ADMIN, 'ok');
    ok(!empty($r['success']), 'retry reports success');
    $row2b = $pdo->query("SELECT * FROM meetings WHERE meeting_id=$m2")->fetch(PDO::FETCH_ASSOC);
    ok($row2b['zoom_sync_status']==='synced', "zoom_sync_status flips to 'synced' after a successful retry");
    ok($row2b['zoom_meeting_id']==='778899001', 'zoom_meeting_id populated by the retry');

    section('4. Cancel — deletes Zoom side first; a delete failure still cancels locally');
    $r = call('manage_meeting', ['action'=>'cancel','meeting_id'=>$m1], $ADMIN, 'delete_fail');
    ok(!empty($r['success']), 'cancel still reports success (local cancel never blocked)');
    ok(stripos($r['message'] ?? '','zoom-side deletion failed')!==false, 'cancel response surfaces the Zoom delete failure');
    $row1b = $pdo->query("SELECT status, zoom_sync_status FROM meetings WHERE meeting_id=$m1")->fetch(PDO::FETCH_ASSOC);
    ok($row1b['status']==='cancelled', 'meeting is cancelled locally');
    ok($row1b['zoom_sync_status']==='failed', "zoom_sync_status='failed' after the delete failure");

    section('5. Switch zoom -> in_person on update removes the orphaned Zoom meeting');
    $r = call('manage_meeting', ['action'=>'add','title'=>'Will Switch','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'meeting_type'=>'zoom','host_user_id'=>$admin_uid], $ADMIN, 'ok');
    $m3 = (int)($r['meeting_id'] ?? 0);
    ok(!empty($r['success']) && $m3, 'zoom meeting created for the switch scenario');
    $r = call('manage_meeting', ['action'=>'update','meeting_id'=>$m3,'title'=>'Will Switch','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'meeting_type'=>'in_person','venue'=>'Room B'], $ADMIN, 'ok');
    ok(!empty($r['success']), 'update to in_person succeeds');
    $row3 = $pdo->query("SELECT meeting_type, zoom_meeting_id FROM meetings WHERE meeting_id=$m3")->fetch(PDO::FETCH_ASSOC);
    ok($row3['meeting_type']==='in_person', 'meeting_type switched to in_person');
    ok($row3['zoom_meeting_id']===null, 'zoom_meeting_id cleared after switching away from Zoom');

    section('6. Guardrails');
    $r = call('manage_meeting', ['action'=>'add','title'=>'No host','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'meeting_type'=>'zoom'], $ADMIN, 'ok');
    ok(empty($r['success']) && stripos($r['message'],'host')!==false, 'rejects a Zoom meeting with no host selected');

    $pdo->prepare("UPDATE system_settings SET setting_value='0' WHERE setting_key='zoom_enabled'")->execute();
    $r = call('manage_meeting', ['action'=>'add','title'=>'Disabled','meeting_date'=>date('Y-m-d',strtotime('+1 day')),'meeting_type'=>'zoom','host_user_id'=>$admin_uid], $ADMIN, 'ok');
    $m4 = (int)($r['meeting_id'] ?? 0);
    ok(empty($r['success']) && stripos($r['message'],'not enabled')!==false, 'rejects a Zoom meeting when the integration is disabled');
    $pdo->prepare("UPDATE system_settings SET setting_value='1' WHERE setting_key='zoom_enabled'")->execute();

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    foreach ([$m1,$m2,$m3,$m4] as $mid) if ($mid) { $pdo->exec("DELETE FROM meeting_attendees WHERE meeting_id=$mid"); $pdo->exec("DELETE FROM meetings WHERE meeting_id=$mid"); }
    if ($emp) { $pdo->exec("DELETE FROM meeting_attendees WHERE employee_id=$emp"); $pdo->exec("DELETE FROM employees WHERE employee_id=$emp"); }
    $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE 'meeting_%'");
    foreach ($origSettings as $k => $v) $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([$v, $k]);
    echo "  (fixtures + zoom settings restored)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
