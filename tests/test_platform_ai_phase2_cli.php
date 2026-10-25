<?php
/**
 * tests/test_platform_ai_phase2_cli.php — Platform AI, Phase 2 (platform_ai_plan.md):
 * the engine switch in core/ai_service.php. Each case runs in its own worker
 * process (getSetting() caches per process) with a simulated tenant and module
 * map, against the FAKE provider (AI_TEST_FAKE_PROVIDER) — never a real one.
 *
 *   php tests/test_platform_ai_phase2_cli.php
 *
 * CLI ONLY. Snapshots and restores the local tenant's ai_* settings, its
 * ai_usage_log, and the control DB's platform settings / policy / usage.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ── worker ───────────────────────────────────────────────────────────────────
if (($argv[1] ?? '') === '--worker') {
    $o = json_decode((string)base64_decode((string)$argv[2]), true) ?: [];
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    if (!empty($o['meter_down'])) {
        // Simulate an unreachable control DB meter (functions are function_exists-guarded).
        function aiPlatformMonthSpend(?int $tenantId): ?float { return null; }
    }
    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/ai_service.php';
    if (array_key_exists('tenant', $o) && $o['tenant'] !== null) $GLOBALS['__bms_tenant'] = ['id' => (int)$o['tenant']];
    if (isset($o['features'])) $GLOBALS['__bms_features'] = $o['features'];
    if (isset($o['uid'])) $_SESSION['user_id'] = (int)$o['uid'];
    $s = aiSettings();
    $out = [
        'source'     => $s['source'],
        'tenant_id'  => $s['tenant_id'],
        'configured' => aiConfigured(),
        'cap'        => aiCapInfo(),
        'own_spend'  => aiMonthSpend(),
    ];
    if (!empty($o['call'])) $out['res'] = aiComplete([['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => 'hi']], ['feature' => 'ask']);
    if (!empty($o['digest'])) {
        require_once __DIR__ . '/../core/notify.php';
        $out['digest'] = aiSummarizeNotifications([['title' => 'T1', 'message' => 'M1', 'priority' => 'high']]);
    }
    echo "@@JSON@@" . json_encode($out);
    exit(0);
}

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/ai_service.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Platform AI, Phase 2\n";

$root = dirname(__DIR__);
$c = getControlPdo();
global $pdo;
aiPlatformSchema($c, controlDbName());

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_pai2_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$FAKE = $tmp . DIRECTORY_SEPARATOR . 'fake.json';
$CAP  = $tmp . DIRECTORY_SEPARATOR . 'capture.jsonl';

// ── snapshots ────────────────────────────────────────────────────────────────
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

// ── helpers ──────────────────────────────────────────────────────────────────
$OWNKEY = 'sk-own-TENANTKEY0123456789';
$PLATKEY = 'sk-plat-BJPKEY9876543210abc';
function setOwn(array $kv): void {
    global $pdo;
    $up = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, updated_at) VALUES (?,?,'ai','0',NOW())
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($kv as $k => $v) $up->execute([$k, $v]);
}
function ownOn(string $baseUrl = ''): void {
    global $OWNKEY;
    setOwn(['ai_enabled' => '1', 'ai_provider' => 'openai', 'ai_model' => 'gpt-4o-mini', 'ai_api_key_enc' => encryptSecret($OWNKEY),
            'ai_base_url' => $baseUrl, 'ai_monthly_cost_cap' => '0', 'ai_temperature' => '0.4']);
}
function ownOff(): void { setOwn(['ai_enabled' => '0', 'ai_api_key_enc' => '', 'ai_base_url' => '', 'ai_monthly_cost_cap' => '0']); }
function platOn(array $extra = []): void {
    global $PLATKEY;
    $r = aiPlatformSave(array_merge(['enabled' => '1', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'base_url' => '',
        'temperature' => '0.3', 'tenant_cap' => '5', 'global_cap' => '0', 'api_key' => $PLATKEY], $extra), 1);
    if (!$r['ok']) echo "  !! platOn failed: " . json_encode($r['errors']) . "\n";
}
function fake(array $resp): void { global $FAKE; file_put_contents($FAKE, json_encode($resp)); }
function fakeOk(): void { fake(['status' => 200, 'body' => ['choices' => [['message' => ['content' => 'Hello from fake']]], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500]]]); }
function worker(array $o): array {
    global $FAKE, $CAP;
    @unlink($CAP);
    putenv('AI_TEST_FAKE_PROVIDER=' . $FAKE);
    putenv('AI_TEST_FAKE_CAPTURE=' . $CAP);
    $out = shell_exec('php ' . escapeshellarg(__FILE__) . ' --worker ' . escapeshellarg(base64_encode(json_encode($o))) . ' 2>&1');
    $p = strpos((string)$out, '@@JSON@@');
    $j = $p === false ? null : json_decode(substr($out, $p + 8), true);
    if (!is_array($j)) { echo "  !! worker output: " . substr((string)$out, 0, 600) . "\n"; return []; }
    $j['_captures'] = is_file($CAP) ? array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($CAP)))) : [];
    return $j;
}
function authOf(array $j): string {
    foreach (($j['_captures'][0]['headers'] ?? []) as $h) if (stripos($h, 'Authorization:') === 0) return $h;
    return '';
}
function setPolicy(int $tid, string $mode, string $cap = ''): void { $r = aiSetTenantPolicy($tid, $mode, $cap, 1); if (!$r['ok']) echo "  !! policy: {$r['error']}\n"; }
function lastPlat(int $tid): ?array {
    global $c;
    $st = $c->prepare("SELECT * FROM platform_ai_usage WHERE tenant_id = ? ORDER BY id DESC LIMIT 1"); $st->execute([$tid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function lastTen(): ?array { global $pdo; return $pdo->query("SELECT * FROM ai_usage_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null; }

$T = (int)$c->query("SELECT id FROM tenants WHERE status <> 'deleted' ORDER BY id LIMIT 1")->fetchColumn();
$c->prepare("DELETE FROM tenant_ai_policy WHERE tenant_id = ?")->execute([$T]);
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id = ?")->execute([$T]);
$allOn = ['ai_assistant' => true];

section('1. No tenant (single-tenant / CLI) — unchanged behaviour');
ownOn(); platOn(); fakeOk();
$j = worker(['tenant' => null, 'call' => 1, 'uid' => 5]);
ok('source own, configured', ($j['source'] ?? '') === 'own' && $j['configured'] === true);
ok('call succeeds with the OWN key', ($j['res']['ok'] ?? false) && authOf($j) === 'Authorization: Bearer ' . $OWNKEY, authOf($j));
ok('tenant log names the real provider/model', (lastTen()['provider'] ?? '') === 'openai' && (lastTen()['model'] ?? '') === 'gpt-4o-mini');
ok('nothing metered on the platform', lastPlat($T) === null);
ownOff();
$j = worker(['tenant' => null]);
ok('own key off + no tenant → not configured (platform never used without a tenant)', ($j['configured'] ?? true) === false);
ownOn('http://localhost:11434/v1');
$j = worker(['tenant' => null, 'call' => 1]);
ok('single-tenant local gateway base URL still allowed (unchanged)', ($j['res']['ok'] ?? false) && strpos($j['_captures'][0]['url'] ?? '', 'http://localhost:11434/v1/chat/completions') === 0);

section('2. Tenant on auto');
ownOff(); platOn(); fakeOk();
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1, 'uid' => 9]);
ok('auto + no own key → platform, configured', ($j['source'] ?? '') === 'platform' && $j['configured'] === true);
ok('call succeeds with the PLATFORM key', ($j['res']['ok'] ?? false) && authOf($j) === 'Authorization: Bearer ' . $PLATKEY, authOf($j));
ok('platform temperature used', abs((float)($j['_captures'][0]['body']['temperature'] ?? 0) - 0.3) < 0.001);
$pu = lastPlat($T);
ok('metered in control DB with tenant + user + tokens + cost', $pu && (int)$pu['user_id'] === 9 && $pu['status'] === 'ok' && (int)$pu['prompt_tokens'] === 1000 && (float)$pu['est_cost'] > 0 && $pu['model'] === 'gpt-4o-mini');
ok('tenant log hides BJP provider/model', (lastTen()['provider'] ?? '') === 'platform' && (lastTen()['model'] ?? '') === 'platform');
$ownOnly = (float)$pdo->query("SELECT COALESCE(SUM(est_cost),0) FROM ai_usage_log WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01') AND (provider IS NULL OR provider <> 'platform')")->fetchColumn();
ok('tenant own-cap spend excludes platform calls', abs((float)($j['own_spend'] ?? -1) - $ownOnly) < 0.000001);
ok('aiCapInfo reports platform limit', ($j['cap']['source'] ?? '') === 'platform' && (float)$j['cap']['cap'] === 5.0);
ownOn();
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('auto + own working key → own key (existing set-ups keep working)', ($j['source'] ?? '') === 'own' && authOf($j) === 'Authorization: Bearer ' . $OWNKEY);

section('3. Explicit policy');
setPolicy($T, 'platform');
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('mode platform overrides own key', ($j['source'] ?? '') === 'platform' && authOf($j) === 'Authorization: Bearer ' . $PLATKEY);
setPolicy($T, 'own'); ownOff();
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('mode own + no own key → not configured, no call', $j['configured'] === false && ($j['res']['ok'] ?? true) === false && $j['_captures'] === []);
setPolicy($T, 'off'); ownOn();
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('mode off → no AI even with own key', ($j['source'] ?? '') === 'off' && $j['configured'] === false && $j['_captures'] === []);
setPolicy($T, 'auto'); ownOff();

section('4. Module entitlement (superadmin) — fixes G2');
$j = worker(['tenant' => $T, 'features' => ['ai_assistant' => false], 'call' => 1, 'digest' => 1]);
ok('restricted module → aiConfigured false', ($j['configured'] ?? true) === false);
ok('restricted module → aiComplete refuses, no provider call', ($j['res']['ok'] ?? true) === false && $j['_captures'] === [] && strpos($j['res']['error'], 'not available') !== false);
ok('notification digest falls back to the plain list', strpos((string)($j['digest'] ?? ''), 'item(s) needing attention') !== false);
ownOn(); setPolicy($T, 'own');
$j = worker(['tenant' => $T, 'features' => ['ai_assistant' => false], 'call' => 1]);
ok('restricted module blocks the OWN key too', ($j['configured'] ?? true) === false && $j['_captures'] === []);
setPolicy($T, 'auto'); ownOff();

section('5. Platform caps, rate limit, fail-closed');
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id = ?")->execute([$T]);
aiPlatformLogUsage($T, 1, 'ask', 'openai', 'gpt-4o-mini', 0, 0, 5.0, 'ok');
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('tenant cap reached → friendly limit message, no call', ($j['res']['ok'] ?? true) === false && strpos($j['res']['error'], 'limit has been reached') !== false && $j['_captures'] === []);
ok('blocked call metered with reason', (lastPlat($T)['status'] ?? '') === 'blocked' && strpos((string)lastPlat($T)['error'], 'tenant monthly cap') !== false);
ok('aiCapInfo shows exceeded', ($j['cap']['exceeded'] ?? false) === true);
setPolicy($T, 'auto', '50');
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('raised tenant cap → allowed again', ($j['res']['ok'] ?? false) === true);
platOn(['global_cap' => '0.01', 'api_key' => '']);
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('global cap reached → blocked for every tenant', ($j['res']['ok'] ?? true) === false && $j['_captures'] === [] && strpos((string)lastPlat($T)['error'], 'platform monthly cap') !== false);
platOn(['global_cap' => '0', 'api_key' => '']);
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1, 'meter_down' => 1]);
ok('meter unreachable → refused, never unmetered', ($j['res']['ok'] ?? true) === false && $j['_captures'] === [] && strpos($j['res']['error'], 'temporarily unavailable') !== false);
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id = ?")->execute([$T]);
$ins = $c->prepare("INSERT INTO platform_ai_usage (tenant_id, feature, status) VALUES (?, 'ask', 'ok')");
for ($i = 0; $i < 60; $i++) $ins->execute([$T]);
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('per-tenant rate limit (60/min) → busy, no call', ($j['res']['ok'] ?? true) === false && $j['_captures'] === [] && strpos($j['res']['error'], 'busy') !== false);
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id = ?")->execute([$T]);

section('6. Provider errors never leak keys');
fake(['status' => 401, 'body' => ['error' => ['message' => "Incorrect API key provided: $PLATKEY. Bearer $PLATKEY"]]]);
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('platform tenant sees only a neutral message', ($j['res']['ok'] ?? true) === false && $j['res']['error'] === 'AI is temporarily unavailable. Please try again later.');
ok('control-DB error log has no key', strpos((string)lastPlat($T)['error'], 'BJPKEY') === false && (lastPlat($T)['status'] ?? '') === 'error', (string)lastPlat($T)['error']);
ok('tenant error log has no key', strpos((string)lastTen()['error'], 'BJPKEY') === false);
fake(['status' => 429, 'body' => ['error' => ['message' => 'rate']]]);
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('provider throttling → neutral "busy" message for platform tenants', strpos((string)($j['res']['error'] ?? ''), 'busy') !== false && strpos((string)$j['res']['error'], 'billing') === false);
ownOn(); setPolicy($T, 'own');
fake(['status' => 401, 'body' => ['error' => ['message' => "Incorrect API key provided: $OWNKEY"]]]);
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('own-key tenant still gets the provider message, key redacted', strpos((string)($j['res']['error'] ?? ''), 'Incorrect API key') !== false && strpos($j['res']['error'], 'TENANTKEY') === false, (string)($j['res']['error'] ?? ''));
fake(['network_error' => "Could not resolve host; url ?key=$OWNKEY"]);
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('network errors redacted too', strpos((string)($j['res']['error'] ?? ''), 'TENANTKEY') === false);
fakeOk();

section('7. Base-URL SSRF guard at call time — fixes G3');
ownOn('https://127.0.0.1/v1'); setPolicy($T, 'own');
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('tenant own key → private base URL refused, no call', ($j['res']['ok'] ?? true) === false && $j['_captures'] === [] && strpos($j['res']['error'], 'base URL is not allowed') !== false);
ownOn('http://8.8.8.8/v1');
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('tenant own key → plain http refused', ($j['res']['ok'] ?? true) === false && $j['_captures'] === []);
ownOn('https://8.8.8.8/v1');
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('public https base URL allowed and pinned', ($j['res']['ok'] ?? false) && ($j['_captures'][0]['pin']['ips'] ?? []) === ['8.8.8.8'] && strpos($j['_captures'][0]['url'], 'https://8.8.8.8/v1/chat/completions') === 0);
ownOff(); setPolicy($T, 'platform');
$c->prepare("UPDATE platform_settings SET setting_value = 'https://10.0.0.5/v1' WHERE setting_key = 'ai_platform_base_url'")->execute();
$j = worker(['tenant' => $T, 'features' => $allOn, 'call' => 1]);
ok('platform base URL tampered to private → refused (neutral message)', ($j['res']['ok'] ?? true) === false && $j['_captures'] === [] && strpos($j['res']['error'], 'temporarily unavailable') !== false);
$c->prepare("UPDATE platform_settings SET setting_value = '' WHERE setting_key = 'ai_platform_base_url'")->execute();
setPolicy($T, 'auto');

section('8. Callers untouched');
$callers = ['api/ai/ask.php', 'api/ai/generate.php', 'api/ai/monthly_summary.php', 'api/ai/test_ai_config.php', 'api/ai_audit_analysis.php',
            'app/activity_log.php', 'app/constant/communication/ai_assistant.php', 'app/includes/ai_generate.php', 'core/notify.php',
            'header.php', 'includes/help_panel.php', 'core/help_nav.php'];
foreach ($callers as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    ok("$f reaches AI only through the engine", strpos($src, 'ai_api_key_enc') === false && strpos($src, '_aiHttp(') === false);
}
$eng = (string)file_get_contents($root . '/core/ai_service.php');
ok('fake-provider hook is CLI-only', strpos($eng, "PHP_SAPI === 'cli' ? (string)getenv('AI_TEST_FAKE_PROVIDER')") !== false);

echo "\nPassed: $pass   Failed: $fail\n";
exit($fail ? 1 : 0);
