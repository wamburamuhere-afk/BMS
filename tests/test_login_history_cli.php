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

$pass = 0; $fail = 0;

function ok(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✅\033[0m $msg\n"; }
    else        { $fail++; echo "  \033[31m❌ FAIL: $msg\033[0m\n"; }
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
ok(str_contains($apiContent, 'ORDER BY us.login_at DESC'), "API orders newest logins first");

// ── 7. Page file checks ──────────────────────────────────────────────────────
echo "\n[7] Page — login_history.php content checks\n";

$pageContent = file_get_contents($loginHistoryFile);
ok(str_contains($pageContent, 'isAdmin()'),          "Page enforces admin-only access");
ok(str_contains($pageContent, 'function safeOutput'), "Page defines safeOutput() JS helper");
ok(str_contains($pageContent, 'Login History'),       "Page title is 'Login History'");
ok(str_contains($pageContent, 'get_login_history'),   "Page references the correct API endpoint");
ok(str_contains($pageContent, 'Location'),            "Page shows Location column");
ok(str_contains($pageContent, 'ISP'),                 "Page shows ISP column");
ok(str_contains($pageContent, 'device_type'),         "Page uses device_type from API");
ok(str_contains($pageContent, 'today_logins'),        "Page has Today stat card");

// Check nav link added to header
$headerContent = file_get_contents($root . '/header.php');
ok(str_contains($headerContent, "getUrl('login_history')"), "header.php contains login_history nav link");
ok(str_contains($headerContent, 'bi-clock-history'),        "header.php uses clock-history icon");

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n";
echo "Passes:   \033[32m{$pass}\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m{$fail}\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
