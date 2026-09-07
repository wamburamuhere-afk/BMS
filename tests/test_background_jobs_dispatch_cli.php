<?php
/**
 * tests/test_background_jobs_dispatch_cli.php
 *
 * Regression cover for the 2026-09-07 fix: the six "opportunistic" housekeeping
 * jobs (notification outbox drain + 5 once-a-day checks) used to run INLINE
 * inside header.php on whichever authenticated page load happened to cross
 * their throttle window — up to 50 real SMTP sends (each up to a 20s connect
 * timeout) or a full table scan, blocking that one random user's page for as
 * long as the work took. That was the "login/pages sometimes just freeze"
 * symptom reported by the user.
 *
 * The fix moves the actual work to api/run_background_jobs.php, fired
 * fire-and-forget (navigator.sendBeacon) from header.php so the page that
 * triggers it never waits on it.
 *
 * Run: php tests/test_background_jobs_dispatch_cli.php
 * The HTTP section self-skips when the local server is not reachable.
 */

$root = dirname(__DIR__);

$pass = 0; $fail = 0; $skip = 0;
function ok($c, $m)   { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function skip($m)     { global $skip; $skip++; echo "  \033[33m⏭\033[0m  $m\n"; }
function section($t)  { echo "\n\033[1m── $t ──\033[0m\n"; }

$header = file_get_contents("$root/header.php");
$dispatcher = file_get_contents("$root/api/run_background_jobs.php");
$rootsSrc = file_get_contents("$root/roots.php");

// ─────────────────────────────────────────────────────────────────────────────
section('1. header.php no longer runs the six jobs inline');

$inlineJobs = [
    "@include_once __DIR__ . '/cron/check_document_expiry.php'",
    "@include_once __DIR__ . '/cron/run_leave_accrual.php'",
    "@include_once __DIR__ . '/cron/check_hr_expiry.php'",
    "@include_once __DIR__ . '/cron/run_notification_checks.php'",
    "@include_once __DIR__ . '/cron/process_notifications.php'",
    "@include_once __DIR__ . '/cron/send_notification_digests.php'",
];
foreach ($inlineJobs as $line) {
    ok(!str_contains($header, $line), "header.php no longer inline-includes " . basename(trim($line, "'")));
}

ok(str_contains($header, '$__bmsBackgroundDue'), 'header.php computes a cheap "is anything due" flag');
ok(str_contains($header, "navigator.sendBeacon(APP_URL + '/api/run_background_jobs')"),
   'header.php fires the dispatcher via sendBeacon (fire-and-forget)');
ok(str_contains($header, 'if ($__bmsBackgroundDue)'),
   'the beacon is only emitted when something is actually due');

// ─────────────────────────────────────────────────────────────────────────────
section('2. api/run_background_jobs.php owns the real work');

ok(str_contains($dispatcher, "require_once __DIR__ . '/../roots.php'"), 'boots the app normally');
ok(str_contains($dispatcher, "empty(\$_SESSION['user_id'])") && str_contains($dispatcher, 'http_response_code(403)'),
   'refuses anonymous callers (same implicit trust boundary as before)');
ok(str_contains($dispatcher, 'ignore_user_abort(true)'),
   'keeps running server-side even if the sendBeacon tab closes mid-send');

foreach ([
    'check_document_expiry.php' => 'doc_expiry_last_run',
    'run_leave_accrual.php'     => 'leave_accrual_last_run',
    'check_hr_expiry.php'       => 'hr_expiry_last_run',
    'run_notification_checks.php' => 'notif_checks_last_run',
    'process_notifications.php' => 'notif_outbox_last_ts',
    'send_notification_digests.php' => 'notif_digest_last_run',
] as $file => $gate) {
    ok(str_contains($dispatcher, "cron/$file") && str_contains($dispatcher, $gate),
       "still gates and includes cron/$file exactly like header.php used to");
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Routing + the cron/ HTTP block stay intact');

ok(str_contains($rootsSrc, "'api/run_background_jobs' => API_DIR . '/run_background_jobs.php'"),
   'clean-URL route registered');

$htaccess = file_get_contents("$root/.htaccess");
ok(str_contains($htaccess, '(scripts|tests|cron|migrations)(/|$) - [F,L]'),
   'cron/ is still blocked at the web-server level — the new endpoint lives under api/ instead, never inside cron/');

// ─────────────────────────────────────────────────────────────────────────────
section('4. Live behaviour over HTTP');

$base = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$probe = "$root/_bgjobs_probe.php";

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

    [$code] = $getCode("$base/cron/process_notifications.php");
    ok($code === 403, 'cron/process_notifications.php is still unreachable over HTTP (Apache-level block)');

    [$code, $body] = $getCode("$base/api/run_background_jobs");
    ok($code === 403 && str_contains($body, '"ok":false'), 'the dispatcher refuses an anonymous request');

    // Establish a session with a user_id set, exactly like a real login would,
    // via a throwaway probe (same technique as test_session_guard_cli.php).
    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
$_SESSION['user_id'] = 999999999; // does not need to be a real row for this dispatcher
echo json_encode(['ok' => true]);
PHP);

    try {
        $sid = 'bgtest' . bin2hex(random_bytes(8));
        $getCode("$base/_bgjobs_probe.php", "PHPSESSID=$sid");

        [$code, $body] = $getCode("$base/api/run_background_jobs", "PHPSESSID=$sid");
        ok($code === 200, 'an authenticated request reaches the dispatcher (HTTP 200)');
        $decoded = json_decode($body, true);
        ok(is_array($decoded) && ($decoded['ok'] ?? false) === true, 'response is valid JSON with ok:true');
        ok(is_array($decoded['ran'] ?? null), 'response reports which jobs it ran, as an array');
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
