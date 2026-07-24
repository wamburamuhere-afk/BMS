<?php
/**
 * Zoom Integration — Phase 2 (service layer) CLI test
 *   php tests/test_zoom_service_cli.php
 *
 * Exercises core/zoom_service.php entirely against a mocked HTTP layer
 * ($GLOBALS['ZOOM_HTTP_MOCK']) — no live Zoom account needed. Verifies:
 *   - a valid cached token is used without a network call
 *   - an expired/missing token triggers a fetch + gets cached
 *   - token-fetch failure degrades gracefully (uniform error shape)
 *   - zoomCreateMeeting validates host_email before ever calling out
 *   - zoomCreateMeeting builds the Zoom request body correctly + maps the response
 *   - zoomUpdateMeeting / zoomDeleteMeeting hit the right method + URL
 *   - zoomDeleteMeeting treats a 404 (already gone on Zoom's side) as success
 * All settings mutations happen inside a rolled-back transaction. Exit 0 = pass.
 */
$root = dirname(__DIR__);

// helpers.php's get_setting() caches the whole system_settings table in a
// function-local static on first call, for the life of the process — great
// in a real request, but it means the very first getSetting() call made
// anywhere during roots.php's own bootstrap (before our test transaction even
// starts) would permanently freeze this process's view of every zoom_* row.
// Defining an always-fresh version FIRST wins the function_exists() guard in
// helpers.php, so every getSetting() call in this test reads the live,
// in-transaction value instead. Test-only seam — production code untouched.
function get_setting($key, $default = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v !== false ? $v : $default;
    } catch (Throwable $e) { return $default; }
}
function getSetting($key, $default = '') { return get_setting($key, $default); }

require_once "$root/roots.php";
require_once "$root/core/zoom_service.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function(){ global $pass,$fail,$pdo; unset($GLOBALS['ZOOM_HTTP_MOCK']); if($pdo && $pdo->inTransaction()) $pdo->rollBack(); echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

function zsSetCreds(PDO $pdo, string $tokenEnc = '', string $expiresAt = '0'): void {
    $up = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    $up->execute(['1', 'zoom_enabled']);
    $up->execute(['acct-123', 'zoom_account_id']);
    $up->execute(['client-123', 'zoom_client_id']);
    $up->execute([encryptSecret('s3cr3t'), 'zoom_client_secret_enc']);
    $up->execute([$tokenEnc, 'zoom_access_token_enc']);
    $up->execute([$expiresAt, 'zoom_token_expires_at']);
}

