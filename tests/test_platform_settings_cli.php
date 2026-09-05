<?php
/**
 * tests/test_platform_settings_cli.php — Phase A acceptance gate (superadmin
 * professional-gap plan): platform_settings + platform email foundation.
 *
 *   php tests/test_platform_settings_cli.php
 *
 * Proves:
 *   1. core/platform_settings.php — round-trip, defaults, encrypted password,
 *      never touches a tenant's own $pdo/system_settings
 *   2. actions/superadmin_platform_settings.php — guards (session/GET/CSRF/
 *      tenant-host) and validation, with a positive control
 *   3. actions/superadmin_test_platform_email.php — same guard chain, and
 *      refuses cleanly before ever opening a socket when host/username is missing
 *   4. app/superadmin/settings.php renders through the real router, with the
 *      Settings nav item marked active and the saved values pre-filled
 *   5. the password is genuinely encrypted at rest, not plaintext
 *
 * CLI ONLY. Snapshots whatever is in platform_settings, runs against it, and
 * restores the exact prior state afterwards — this table is real operator
 * configuration, not a throwaway per-test fixture.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Endpoint worker — runs a real action file with real $_SERVER/$_POST ───
if (($argv[1] ?? '') === '--endpoint') {
    $file   = (string)$argv[2];
    $post   = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $method = (string)($argv[4] ?? 'POST');
    $host   = (string)($argv[5] ?? 'localhost');
    $auth   = (string)($argv[6] ?? '0') === '1';

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_URI']    = '/' . $file;

    if ($auth) {
        require_once __DIR__ . '/../core/superadmin_auth.php';
        require_once __DIR__ . '/../helpers.php';
        superadminSessionReady();
        require_once __DIR__ . '/../core/control_db.php';
        $row = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
        if ($row) $_SESSION['superadmin_id'] = (int)$row['id'];
        if (!array_key_exists('_csrf', $post)) $post['_csrf'] = csrf_token();
    }

    $_POST = $post;
    require __DIR__ . '/../' . $file;
    exit(0);
}

// ─── Router worker — renders a page through the real handleRoute() ─────────
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = '';

    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    superadminSessionReady();
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];

    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

// ─── Runner ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/crypto.php';
require_once __DIR__ . '/../core/platform_settings.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Phase A: platform settings + email foundation\n";

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

// Snapshot + restore: this table holds real operator configuration.
$snapshot = $c->query("SELECT setting_key, setting_value, updated_by FROM platform_settings")->fetchAll();
register_shutdown_function(function () use ($c, $snapshot) {
    $c->exec("DELETE FROM platform_settings");
    $ins = $c->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)");
    foreach ($snapshot as $row) $ins->execute([$row['setting_key'], $row['setting_value'], $row['updated_by']]);
});
$c->exec("DELETE FROM platform_settings");
unset($GLOBALS['__bms_platform_settings_cache']);

function endpoint(string $file, array $post, array $server = []): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint '
         . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($post))) . ' '
         . escapeshellarg($server['method'] ?? 'POST') . ' '
         . escapeshellarg($server['host'] ?? SA_HOST) . ' '
         . escapeshellarg(!empty($server['auth']) ? '1' : '0');
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    $joined = implode("\n", $out);
    if (str_contains($joined, 'Parse error') || str_contains($joined, 'Fatal error')) $joined = 'WORKER_CRASHED: ' . $joined;
    return ['out' => $joined, 'rc' => $rc];
}
function refused(array $r, string $expect = ''): bool {
    if (str_contains($r['out'], 'WORKER_CRASHED')) return false;
    if (str_contains($r['out'], '"success":true')) return false;
    return $expect === '' ? true : str_contains($r['out'], $expect);
}
function route(string $host, string $uri): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg($host) . ' ' . escapeshellarg($uri);
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. core/platform_settings.php — round-trip, defaults, never touches a tenant $pdo');

ok('empty state: default is returned, not a crash', getPlatformSetting('smtp_host', 'DEFAULT') === 'DEFAULT');
$m0 = platformMailerOpts();
ok('empty state: not configured', $m0['configured'] === false);

setPlatformSetting('platform_name', 'Test Platform Co');
setPlatformSetting('smtp_host', 'smtp.example.test');
setPlatformSetting('smtp_port', '2525');
setPlatformSetting('smtp_username', 'plat_user');
setPlatformSetting('smtp_password_enc', encryptSecret('sup3r-secret'));
setPlatformSetting('smtp_encryption', 'ssl');
setPlatformSetting('from_email', 'noreply@example.test');
setPlatformSetting('from_name', 'Example Platform');

ok('platform_name round-trips', getPlatformSetting('platform_name') === 'Test Platform Co');
ok('write is visible immediately (cache invalidation works)', getPlatformSetting('smtp_host') === 'smtp.example.test');

$m1 = platformMailerOpts();
ok('now configured', $m1['configured'] === true);
ok('password decrypts correctly', $m1['opts']['smtp']['password'] === 'sup3r-secret');
ok('port resolved as int', $m1['opts']['smtp']['port'] === 2525);
ok('encryption resolved', $m1['opts']['smtp']['encryption'] === 'ssl');
ok('from_email resolved', $m1['opts']['from_email'] === 'noreply@example.test');

// Not a $GLOBALS['pdo'] runtime check: this runner process's own bootstrap
// (includes/config.php, required by every CLI test) sets that global for
// unrelated reasons, so its mere presence proves nothing. What actually
// matters is that platform_settings.php's SOURCE never references it.
$src = file_get_contents(__DIR__ . '/../core/platform_settings.php');
$srcNoComments = preg_replace('~/\*.*?\*/~s', '', $src);
ok('source never references the tenant global $pdo', strpos($srcNoComments, '$pdo') === false);

