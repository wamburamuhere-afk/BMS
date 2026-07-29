<?php
/**
 * api/join_meeting.php + get_meetings.php join-info visibility — CLI test.
 *   php tests/test_join_meeting_cli.php
 *
 * Each check runs in its own subprocess (mirrors tests/test_zoom_meeting_sync_cli.php)
 * so session state is clean per user. join_meeting.php's redirects are observed via
 * its $GLOBALS['JOIN_REDIRECT_MOCK'] test seam, NOT headers_list() -- PHP's CLI SAPI
 * never records header() calls (headers_list() stays empty there), so that would
 * silently observe nothing at all.
 * Verifies:
 *   - an invited Zoom attendee (user_id-based) clicking the link gets redirected to
 *     zoom_join_url AND meeting_attendees.attended/joined_at gets stamped
 *   - the host gets redirected through with no attendee row required
 *   - an outsider (neither host nor invited) is refused, never reaches Zoom
 *   - a cancelled meeting / non-Zoom meeting / unknown id never redirect to Zoom
 *   - get_meetings.php nulls zoom_join_url/password/start_url for that same outsider,
 *     but returns them for the host and the invited attendee
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";

    // join_meeting.php's test seam -- get_meetings.php ignores this global entirely,
    // it never redirects, so setting it unconditionally here is harmless either way.
    $GLOBALS['JOIN_REDIRECT_MOCK'] = function ($url) { echo "\n__REDIRECT__" . $url; };

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $cfg['get'] ?? [];
    require "$root/api/{$cfg['endpoint']}.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

function callGet($ep, $get, $session) {
    global $root;
    $cfg = ['session' => $session, 'get' => $get, 'endpoint' => $ep];
    $f = tempnam(sys_get_temp_dir(), 'jmt'); file_put_contents($f, json_encode($cfg));
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f));
    @unlink($f);
    $marker = strpos($o, '__REDIRECT__');
    $stdout = $marker === false ? $o : substr($o, 0, $marker);
    $location = $marker === false ? null : trim(substr($o, $marker + strlen('__REDIRECT__')));
    $s = strpos($stdout, '{');
    $json = $s === false ? null : json_decode(substr($stdout, $s), true);
    return ['location' => $location, 'json' => $json];
}

$hostUid = 0; $attUid = 0; $outsiderUid = 0; $bystanderAdminUid = 0; $editorBystanderUid = 0; $meetingId = 0; $meetingId2 = 0;
try {
    section('Fixtures');
    $pdo->exec("INSERT INTO users (username,password,email,is_admin,role_id,is_active,created_at) VALUES ('__jmt_host','x','jmthost@example.local',1,1,1,NOW())");
    $hostUid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO users (username,password,email,is_admin,role_id,is_active,created_at) VALUES ('__jmt_att','x','jmtatt@example.local',1,1,1,NOW())");
    $attUid = (int)$pdo->lastInsertId();
    // Deliberately NOT admin -- isAdmin() bypasses every visibility check here (same
    // as get_meetings.php's own canView() bypass just above it), so a genuinely
    // non-admin, non-invited user is the only way to prove the outsider-refused path.
    $pdo->exec("INSERT INTO users (username,password,email,is_admin,role_id,is_active,created_at) VALUES ('__jmt_outsider','x','jmtoutsider@example.local',0,4,1,NOW())");
    $outsiderUid = (int)$pdo->lastInsertId();
    $HOST = ['user_id'=>$hostUid,'username'=>'__jmt_host','is_admin'=>true,'role_id'=>1];
    $ATT = ['user_id'=>$attUid,'username'=>'__jmt_att','is_admin'=>true,'role_id'=>1];
    $OUTSIDER = ['user_id'=>$outsiderUid,'username'=>'__jmt_outsider','is_admin'=>false,'role_id'=>4,'permissions'=>['meetings'=>['view'=>true]]];
    $pdo->exec("INSERT INTO users (username,password,email,is_admin,role_id,is_active,created_at) VALUES ('__jmt_admin_bystander','x','jmtadminbystander@example.local',1,1,1,NOW())");
    $bystanderAdminUid = (int)$pdo->lastInsertId();
    $ADMIN_BYSTANDER = ['user_id'=>$bystanderAdminUid,'username'=>'__jmt_admin_bystander','is_admin'=>true,'role_id'=>1];
    // Non-admin but CAN edit any meeting -- distinct bypass path from isAdmin().
    $pdo->exec("INSERT INTO users (username,password,email,is_admin,role_id,is_active,created_at) VALUES ('__jmt_editor_bystander','x','jmteditorbystander@example.local',0,4,1,NOW())");
    $editorBystanderUid = (int)$pdo->lastInsertId();
    $EDITOR_BYSTANDER = ['user_id'=>$editorBystanderUid,'username'=>'__jmt_editor_bystander','is_admin'=>false,'role_id'=>4,'permissions'=>['meetings'=>['view'=>true,'edit'=>true]]];
    ok($hostUid && $attUid && $outsiderUid && $bystanderAdminUid && $editorBystanderUid, 'host/attendee/outsider/admin-bystander/editor-bystander fixture users ready');

    $pdo->exec("INSERT INTO meetings (title, meeting_date, meeting_type, host_user_id, zoom_join_url, zoom_start_url, zoom_password, zoom_sync_status, status, created_by) VALUES ('JMT Zoom Meeting', CURDATE(), 'zoom', $hostUid, 'https://zoom.us/j/555', 'https://zoom.us/s/555', 'pw123', 'synced', 'scheduled', $hostUid)");
    $meetingId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO meeting_attendees (meeting_id, user_id) VALUES ($meetingId, $attUid)");
    ok($meetingId > 0, "fixture Zoom meeting #$meetingId ready with 1 invited attendee");

    section('1. Invited attendee clicks Join');
    $r = callGet('join_meeting', ['meeting_id'=>$meetingId], $ATT);
    ok($r['location'] === 'https://zoom.us/j/555', 'redirected straight to zoom_join_url');
    $row = $pdo->query("SELECT attended, joined_at FROM meeting_attendees WHERE meeting_id=$meetingId AND user_id=$attUid")->fetch(PDO::FETCH_ASSOC);
    ok((int)$row['attended'] === 1 && $row['joined_at'] !== null, 'attended=1 and joined_at stamped on click');

    section('2. Host clicks Join (not an invited-attendee row)');
    $r = callGet('join_meeting', ['meeting_id'=>$meetingId], $HOST);
    ok($r['location'] === 'https://zoom.us/j/555', 'host is redirected straight through too');
    $hostRow = $pdo->query("SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id=$meetingId AND user_id=$hostUid")->fetchColumn();
    ok((int)$hostRow === 0, 'no attendee row is fabricated for the host');

    section('3. Outsider (neither host nor invited) is refused');
    $r = callGet('join_meeting', ['meeting_id'=>$meetingId], $OUTSIDER);
    ok($r['location'] !== 'https://zoom.us/j/555', 'never reaches the real Zoom URL');
    // Not an exact-equality check against a separately-called getUrl('unauthorized'):
    // getBasePath() resolves relative to DOCUMENT_ROOT/SCRIPT_NAME, which differ
    // between this test script's own CLI context and the worker subprocess's --
    // both are meaningless in CLI, only real webserver requests set them consistently.
    ok(stripos((string)$r['location'], 'unauthorized') !== false, 'sent to the unauthorized page instead');
    $outsiderRow = $pdo->query("SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id=$meetingId AND user_id=$outsiderUid")->fetchColumn();
    ok((int)$outsiderRow === 0, 'no attendee row created for the outsider either');

    section('4. Guardrails: cancelled / non-Zoom / unknown meeting never redirect to Zoom');
    $pdo->exec("UPDATE meetings SET status='cancelled' WHERE meeting_id=$meetingId");
    $r = callGet('join_meeting', ['meeting_id'=>$meetingId], $ATT);
    ok($r['location'] !== 'https://zoom.us/j/555', 'a cancelled meeting does not redirect to Zoom');
    $pdo->exec("UPDATE meetings SET status='scheduled' WHERE meeting_id=$meetingId");

    $pdo->exec("INSERT INTO meetings (title, meeting_date, meeting_type, venue, host_user_id, status, created_by) VALUES ('JMT In-Person', CURDATE(), 'in_person', 'Room A', $hostUid, 'scheduled', $hostUid)");
    $meetingId2 = (int)$pdo->lastInsertId();
    $r = callGet('join_meeting', ['meeting_id'=>$meetingId2], $HOST);
    ok($r['location'] !== 'https://zoom.us/j/555' && stripos((string)$r['location'], 'zoom.us') === false, 'an in-person meeting never redirects to a Zoom URL');

    $r = callGet('join_meeting', ['meeting_id'=>999999], $ATT);
    ok(stripos((string)$r['location'], 'zoom.us') === false, 'an unknown meeting id never redirects to Zoom');

    section('5. get_meetings.php nulls join info for a non-invited viewer, keeps it for host/attendee');
    $r = callGet('get_meetings', ['meeting_id'=>$meetingId], $OUTSIDER);
    ok($r['json']['success'] === true, 'outsider can still fetch the meeting (view permission, just not join info)');
    ok($r['json']['data']['zoom_join_url'] === null && $r['json']['data']['zoom_password'] === null && $r['json']['data']['zoom_start_url'] === null, 'join_url/password/start_url all nulled out for a non-invited viewer');

    $r = callGet('get_meetings', ['meeting_id'=>$meetingId], $ATT);
    ok($r['json']['data']['zoom_join_url'] === 'https://zoom.us/j/555', 'invited attendee still gets the real join_url');

    $r = callGet('get_meetings', ['meeting_id'=>$meetingId], $HOST);
    ok($r['json']['data']['zoom_start_url'] === 'https://zoom.us/s/555', 'host still gets the real start_url');

    section('6. A genuine admin bystander (not host, not invited) still sees + can use join info');
    // isAdmin() bypass matches every other permission gate in this codebase seeing
    // through for admins (e.g. canView() two lines above in get_meetings.php) --
    // without it an admin couldn't even debug someone else's Zoom meeting.
    $r = callGet('get_meetings', ['meeting_id'=>$meetingId], $ADMIN_BYSTANDER);
    ok($r['json']['data']['zoom_join_url'] === 'https://zoom.us/j/555', 'admin bypass: get_meetings.php still returns the real join_url');
    $r = callGet('join_meeting', ['meeting_id'=>$meetingId], $ADMIN_BYSTANDER);
    ok($r['location'] === 'https://zoom.us/j/555', 'admin bypass: join_meeting.php redirects them through too');
    $bystanderRow = $pdo->query("SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id=$meetingId AND user_id=$bystanderAdminUid")->fetchColumn();
    ok((int)$bystanderRow === 0, 'no attendee row fabricated for the admin bystander -- they still are not actually invited');

    section('7. A non-admin editor (not host, not invited, but canEdit) also sees + can use join info');
    // Without this bypass, an editor who can already reschedule/cancel this meeting
    // would see a blank password on the Edit form (get_meetings.php feeds that
    // pre-fill) and get refused if they tried the Join button -- both dead ends.
    $r = callGet('get_meetings', ['meeting_id'=>$meetingId], $EDITOR_BYSTANDER);
    ok($r['json']['data']['zoom_join_url'] === 'https://zoom.us/j/555' && $r['json']['data']['zoom_password'] === 'pw123', 'editor bypass: get_meetings.php returns the real join_url + password (Edit form pre-fill)');
    $r = callGet('join_meeting', ['meeting_id'=>$meetingId], $EDITOR_BYSTANDER);
    ok($r['location'] === 'https://zoom.us/j/555', 'editor bypass: join_meeting.php redirects them through too');
    $editorBystanderRow = $pdo->query("SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id=$meetingId AND user_id=$editorBystanderUid")->fetchColumn();
    ok((int)$editorBystanderRow === 0, 'no attendee row fabricated for the editor bystander either');

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    foreach ([$meetingId, $meetingId2] as $mid) if ($mid) { $pdo->exec("DELETE FROM meeting_attendees WHERE meeting_id=$mid"); $pdo->exec("DELETE FROM meetings WHERE meeting_id=$mid"); }
    foreach ([$hostUid, $attUid, $outsiderUid, $bystanderAdminUid, $editorBystanderUid] as $uid) if ($uid) $pdo->exec("DELETE FROM users WHERE user_id=$uid");
    echo "  (fixtures cleaned up)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
