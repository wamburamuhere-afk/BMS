<?php
/**
 * tests/test_platform_ai_phase3_cli.php — Platform AI, Phase 3 (platform_ai_plan.md):
 * Superadmin → AI → Settings page, actions/superadmin_ai.php (save_settings, test),
 * route + nav. Uses the FAKE provider (AI_TEST_FAKE_PROVIDER) — never a real one.
 *
 *   php tests/test_platform_ai_phase3_cli.php
 *
 * CLI ONLY. Snapshots and restores platform_settings and platform_ai_usage.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

if (($argv[1] ?? '') === '--endpoint') {
    $file = (string)$argv[2];
    $post = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $_SERVER['REQUEST_METHOD'] = (string)($argv[4] ?? 'POST');
    $_SERVER['HTTP_HOST']      = (string)($argv[5] ?? 'localhost');
    $_SERVER['REQUEST_URI']    = '/' . $file;
    if ((string)($argv[6] ?? '0') === '1') {
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

if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = (string)parse_url((string)$argv[3], PHP_URL_QUERY);
    parse_str($_SERVER['QUERY_STRING'], $_GET);
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

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/ai_platform.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Platform AI, Phase 3\n";

$root = dirname(__DIR__);
$c    = getControlPdo();
aiPlatformSchema($c, controlDbName());
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_pai3_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$FAKE = $tmp . DIRECTORY_SEPARATOR . 'fake.json';
$CAP  = $tmp . DIRECTORY_SEPARATOR . 'capture.jsonl';
putenv('AI_TEST_FAKE_PROVIDER=' . $FAKE);
putenv('AI_TEST_FAKE_CAPTURE=' . $CAP);

$snapPS = $c->query("SELECT setting_key, setting_value, updated_by FROM platform_settings")->fetchAll(PDO::FETCH_ASSOC);
$maxPU  = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM platform_ai_usage")->fetchColumn();
$maxLog = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM tenant_admin_log")->fetchColumn();
register_shutdown_function(function () use ($c, $snapPS, $maxPU, $maxLog, $tmp) {
    $c->exec("DELETE FROM platform_settings");
    $i = $c->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)");
    foreach ($snapPS as $r) $i->execute([$r['setting_key'], $r['setting_value'], $r['updated_by']]);
    $c->prepare("DELETE FROM platform_ai_usage WHERE id > ?")->execute([$maxPU]);
    $c->prepare("DELETE FROM tenant_admin_log WHERE id > ? AND action IN ('ai_settings','ai_test')")->execute([$maxLog]);
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});
$c->exec("DELETE FROM platform_settings WHERE setting_key LIKE 'ai\\_platform\\_%'");

function endpoint(string $file, array $post, array $o = []): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint ' . escapeshellarg($file) . ' '
         . escapeshellarg(base64_encode(json_encode($post))) . ' ' . escapeshellarg($o['method'] ?? 'POST') . ' '
         . escapeshellarg($o['host'] ?? SA_HOST) . ' ' . escapeshellarg(!empty($o['auth']) ? '1' : '0');
    $out = []; exec($cmd . ' 2>&1', $out);
    $j = implode("\n", $out);
    return (str_contains($j, 'Fatal error') || str_contains($j, 'Parse error')) ? 'WORKER_CRASHED: ' . $j : $j;
}
function refused(string $out, string $expect = ''): bool {
    if (str_contains($out, 'WORKER_CRASHED') || str_contains($out, '"success":true')) return false;
    return $expect === '' || str_contains($out, $expect);
}
function route(string $host, string $uri): string {
    $out = []; exec('php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg($host) . ' ' . escapeshellarg($uri) . ' 2>&1', $out);
    return implode("\n", $out);
}
function fresh(): void { unset($GLOBALS['__bms_platform_settings_cache']); }
$ACT = 'actions/superadmin_ai.php';
$KEY = 'sk-plat-SECRETVALUE123456789';

section('1. Lint');
foreach (['core/ai_platform.php', 'core/ai_service.php', 'actions/superadmin_ai.php', 'app/superadmin/ai.php', 'core/superadmin_auth.php', 'core/superadmin_ui.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

section('2. Guards');
$payload = ['action' => 'save_settings', 'enabled' => '1', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'base_url' => '',
            'temperature' => '0.4', 'tenant_cap' => '5', 'global_cap' => '100', 'api_key' => $KEY];
ok('refuses without a superadmin session', refused(endpoint($ACT, $payload), 'session has ended'));
ok('refuses GET', refused(endpoint($ACT, $payload, ['method' => 'GET', 'auth' => true]), 'Method not allowed'));
ok('refuses a wrong CSRF token', refused(endpoint($ACT, $payload + ['_csrf' => 'wrong-token'], ['auth' => true])));
ok('refuses on a tenant host', refused(endpoint($ACT, $payload, ['host' => 'sometenant.' . $BASE, 'auth' => true])));
ok('refuses an unknown action', refused(endpoint($ACT, ['action' => 'drop'] + $payload, ['auth' => true]), 'Unknown action'));
fresh();
ok('nothing saved by refused requests', aiPlatformSettings()['has_key'] === false);

section('3. Save settings');
$r = endpoint($ACT, $payload, ['auth' => true]);
ok('POSITIVE CONTROL: operator saves settings + key', str_contains($r, '"success":true'), substr($r, 0, 300));
ok('response never contains the key', !str_contains($r, 'SECRETVALUE'));
fresh();
$s = aiPlatformSettings(true);
ok('saved: enabled, model, caps', $s['enabled'] && $s['model'] === 'gpt-4o-mini' && $s['tenant_cap'] === 5.0 && $s['global_cap'] === 100.0);
ok('saved key decrypts', $s['api_key'] === $KEY);
$log = $c->query("SELECT detail FROM tenant_admin_log WHERE action = 'ai_settings' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('audit entry written', is_string($log) && str_contains($log, 'key changed=yes'), (string)$log);
ok('audit entry has no key', !str_contains((string)$log, 'SECRETVALUE'));
$r = endpoint($ACT, array_merge($payload, ['base_url' => 'http://10.0.0.1/v1', 'api_key' => '']), ['auth' => true]);
ok('private/plain-http base URL refused with a clear message', refused($r, 'https'), $r);
$r = endpoint($ACT, array_merge($payload, ['model' => 'x;rm -rf', 'api_key' => '']), ['auth' => true]);
ok('bad model refused', refused($r, 'model'));
fresh();
ok('rejected saves changed nothing', aiPlatformSettings()['model'] === 'gpt-4o-mini' && aiPlatformSettings()['base_url'] === '');
$r = endpoint($ACT, array_merge($payload, ['api_key' => '', 'tenant_cap' => '7.5']), ['auth' => true]);
fresh();
ok('blank key keeps the saved key', str_contains($r, '"success":true') && aiPlatformSettings(true)['api_key'] === $KEY && aiPlatformSettings()['tenant_cap'] === 7.5);

section('4. Test connection (fake provider)');
file_put_contents($FAKE, json_encode(['status' => 200, 'body' => ['choices' => [['message' => ['content' => 'OK']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1]]]));
@unlink($CAP);
$r = endpoint($ACT, ['action' => 'test'], ['auth' => true]);
ok('test passes', str_contains($r, '"success":true') && str_contains($r, 'Connected'), $r);
$cap = json_decode((string)@file_get_contents($CAP), true);
ok('used the platform key', in_array('Authorization: Bearer ' . $KEY, $cap['headers'] ?? [], true));
$row = $c->query("SELECT * FROM platform_ai_usage WHERE feature = 'test' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok('test metered with no tenant', $row && $row['tenant_id'] === null && $row['status'] === 'ok');
file_put_contents($FAKE, json_encode(['status' => 401, 'body' => ['error' => ['message' => "Incorrect API key provided: $KEY"]]]));
$r = endpoint($ACT, ['action' => 'test'], ['auth' => true]);
ok('failed test reports the provider reason', refused($r, 'Incorrect API key'), $r);
ok('failed test never echoes the key', !str_contains($r, 'SECRETVALUE'));
ok('test refused without a session', refused(endpoint($ACT, ['action' => 'test']), 'session has ended'));
$ins = $c->prepare("INSERT INTO platform_ai_usage (tenant_id, feature, status) VALUES (NULL, 'test', 'ok')");
for ($i = 0; $i < 10; $i++) $ins->execute();
ok('test is rate limited (10/min)', refused(endpoint($ACT, ['action' => 'test'], ['auth' => true]), 'Too many tests'));

section('5. Key removal rules');
$r = endpoint($ACT, array_merge($payload, ['api_key' => '', 'remove_key' => '1']), ['auth' => true]);
ok('cannot remove the key while enabled', refused($r, 'Disable AI'));
$r = endpoint($ACT, array_merge($payload, ['enabled' => '0', 'api_key' => '', 'remove_key' => '1']), ['auth' => true]);
fresh();
ok('disable + remove clears it', str_contains($r, '"success":true') && aiPlatformSettings()['has_key'] === false);
endpoint($ACT, $payload, ['auth' => true]);
fresh();

section('6. Page through the real router');
$html = route(SA_HOST, '/ai');
ok('renders with no PHP fatal', !str_contains($html, 'Fatal error') && !str_contains($html, 'Uncaught') && str_contains($html, 'Save AI settings'), substr($html, 0, 300));
ok('AI nav item active', (bool)preg_match('~nav-link active[^>]*>\s*<i class="bi bi-stars~', $html));
ok('page never contains the key or its ciphertext', !str_contains($html, 'SECRETVALUE') && !str_contains($html, (string)$c->query("SELECT setting_value FROM platform_settings WHERE setting_key='ai_platform_api_key_enc'")->fetchColumn()));
ok('key field is a password input with new-password autocomplete', str_contains($html, 'type="password"') && str_contains($html, 'autocomplete="new-password"'));
ok('shows "key saved" state, not the value', str_contains($html, 'A key is saved'));
ok('posts to the AI action with CSRF', str_contains($html, 'superadmin_ai.php') && str_contains($html, 'SA_CSRF_TOKEN'));
ok('saUrl maps ai', saUrl('ai') !== '' && isset(superadminRouteMap()['ai']));
ok('AI link shown on other panel pages', str_contains(route(SA_HOST, '/settings'), 'bi-stars'));
ok('page source requires a superadmin', str_contains((string)file_get_contents("$root/app/superadmin/ai.php"), 'requireSuperadmin();'));

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
