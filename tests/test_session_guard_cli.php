<?php
/**
 * tests/test_session_guard_cli.php
 *
 * Regression cover for core/session_guard.php — the early release of the PHP
 * session file lock.
 *
 * The live HTTP section reproduces the exact production failure Sentry caught
 * (ErrorException at session_guard.php:78, GET /purchase_orders): when a page
 * flushes its output before shutdown, headers are already sent, and PHP then
 * refuses session_id(), session_start() options, and any session ini change.
 * The first revision of the guard hit that, minted a brand-new session, and
 * wrote the request's $_SESSION changes into an orphan file — losing flash
 * messages and, critically, lazily generated CSRF tokens.
 *
 * Run: php tests/test_session_guard_cli.php
 * The HTTP section self-skips when the local server is not reachable.
 */

$root = dirname(__DIR__);

$pass = 0; $fail = 0; $skip = 0;
function ok($c, $m)   { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function skip($m)     { global $skip; $skip++; echo "  \033[33m⏭\033[0m  $m\n"; }
function section($t)  { echo "\n\033[1m── $t ──\033[0m\n"; }

$guard = file_get_contents("$root/core/session_guard.php");

// ─────────────────────────────────────────────────────────────────────────────
section('1. Guard structure');

ok(str_contains($guard, 'function bmsSessionRelease'),      'bmsSessionRelease() defined');
ok(str_contains($guard, 'function bmsSessionPersist'),      'bmsSessionPersist() defined');
ok(str_contains($guard, 'function bmsSessionReopen'),       'bmsSessionReopen() defined');
ok(str_contains($guard, 'function bmsSessionMarkDestroyed'),'bmsSessionMarkDestroyed() defined');
ok(str_contains($guard, 'session_write_close()'),           'the lock is actually released');
ok(str_contains($guard, "register_shutdown_function('bmsSessionPersist')"), 'write-back registered on shutdown');

// ─────────────────────────────────────────────────────────────────────────────
section('2. The production regression: ini order (Sentry ErrorException)');

// Both ini settings must be applied in bmsSessionRelease(), which runs during
// bootstrap while headers can still be changed. Applying them any later is what
// broke production.
$releaseBody = '';
if (preg_match('/function bmsSessionRelease\(\).*?\n    \}/s', $guard, $m)) $releaseBody = $m[0];
$persistBody = '';
if (preg_match('/function bmsSessionPersist\(\).*?\n    \}/s', $guard, $m)) $persistBody = $m[0];

ok($releaseBody !== '' && $persistBody !== '', 'could isolate both function bodies');
ok(str_contains($releaseBody, "ini_set('session.use_cookies', '0')"),
   "release sets session.use_cookies=0 (lifts the headers-sent gate on session_id/session_start)");
ok(str_contains($releaseBody, "ini_set('session.cache_limiter', '')"),
   "release sets session.cache_limiter='' (else session_start() fails sending Cache-Control)");
ok(!str_contains($persistBody, 'ini_set('),
   'persist does NOT call ini_set — illegal once headers are sent');

// session_start() in the write-back must take NO options array: each option is
// applied as an ini_set at start time and fails after headers are sent.
ok((bool)preg_match('/session_start\(\)\s*\)/', $persistBody) || str_contains($persistBody, 'if (!session_start())'),
   'persist calls session_start() with no options array');
ok(!preg_match('/session_start\(\s*\[/', $persistBody),
   'persist passes no options to session_start() (would re-trigger the ini gate)');

// The release must only happen when the browser already holds the cookie, so
// the write-back knows which id to reopen.
ok(str_contains($releaseBody, 'session_name()') && str_contains($releaseBody, '$_COOKIE'),
   'release only fires when the request cookie matches the session id');

// ─────────────────────────────────────────────────────────────────────────────
section('3. Nothing re-opens a session behind the guard');

// With session.use_cookies=0 a bare session_start() after roots.php would build
// a NEW empty session and replace $_SESSION — turning every request into
// "Unauthorized". These two files used to do exactly that.
foreach (['api/operations/get_po_items.php', 'migrations/status.php'] as $rel) {
    $src = file_get_contents("$root/$rel");
    $hasRoots = (bool)preg_match('/require(_once)?\s.*roots\.php/', $src);
    $live = preg_replace('~^\s*(//|\*|/\*).*$~m', '', $src);   // ignore commented-out mentions
    ok($hasRoots && !str_contains($live, 'session_start()'),
       "$rel includes roots.php and no longer calls session_start()");
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. session_destroy() paths are guarded');

$sec = file_get_contents("$root/app/bms/pos/includes/security.php");
ok(substr_count($sec, 'bmsSessionReopen') >= 2,
   'both POS destroy paths re-open the session first (destroy is a no-op when closed)');
ok(substr_count($sec, 'bmsSessionMarkDestroyed') >= 2,
   'both POS destroy paths mark the session destroyed (so it is not resurrected)');

$logout = file_get_contents("$root/logout.php");
ok(!preg_match('/require(_once)?\s.*roots\.php/', $logout),
   'logout.php does not include roots.php, so it is unaffected by the guard');

// ─────────────────────────────────────────────────────────────────────────────
section('5. Live behaviour over HTTP');

$base = getenv('BMS_TEST_URL') ?: 'http://localhost/bms';
$probe = "$root/_sessguard_probe.php";

$reachable = false;
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;

if (!$reachable) {
    skip("local server not reachable at $base — HTTP section skipped (set BMS_TEST_URL to override)");
} else {
    // Temporary probe: exercises the real bootstrap and can flush headers early.
    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
$act = $_GET['act'] ?? 'read';
if     ($act === 'set')  { $_SESSION[$_GET['k']] = $_GET['v']; }
elseif ($act === 'del')  { unset($_SESSION[$_GET['k']]); }
elseif ($act === 'csrf') { csrf_token(); }
echo json_encode(['session' => $_SESSION ?? []]), "\n";
if (!empty($_GET['flush'])) { while (ob_get_level() > 0) { ob_end_flush(); } flush(); }
PHP);

    $req = function (string $qs, string $sid) use ($base) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 20, 'ignore_errors' => true,
            'header'  => "Cookie: PHPSESSID=$sid\r\n",
        ]]);
        return (string)@file_get_contents("$base/_sessguard_probe.php?$qs", false, $ctx);
    };

    try {
        // The exact production condition: write with headers already flushed.
        $sid = 'sgtest' . bin2hex(random_bytes(8));
        $req('act=set&k=seed&v=1', $sid);                       // establish the cookie
        $req('act=set&k=late&v=KEEPME&flush=1', $sid);          // write after flush
        $back = $req('act=read', $sid);
        ok(str_contains($back, 'KEEPME'),
           'write made after the response was flushed still persists (the Sentry bug)');

        // A lazily generated CSRF token must survive, or every POST 419s.
        $sid = 'sgtest' . bin2hex(random_bytes(8));
        $req('act=read', $sid);
        $a = $req('act=csrf&flush=1', $sid);
        $b = $req('act=csrf&flush=1', $sid);
        preg_match('/"csrf_token":"([a-f0-9]+)"/', $a, $ma);
        preg_match('/"csrf_token":"([a-f0-9]+)"/', $b, $mb);
        ok(!empty($ma[1]) && ($ma[1] ?? null) === ($mb[1] ?? null),
           'csrf_token() stays stable across flushed requests');

        // Per-key merge: concurrent writers must not clobber one another.
        $sid = 'sgtest' . bin2hex(random_bytes(8));
        $req('act=set&k=base&v=1', $sid);
        foreach (['k1', 'k2', 'k3'] as $k) $req("act=set&k=$k&v=$k&flush=1", $sid);
        $back = $req('act=read', $sid);
        $kept = 0;
        foreach (['base', 'k1', 'k2', 'k3'] as $k) if (str_contains($back, "\"$k\"")) $kept++;
        ok($kept === 4, 'all keys survive successive writes (per-key merge, no clobber)');

        // Deletions must propagate too.
        $req('act=del&k=k2&flush=1', $sid);
        $back = $req('act=read', $sid);
        ok(!str_contains($back, '"k2"'), 'unset() propagates to the stored session');

        // And the whole point: the lock really is gone.
        $sid = 'sgtest' . bin2hex(random_bytes(8));
        $req('act=read', $sid);
        $start = microtime(true);
        $procs = [];
        for ($i = 0; $i < 3; $i++) {
            $procs[] = popen('curl -s -o ' . escapeshellarg(sys_get_temp_dir() . "/sg$i")
                . ' -b ' . escapeshellarg("PHPSESSID=$sid")
                . ' ' . escapeshellarg("$base/_sessguard_probe.php?act=read"), 'r');
        }
        foreach ($procs as $p) pclose($p);
        ok((microtime(true) - $start) < 10, 'concurrent same-session requests complete without queueing');

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
