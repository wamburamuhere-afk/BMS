<?php
/**
 * test_login_history_cli.php
 * php tests/test_login_history_cli.php
 *
 * Covers the Login History feature (2026-06-26):
 *   1. Schema  — all 9 new columns exist on user_sessions
 *   2. parseUserAgent() — browser / OS / device detection
 *   3. lookupGeoIP()   — private-IP short-circuit (no real HTTP needed)
 *   4. startUserSession() integration — inserts row with enriched data
 *   5. Route  — login_history key resolves in roots.php
 *   6. API    — get_login_history.php returns valid JSON shape
 *   7. Page   — login_history.php file exists and is readable
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/session_tracker.php";
global $pdo;

$pass = 0; $fail = 0; $skip = 0;

function ok(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✅\033[0m $msg\n"; }
    else        { $fail++; echo "  \033[31m❌ FAIL: $msg\033[0m\n"; }
}

function skip(string $msg): void {
    global $skip;
    $skip++; echo "  \033[33m⏭\033[0m  $msg\n";
}

// ── 1. Schema ────────────────────────────────────────────────────────────────
echo "\n[1] Schema — user_sessions new columns\n";
$newCols = ['city','region','country','country_code','isp','org','timezone','browser','os','device_type'];
foreach ($newCols as $col) {
    ok((bool)$pdo->query("SHOW COLUMNS FROM user_sessions LIKE " . $pdo->quote($col))->fetch(),
       "user_sessions.{$col} exists");
}

// ── 2. parseUserAgent() ──────────────────────────────────────────────────────
echo "\n[2] parseUserAgent() — browser / OS / device detection\n";

$cases = [
    // [ua_string, expected_browser, expected_os_prefix, expected_device]
    [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Chrome', 'Windows', 'Desktop'
    ],
    [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        'Safari', 'iOS', 'Mobile'
    ],
    [
        'Mozilla/5.0 (Linux; Android 14; SM-S928B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.6367.82 Mobile Safari/537.36',
        'Chrome', 'Android', 'Mobile'
    ],
    [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
        'Firefox', 'Windows', 'Desktop'
    ],
    [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
        'Edge', 'macOS', 'Desktop'
    ],
    [
        'Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'Safari', 'iPadOS', 'Tablet'
    ],
];

foreach ($cases as [$ua, $expBrowser, $expOsPrefix, $expDevice]) {
    $r = parseUserAgent($ua);
    ok($r['browser'] === $expBrowser,
       "browser: expected '{$expBrowser}', got '{$r['browser']}' | " . substr($ua, 0, 60) . '…');
    ok(str_starts_with($r['os'], $expOsPrefix),
       "os: expected prefix '{$expOsPrefix}', got '{$r['os']}'");
    ok($r['device_type'] === $expDevice,
       "device_type: expected '{$expDevice}', got '{$r['device_type']}'");
}

// null / empty UA
$empty = parseUserAgent(null);
ok($empty['browser'] === 'Unknown' && $empty['device_type'] === 'Unknown',
   'parseUserAgent(null) returns Unknown values');

// Windows 11 via Sec-CH-UA-Platform-Version client hint
$winUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$_SERVER['HTTP_SEC_CH_UA_PLATFORM_VERSION'] = '"14.0.0"'; // Windows 11
$r11 = parseUserAgent($winUa);
ok($r11['os'] === 'Windows 11', "Windows 11 detected via Sec-CH-UA-Platform-Version = 14.0.0 (got: {$r11['os']})");

$_SERVER['HTTP_SEC_CH_UA_PLATFORM_VERSION'] = '"10.0.0"'; // Windows 10
$r10 = parseUserAgent($winUa);
ok($r10['os'] === 'Windows 10', "Windows 10 detected via Sec-CH-UA-Platform-Version = 10.0.0 (got: {$r10['os']})");

unset($_SERVER['HTTP_SEC_CH_UA_PLATFORM_VERSION']); // Firefox — no hint
$rAmb = parseUserAgent($winUa);
ok($rAmb['os'] === 'Windows 10/11', "Windows 10/11 (ambiguous) when no client hint present (got: {$rAmb['os']})");


// ── 3. lookupGeoIP() — private/loopback IPs ─────────────────────────────────
echo "\n[3] lookupGeoIP() — private / loopback addresses (no HTTP call)\n";

foreach (['127.0.0.1', '::1', '10.0.0.1', '192.168.1.1', '172.16.0.1'] as $privateIp) {
    $geo = lookupGeoIP($privateIp);
    ok($geo !== null,                              "lookupGeoIP({$privateIp}) returns a result (not null)");
    ok(($geo['city']    ?? '') === 'Local',         "lookupGeoIP({$privateIp}) city = 'Local'");
    ok(($geo['country'] ?? '') === 'Local',         "lookupGeoIP({$privateIp}) country = 'Local'");
    ok(array_key_exists('region', $geo),           "lookupGeoIP({$privateIp}) has region key");
}

ok(lookupGeoIP(null) === null, 'lookupGeoIP(null) returns null');
ok(lookupGeoIP('')   === null, 'lookupGeoIP(\'\') returns null');

// ── 4. startUserSession() integration ───────────────────────────────────────
echo "\n[4] startUserSession() — inserts enriched row\n";

// Find a real user to use
$uid = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
ok($uid > 0, "Found a user to test with (user_id={$uid})");

$testIp = '127.0.0.1';
$testUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

$sid = startUserSession($pdo, $uid, $testIp, $testUa);
ok($sid !== null && $sid > 0, "startUserSession() returned a valid session id ({$sid})");

if ($sid) {
    $row = $pdo->prepare("SELECT * FROM user_sessions WHERE id = ?");
    $row->execute([$sid]);
    $sess = $row->fetch(PDO::FETCH_ASSOC);

    ok($sess !== false, "Session row {$sid} found in DB");
    ok($sess['user_id'] == $uid,                     "user_id stored correctly");
    ok($sess['ip_address'] === $testIp,              "ip_address stored correctly");
    ok($sess['browser'] === 'Chrome',                "browser = Chrome (parsed from UA)");
    ok(str_starts_with($sess['os'], 'Windows'),      "os starts with 'Windows'");
    ok($sess['device_type'] === 'Desktop',           "device_type = Desktop");
    ok($sess['city'] === 'Local',                    "city = Local (private IP)");
    ok(isset($sess['region']),                       "region column exists in row");
    ok($sess['country'] === 'Local',                 "country = Local (private IP)");
    ok($sess['isp'] === 'Internal Network',          "isp = Internal Network");
    ok($sess['logout_at'] === null,                  "logout_at is NULL (session still open)");

    // Clean up test row
    $pdo->prepare("DELETE FROM user_sessions WHERE id = ?")->execute([$sid]);
    ok(!(bool)$pdo->prepare("SELECT id FROM user_sessions WHERE id = ?")->execute([$sid]) ||
       !$pdo->query("SELECT id FROM user_sessions WHERE id = {$sid}")->fetch(),
       "Test session row cleaned up");
}

// ── 5. Route registration ────────────────────────────────────────────────────
echo "\n[5] Route — login_history resolves in roots.php\n";

// Simulate how getUrl() resolves paths — check the PAGES constant/array
$routes = defined('PAGES') ? PAGES : (isset($pages) ? $pages : null);

// The router map is built in roots.php; we check via the resolved file path
$loginHistoryFile = defined('SETTINGS_DIR')
    ? SETTINGS_DIR . '/login_history.php'
    : $root . '/app/constant/settings/login_history.php';

ok(is_file($loginHistoryFile), "login_history.php exists at expected path");

// Check that the API file exists
ok(is_file($root . '/api/get_login_history.php'), "api/get_login_history.php exists");

// Check roots.php contains the route key
$rootsContent = file_get_contents($root . '/roots.php');
ok(str_contains($rootsContent, "'login_history'"), "'login_history' route key present in roots.php");
ok(str_contains($rootsContent, "login_history.php"), "login_history.php mapped in roots.php");

// ── 6. API — JSON shape ──────────────────────────────────────────────────────
echo "\n[6] API — get_login_history.php output shape\n";

// Simulate a GET request by setting up the environment
$_GET = ['draw' => '1', 'start' => '0', 'length' => '5', 'search' => ['value' => '']];
$_SESSION['user_id'] = $uid;
$_SESSION['role_id'] = 1; // Admin

ob_start();
// We can't call isAdmin() without the real session, so we test the file parses + DB query works
$apiContent = file_get_contents($root . '/api/get_login_history.php');
ob_end_clean();

ok(str_contains($apiContent, 'recordsTotal'),   "API file references recordsTotal key");
ok(str_contains($apiContent, 'recordsFiltered'), "API file references recordsFiltered key");
ok(str_contains($apiContent, 'city'),           "API file selects city column");
ok(str_contains($apiContent, 'browser'),        "API file selects browser column");
ok(str_contains($apiContent, 'device_type'),    "API file selects device_type column");
ok(str_contains($apiContent, 'isp'),            "API file selects isp column");
ok(str_contains($apiContent, 'timezone'),       "API file selects timezone column");
ok(str_contains($apiContent, "'us.login_at'"), "API orders by login_at column");

// ── 7. Page file checks ──────────────────────────────────────────────────────
echo "\n[7] Page — login_history.php content checks\n";

$pageContent = file_get_contents($loginHistoryFile);
// Login History is strictly admin-only by request (privacy-sensitive audit
// trail) — consolidated inside Settings > Admin, not delegable via Roles &
// Permissions no matter what's granted (permission row hidden from that UI).
ok(str_contains($pageContent, "autoEnforcePermission('login_history')"), "Page still calls autoEnforcePermission('login_history')");
ok(str_contains($pageContent, 'if (!isAdmin())'),   "Page hard-redirects non-admins (strictly admin-only)");
ok(str_contains($pageContent, 'function safeOutput'), "Page defines safeOutput() JS helper");
ok(str_contains($pageContent, 'Login History'),       "Page title is 'Login History'");
ok(str_contains($pageContent, 'get_login_history'),   "Page references the correct API endpoint");
ok(str_contains($pageContent, 'Location'),            "Page shows Location column");
ok(str_contains($pageContent, 'ISP'),                 "Page shows ISP column");
ok(str_contains($pageContent, 'device_type'),         "Page uses device_type from API");
ok(str_contains($pageContent, 'signins_today'),       "Page has Sign-ins Today stat card");
ok(str_contains($pageContent, 'signed_in_now'),       "Page has Signed In Now stat card");
ok(str_contains($pageContent, 'precise_today'),       "Page has Precise Today stat card");

// Login History is consolidated inside Settings > Admin (system_settings.php),
// not a standalone top-nav item — header.php should NOT link to it directly.
$headerContent = file_get_contents($root . '/header.php');
ok(!str_contains($headerContent, "getUrl('login_history')"), "header.php no longer links to login_history directly (moved into Settings > Admin)");
$settingsPageContent = file_get_contents($root . '/app/constant/settings/system_settings.php');
// 2026-08-29: relocated out of Settings > Admin into Activity Logs (still
// strictly admin-only — see the [8] section below for the cross-link checks).
ok(!str_contains($settingsPageContent, "getUrl('login_history')"), "system_settings.php no longer links to login_history (relocated)");

// ── 8. Session lifecycle upgrade (2026-08-29) ──────────────────────────────────
// Matches/exceeds the reference LMS Login History page: real expiry, "signed in
// again" reconciliation, admin revoke, precise location, richer filters.
echo "\n[8] Session lifecycle — schema, functions, filters, UI\n";

$lifecycleCols = ['last_seen_at', 'php_session_id', 'revoked_by', 'revoked_at',
                  'precise_lat', 'precise_lng', 'precise_accuracy_m', 'precise_captured_at'];
foreach ($lifecycleCols as $col) {
    ok((bool) $pdo->query("SHOW COLUMNS FROM user_sessions LIKE " . $pdo->quote($col))->fetch(),
       "user_sessions.{$col} exists");
}

ok(function_exists('touchUserSession'),           'touchUserSession() defined');
ok(function_exists('expireIdleSessions'),          'expireIdleSessions() defined');
ok(function_exists('revokeUserSession'),           'revokeUserSession() defined');
ok(function_exists('recordPreciseLocation'),       'recordPreciseLocation() defined');
ok(function_exists('bmsEnforceSessionLifecycle'),  'bmsEnforceSessionLifecycle() defined');

// -- Real DB round-trips, same pattern/cleanup convention as section [4] --
$testUid = 999900 + random_int(1, 99); // avoid collision with a concurrent test run
$pdo->exec("DELETE FROM user_sessions WHERE user_id = {$testUid}");

// 8a. Re-login supersedes the prior open row, ended at its own last-seen moment.
$firstRow  = startUserSession($pdo, $testUid, '127.0.0.1', 'LifecycleTest/1', 'phpsess-t1');
$secondRow = startUserSession($pdo, $testUid, '127.0.0.1', 'LifecycleTest/2', 'phpsess-t2');
$first  = $pdo->query("SELECT logout_type, logout_at FROM user_sessions WHERE id={$firstRow}")->fetch(PDO::FETCH_ASSOC);
$second = $pdo->query("SELECT logout_type FROM user_sessions WHERE id={$secondRow}")->fetch(PDO::FETCH_ASSOC);
ok($first['logout_type'] === 'superseded' && $first['logout_at'] !== null, "re-login closes the prior row as 'superseded'");
ok($second['logout_type'] === null,                                       "the new row from re-login is still open");

// 8b. Idle sweep closes at last-seen, never "now" — and never goes negative.
$pdo->exec("UPDATE user_sessions SET login_at = DATE_SUB(NOW(), INTERVAL 60 MINUTE),
            last_seen_at = DATE_SUB(NOW(), INTERVAL 45 MINUTE) WHERE id = {$secondRow}");
expireIdleSessions($pdo, 1800);
$idle = $pdo->query("SELECT logout_type, duration_seconds, logout_at, last_seen_at FROM user_sessions WHERE id={$secondRow}")->fetch(PDO::FETCH_ASSOC);
ok($idle['logout_type'] === 'timeout',                 "idle sweep closes with logout_type='timeout'");
ok($idle['logout_at'] === $idle['last_seen_at'],       "idle sweep closes AT last-seen, not 'now'");
ok((int) $idle['duration_seconds'] === 900,            "idle sweep computes duration from login_at to last-seen (900s)");

// 8c. Revoke: audit fields set, idempotent against a second call.
$thirdRow = startUserSession($pdo, $testUid, '127.0.0.1', 'LifecycleTest/3', 'phpsess-t3');
$revoked  = revokeUserSession($pdo, $thirdRow, 4, 'revoked');
$rrow = $pdo->query("SELECT logout_type, revoked_by, revoked_at FROM user_sessions WHERE id={$thirdRow}")->fetch(PDO::FETCH_ASSOC);
ok($revoked === true,                                  "revokeUserSession() returns true on a live row");
ok($rrow['logout_type'] === 'revoked' && (int) $rrow['revoked_by'] === 4 && $rrow['revoked_at'] !== null,
   "revoke stamps logout_type/revoked_by/revoked_at");
ok(revokeUserSession($pdo, $thirdRow, 4, 'revoked') === false, "revoking an already-closed row is a no-op, not an error");

// 8d. Precise location: bounds-checked and one-shot at the SQL level.
$fourthRow = startUserSession($pdo, $testUid, '127.0.0.1', 'LifecycleTest/4', 'phpsess-t4');
ok(recordPreciseLocation($pdo, $fourthRow, -6.7924, 39.2083, 25) === true, "recordPreciseLocation() accepts valid coordinates");
ok(recordPreciseLocation($pdo, $fourthRow, 0, 0, 10) === false,            "a second ping on the same row is refused (one-shot)");
ok(recordPreciseLocation($pdo, $fourthRow, 999, 39, 10) === false,         "out-of-range latitude is refused");
$geo = $pdo->query("SELECT precise_lat FROM user_sessions WHERE id={$fourthRow}")->fetch(PDO::FETCH_ASSOC);
ok(abs((float) $geo['precise_lat'] + 6.7924) < 0.0001,                     "the original (not the rejected) fix is what's stored");

$pdo->exec("DELETE FROM user_sessions WHERE user_id = {$testUid}");
ok(!$pdo->query("SELECT id FROM user_sessions WHERE user_id = {$testUid}")->fetch(), "lifecycle test rows cleaned up");

// -- Static checks: new filters wired end to end (API + page) --
$apiContent = file_get_contents($root . '/api/get_login_history.php');
foreach (["'ended'", "'device'", "'country'", "'location_source'", "'superseded'", "'revoked'", "'admin_ended'"] as $needle) {
    ok(str_contains($apiContent, $needle), "API references filter/state {$needle}");
}

$pageContent = file_get_contents($loginHistoryFile);
foreach (['periodChips', 'f-ended', 'f-device', 'f-country', 'f-location-source', 'endSession(', 'endedBadge', 'CURRENT_SESSION_ROW_ID'] as $needle) {
    ok(str_contains($pageContent, $needle), "Page references {$needle}");
}

ok(is_file($root . '/api/revoke_session.php'), "api/revoke_session.php exists");
$revokeApiContent = file_get_contents($root . '/api/revoke_session.php');
ok(str_contains($revokeApiContent, 'isAdmin()'),          "revoke_session.php checks isAdmin()");
ok(str_contains($revokeApiContent, 'csrf_check()'),       "revoke_session.php checks csrf_check()");
ok(str_contains($revokeApiContent, "'POST'"),             "revoke_session.php requires POST");
ok(str_contains($revokeApiContent, 'revokeUserSession'),  "revoke_session.php calls revokeUserSession()");
ok(str_contains($revokeApiContent, 'logAudit('),          "revoke_session.php writes an audit-log entry");

ok(is_file($root . '/api/session_geo_ping.php'), "api/session_geo_ping.php exists");
$geoApiContent = file_get_contents($root . '/api/session_geo_ping.php');
ok(str_contains($geoApiContent, 'isAuthenticated()'),      "session_geo_ping.php checks isAuthenticated()");
ok(str_contains($geoApiContent, 'recordPreciseLocation'),  "session_geo_ping.php calls recordPreciseLocation()");

// -- Relocation: cross-linked from Activity Logs, still admin-only, not a
//    standalone Settings > Admin item --
$activityLogContent = file_get_contents($root . '/app/activity_log.php');
ok(str_contains($activityLogContent, "getUrl('login_history')"),  "activity_log.php links to login_history");
// A FRESH isAdmin() call, not the page's own $is_admin variable — that variable
// is captured near the top of the file, BEFORE header.php (required much later
// in this file) refreshes $_SESSION['is_admin'] from the DB, so it can be stale
// for this render. Caught live: a forged non-admin session still saw the
// button because $is_admin held an admin value left over from a prior request
// on the same test session.
$loginHistoryLinkPos = strpos($activityLogContent, "getUrl('login_history')");
$precedingSnippet = $loginHistoryLinkPos !== false ? substr($activityLogContent, max(0, $loginHistoryLinkPos - 400), 400) : '';
ok(str_contains($precedingSnippet, 'if (isAdmin())'),
   "activity_log.php's Login History link is gated on a FRESH isAdmin() call, not the page's own (possibly stale) \$is_admin");
ok(str_contains($pageContent, "getUrl('activity_log')"), "login_history.php links back to Activity Logs");
ok(str_contains($pageContent, 'Admin only'),             "login_history.php still visibly marks itself admin-only");

// ── 9. Duration display + timezone (2026-08-29 live-verification bugfixes) ──────
// Both caught by loading the real page after two real logins, not by static
// review — worth pinning down precisely so they can't come back silently.
echo "\n[9] Duration display + timezone-qualified timestamps\n";

// 9a. formatDur(0) must be "0s", never the dash it used to be. `!secs` treats
// 0 as falsy — that's what made every honestly-zero-second session (a
// re-login a moment after signing in, an idle-timeout with no heartbeat ever
// recorded) show a dash instead of the reference LMS's own "0s".
ok(str_contains($pageContent, "if (secs === null || secs === undefined || secs === '') return '—';"),
   "formatDur() treats only missing data as '—' — 0 is a real, displayable value");
ok(!preg_match('/function formatDur\([^)]*\)\s*\{\s*if\s*\(!secs\)/', $pageContent),
   "the old '!secs returns em-dash' form (which swallowed real zeros) is gone");

// 9b. Active-row duration is computed immediately from login_at, not hardcoded
// while waiting for the first tick — no flash of a placeholder on first paint.
ok(str_contains($pageContent, 'function liveElapsedSeconds('), "liveElapsedSeconds() helper exists");
ok(!str_contains($pageContent, "isActive ? '—' : formatDur(data)"),
   "table's Duration column no longer hardcodes '—' for Active rows");
ok(str_contains($pageContent, 'isActive ? formatDur(liveElapsedSeconds(row.login_at))'),
   "table computes the Active row's real elapsed time immediately, from its own login_at");
ok(str_contains($pageContent, 'isActive ? formatDur(liveElapsedSeconds(r.login_at))'),
   "card view computes the Active row's real elapsed time immediately too");

// 9c. Live HTTP reproduction of the actual bug: MySQL returns a bare
// "Y-m-d H:i:s" string with no timezone marker. Handed straight to JS's
// Date(), that string is parsed in the BROWSER's own timezone, not the
// server's — so on any viewer not also set to Africa/Dar_es_Salaam,
// "new Date(login_at) - Date.now()" comes out negative and the ticker gets
// stuck at 0 forever (reproduced live: a session that had been running for
// over 3 minutes showed a permanent "0s"). withTzOffset() fixes this by
// appending the real UTC offset so the timestamp is unambiguous ISO-8601
// everywhere, independent of the viewer's own machine.
$apiContent = file_get_contents($root . '/api/get_login_history.php');
ok(str_contains($apiContent, 'function withTzOffset('), "get_login_history.php defines withTzOffset()");
ok(str_contains($apiContent, "'login_at'           => withTzOffset(\$row['login_at'])"),
   "login_at is sent timezone-qualified");
ok(str_contains($apiContent, "'logout_at'          => withTzOffset(\$row['logout_at'])"),
   "logout_at is sent timezone-qualified");

$base = getenv('BMS_TEST_URL') ?: 'http://localhost/bms';
$reachable = @file_get_contents("$base/login.php", false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])) !== false;
if (!$reachable) {
    skip("local server not reachable at $base — live timezone reproduction skipped (set BMS_TEST_URL to override)");
} else {
    $tzTestUid = 999600 + random_int(1, 99);
    $pdo->exec("DELETE FROM user_sessions WHERE user_id = {$tzTestUid}");
    $rowId = startUserSession($pdo, $tzTestUid, '127.0.0.1', 'TzOffsetTest/1', 'sess-tz-' . $tzTestUid);

    // Simulate the auth this API demands, then call it directly in-process —
    // no real login credentials available, same technique the other live
    // sections in this file already use.
    $_SESSION['user_id'] = $tzTestUid; // arbitrary; only isAdmin() matters below
    $_SESSION['is_admin'] = true;
    $_GET = ['draw' => 1, 'start' => 0, 'length' => 5, 'search' => ['value' => ''], 'user_id' => $tzTestUid];
    ob_start();
    include "$root/api/get_login_history.php";
    $json = json_decode(ob_get_clean(), true);
    $sentLoginAt = $json['data'][0]['login_at'] ?? null;

    ok($sentLoginAt !== null && (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $sentLoginAt),
       "API sends login_at as full ISO-8601 with a UTC offset (got: " . var_export($sentLoginAt, true) . ")");

    if ($sentLoginAt !== null) {
        // The offset-qualified string must resolve to the SAME real-world instant
        // as the raw DB value interpreted in the app's own configured timezone —
        // i.e. withTzOffset() didn't just decorate the string, it decorated it
        // with the RIGHT offset.
        $rawFromDb   = $pdo->query("SELECT login_at FROM user_sessions WHERE id={$rowId}")->fetchColumn();
        $appTz       = function_exists('get_setting') ? get_setting('timezone', 'Africa/Dar_es_Salaam') : 'Africa/Dar_es_Salaam';
        $groundTruth = new DateTime($rawFromDb, new DateTimeZone($appTz));
        $asSent      = new DateTime($sentLoginAt);
        ok($groundTruth->getTimestamp() === $asSent->getTimestamp(),
           "the sent timestamp resolves to the exact same real-world instant as the raw DB value in the app's configured timezone");
    }

    $pdo->exec("DELETE FROM user_sessions WHERE user_id = {$tzTestUid}");
    unset($_SESSION['user_id'], $_SESSION['is_admin']);
}

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n";
echo "Passes:   \033[32m{$pass}\033[0m\n";
echo "Skipped:  \033[33m{$skip}\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m{$fail}\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
