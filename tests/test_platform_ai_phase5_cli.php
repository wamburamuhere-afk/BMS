<?php
/**
 * tests/test_platform_ai_phase5_cli.php — Platform AI, Phase 5 (platform_ai_plan.md):
 * tenant side — AI Settings page per key source, save/test endpoints refuse when
 * the service provider manages AI, base-URL guard, "Ask BMS" links appear for
 * platform companies, Swahili strings. FAKE provider only.
 *
 *   php tests/test_platform_ai_phase5_cli.php
 *
 * CLI ONLY. Snapshots and restores everything it touches.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);

// Tenant worker: a company user's request with a simulated tenant + module map.
if (($argv[1] ?? '') === 'tworker') {
    $cfg = json_decode((string)file_get_contents($argv[3]), true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/' . $argv[2];
    require_once "$root/roots.php";
    if (!empty($cfg['tenant'])) {
        $row = getControlPdo()->query('SELECT * FROM tenants WHERE id = ' . (int)$cfg['tenant'])->fetch(PDO::FETCH_ASSOC);
        $GLOBALS['__bms_tenant'] = $row;
    }
    if (isset($cfg['features'])) $GLOBALS['__bms_features'] = $cfg['features'];
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/{$argv[2]}";
    exit;
}

require_once "$root/roots.php";
require_once "$root/core/ai_service.php";
global $pdo;
$c = getControlPdo();
aiPlatformSchema($c, controlDbName());

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Platform AI, Phase 5\n";

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_pai5_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$FAKE = $tmp . DIRECTORY_SEPARATOR . 'fake.json';
$CAPF = $tmp . DIRECTORY_SEPARATOR . 'capture.jsonl';
putenv('AI_TEST_FAKE_PROVIDER=' . $FAKE);
putenv('AI_TEST_FAKE_CAPTURE=' . $CAPF);
file_put_contents($FAKE, json_encode(['status' => 200, 'body' => ['choices' => [['message' => ['content' => 'OK']]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]]]));

$snapPS  = $c->query("SELECT setting_key, setting_value, updated_by FROM platform_settings")->fetchAll(PDO::FETCH_ASSOC);
$snapPol = $c->query("SELECT * FROM tenant_ai_policy")->fetchAll(PDO::FETCH_ASSOC);
$maxPU   = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM platform_ai_usage")->fetchColumn();
$snapAI  = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'ai\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$maxTU   = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM ai_usage_log")->fetchColumn();
register_shutdown_function(function () use ($c, $pdo, $snapPS, $snapPol, $maxPU, $snapAI, $maxTU, $tmp) {
    $c->exec("DELETE FROM platform_settings");
    $i = $c->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)");
    foreach ($snapPS as $r) $i->execute([$r['setting_key'], $r['setting_value'], $r['updated_by']]);
    $c->exec("DELETE FROM tenant_ai_policy");
    $i = $c->prepare("INSERT INTO tenant_ai_policy (tenant_id, mode, monthly_cap, updated_by, updated_at) VALUES (?,?,?,?,?)");
    foreach ($snapPol as $r) $i->execute([$r['tenant_id'], $r['mode'], $r['monthly_cap'], $r['updated_by'], $r['updated_at']]);
    $c->prepare("DELETE FROM platform_ai_usage WHERE id > ?")->execute([$maxPU]);
    $pdo->exec("DELETE FROM system_settings WHERE setting_key LIKE 'ai\\_%'");
    $i = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, updated_at) VALUES (?,?,'ai','0',NOW())");
    foreach ($snapAI as $k => $v) $i->execute([$k, $v]);
    $pdo->prepare("DELETE FROM ai_usage_log WHERE id > ?")->execute([$maxTU]);
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$TOKEN = bin2hex(random_bytes(16));
$S_ADMIN = ['user_id' => $adminUid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1, 'role' => 'admin', 'csrf_token' => $TOKEN];
$T = (int)$c->query("SELECT id FROM tenants WHERE status <> 'deleted' ORDER BY id LIMIT 1")->fetchColumn();
$ON = ['ai_assistant' => true];
$PMODEL = 'zz-platform-model';

function tw(string $file, array $cfg): string {
    global $tmp;
    $f = $tmp . DIRECTORY_SEPARATOR . 'cfg_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($f, json_encode($cfg));
    $out = shell_exec('php ' . escapeshellarg(__FILE__) . ' tworker ' . escapeshellarg($file) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return (string)$out;
}
function page(array $extra = []): string {
    global $S_ADMIN;
    return tw('app/constant/settings/ai_settings.php', array_merge(['session' => $S_ADMIN, 'method' => 'GET'], $extra));
}
function api(string $file, array $post, array $extra = []): array {
    global $S_ADMIN, $TOKEN;
    $out = tw($file, array_merge(['session' => $S_ADMIN, 'method' => 'POST', 'post' => $post + ['_csrf' => $TOKEN]], $extra));
    $p = strrpos($out, '{"success"');
    $j = $p === false ? null : json_decode(substr($out, $p), true);
    return is_array($j) ? $j : ['_raw' => $out];
}
function setOwn(array $kv): void {
    global $pdo;
    $up = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, updated_at) VALUES (?,?,'ai','0',NOW())
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($kv as $k => $v) $up->execute([$k, $v]);
}
function ownOff(): void { setOwn(['ai_enabled' => '0', 'ai_api_key_enc' => '', 'ai_base_url' => '', 'ai_model' => 'gpt-4o-mini', 'ai_provider' => 'openai']); }
function ownKeyEnc(): string { global $pdo; return (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ai_api_key_enc'")->fetchColumn(); }
function platOn(bool $enabled = true): void {
    global $PMODEL;
    $r = aiPlatformSave(['enabled' => $enabled ? '1' : '0', 'provider' => 'openai', 'model' => $PMODEL, 'base_url' => '', 'temperature' => '0.4',
                         'tenant_cap' => '5', 'global_cap' => '0', 'api_key' => 'sk-plat-PHASE5KEY0123456789'], 1);
    if (!$r['ok']) echo "  !! platOn: " . json_encode($r['errors']) . "\n";
}
function pol(string $m): void { global $T; $r = aiSetTenantPolicy($T, $m, '', 1); if (!$r['ok']) echo "  !! policy {$r['error']}\n"; }
$crashed = fn(string $h) => str_contains($h, 'Fatal error') || str_contains($h, 'Uncaught') || str_contains($h, 'Parse error');

$c->prepare("DELETE FROM tenant_ai_policy WHERE tenant_id = ?")->execute([$T]);
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id = ?")->execute([$T]);

section('1. Lint');
foreach (['core/ai_service.php', 'api/ai/save_ai_settings.php', 'api/ai/test_ai_config.php', 'app/constant/settings/ai_settings.php', 'lang/sw.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

section('2. AI Settings page per key source');
ownOff(); platOn();
$h = page();
ok('no tenant: original form, no provider card', !$crashed($h) && str_contains($h, 'id="aiSettingsForm"') && !str_contains($h, 'id="aiProvidedCard"'), substr($h, 0, 400));
$h = page(['tenant' => $T, 'features' => $ON]);
ok('auto + no own key: "provided" card, ready message', !$crashed($h) && str_contains($h, 'data-ai-state="ready"'), substr($h, 0, 400));
ok('  ...own-key form offered inside "advanced"', str_contains($h, 'id="aiOwnKeyDetails"') && str_contains($h, 'id="aiSettingsForm"'));
ok('  ...badge Enabled', str_contains($h, 'id="aiStatusBadge" data-on="1"'));
ok('  ...never shows the platform model or key', !str_contains($h, $PMODEL) && !str_contains($h, 'PHASE5KEY'));
ok('  ..."Ask BMS" link in the help panel', str_contains($h, 'id="bhAskAi"'));
pol('platform');
$h = page(['tenant' => $T, 'features' => $ON]);
ok('mode BJP key: provided card, no own-key form', str_contains($h, 'id="aiProvidedCard"') && !str_contains($h, 'id="aiSettingsForm"'));
pol('off');
$h = page(['tenant' => $T, 'features' => $ON]);
ok('mode off: "not included" card, no form, no Ask BMS', str_contains($h, 'data-ai-state="off"') && !str_contains($h, 'id="aiSettingsForm"') && !str_contains($h, 'id="bhAskAi"'));
pol('auto'); platOn(false);
$h = page(['tenant' => $T, 'features' => $ON]);
ok('platform switched off: "not switched on yet", no Ask BMS', str_contains($h, 'data-ai-state="pending"') && str_contains($h, 'id="aiStatusBadge" data-on="0"') && !str_contains($h, 'id="bhAskAi"'));
platOn();
aiPlatformLogUsage($T, $adminUid, 'ask', 'openai', $PMODEL, 10, 5, 0.01, 'ok');
$pdo->prepare("INSERT INTO ai_usage_log (user_id, feature, provider, model, prompt_tokens, completion_tokens, est_cost, status) VALUES (?, 'ask', 'platform', 'platform', 10, 5, 0.01, 'ok')")->execute([$adminUid]);
$h = page(['tenant' => $T, 'features' => $ON]);
ok('usage table shows "Service provider", not the platform model', (str_contains($h, 'Service provider') || str_contains($h, 'Mtoa huduma')) && !str_contains($h, $PMODEL));
ok('usage card shows the company limit ($5.00)', str_contains($h, '$5.00'));

section('3. save_ai_settings.php');
$own = ['ai_enabled' => '1', 'ai_provider' => 'openai', 'ai_model' => 'gpt-4o-mini', 'ai_base_url' => '', 'ai_monthly_cost_cap' => '0', 'ai_temperature' => '0.4', 'ai_api_key' => 'sk-own-PHASE5OWNKEY12345'];
pol('platform');
$r = api('api/ai/save_ai_settings.php', $own, ['tenant' => $T, 'features' => $ON]);
ok('mode BJP key: own key refused', ($r['success'] ?? true) === false && str_contains((string)($r['message'] ?? ''), 'managed by your service provider'), json_encode($r));
ok('  ...nothing stored', ownKeyEnc() === '');
pol('off');
$r = api('api/ai/save_ai_settings.php', $own, ['tenant' => $T, 'features' => $ON]);
ok('mode off: own key refused', ($r['success'] ?? true) === false && ownKeyEnc() === '');
pol('auto');
$r = api('api/ai/save_ai_settings.php', array_merge($own, ['ai_base_url' => 'https://169.254.169.254/latest']), ['tenant' => $T, 'features' => $ON]);
ok('auto: private base URL refused', ($r['success'] ?? true) === false && str_contains((string)($r['message'] ?? ''), 'public') && ownKeyEnc() === '', json_encode($r));
$r = api('api/ai/save_ai_settings.php', $own, ['tenant' => $T, 'features' => $ON]);
ok('auto: own key saved', ($r['success'] ?? false) === true && ownKeyEnc() !== '', json_encode($r));
$h = page(['tenant' => $T, 'features' => $ON]);
ok('  ...company now on its own key: original form, no provider card', str_contains($h, 'id="aiSettingsForm"') && !str_contains($h, 'id="aiProvidedCard"'));
ownOff();
$r = api('api/ai/save_ai_settings.php', array_merge($own, ['ai_base_url' => 'http://localhost:11434/v1']));
ok('no tenant (single install): local gateway still accepted (unchanged)', ($r['success'] ?? false) === true, json_encode($r));
ownOff();
$staff = ['user_id' => $adminUid, 'is_admin' => false, 'role_id' => 999999, 'role' => 'x', 'csrf_token' => $TOKEN, 'permissions' => []];
$r = api('api/ai/save_ai_settings.php', $own, ['session' => $staff, 'tenant' => $T, 'features' => $ON]);
ok('non-admin still refused', ($r['success'] ?? true) === false);

section('4. test_ai_config.php');
pol('off');
$r = api('api/ai/test_ai_config.php', [], ['tenant' => $T, 'features' => $ON]);
ok('off: clear "not included" message', str_contains((string)($r['message'] ?? ''), 'not included'), json_encode($r));
pol('auto'); platOn(false);
$r = api('api/ai/test_ai_config.php', [], ['tenant' => $T, 'features' => $ON]);
ok('platform not ready: "not switched on yet"', str_contains((string)($r['message'] ?? ''), 'not been switched on'), json_encode($r));
platOn();
@unlink($CAPF);
$r = api('api/ai/test_ai_config.php', [], ['tenant' => $T, 'features' => $ON]);
ok('platform ready: test answers through the platform key', ($r['success'] ?? false) === true && str_contains((string)@file_get_contents($CAPF), 'PHASE5KEY'), json_encode($r));
ok('  ...metered against the company', (int)$c->query("SELECT COUNT(*) FROM platform_ai_usage WHERE tenant_id = $T AND feature = 'test'")->fetchColumn() === 1);

section('5. Swahili strings');
$sw = require "$root/lang/sw.php";
foreach (['AI provided by your service provider', 'AI is not included for your company', 'Use your own AI provider key instead (advanced)',
          'Service provider', "This month's AI limit has been reached. Please contact support.", 'AI is temporarily unavailable. Please try again later.',
          'The AI is busy right now. Please wait a moment and try again.', 'AI is not available for this company.'] as $k) {
    ok("sw: $k", isset($sw[$k]) && $sw[$k] !== '' && $sw[$k] !== $k);
}
$src = (string)file_get_contents("$root/lang/sw.php");
$block = substr($src, (int)strpos($src, '// Platform AI (platform_ai_plan.md, Phase 5)'));
preg_match_all("~^\s*(['\"])(.*?)\\1\s*=>~m", $block, $m);
$dups = 0;
foreach ($m[2] as $k) { if (substr_count($src, "'" . $k . "' =>") + substr_count($src, '"' . $k . '" =>') > 1) $dups++; }
ok('no duplicate keys added', $dups === 0, "$dups duplicates");

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
