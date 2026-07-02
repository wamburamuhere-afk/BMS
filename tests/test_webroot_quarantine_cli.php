<?php
/**
 * Webroot quarantine regression guard.
 *
 * Ensures the webroot stays clean after the 2026-07-01 quarantine of 284
 * one-off scripts into scripts/legacy/:
 *   1. The ONLY top-level .php files are the 15 approved entry points.
 *   2. Every approved entry point still exists (over-deletion guard).
 *   3. .htaccess denies HTTP access to scripts/, tests/, cron/, migrations/.
 *   4. migrations/runner.php is CLI-only.
 *   5. Every kept root ajax_*.php endpoint performs a session auth check.
 *
 * Static checks only — no DB required. Run: php tests/test_webroot_quarantine_cli.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);

$passes = 0; $failures = 0;
function pass($m) { global $passes;   $passes++;   echo "  \xE2\x9C\x85 $m\n"; }
function fail($m) { global $failures; $failures++; echo "  \xE2\x9D\x8C $m\n"; }
function section($t) { echo "\n\xE2\x94\x80\xE2\x94\x80 $t \xE2\x94\x80\xE2\x94\x80\n"; }

$keepers = [
    'roots.php', 'index.php', 'helpers.php', 'header.php', 'footer.php',
    'login.php', 'logout.php', 'unauthorized.php', 'register.php',
    'ajax_get_warehouse.php', 'ajax_delete_warehouse.php',
    'ajax_toggle_warehouse_status.php', 'ajax_get_transfer_items.php',
    'print_transfer.php',
    'calculate_penalties.php', // possible server crontab target; has own CLI guard
];

// ── 1. Webroot only contains the approved entry points ────────────────────
section('Webroot contains only approved entry points');
$rootPhp = array_map('basename', glob("$root/*.php"));
$strays  = array_diff($rootPhp, $keepers);
if (empty($strays)) {
    pass('No stray .php files at webroot top level (' . count($rootPhp) . ' approved files)');
} else {
    fail('Stray .php files at webroot: ' . implode(', ', $strays)
       . ' — one-off scripts belong in migrations/ (schema/data) or scratch/ (experiments)');
}

// ── 2. Every approved entry point still exists ────────────────────────────
section('Approved entry points all present');
$missing = array_filter($keepers, fn($f) => !is_file("$root/$f"));
if (empty($missing)) {
    pass('All ' . count($keepers) . ' approved entry points exist');
} else {
    fail('Missing entry points: ' . implode(', ', $missing));
}

// ── 3. .htaccess denies non-web directories ───────────────────────────────
section('.htaccess HTTP lockdown');
$ht = @file_get_contents("$root/.htaccess") ?: '';
if (preg_match('/RewriteRule\s+\^\(scripts\|tests\|cron\|migrations\)\(\/\|\$\)\s+-\s+\[F,L\]/', $ht)) {
    pass('.htaccess denies scripts/, tests/, cron/, migrations/ over HTTP');
} else {
    fail('.htaccess is missing the deny rule for scripts|tests|cron|migrations');
}
if (strpos($ht, 'migrations/status\.php') !== false) {
    pass('.htaccess keeps migrations/status.php reachable (login-guarded dashboard)');
} else {
    fail('.htaccess no longer exempts migrations/status.php');
}

// ── 4. migrations/runner.php is CLI-only ──────────────────────────────────
section('Migration runner CLI guard');
$runner = @file_get_contents("$root/migrations/runner.php") ?: '';
if (strpos($runner, "PHP_SAPI !== 'cli'") !== false) {
    pass('migrations/runner.php refuses non-CLI execution');
} else {
    fail('migrations/runner.php is missing the PHP_SAPI CLI guard');
}

// ── 5. Kept root ajax endpoints authenticate ──────────────────────────────
section('Root ajax endpoints require a session');
foreach ($keepers as $f) {
    if (strpos($f, 'ajax_') !== 0) continue;
    $src = @file_get_contents("$root/$f") ?: '';
    if (strpos($src, "\$_SESSION['user_id']") !== false) {
        pass("$f checks \$_SESSION['user_id']");
    } else {
        fail("$f has no session auth check");
    }
}

// ── Result ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Passed: $passes   Failed: $failures\n";
echo $failures > 0 ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failures > 0 ? 1 : 0);
