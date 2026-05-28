<?php
/**
 * tools/clear_opcache.php — Manually flush PHP OPcache without restarting Apache.
 *
 * Why this exists:
 *   Production PHP usually runs with opcache.validate_timestamps=0 for
 *   speed. That means PHP serves the cached bytecode from memory and
 *   does NOT re-read files from disk after a git pull / deploy. Until
 *   Apache restarts (or opcache is explicitly flushed), users see the
 *   OLD code even though the new files are already on disk.
 *
 *   This script gives the admin a zero-downtime flush button. Hit the
 *   URL once after a deploy that doesn't visibly take effect.
 *
 * Security:
 *   - Requires an authenticated session (uses isAuthenticated() from
 *     actions/check_auth.php, auto-loaded by roots.php)
 *   - Requires admin (uses isAdmin() from core/permissions.php)
 *   - Logs the action to activity_logs so any flush is auditable
 *   - Returns plaintext (not JSON) — this is an operational tool, not
 *     part of any data API
 *
 * Usage:
 *   1. Visit  https://<site>/tools/clear_opcache.php  in the browser
 *      while logged in as an admin
 *   2. PHP-FPM workers each have their own opcache, so the script
 *      hits the in-process opcache; on multi-worker servers you may
 *      need to refresh 3-5 times to land on each worker.
 *   3. After the flush, re-load any affected page — the new code
 *      from disk will be re-compiled on first hit.
 *
 *   Auto-reload helper:
 *      https://<site>/tools/clear_opcache.php?reload=5
 *      — instructs the browser to auto-refresh the page 5 times to
 *        catch the maximum likely number of FPM workers.
 */

require_once __DIR__ . '/../roots.php';

// ── Auth gates ────────────────────────────────────────────────────────
if (!isAuthenticated()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Unauthorized — please log in first.\n");
}
if (!isAdmin()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden — admin role required to clear OPcache.\n");
}

header('Content-Type: text/plain; charset=utf-8');

$reloadCount = isset($_GET['reload']) ? max(0, min(10, (int)$_GET['reload'])) : 0;

echo "════════════════════════════════════════════════════════════\n";
echo "  BMS — PHP OPcache Manual Flush\n";
echo "  " . date('Y-m-d H:i:s') . "  (server time)\n";
echo "════════════════════════════════════════════════════════════\n\n";

// ── OPcache status BEFORE flush ───────────────────────────────────────
if (!function_exists('opcache_get_status')) {
    echo "❌ OPcache extension is NOT loaded on this server.\n";
    echo "   There's nothing to flush. New code from disk should already\n";
    echo "   be running on every request. If your deploy doesn't seem to\n";
    echo "   take effect, the issue is something else (browser cache,\n";
    echo "   stale CDN, file permissions, wrong git branch deployed).\n";
    exit(0);
}

$before = @opcache_get_status(false);
if ($before === false) {
    echo "⚠  opcache_get_status() returned false. OPcache may be disabled\n";
    echo "   in php.ini (opcache.enable=0). No cache to flush.\n";
    exit(0);
}

$before_cached_scripts = $before['opcache_statistics']['num_cached_scripts'] ?? 0;
$before_memory_used    = $before['memory_usage']['used_memory'] ?? 0;
$before_hits           = $before['opcache_statistics']['hits'] ?? 0;
$before_misses         = $before['opcache_statistics']['misses'] ?? 0;

echo "BEFORE flush:\n";
echo "  Cached scripts : " . number_format($before_cached_scripts) . "\n";
echo "  Memory used    : " . number_format($before_memory_used) . " bytes\n";
echo "  Cache hits     : " . number_format($before_hits) . "\n";
echo "  Cache misses   : " . number_format($before_misses) . "\n\n";

// ── Flush ─────────────────────────────────────────────────────────────
$ok = @opcache_reset();
if ($ok) {
    echo "✅ opcache_reset() succeeded — bytecode cache flushed.\n\n";
} else {
    echo "❌ opcache_reset() returned false. Possible causes:\n";
    echo "   - OPcache disabled (opcache.enable=0 in php.ini)\n";
    echo "   - opcache.restrict_api is set to a path that excludes this script\n";
    echo "   - PHP-FPM does not allow reset from CLI/web request\n";
    echo "   In this case, restart Apache to fully clear the cache.\n\n";
}

// ── OPcache status AFTER flush ────────────────────────────────────────
$after = @opcache_get_status(false);
if ($after !== false) {
    $after_cached_scripts = $after['opcache_statistics']['num_cached_scripts'] ?? 0;
    $after_memory_used    = $after['memory_usage']['used_memory'] ?? 0;
    echo "AFTER flush:\n";
    echo "  Cached scripts : " . number_format($after_cached_scripts) . "\n";
    echo "  Memory used    : " . number_format($after_memory_used) . " bytes\n\n";

    if ($after_cached_scripts < $before_cached_scripts) {
        $delta = $before_cached_scripts - $after_cached_scripts;
        echo "🧹 Cleared " . number_format($delta) . " cached script(s).\n";
        echo "   The next request to each PHP file will recompile it from disk.\n\n";
    } else {
        echo "ℹ  Cached-script count unchanged. PHP-FPM may have multiple worker\n";
        echo "   processes — refresh this page a few more times to flush all of\n";
        echo "   them, or use the ?reload=N parameter (see below).\n\n";
    }
}

// ── Audit log ─────────────────────────────────────────────────────────
if (function_exists('logActivity') && isset($pdo) && isset($_SESSION['user_id'])) {
    @logActivity(
        $pdo,
        $_SESSION['user_id'],
        'CACHE_FLUSH',
        '[OPcache] Admin manually flushed PHP opcache via tools/clear_opcache.php'
    );
    echo "📝 Logged to activity_logs.\n\n";
}

// ── Hints ─────────────────────────────────────────────────────────────
echo "Next steps:\n";
echo "  1. Hard-refresh any page that still shows stale output (Ctrl+Shift+R).\n";
echo "  2. On multi-worker servers (PHP-FPM), re-load THIS page 3-5 times so\n";
echo "     the flush lands on every worker. Or pass ?reload=5 (see below).\n";
echo "  3. If pages STILL show old code after that, ask your hosting to run\n";
echo "     `sudo systemctl restart apache2` once — that's the definitive\n";
echo "     cache reset.\n\n";

// ── Auto-reload sequence (browser-only) ───────────────────────────────
if ($reloadCount > 0) {
    $nextReload = $reloadCount - 1;
    $nextUrl = strtok($_SERVER['REQUEST_URI'], '?') . ($nextReload > 0 ? '?reload=' . $nextReload : '');
    echo "🔄 Auto-reload sequence active. $reloadCount refresh(es) remaining.\n";
    echo "   This page will reload itself in 2 seconds.\n";
    // Use plain HTML meta-refresh (works without JS, won't fire on raw curl)
    echo "\n<meta http-equiv=\"refresh\" content=\"2;url=" . htmlspecialchars($nextUrl, ENT_QUOTES) . "\">\n";
} else {
    echo "Tip: append ?reload=5 to the URL to auto-cycle 5 flushes (catches\n";
    echo "     multiple PHP-FPM workers).\n";
}
