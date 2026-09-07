<?php
/**
 * tests/test_login_finalize_dispatch_cli.php
 *
 * Regression cover for the 2026-09-07 login-performance fix: actions/login.php
 * used to call startUserSession() (core/session_tracker.php) inline, which
 * does a real GeoIP HTTP lookup (ip-api.com, up to a 3s timeout) and can
 * trigger an SMTP-sent notification email (unfamiliar/concurrent login) —
 * both genuine network calls with nothing to do with verifying a password.
 * That made login itself only as fast as whichever of those was slowest.
 *
 * The fix splits that work into api/finalize_login.php, fired fire-and-forget
 * (navigator.sendBeacon) from login.php right after a successful response, in
 * parallel with the redirect to dashboard.
 *
 * Run: php tests/test_login_finalize_dispatch_cli.php
 * The HTTP section self-skips when the local server is not reachable.
 */

$root = dirname(__DIR__);

$pass = 0; $fail = 0; $skip = 0;
function ok($c, $m)   { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function skip($m)     { global $skip; $skip++; echo "  \033[33m⏭\033[0m  $m\n"; }
function section($t)  { echo "\n\033[1m── $t ──\033[0m\n"; }

$loginAction = file_get_contents("$root/actions/login.php");
$finalize    = file_get_contents("$root/api/finalize_login.php");
$loginPage   = file_get_contents("$root/login.php");
$rootsSrc    = file_get_contents("$root/roots.php");

// ─────────────────────────────────────────────────────────────────────────────
section('1. actions/login.php no longer does the slow work inline');

ok(!str_contains($loginAction, 'startUserSession('), 'no longer calls startUserSession() directly');
ok(!str_contains($loginAction, "require_once __DIR__ . '/../core/session_tracker.php'"),
   'no longer pulls in session_tracker.php (GeoIP + notify) at all');
ok(str_contains($loginAction, "\$response['success'] = true;"), 'still reports success on valid credentials');

// ─────────────────────────────────────────────────────────────────────────────
section('2. api/finalize_login.php owns the slow work');

ok(str_contains($finalize, "require_once __DIR__ . '/../roots.php'"),
   'boots through roots.php (inherits the early session-lock release automatically)');
ok(str_contains($finalize, "empty(\$_SESSION['user_id'])") && str_contains($finalize, 'http_response_code(403)'),
   'refuses a request with no logged-in session');
ok(str_contains($finalize, "!empty(\$_SESSION['session_row_id'])") && str_contains($finalize, "'already' => true"),
   'idempotent — a second call for the same session is a safe no-op');
ok(str_contains($finalize, 'startUserSession(') && str_contains($finalize, 'logActivity('),
   'still does the exact same work startUserSession()/logActivity() used to do inline');
ok(str_contains($finalize, 'ignore_user_abort(true)'),
   'keeps running server-side even if the sendBeacon tab navigates away mid-call');

// ─────────────────────────────────────────────────────────────────────────────
section('3. login.php fires it without blocking the redirect');

ok(str_contains($loginPage, "navigator.sendBeacon('<?= getUrl('api/finalize_login') ?>')"),
   'fires finalize_login via sendBeacon on a successful login response');
$beaconPos    = strpos($loginPage, 'sendBeacon');
$redirectPos  = strpos($loginPage, "window.location.href = 'dashboard'");
ok($beaconPos !== false && $redirectPos !== false && $beaconPos < $redirectPos
   && ($redirectPos - $beaconPos) < 300,
   'the beacon call sits right before the redirect, not blocking it (fire-and-forget, not awaited)');

ok(str_contains($rootsSrc, "'api/finalize_login' => API_DIR . '/finalize_login.php'"),
   'clean-URL route registered');

// ─────────────────────────────────────────────────────────────────────────────
section('4. Live behaviour over HTTP');

$base = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$probe = "$root/_finalizelogin_probe.php";

$reachable = false;
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;

if (!$reachable) {
    skip("local server not reachable at $base — HTTP section skipped (set BMS_TEST_URL to override)");
} else {
    $getCode = function (string $url, string $cookie = '') {
        $ctx = stream_context_create(['http' => [
            'timeout' => 20, 'ignore_errors' => true,
            'header'  => $cookie !== '' ? "Cookie: $cookie\r\n" : '',
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
        }
        return [$code, (string)$body];
    };

    [$code, $body] = $getCode("$base/api/finalize_login");
    ok($code === 403 && str_contains($body, '"ok":false'), 'refuses an anonymous request');

    // Establish a session with a user_id set, same throwaway-probe technique
    // used by tests/test_session_guard_cli.php and
    // tests/test_background_jobs_dispatch_cli.php. REMOTE_ADDR for a loopback
    // curl request is 127.0.0.1, which lookupGeoIP() short-circuits (no real
    // ip-api.com network call), so this stays a fast, offline-safe test.
    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
$_SESSION['user_id'] = 999999999; // does not need to be a real row for this endpoint
echo json_encode(['ok' => true]);
PHP);

    try {
        $sid = 'fltest' . bin2hex(random_bytes(8));
        $getCode("$base/_finalizelogin_probe.php", "PHPSESSID=$sid");

        $t0 = microtime(true);
        [$code, $body] = $getCode("$base/api/finalize_login", "PHPSESSID=$sid");
        $elapsed = microtime(true) - $t0;

        ok($code === 200, 'an authenticated call reaches finalize_login (HTTP 200)');
        $decoded = json_decode($body, true);
        ok(is_array($decoded) && ($decoded['ok'] ?? false) === true, 'response is valid JSON with ok:true');
        ok($elapsed < 10, "completes quickly for a loopback IP (no live GeoIP call) — took " . round($elapsed, 2) . "s");

        [$code2, $body2] = $getCode("$base/api/finalize_login", "PHPSESSID=$sid");
        $decoded2 = json_decode($body2, true);
        ok($code2 === 200 && ($decoded2['already'] ?? false) === true,
           'a second call for the same session is a no-op (idempotency guard)');
    } finally {
        @unlink($probe);
    }
    ok(!file_exists($probe), 'temporary probe cleaned up');
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═══ Result ═══\033[0m\n";
echo "Passes:   \033[32m$pass\033[0m\n";
echo "Skipped:  \033[33m$skip\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