try {
    $pdo->beginTransaction();

    section('1. Cached token — no network call when still valid');
    zsSetCreds($pdo, encryptSecret('cached-token-xyz'), (string)(time() + 3000));
    $calls = 0;
    $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) use (&$calls) { $calls++; return ['ok'=>false,'json'=>null,'http_code'=>0,'error'=>'should not be called']; };
    $res = zoomGetAccessToken();
    ok($res['success']===true, 'zoomGetAccessToken() succeeds from cache');
    ok(($res['data']['access_token']??null)==='cached-token-xyz', 'returns the cached token');
    ok($calls===0, 'cache hit makes zero HTTP calls');

    section('2. Expired token — fetches fresh + caches it');
    zsSetCreds($pdo, '', '0');
    $calls = 0; $seenUrl = ''; $seenAuthHeader = '';
    $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) use (&$calls,&$seenUrl,&$seenAuthHeader) {
        $calls++; $seenUrl = $url; $seenAuthHeader = $headers[0] ?? '';
        return ['ok'=>true,'json'=>['access_token'=>'fresh-token-123','expires_in'=>3600],'http_code'=>200,'error'=>null];
    };
    $res = zoomGetAccessToken();
    ok($res['success']===true && ($res['data']['access_token']??null)==='fresh-token-123', 'fetches a fresh token when expired');
    ok($calls===1, 'exactly one HTTP call made');
    ok(strpos($seenUrl,'zoom.us/oauth/token')!==false && strpos($seenUrl,'account_id=acct-123')!==false, 'requests the correct OAuth token URL');
    ok(strpos($seenAuthHeader,'Authorization: Basic ')===0, 'sends Basic auth (base64 client_id:client_secret)');
    $storedEnc = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='zoom_access_token_enc'")->fetchColumn();
    $storedExp = (int)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='zoom_token_expires_at'")->fetchColumn();
    ok(decryptSecret($storedEnc)==='fresh-token-123', 'fresh token persisted to system_settings (encrypted)');
    ok($storedExp > time()+3500, 'expiry persisted (~3600s out)');

    section('3. Token-fetch failure — graceful, uniform shape');
    zsSetCreds($pdo, '', '0');
    $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) { return ['ok'=>false,'json'=>['reason'=>'Invalid client'],'http_code'=>401,'error'=>'Invalid client_id or client_secret']; };
    $res = zoomGetAccessToken();
    ok($res['success']===false, 'reports failure, does not throw');
    ok(array_keys($res)===['success','message','data'], 'uniform {success,message,data} shape');
    ok(strpos($res['message'],'Invalid client')!==false, 'surfaces Zoom\'s error message');

    section('4. zoomCreateMeeting — validation + payload + response mapping');
    $res = zoomCreateMeeting(['topic'=>'No host set']);
    ok($res['success']===false && stripos($res['message'],'host email')!==false, 'rejects missing host_email before any network call');

    zsSetCreds($pdo, encryptSecret('tok-abc'), (string)(time()+3000));
    $calls=0; $seenMethod=''; $seenUrl=''; $seenBody=null;
    $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) use (&$calls,&$seenMethod,&$seenUrl,&$seenBody) {
        $calls++; $seenMethod=$method; $seenUrl=$url; $seenBody=$body;
        return ['ok'=>true,'json'=>['id'=>555444333,'join_url'=>'https://zoom.us/j/555444333','start_url'=>'https://zoom.us/s/555444333','password'=>'ab12cd'],'http_code'=>201,'error'=>null];
    };
    $res = zoomCreateMeeting([
        'topic'=>'Board sync','agenda'=>'Q3 review','host_email'=>'ceo@bjptech.co.tz',
        'start_time'=>'2026-08-01T09:00:00Z','duration'=>45,'password'=>'ab12cd',
        'host_video'=>true,'participant_video'=>false,'waiting_room'=>true,'auto_recording'=>false,
    ]);
    ok($calls===1, 'exactly one HTTP call for create');
    ok($seenMethod==='POST', 'create uses POST');
    ok(strpos($seenUrl,'/users/ceo%40bjptech.co.tz/meetings')!==false, 'creates under the correct host user (URL-encoded email)');
    ok($seenBody['topic']==='Board sync' && $seenBody['agenda']==='Q3 review', 'topic/agenda passed through');
    ok($seenBody['settings']['host_video']===true && $seenBody['settings']['participant_video']===false && $seenBody['settings']['waiting_room']===true, 'video/waiting-room flags mapped correctly');
    ok($seenBody['settings']['auto_recording']==='none', 'auto_recording off maps to "none"');
    ok($res['success']===true, 'create reports success');
    ok($res['data']['zoom_meeting_id']==='555444333', 'zoom_meeting_id mapped from response id');
    ok($res['data']['join_url']==='https://zoom.us/j/555444333', 'join_url mapped');
    ok($res['data']['start_url']==='https://zoom.us/s/555444333', 'start_url mapped');

    section('5. zoomUpdateMeeting / zoomDeleteMeeting — method + URL + 404-as-success');
    $seenMethod=''; $seenUrl='';
    $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) use (&$seenMethod,&$seenUrl) { $seenMethod=$method; $seenUrl=$url; return ['ok'=>true,'json'=>[],'http_code'=>204,'error'=>null]; };
    $res = zoomUpdateMeeting('555444333', ['topic'=>'Board sync (rescheduled)']);
    ok($res['success']===true, 'update reports success');
    ok($seenMethod==='PATCH' && strpos($seenUrl,'/meetings/555444333')!==false, 'update uses PATCH on the correct meeting id');

    $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) use (&$seenMethod,&$seenUrl) { $seenMethod=$method; $seenUrl=$url; return ['ok'=>true,'json'=>[],'http_code'=>204,'error'=>null]; };
    $res = zoomDeleteMeeting('555444333');
    ok($res['success']===true, 'delete reports success');
    ok($seenMethod==='DELETE' && strpos($seenUrl,'/meetings/555444333')!==false, 'delete uses DELETE on the correct meeting id');

    $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) { return ['ok'=>false,'json'=>['message'=>'Meeting not found'],'http_code'=>404,'error'=>'Meeting not found']; };
    $res = zoomDeleteMeeting('999999999');
    ok($res['success']===true, 'delete treats a 404 (already gone on Zoom) as success, not a failure');

    $GLOBALS['ZOOM_HTTP_MOCK'] = function ($method, $url, $headers, $body) { return ['ok'=>false,'json'=>['message'=>'Server error'],'http_code'=>500,'error'=>'Server error']; };
    $res = zoomDeleteMeeting('999999999');
    ok($res['success']===false, 'delete surfaces a genuine (non-404) failure');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — settings restored');

} catch (Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); ok(false,'test threw: '.$e->getMessage()); }
exit($fail===0?0:1);
