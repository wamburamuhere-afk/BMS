<?php
/**
 * tests/test_platform_ai_phase6_cli.php — Platform AI, Phase 6 END-TO-END (platform_ai_plan.md).
 *
 * The whole journey through the real endpoints, with the FAKE provider:
 *   superadmin saves the BJP key → company A (BJP key) asks Ask BMS → the call shows
 *   in superadmin Usage → superadmin lowers A's limit → A is blocked with a friendly
 *   message; company B (own key) is untouched and never metered on the platform;
 *   company C (AI module off) has no AI anywhere; superadmin switches AI off → A loses it.
 *
 *   php tests/test_platform_ai_phase6_cli.php
 *
 * CLI ONLY. Snapshots and restores everything it touches.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'tworker') {
    $cfg = json_decode((string)file_get_contents($argv[3]), true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/' . $argv[2];
    require_once "$root/roots.php";
    if (!empty($cfg['tenant'])) $GLOBALS['__bms_tenant'] = getControlPdo()->query('SELECT * FROM tenants WHERE id = ' . (int)$cfg['tenant'])->fetch(PDO::FETCH_ASSOC);
    if (isset($cfg['features'])) $GLOBALS['__bms_features'] = $cfg['features'];
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/{$argv[2]}";
    exit;
}
if (($argv[1] ?? '') === 'sworker') {
    $cfg = json_decode((string)file_get_contents($argv[3]), true);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = $cfg['host'];
    $_SERVER['REQUEST_URI'] = '/' . $argv[2];
    require_once "$root/core/superadmin_auth.php";
    require_once "$root/helpers.php";
    superadminSessionReady();
    require_once "$root/core/control_db.php";
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];
    $post = $cfg['post'];
    if (!isset($post['_csrf'])) $post['_csrf'] = csrf_token();
    $_POST = $post;
    require "$root/{$argv[2]}";
    exit;
}
if (($argv[1] ?? '') === 'sroute') {
    $_SERVER['HTTP_HOST'] = (string)$argv[2];
    $uri = (string)$argv[3];
    $_SERVER['REQUEST_URI'] = $uri; $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING'] = (string)parse_url($uri, PHP_URL_QUERY);
    parse_str($_SERVER['QUERY_STRING'], $_GET);
    require_once "$root/roots.php";
    require_once "$root/core/superadmin_auth.php";
    superadminSessionReady();
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];
    ob_start(); handleRoute(); fwrite(STDOUT, ob_get_clean());
    exit;
}

require_once "$root/roots.php";
require_once "$root/core/ai_service.php";
require_once "$root/core/superadmin_auth.php";
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

echo "\nBMS — Platform AI, Phase 6 (end-to-end)\n";

$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
$SA = superadminHostLabel() . '.' . $BASE;
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_pai6_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$FAKE = $tmp . DIRECTORY_SEPARATOR . 'fake.json';
$CAPF = $tmp . DIRECTORY_SEPARATOR . 'capture.jsonl';
putenv('AI_TEST_FAKE_PROVIDER=' . $FAKE);
putenv('AI_TEST_FAKE_CAPTURE=' . $CAPF);
file_put_contents($FAKE, json_encode(['status' => 200, 'body' => ['choices' => [['message' => ['content' => 'Your sales today are shown on the dashboard.']]], 'usage' => ['prompt_tokens' => 2000, 'completion_tokens' => 1000]]]));

$snapPS  = $c->query("SELECT setting_key, setting_value, updated_by FROM platform_settings")->fetchAll(PDO::FETCH_ASSOC);
$snapPol = $c->query("SELECT * FROM tenant_ai_policy")->fetchAll(PDO::FETCH_ASSOC);
$maxPU   = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM platform_ai_usage")->fetchColumn();
$maxLog  = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM tenant_admin_log")->fetchColumn();
$snapAI  = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'ai\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$maxTU   = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM ai_usage_log")->fetchColumn();
register_shutdown_function(function () use ($c, $pdo, $snapPS, $snapPol, $maxPU, $maxLog, $snapAI, $maxTU, $tmp) {
    $c->exec("DELETE FROM platform_settings");
    $i = $c->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)");
    foreach ($snapPS as $r) $i->execute([$r['setting_key'], $r['setting_value'], $r['updated_by']]);
    $c->exec("DELETE FROM tenant_ai_policy");
    $i = $c->prepare("INSERT INTO tenant_ai_policy (tenant_id, mode, monthly_cap, updated_by, updated_at) VALUES (?,?,?,?,?)");
    foreach ($snapPol as $r) $i->execute([$r['tenant_id'], $r['mode'], $r['monthly_cap'], $r['updated_by'], $r['updated_at']]);
    $c->prepare("DELETE FROM platform_ai_usage WHERE id > ?")->execute([$maxPU]);
    $c->prepare("DELETE FROM tenant_admin_log WHERE id > ? AND action IN ('ai_settings','ai_test','ai_policy')")->execute([$maxLog]);
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
$ids = $c->query("SELECT id FROM tenants WHERE status <> 'deleted' ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
[$A, $B, $C] = array_map('intval', $ids);
$ON = ['ai_assistant' => true]; $OFF = ['ai_assistant' => false];
$PKEY = 'sk-plat-E2EBJPKEY0123456789'; $OKEY = 'sk-own-E2EOWNKEY0123456789';

function cfgFile(array $cfg): string { global $tmp; $f = $tmp . DIRECTORY_SEPARATOR . 'c' . bin2hex(random_bytes(4)) . '.json'; file_put_contents($f, json_encode($cfg)); return $f; }
function run(string $mode, string $file, array $cfg): string { $f = cfgFile($cfg); $o = (string)shell_exec('php ' . escapeshellarg(__FILE__) . " $mode " . escapeshellarg($file) . ' ' . escapeshellarg($f) . ' 2>&1'); @unlink($f); return $o; }
function json(string $o): array { $p = strrpos($o, '{"success"'); $j = $p === false ? null : json_decode(substr($o, $p), true); return is_array($j) ? $j : ['_raw' => substr($o, 0, 400)]; }
function sa(array $post): array { global $SA; return json(run('sworker', 'actions/superadmin_ai.php', ['host' => $SA, 'post' => $post])); }
function ask(int $tid, array $features): array { global $S_ADMIN, $TOKEN; return json(run('tworker', 'api/ai/ask.php', ['session' => $S_ADMIN, 'tenant' => $tid, 'features' => $features, 'post' => ['question' => 'What are my sales today?', '_csrf' => $TOKEN]])); }
function tpage(int $tid, array $features, string $file = 'app/constant/settings/help.php'): string { global $S_ADMIN; return run('tworker', $file, ['session' => $S_ADMIN, 'tenant' => $tid, 'features' => $features, 'method' => 'GET']); }
function sroute(string $uri): string { global $SA; return (string)shell_exec('php ' . escapeshellarg(__FILE__) . ' sroute ' . escapeshellarg($SA) . ' ' . escapeshellarg($uri) . ' 2>&1'); }
function captures(): string { global $CAPF; $s = (string)@file_get_contents($CAPF); @unlink($CAPF); return $s; }
function platCount(int $tid): int { global $c; return (int)$c->query("SELECT COUNT(*) FROM platform_ai_usage WHERE tenant_id = $tid AND status = 'ok'")->fetchColumn(); }

$c->exec("DELETE FROM platform_settings WHERE setting_key LIKE 'ai\\_platform\\_%'");
$c->prepare("DELETE FROM tenant_ai_policy WHERE tenant_id IN (?,?,?)")->execute([$A, $B, $C]);
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id IN (?,?,?)")->execute([$A, $B, $C]);
$pdo->exec("UPDATE system_settings SET setting_value = '' WHERE setting_key = 'ai_api_key_enc'");
$pdo->exec("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'ai_enabled'");

section('0. Before BJP switches anything on — nothing changes for anyone');
ok('company A: no AI (no own key, platform off)', (ask($A, $ON)['success'] ?? true) === false && captures() === '');

section('1. Superadmin saves the BJP key and tests it');
$r = sa(['action' => 'save_settings', 'enabled' => '1', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'base_url' => '', 'temperature' => '0.3', 'tenant_cap' => '5', 'global_cap' => '0', 'api_key' => $PKEY]);
ok('settings + key saved', ($r['success'] ?? false) === true, json_encode($r));
$r = sa(['action' => 'test']);
ok('connection test passes on the BJP key', ($r['success'] ?? false) === true && str_contains(captures(), 'E2EBJPKEY'), json_encode($r));
sa(['action' => 'set_policy', 'tenant_id' => (string)$A, 'mode' => 'platform', 'monthly_cap' => '']);
sa(['action' => 'set_policy', 'tenant_id' => (string)$B, 'mode' => 'own', 'monthly_cap' => '']);
ok('policies set for A (BJP key) and B (own key)', aiTenantPolicy($A)['mode'] === 'platform' && aiTenantPolicy($B)['mode'] === 'own');

section('2. Company A asks — answered on the BJP key, metered');
$h = tpage($A, $ON);
ok('A sees the floating Ask button, AI on', str_contains($h, 'id="bmsAskBtn"') && str_contains($h, 'data-ai="1"'));
$r = ask($A, $ON);
ok('A gets an answer', ($r['success'] ?? false) === true && str_contains((string)($r['answer'] ?? ''), 'dashboard'), json_encode($r));
ok('  ...through the BJP key', str_contains(captures(), 'E2EBJPKEY'));
ok('  ...metered against A', platCount($A) === 1);

section('3. Superadmin sees it');
$u = sroute('/ai?tab=usage');
$aName = htmlspecialchars((string)$c->query("SELECT company_name FROM tenants WHERE id = $A")->fetchColumn(), ENT_QUOTES, 'UTF-8');
ok('Usage tab lists company A', str_contains($u, $aName) && str_contains($u, 'Top companies this month'));
$co = sroute('/ai?tab=companies&mode=platform');
ok('Companies tab shows A on the BJP key', str_contains($co, 'data-id="' . $A . '"') && str_contains($co, 'BJP key'));

section('4. Superadmin lowers A\'s limit → A is blocked politely');
$spent = aiPlatformMonthSpend($A);
$r = sa(['action' => 'set_policy', 'tenant_id' => (string)$A, 'mode' => 'platform', 'monthly_cap' => '0.01']);
ok('limit lowered', ($r['success'] ?? false) === true);
if ($spent < 0.01) aiPlatformLogUsage($A, $adminUid, 'ask', 'openai', 'gpt-4o-mini', 0, 0, 0.02, 'ok');
$r = ask($A, $ON);
ok('A is told the monthly limit is reached', ($r['success'] ?? true) === false && str_contains((string)($r['message'] ?? ''), 'limit'), json_encode($r));
ok('  ...and no provider call was made', captures() === '');
$h = tpage($A, $ON, 'app/constant/settings/ai_settings.php');
ok('A\'s AI Settings shows the provided card, no own-key form, not the BJP key', str_contains($h, 'id="aiProvidedCard"') && !str_contains($h, 'id="aiSettingsForm"') && !str_contains($h, 'E2EBJPKEY'), substr($h, 0, 300));

section('5. Company B (own key) is untouched');
$pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'ai_api_key_enc'")->execute([encryptSecret($OKEY)]);
$pdo->exec("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'ai_enabled'");
$before = platCount($B);
$r = ask($B, $ON);
ok('B is answered on its OWN key', ($r['success'] ?? false) === true && str_contains($cap = captures(), 'E2EOWNKEY') && !str_contains($cap, 'E2EBJPKEY'), json_encode($r));
ok('  ...and never metered on the platform', platCount($B) === $before);
$h = tpage($B, $ON, 'app/constant/settings/ai_settings.php');
ok('B\'s AI Settings is the original own-key form', str_contains($h, 'id="aiSettingsForm"') && !str_contains($h, 'id="aiProvidedCard"'));

section('6. Company C (AI module off) has no AI anywhere');
$r = ask($C, $OFF);
ok('Ask BMS refused', ($r['success'] ?? true) === false && captures() === '');
$h = tpage($C, $OFF);
ok('no floating button, no Ask BMS link', !str_contains($h, 'id="bmsAskBtn"') && !str_contains($h, 'id="bhAskAi"'));

section('7. Superadmin switches platform AI off');
$pdo->exec("UPDATE system_settings SET setting_value = '' WHERE setting_key = 'ai_api_key_enc'");
$pdo->exec("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'ai_enabled'");
sa(['action' => 'set_policy', 'tenant_id' => (string)$A, 'mode' => 'platform', 'monthly_cap' => '']);
$r = sa(['action' => 'save_settings', 'enabled' => '0', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'base_url' => '', 'temperature' => '0.3', 'tenant_cap' => '5', 'global_cap' => '0', 'api_key' => '']);
ok('switched off', ($r['success'] ?? false) === true);
$r = ask($A, $ON);
ok('A no longer gets AI', ($r['success'] ?? true) === false && captures() === '');
$h = tpage($A, $ON);
ok('A\'s chat button stays (articles + person) with AI flag off', str_contains($h, 'id="bmsAskBtn"') && str_contains($h, 'data-ai="0"'));

section('8. Re-scout fixes');
$aa = (string)file_get_contents("$root/api/ai_audit_analysis.php");
ok('audit analysis tells BJP-key companies to contact support (not "adjust AI Settings")', str_contains($aa, "(\$cap['source'] ?? 'own') === 'platform'") && str_contains($aa, "limit has been reached. Please contact support."));

section('9. Audit trail');
$acts = $c->query("SELECT action FROM tenant_admin_log WHERE id > $maxLog AND action LIKE 'ai\\_%'")->fetchAll(PDO::FETCH_COLUMN);
ok('every superadmin step audited', in_array('ai_settings', $acts, true) && in_array('ai_test', $acts, true) && in_array('ai_policy', $acts, true));
ok('no audit row contains a key', (int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE id > $maxLog AND detail LIKE '%E2E%KEY%'")->fetchColumn() === 0);
ok('no usage row contains a key', (int)$c->query("SELECT COUNT(*) FROM platform_ai_usage WHERE id > $maxPU AND error LIKE '%E2E%KEY%'")->fetchColumn() === 0);

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
