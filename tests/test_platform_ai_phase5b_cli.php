<?php
/**
 * tests/test_platform_ai_phase5b_cli.php — Platform AI, Phase 5b (platform_ai_plan.md):
 * the floating "Ask BMS" chat. Shown only when the AI Assistant module is allowed
 * and the user may use it; hidden on the POS Sell screen; reuses existing
 * endpoints only; answers inserted as text; "Talk to a person" pre-fills the
 * Contact form. Runs the real api/ai/ask.php against the FAKE provider.
 *
 *   php tests/test_platform_ai_phase5b_cli.php
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
    if (!empty($cfg['uri_route'])) $_SERVER['REQUEST_URI'] = (string)parse_url(getUrl($cfg['uri_route']), PHP_URL_PATH);
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    if ($argv[2] === '--allowed') {
        require_once "$root/includes/ask_widget.php";
        echo '@@ALLOWED=' . (askWidgetAllowed() ? '1' : '0');
        exit;
    }
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

echo "\nBMS — Platform AI, Phase 5b (Ask BMS chat)\n";

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_pai5b_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$FAKE = $tmp . DIRECTORY_SEPARATOR . 'fake.json';
$CAPF = $tmp . DIRECTORY_SEPARATOR . 'capture.jsonl';
putenv('AI_TEST_FAKE_PROVIDER=' . $FAKE);
putenv('AI_TEST_FAKE_CAPTURE=' . $CAPF);
file_put_contents($FAKE, json_encode(['status' => 200, 'body' => ['choices' => [['message' => ['content' => "Open Sell, pick the items <b>and</b> press Pay."]]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20]]]));

$snapPS  = $c->query("SELECT setting_key, setting_value, updated_by FROM platform_settings")->fetchAll(PDO::FETCH_ASSOC);
$snapPol = $c->query("SELECT * FROM tenant_ai_policy")->fetchAll(PDO::FETCH_ASSOC);
$maxPU   = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM platform_ai_usage")->fetchColumn();
$snapAI  = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'ai\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$maxTU   = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM ai_usage_log")->fetchColumn();
$pdo->exec("INSERT INTO roles (role_name, is_admin, description, created_at, updated_at) VALUES ('__ask_role', 0, 'phase 5b test', NOW(), NOW())");
$role = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (username, email, first_name, last_name, role_id, is_active, password, created_at) VALUES ('__ask_staff', 'askstaff@example.test', 'Asha', 'Staff', ?, 1, 'x', NOW())")->execute([$role]);
$staffUid = (int)$pdo->lastInsertId();
register_shutdown_function(function () use ($c, $pdo, $snapPS, $snapPol, $maxPU, $snapAI, $maxTU, $tmp, $staffUid, $role) {
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
    $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$staffUid]);
    $pdo->prepare('DELETE FROM roles WHERE role_id = ?')->execute([$role]);
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$TOKEN = bin2hex(random_bytes(16));
$S_ADMIN = ['user_id' => $adminUid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1, 'role' => 'admin', 'csrf_token' => $TOKEN];
$S_STAFF = ['user_id' => $staffUid, 'username' => '__ask_staff', 'is_admin' => false, 'role_id' => $role, 'role' => 'Staff', 'csrf_token' => $TOKEN, 'permissions' => []];
$T = (int)$c->query("SELECT id FROM tenants WHERE status <> 'deleted' ORDER BY id LIMIT 1")->fetchColumn();
$ON = ['ai_assistant' => true]; $OFF = ['ai_assistant' => false];
$PAGE = 'app/constant/settings/help.php';

function tw(string $file, array $cfg): string {
    global $tmp;
    $f = $tmp . DIRECTORY_SEPARATOR . 'cfg_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($f, json_encode($cfg));
    $out = shell_exec('php ' . escapeshellarg(__FILE__) . ' tworker ' . escapeshellarg($file) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return (string)$out;
}
function api(string $file, array $post, array $extra = []): array {
    global $S_ADMIN, $TOKEN;
    $out = tw($file, array_merge(['session' => $S_ADMIN, 'method' => 'POST', 'post' => $post + ['_csrf' => $TOKEN]], $extra));
    $p = strrpos($out, '{"success"');
    $j = $p === false ? null : json_decode(substr($out, $p), true);
    return is_array($j) ? $j : ['_raw' => substr($out, 0, 400)];
}
function platOn(bool $on = true): void {
    $r = aiPlatformSave(['enabled' => $on ? '1' : '0', 'provider' => 'openai', 'model' => 'zz-chat-model', 'base_url' => '', 'temperature' => '0.4',
                         'tenant_cap' => '5', 'global_cap' => '0', 'api_key' => 'sk-plat-CHATKEY0123456789'], 1);
    if (!$r['ok']) echo "  !! platOn " . json_encode($r['errors']) . "\n";
}
$pdo->exec("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'ai_enabled'");
$c->prepare("DELETE FROM tenant_ai_policy WHERE tenant_id = ?")->execute([$T]);
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id = ?")->execute([$T]);
$crashed = fn(string $h) => str_contains($h, 'Fatal error') || str_contains($h, 'Uncaught') || str_contains($h, 'Parse error');

section('1. Lint');
foreach (['includes/ask_widget.php', 'includes/help_panel.php', 'header.php', 'lang/sw.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

section('2. When the button appears');
platOn();
$h = tw($PAGE, ['session' => $S_ADMIN, 'method' => 'GET', 'tenant' => $T, 'features' => $ON]);
ok('module on + admin: floating Ask button rendered', !$crashed($h) && str_contains($h, 'id="bmsAskBtn"') && str_contains($h, 'id="bmsAskBox"'), substr($h, 0, 300));
ok('  ...AI ready flag on', str_contains($h, 'data-ai="1"'));
ok('  ...greets the user by first name', (bool)preg_match('~"hello":"[^"]*, [^"]+!"~', $h));
ok('  ..."Talk to a person" present', str_contains($h, 'id="bmsAskPerson"'));
ok('  ...never prints the platform key or model', !str_contains($h, 'CHATKEY') && !str_contains($h, 'zz-chat-model'));
$h = tw($PAGE, ['session' => $S_ADMIN, 'method' => 'GET', 'tenant' => $T, 'features' => $OFF]);
ok('module OFF: no button, no chat script', !$crashed($h) && !str_contains($h, 'id="bmsAskBtn"') && !str_contains($h, 'bmsAsk.v1'), substr($h, 0, 300));
$h = tw($PAGE, ['session' => $S_STAFF, 'method' => 'GET', 'tenant' => $T, 'features' => $ON]);
ok('staff without AI Assistant permission: no button', !$crashed($h) && !str_contains($h, 'id="bmsAskBtn"'), substr($h, 0, 300));
platOn(false);
$h = tw($PAGE, ['session' => $S_ADMIN, 'method' => 'GET', 'tenant' => $T, 'features' => $ON]);
ok('module on but AI not switched on: button still there (articles + person), AI flag off', str_contains($h, 'id="bmsAskBtn"') && str_contains($h, 'data-ai="0"'));
platOn();
$o = tw('--allowed', ['session' => $S_ADMIN, 'method' => 'GET', 'tenant' => $T, 'features' => $ON, 'uri_route' => 'pos']);
ok('hidden on the POS Sell screen', str_contains($o, '@@ALLOWED=0'), substr($o, 0, 300));
$o = tw('--allowed', ['session' => $S_ADMIN, 'method' => 'GET', 'tenant' => $T, 'features' => $ON, 'uri_route' => 'dashboard']);
ok('shown on the dashboard', str_contains($o, '@@ALLOWED=1'), substr($o, 0, 300));
$o = tw('--allowed', ['session' => [], 'method' => 'GET', 'tenant' => $T, 'features' => $ON]);
ok('never for a signed-out visitor', str_contains($o, '@@ALLOWED=0'));

section('3. The chat flow through the real endpoints');
$r = api('api/support_help.php', ['action' => 'home', 'page_key' => ''], ['tenant' => $T, 'features' => $ON]);
ok('popular questions come from the help endpoint', ($r['success'] ?? false) === true && array_key_exists('popular', $r), json_encode($r));
@unlink($CAPF);
$r = api('api/ai/ask.php', ['question' => 'How do I sell?'], ['tenant' => $T, 'features' => $ON]);
ok('AI answer through api/ai/ask.php on the platform key', ($r['success'] ?? false) === true && str_contains((string)($r['answer'] ?? ''), 'press Pay'), json_encode($r));
ok('  ...used the platform key', str_contains((string)@file_get_contents($CAPF), 'CHATKEY'));
ok('  ...metered against the company as "ask"', (int)$c->query("SELECT COUNT(*) FROM platform_ai_usage WHERE tenant_id = $T AND feature = 'ask' AND status = 'ok'")->fetchColumn() === 1);
$r = api('api/ai/ask.php', ['question' => 'How do I sell?'], ['tenant' => $T, 'features' => $OFF]);
ok('module OFF: ask.php refuses', ($r['success'] ?? true) === false);
$r = api('api/ai/ask.php', ['question' => 'How do I sell?'], ['session' => $S_STAFF, 'tenant' => $T, 'features' => $ON]);
ok('staff without permission: ask.php refuses', ($r['success'] ?? true) === false);
$r = api('api/ai/ask.php', ['question' => 'x', '_csrf' => 'wrong'], ['tenant' => $T, 'features' => $ON]);
ok('wrong CSRF token: refused', ($r['success'] ?? false) !== true);
ok('a sub-cent limit is refused (would mean "no limit")', aiSetTenantPolicy($T, 'platform', '0.000001', 1)['ok'] === false);
aiPlatformLogUsage($T, $adminUid, 'ask', 'openai', 'zz-chat-model', 0, 0, 1.0, 'ok');
aiSetTenantPolicy($T, 'platform', '0.01', 1);
$r = api('api/ai/ask.php', ['question' => 'How do I sell?'], ['tenant' => $T, 'features' => $ON]);
ok('limit reached: friendly limit message reaches the chat', ($r['success'] ?? true) === false && str_contains((string)($r['message'] ?? ''), 'limit'), json_encode($r));
aiSetTenantPolicy($T, 'auto', '', 1);

section('4. Safe rendering + no new endpoints');
$w = (string)file_get_contents("$root/includes/ask_widget.php");
ok('AI and user text inserted with textContent only', str_contains($w, "function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text;"));
ok('only article bodies use innerHTML (server-escaped)', substr_count($w, '.innerHTML') === 1 && str_contains($w, 'body.innerHTML = a.html;'));
ok('reuses existing endpoints only', str_contains($w, "buildUrl('api/support_help.php')") && str_contains($w, "buildUrl('api/ai/ask.php')") && !preg_match("~buildUrl\('api/(?!support_help|ai/ask)~", $w));
ok('every request sends the CSRF token', str_contains($w, "'X-CSRF-Token': csrf()") && str_contains($w, '_csrf: csrf()'));
ok('question length capped (500) like ask.php', str_contains($w, 'maxlength="500"') && str_contains($w, '.slice(0, 500)'));
ok('history kept only in this browser tab', str_contains($w, 'sessionStorage') && !str_contains($w, 'localStorage'));
ok('gate checks the module and the permission', str_contains($w, "tenantFeatureEnabled('ai_assistant')") && str_contains($w, "canView('ai_assistant')"));
ok('server values JSON-encoded with HEX flags', str_contains($w, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT'));
$ask = (string)file_get_contents("$root/api/ai/ask.php");
ok('ask.php: auth + permission + CSRF + rate limit + length cap', str_contains($ask, 'isAuthenticated()') && str_contains($ask, "canView('ai_assistant')") && str_contains($ask, 'csrf_check();') && str_contains($ask, 'aiRateLimited()') && str_contains($ask, 'mb_strlen($question) > 500'));
$hp = (string)file_get_contents("$root/includes/help_panel.php");
ok('"Talk to a person" pre-fills the Contact form description', str_contains($hp, "document.getElementById('bhDesc').value = (prefill.body || '').slice(0, 5000);"));
ok('header renders the widget after the help panel', strpos((string)file_get_contents("$root/header.php"), 'askWidgetRender();') > strpos((string)file_get_contents("$root/header.php"), 'helpPanelRender();'));

section('5. Swahili');
$sw = require "$root/lang/sw.php";
foreach (['Good morning', 'How can we help you today?', 'Popular questions', 'Type your question…', 'Talk to a person', 'These guides may help:',
          'Get an answer from AI', 'Thinking…', 'Start over', 'Support hours:', 'AI answer — check important figures before acting on them.'] as $k) {
    ok("sw: $k", isset($sw[$k]) && $sw[$k] !== $k);
}

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
