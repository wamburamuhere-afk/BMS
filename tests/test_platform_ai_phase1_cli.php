<?php
/**
 * tests/test_platform_ai_phase1_cli.php — Platform AI, Phase 1 (platform_ai_plan.md):
 * control-DB schema, platform settings + validation, base-URL SSRF guard, error
 * sanitising, per-tenant policy, key-source resolver, caps and metering.
 *
 *   php tests/test_platform_ai_phase1_cli.php
 *
 * CLI ONLY. Snapshots platform_settings, tenant_ai_policy and platform_ai_usage
 * and restores them afterwards. Never calls an AI provider.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/ai_platform.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Platform AI, Phase 1\n";

$root = dirname(__DIR__);
$c = getControlPdo();
$db = controlDbName();

section('1. Schema');
$t1 = aiPlatformSchema($c, $db);
$t2 = aiPlatformSchema($c, $db);
ok('aiPlatformSchema returns both tables', $t1 === ['platform_ai_usage', 'tenant_ai_policy']);
ok('aiPlatformSchema is idempotent', $t1 === $t2);
$cols = fn($t) => $c->query("SELECT column_name FROM information_schema.columns WHERE table_schema=" . $c->quote($db) . " AND table_name=" . $c->quote($t))->fetchAll(PDO::FETCH_COLUMN);
ok('platform_ai_usage has metering columns', !array_diff(['tenant_id','user_id','feature','provider','model','prompt_tokens','completion_tokens','est_cost','status','error','created_at'], $cols('platform_ai_usage')));
ok('tenant_ai_policy has mode + monthly_cap', !array_diff(['tenant_id','mode','monthly_cap','updated_by','updated_at'], $cols('tenant_ai_policy')));
$setup = (string)file_get_contents($root . '/scripts/setup_control_db.php');
ok('setup_control_db.php creates the tables on every deploy', strpos($setup, 'aiPlatformSchema($admin, $controlDb)') !== false);

// ── snapshots ────────────────────────────────────────────────────────────────
$snapSettings = $c->query("SELECT setting_key, setting_value, updated_by FROM platform_settings")->fetchAll(PDO::FETCH_ASSOC);
$snapPolicy   = $c->query("SELECT * FROM tenant_ai_policy")->fetchAll(PDO::FETCH_ASSOC);
$maxUsage     = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM platform_ai_usage")->fetchColumn();
register_shutdown_function(function () use ($c, $snapSettings, $snapPolicy, $maxUsage) {
    $c->exec("DELETE FROM platform_settings");
    $ins = $c->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)");
    foreach ($snapSettings as $r) $ins->execute([$r['setting_key'], $r['setting_value'], $r['updated_by']]);
    $c->exec("DELETE FROM tenant_ai_policy");
    $ip = $c->prepare("INSERT INTO tenant_ai_policy (tenant_id, mode, monthly_cap, updated_by, updated_at) VALUES (?,?,?,?,?)");
    foreach ($snapPolicy as $r) $ip->execute([$r['tenant_id'], $r['mode'], $r['monthly_cap'], $r['updated_by'], $r['updated_at']]);
    $c->prepare("DELETE FROM platform_ai_usage WHERE id > ?")->execute([$maxUsage]);
});
$c->exec("DELETE FROM platform_settings WHERE setting_key LIKE 'ai\\_platform\\_%'");
unset($GLOBALS['__bms_platform_settings_cache']);

section('2. Defaults');
$d = aiPlatformSettings();
ok('ships disabled', $d['enabled'] === false);
ok('default provider openai, no model, no key', $d['provider'] === 'openai' && $d['model'] === '' && $d['has_key'] === false);
ok('default tenant cap 5 USD, global cap unlimited', $d['tenant_cap'] === 5.0 && $d['global_cap'] === 0.0);
ok('settings never expose the key unless asked', !array_key_exists('api_key', $d));
ok('aiPlatformConfigured() false when unset', aiPlatformConfigured() === false);

section('3. Base-URL SSRF guard');
$cases = [
    ['', true], ['https://8.8.8.8/v1', true], ['https://1.1.1.1:8443/api/', true],
    ['http://8.8.8.8/v1', false], ['ftp://8.8.8.8', false], ['8.8.8.8', false],
    ['https://127.0.0.1/v1', false], ['https://10.1.2.3', false], ['https://172.16.0.5', false],
    ['https://192.168.1.1', false], ['https://169.254.169.254/latest', false], ['https://100.64.1.1', false],
    ['https://0.0.0.0', false], ['https://[::1]/', false], ['https://[::ffff:127.0.0.1]/', false],
    ['https://user:pw@8.8.8.8', false], ['https://8.8.8.8/#x', false], ['https://localhost/v1', false],
    ["https://8.8.8.8/\nv1", false], ['https://exa mple.com', false],
];
foreach ($cases as [$u, $want]) {
    $r = aiSafeBaseUrl($u);
    ok(($want ? 'accepts ' : 'rejects ') . json_encode($u), $r['ok'] === $want, (string)$r['error']);
}
$r = aiSafeBaseUrl('https://8.8.8.8:8443/v1/');
ok('normalises URL and returns pinned host/port/ips', $r['url'] === 'https://8.8.8.8:8443/v1' && $r['host'] === '8.8.8.8' && $r['port'] === 8443 && $r['ips'] === ['8.8.8.8']);

section('4. Error sanitising');
$key = 'sk-proj-ABCDEFGH1234567890xyz';
$s = aiSanitizeError("Incorrect API key provided: $key. Authorization: Bearer $key", [$key]);
ok('removes the key value', strpos($s, 'ABCDEFGH1234567890') === false, $s);
$s = aiSanitizeError('Could not resolve https://generativelanguage.googleapis.com/v1beta/models/x:generateContent?key=AIzaSyA1234567890abcdefghijk&alt=json');
ok('removes ?key= query parameter', strpos($s, 'AIzaSyA1234567890') === false && strpos($s, 'key=[redacted]') !== false, $s);
$s = aiSanitizeError('x-api-key: sk-ant-api03-SECRETSECRETSECRET bad');
ok('removes x-api-key header value', strpos($s, 'SECRETSECRET') === false, $s);
ok('truncates to 255 chars', mb_strlen(aiSanitizeError(str_repeat('a', 1000))) === 255);
ok('handles null', aiSanitizeError(null) === '');

section('5. Validation');
$base = ['enabled' => '0', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'base_url' => '', 'temperature' => '0.4', 'tenant_cap' => '5', 'global_cap' => '0'];
[$cl, $er] = aiPlatformValidate($base);
ok('valid form passes', $er === [], json_encode($er));
foreach ([
    'provider' => ['provider' => 'evil'], 'model' => ['model' => 'gpt 4; drop'],
    'base_url' => ['base_url' => 'http://10.0.0.1'], 'temperature' => ['temperature' => '2'],
    'tenant_cap' => ['tenant_cap' => '-1'], 'global_cap' => ['global_cap' => 'abc'],
] as $field => $bad) {
    [, $er] = aiPlatformValidate(array_merge($base, $bad));
    ok("rejects bad $field", isset($er[$field]), json_encode($er));
}
[, $er] = aiPlatformValidate(array_merge($base, ['enabled' => '1']));
ok('cannot enable without a key', isset($er['api_key']));
[, $er] = aiPlatformValidate(array_merge($base, ['model' => 'anthropic/claude-3.5-sonnet:beta', 'provider' => 'openrouter']));
ok('accepts OpenRouter-style model ids', $er === [], json_encode($er));

section('6. Save + encrypted key');
ok('app secret is on disk in this environment', aiAppSecretOnDisk() === true);
$res = aiPlatformSave(array_merge($base, ['enabled' => '1', 'api_key' => 'bad key with spaces']));
ok('rejects a key containing spaces', !$res['ok'] && isset($res['errors']['api_key']));
$res = aiPlatformSave(array_merge($base, ['enabled' => '1', 'api_key' => $key]), 1);
ok('saves settings with a new key', $res['ok'] && $res['key_changed'], json_encode($res['errors']));
$raw = (string)$c->query("SELECT setting_value FROM platform_settings WHERE setting_key='ai_platform_api_key_enc'")->fetchColumn();
ok('key stored encrypted, never plain', $raw !== '' && strpos($raw, 'ABCDEFGH') === false && isEncryptedSecret($raw));
ok('stored key decrypts back', decryptSecret($raw) === $key);
ok('no ai_platform row holds the plain key', (int)$c->query("SELECT COUNT(*) FROM platform_settings WHERE setting_value LIKE '%ABCDEFGH1234%'")->fetchColumn() === 0);
$s = aiPlatformSettings(true);
ok('engine read returns the key', $s['api_key'] === $key && $s['enabled'] && $s['has_key']);
ok('aiPlatformConfigured() true', aiPlatformConfigured());
$res = aiPlatformSave(array_merge($base, ['enabled' => '1', 'model' => 'gpt-4o']), 1);
ok('blank key field keeps the existing key', $res['ok'] && !$res['key_changed'] && aiPlatformSettings(true)['api_key'] === $key);
$res = aiPlatformSave(array_merge($base, ['enabled' => '1', 'remove_key' => '1']));
ok('cannot remove the key while enabled', !$res['ok'] && isset($res['errors']['api_key']));
$res = aiPlatformSave(array_merge($base, ['enabled' => '0', 'remove_key' => '1']));
ok('disable + remove clears the key', $res['ok'] && $res['key_changed'] && aiPlatformSettings()['has_key'] === false);
aiPlatformSave(array_merge($base, ['enabled' => '1', 'api_key' => $key]), 1);
$src = (string)file_get_contents($root . '/core/ai_platform.php');
ok('save checks the on-disk secret before encrypting', strpos($src, '!aiAppSecretOnDisk()') !== false);

section('7. Tenant policy + resolver');
$tid = (int)$c->query("SELECT id FROM tenants WHERE status <> 'deleted' ORDER BY id LIMIT 1")->fetchColumn();
$deleted = (int)$c->query("SELECT id FROM tenants WHERE status = 'deleted' ORDER BY id LIMIT 1")->fetchColumn();
ok('found a live tenant for the test', $tid > 0);
$c->prepare("DELETE FROM tenant_ai_policy WHERE tenant_id = ?")->execute([$tid]);
unset($GLOBALS['__bms_ai_policy']);
ok('no row → mode auto, default cap', aiTenantPolicy($tid) === ['mode' => 'auto', 'monthly_cap' => null]);
ok('no tenant → own (pre-platform behaviour)', aiResolveSource(null, false) === 'own' && aiResolveSource(null, true) === 'own');
ok('auto + own working key → own', aiResolveSource($tid, true) === 'own');
ok('auto + no own key → platform', aiResolveSource($tid, false) === 'platform');
foreach (['platform', 'own', 'off'] as $m) {
    $r = aiSetTenantPolicy($tid, $m, '', 1);
    ok("set mode $m", $r['ok'] && aiResolveSource($tid, true) === $m && aiResolveSource($tid, false) === $m);
}
ok('rejects unknown mode', !aiSetTenantPolicy($tid, 'root', '')['ok']);
ok('rejects negative cap', !aiSetTenantPolicy($tid, 'platform', '-3')['ok']);
ok('rejects non-numeric cap', !aiSetTenantPolicy($tid, 'platform', '5;DROP')['ok']);
ok('rejects unknown company', !aiSetTenantPolicy(987654321, 'platform', '')['ok']);
if ($deleted) ok('rejects deleted company', !aiSetTenantPolicy($deleted, 'platform', '')['ok']);
aiSetTenantPolicy($tid, 'platform', '', 1);

section('8. Caps + metering');
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id = ?")->execute([$tid]);
aiPlatformSave(array_merge($base, ['enabled' => '1', 'tenant_cap' => '5', 'global_cap' => '0']), 1);
$ci = aiPlatformCapInfo($tid);
ok('fresh tenant: available, not blocked', $ci['available'] && !$ci['blocked'] && $ci['cap'] === 5.0 && $ci['spent'] === 0.0);
ok('log a platform call', aiPlatformLogUsage($tid, 7, 'ask', 'openai', 'gpt-4o-mini', 100, 50, 3.0, 'ok'));
$ci = aiPlatformCapInfo($tid);
ok('under the default cap', !$ci['exceeded'] && abs($ci['spent'] - 3.0) < 0.0001);
aiPlatformLogUsage($tid, 7, 'ask', 'openai', 'gpt-4o-mini', 100, 50, 2.5, 'ok');
$ci = aiPlatformCapInfo($tid);
ok('reaching the default cap blocks', $ci['exceeded'] && $ci['blocked']);
aiSetTenantPolicy($tid, 'platform', '20', 1);
ok('custom tenant cap overrides default', aiPlatformCapInfo($tid)['cap'] === 20.0 && !aiPlatformCapInfo($tid)['blocked']);
aiSetTenantPolicy($tid, 'platform', '0', 1);
ok('cap 0 = unlimited', aiPlatformCapInfo($tid)['cap'] === 0.0 && !aiPlatformCapInfo($tid)['exceeded']);
$allSpent = aiPlatformMonthSpend(null);
aiPlatformSave(array_merge($base, ['enabled' => '1', 'global_cap' => (string)max(0.01, floor($allSpent))]), 1);
$ci = aiPlatformCapInfo($tid);
ok('global cap reached blocks every tenant', $ci['global_exceeded'] && $ci['blocked']);
aiPlatformSave(array_merge($base, ['enabled' => '1', 'global_cap' => '0']), 1);
ok('blocked/error calls are metered too', aiPlatformLogUsage($tid, 7, 'ask', 'openai', 'gpt-4o-mini', 0, 0, 0, 'blocked', 'cap'));
ok('tenant rate limit counts recent calls', aiPlatformTenantRateLimited($tid, 3) === true && aiPlatformTenantRateLimited($tid, 60) === false);
ok('usage tagged with the tenant id', (int)$c->query("SELECT COUNT(*) FROM platform_ai_usage WHERE tenant_id = $tid")->fetchColumn() === 3);
ok('long error text is truncated on insert', aiPlatformLogUsage($tid, 7, 'ask', 'openai', 'm', 0, 0, 0, 'error', str_repeat('e', 900)));

echo "\nPassed: $pass   Failed: $fail\n";
exit($fail ? 1 : 0);