// Security: the stored value is genuinely encrypted, not plaintext.
$raw = $c->query("SELECT setting_value FROM platform_settings WHERE setting_key='smtp_password_enc'")->fetchColumn();
ok('password stored encrypted, not plaintext', $raw !== 'sup3r-secret' && str_starts_with((string)$raw, 'enc:v1:'));
ok('plaintext password does not appear anywhere in the stored row', strpos((string)$raw, 'sup3r-secret') === false);

// ─────────────────────────────────────────────────────────────────────────────
section('2. actions/superadmin_platform_settings.php — guards + validation');

$r = endpoint('actions/superadmin_platform_settings.php',
    ['action' => 'save_branding', 'platform_name' => 'Positive Control Co'], ['auth' => true]);
ok('POSITIVE CONTROL: authenticated operator can save branding', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
// The subprocess wrote to the DB; THIS process's own settings cache (warmed by
// section 1's in-process writes) is now stale relative to it — unset it so the
// next read goes back to the DB, exactly as a fresh request would.
unset($GLOBALS['__bms_platform_settings_cache']);
ok('  ...and it really persisted', getPlatformSetting('platform_name') === 'Positive Control Co');

$r = endpoint('actions/superadmin_platform_settings.php', ['action' => 'save_branding', 'platform_name' => 'X']);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_settings.php', ['action' => 'save_branding', 'platform_name' => 'X'],
    ['method' => 'GET', 'auth' => true]);
ok('refuses GET even when authenticated', refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_settings.php',
    ['action' => 'save_branding', 'platform_name' => 'X', '_csrf' => 'wrong-token'], ['auth' => true]);
ok('refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_settings.php', ['action' => 'save_branding', 'platform_name' => 'X'],
    ['host' => 'sometenant.' . $BASE, 'auth' => true]);
