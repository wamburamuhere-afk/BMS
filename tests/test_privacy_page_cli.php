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
ok(!str_contains($src, 'session_start'), 'needs no session');
ok(str_contains($src, 'htmlspecialchars'), 'profile values are escaped before output');

// Profile-driven: set this tenant's Company Profile, render, then restore it.
require_once dirname(__DIR__) . '/includes/config.php';
$old = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name','company_email')")->fetchAll(PDO::FETCH_KEY_PAIR);
$set = function ($k, $v) use ($pdo) {
    $u = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    $u->execute([$v, $k]);
    if ($u->rowCount() === 0 && !$pdo->query("SELECT 1 FROM system_settings WHERE setting_key = " . $pdo->quote($k))->fetchColumn()) {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public) VALUES (?, ?, 'company', 1)")->execute([$k, $v]);
    }
};
$render = fn() => (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --route /privacy 2>&1');
try {
    $set('company_name', 'Acme <b>Traders</b> Ltd'); $set('company_email', 'privacy@acme.example');
    $html = $render();
    ok(str_contains($html, 'Acme &lt;b&gt;Traders&lt;/b&gt; Ltd'), 'shows the company name from Company Profile, escaped');
    ok(str_contains($html, 'privacy@acme.example'), 'shows the company email from Company Profile');
    ok(str_contains($html, 'BMS provider: BJP Technologies'), 'names BJP as provider when the company differs');
    $set('company_name', 'My Company'); $set('company_email', 'info@example.com');
    $html = $render();
    ok(!str_contains($html, 'My Company') && str_contains($html, 'bjptechnologieslimited@gmail.com'), 'placeholder profile falls back to BJP');
} finally {
    foreach (['company_name', 'company_email'] as $k) {
        if (array_key_exists($k, $old)) { $set($k, $old[$k]); }
        else { $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]); }
    }
}

echo "\nPasses:   $pass\nFailures: $fail\n";
exit($fail === 0 ? 0 : 1);
