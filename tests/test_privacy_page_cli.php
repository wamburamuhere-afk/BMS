<?php
/**
 * Public Privacy Policy page — CLI test
 *   php tests/test_privacy_page_cli.php
 *
 * /privacy must render through the real router without a session: Google checks
 * this link for the BMS Backups OAuth app (backup_plan.md §4). Exit 0 = pass.
 */

// ─── Router worker ──────────────────────────────────────────────────────────
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REQUEST_URI']    = (string)$argv[2];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = '';
    require_once __DIR__ . '/../roots.php';
    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

$html = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --route /privacy 2>&1');
ok(str_contains($html, '<h1>Privacy Policy</h1>'), '/privacy renders through the router with no session');
ok(!preg_match('/(Warning|Notice|Fatal error|Deprecated|Uncaught)/', $html), 'no PHP warnings or errors');
ok(!str_contains($html, 'name="password"'), 'visitor is not sent to the login form');
ok(str_contains($html, 'drive.file') && str_contains($html, 'Limited Use'), 'states the Google Drive permission and Limited Use');
ok(str_contains($html, 'bjptechnologieslimited@gmail.com'), 'gives a contact address');

$src = (string)file_get_contents(dirname(__DIR__) . '/privacy.php');
$code = preg_replace('~/\*.*?\*/~s', '', substr($src, 0, (int)strpos($src, '?>')));
ok($code !== '' && !preg_match('/require|include|session_start|PDO/', $code), 'static page: no session, database or app code');

echo "\nPasses:   $pass\nFailures: $fail\n";
exit($fail === 0 ? 0 : 1);