ok('refused from a TENANT host even when authenticated', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_settings.php', ['action' => 'save_branding', 'platform_name' => ''], ['auth' => true]);
ok('rejects an empty platform name', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_settings.php',
    ['action' => 'save_email', 'smtp_host' => '', 'smtp_username' => 'u', 'smtp_port' => '587'], ['auth' => true]);
ok('rejects a missing SMTP host', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_settings.php',
    ['action' => 'save_email', 'smtp_host' => 'h', 'smtp_username' => 'u', 'smtp_port' => 'not-a-number'], ['auth' => true]);
ok('rejects a non-numeric port', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_settings.php',
    ['action' => 'save_email', 'smtp_host' => 'h', 'smtp_username' => 'u', 'smtp_port' => '587', 'from_email' => 'not-an-email'],
    ['auth' => true]);
ok('rejects an invalid From Email', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_settings.php', [
    'action' => 'save_email', 'smtp_host' => 'smtp.real.test', 'smtp_username' => 'realuser',
    'smtp_port' => '465', 'smtp_encryption' => 'ssl', 'smtp_password' => 'newpass123',
    'from_email' => 'real@example.test', 'from_name' => 'Real Sender',
], ['auth' => true]);
ok('a full, valid save succeeds', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
ok('  ...and password blank-means-keep does NOT clear an existing one on a later save', true); // covered next:

$r = endpoint('actions/superadmin_platform_settings.php', [
    'action' => 'save_email', 'smtp_host' => 'smtp.real.test', 'smtp_username' => 'realuser',
    'smtp_port' => '465', 'smtp_encryption' => 'ssl', 'smtp_password' => '',   // blank on purpose
    'from_email' => 'real@example.test', 'from_name' => 'Real Sender',
], ['auth' => true]);
ok('saving again with a BLANK password keeps the previous one', str_contains($r['out'], '"success":true'));
unset($GLOBALS['__bms_platform_settings_cache']);   // same staleness fix as above
$mAfter = platformMailerOpts();
ok('  ...verified: the old password still decrypts correctly', $mAfter['opts']['smtp']['password'] === 'newpass123');

// ─────────────────────────────────────────────────────────────────────────────
section('3. actions/superadmin_test_platform_email.php — same guard chain, fast-fail validation');

$r = endpoint('actions/superadmin_test_platform_email.php', ['smtp_host' => 'h', 'smtp_username' => 'u']);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_test_platform_email.php', ['smtp_host' => 'h', 'smtp_username' => 'u'],
    ['method' => 'GET', 'auth' => true]);
ok('refuses GET even when authenticated', refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_test_platform_email.php', ['smtp_host' => 'h', 'smtp_username' => 'u', '_csrf' => 'bad'], ['auth' => true]);
ok('refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_test_platform_email.php', ['smtp_host' => 'h', 'smtp_username' => 'u'],
    ['host' => 'sometenant.' . $BASE, 'auth' => true]);
ok('refused from a TENANT host', refused($r), substr($r['out'], 0, 200));

// Deliberately no host/username — must be rejected BEFORE any socket is opened
// (fast, deterministic; never attempts a real SMTP connection in this suite).
$r = endpoint('actions/superadmin_test_platform_email.php', ['smtp_host' => '', 'smtp_username' => ''], ['auth' => true]);
ok('rejects a blank host/username before attempting to send', refused($r, 'before testing'), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('4. Routing — /settings resolves through the real router, pre-filled, nav active');

ok('route map has a settings entry', isset(superadminRouteMap()['settings']));
ok('settings.php exists on disk', is_file(superadminRouteMap()['settings']));

$html = route(SA_HOST, '/settings');
ok('renders with no PHP fatal', !str_contains($html, 'Fatal error') && !str_contains($html, 'Uncaught'));
ok('page title present', str_contains($html, 'Platform Settings'));
ok('Settings nav item marked active', str_contains($html, 'nav-link active') && str_contains($html, '>Settings<'));
ok('branding value pre-filled', str_contains($html, 'value="Positive Control Co"') || str_contains($html, htmlspecialchars(getPlatformSetting('platform_name'), ENT_QUOTES)));
ok('SMTP host pre-filled', str_contains($html, 'smtp.real.test'));
ok('password field left blank (never echoes the secret back)', !str_contains($html, 'newpass123'));
ok('shows "Configured" badge once host+username are set', str_contains($html, 'Configured'));

$htmlTenant = route('sometenant.' . $BASE, '/settings');
ok('a tenant host gets no panel page (router does not match)', !str_contains($htmlTenant, 'Platform Settings'));

echo "\n---\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
