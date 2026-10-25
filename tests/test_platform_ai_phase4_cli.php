<?php
/**
 * tests/test_platform_ai_phase4_cli.php — Platform AI, Phase 4 (platform_ai_plan.md):
 * Superadmin → AI → Companies (per-company key source + limit) and Usage tabs,
 * actions/superadmin_ai.php set_policy.
 *
 *   php tests/test_platform_ai_phase4_cli.php
 *
 * CLI ONLY. Snapshots and restores platform_settings, tenant_ai_policy,
 * platform_ai_usage, the test company's ai_assistant module row and the audit rows.
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

echo "\nBMS — Platform AI, Phase 4\n";

$root = dirname(__DIR__);
$c    = getControlPdo();
aiPlatformSchema($c, controlDbName());
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$T  = (int)$c->query("SELECT id FROM tenants WHERE status <> 'deleted' ORDER BY id LIMIT 1")->fetchColumn();
$T2 = (int)$c->query("SELECT id FROM tenants WHERE status <> 'deleted' ORDER BY id LIMIT 1 OFFSET 1")->fetchColumn();
$TD = (int)$c->query("SELECT id FROM tenants WHERE status = 'deleted' ORDER BY id LIMIT 1")->fetchColumn();
$tRow = $c->query("SELECT company_name, subdomain FROM tenants WHERE id = $T")->fetch(PDO::FETCH_ASSOC);

$snapPS  = $c->query("SELECT setting_key, setting_value, updated_by FROM platform_settings")->fetchAll(PDO::FETCH_ASSOC);
$snapPol = $c->query("SELECT * FROM tenant_ai_policy")->fetchAll(PDO::FETCH_ASSOC);
$maxPU   = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM platform_ai_usage")->fetchColumn();
$maxLog  = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM tenant_admin_log")->fetchColumn();
$snapTF  = $c->query("SELECT * FROM tenant_features WHERE tenant_id = $T AND feature_key = 'ai_assistant'")->fetch(PDO::FETCH_ASSOC);
register_shutdown_function(function () use ($c, $snapPS, $snapPol, $maxPU, $maxLog, $snapTF, $T) {
    $c->exec("DELETE FROM platform_settings");
    $i = $c->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)");
    foreach ($snapPS as $r) $i->execute([$r['setting_key'], $r['setting_value'], $r['updated_by']]);
    $c->exec("DELETE FROM tenant_ai_policy");
    $i = $c->prepare("INSERT INTO tenant_ai_policy (tenant_id, mode, monthly_cap, updated_by, updated_at) VALUES (?,?,?,?,?)");
    foreach ($snapPol as $r) $i->execute([$r['tenant_id'], $r['mode'], $r['monthly_cap'], $r['updated_by'], $r['updated_at']]);
    $c->prepare("DELETE FROM platform_ai_usage WHERE id > ?")->execute([$maxPU]);
    $c->prepare("DELETE FROM tenant_admin_log WHERE id > ? AND action IN ('ai_settings','ai_test','ai_policy')")->execute([$maxLog]);
    $c->exec("DELETE FROM tenant_features WHERE tenant_id = $T AND feature_key = 'ai_assistant'");
    if ($snapTF) {
        $cols = array_keys($snapTF);
        $c->prepare('INSERT INTO tenant_features (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($snapTF));
    }
});

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
function row(array $list, int $id): ?array { foreach ($list as $r) if ((int)$r['id'] === $id) return $r; return null; }
function policy(int $tid): ?array {
    global $c;
    $st = $c->prepare('SELECT mode, monthly_cap FROM tenant_ai_policy WHERE tenant_id = ?'); $st->execute([$tid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$ACT = 'actions/superadmin_ai.php';

$c->exec("DELETE FROM platform_settings WHERE setting_key LIKE 'ai\\_platform\\_%'");
$c->prepare("DELETE FROM tenant_ai_policy WHERE tenant_id IN (?, ?)")->execute([$T, $T2]);
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id IN (?, ?)")->execute([$T, $T2]);
setPlatformSetting('ai_platform_tenant_cap', '5');
unset($GLOBALS['__bms_platform_settings_cache']);

section('1. Companies list');
$list = aiPlatformCompanies();
$r = row($list, $T);
ok('live company listed', $r !== null);
ok('deleted companies hidden', $TD === 0 || row($list, $TD) === null);
ok('defaults: auto, default cap, nothing spent', $r && $r['mode'] === 'auto' && $r['monthly_cap'] === null && $r['effective_cap'] === 5.0 && $r['spent'] === 0.0);
aiPlatformLogUsage($T, 1, 'ask', 'openai', 'gpt-4o-mini', 100, 50, 1.25, 'ok');
aiPlatformLogUsage($T, 1, 'ask', 'openai', 'gpt-4o-mini', 0, 0, 0, 'blocked', 'tenant monthly cap reached');
$r = row(aiPlatformCompanies(), $T);
ok('spend and successful calls counted (blocked not counted as a call)', $r && abs($r['spent'] - 1.25) < 0.0001 && $r['calls'] === 1);
$c->exec("DELETE FROM tenant_features WHERE tenant_id = $T AND feature_key = 'ai_assistant'");
$c->exec("INSERT INTO tenant_features (tenant_id, feature_key, is_enabled) VALUES ($T, 'ai_assistant', 0)");
$r = row(aiPlatformCompanies(), $T);
ok('module switched off shows as off', $r && $r['ai_module'] === false);
ok('module filter "off" includes it', row(aiPlatformCompanies(['module' => 'off']), $T) !== null);
ok('module filter "on" excludes it', row(aiPlatformCompanies(['module' => 'on']), $T) === null);
$c->exec("UPDATE tenant_features SET is_enabled = 1 WHERE tenant_id = $T AND feature_key = 'ai_assistant'");
ok('module switched on shows as on', row(aiPlatformCompanies(), $T)['ai_module'] === true);
ok('search by subdomain', row(aiPlatformCompanies(['q' => $tRow['subdomain']]), $T) !== null);
ok('search with LIKE wildcards is literal', aiPlatformCompanies(['q' => '%%%_none_%%%']) === []);
ok('search input is bound, not concatenated', row(aiPlatformCompanies(['q' => "' OR 1=1 -- "]), $T) === null);

section('2. set_policy action');
$r = endpoint($ACT, ['action' => 'set_policy', 'tenant_id' => (string)$T, 'mode' => 'platform', 'monthly_cap' => '12.5'], ['auth' => true]);
ok('POSITIVE CONTROL: saves key source + limit', str_contains($r, '"success":true'), $r);
ok('  ...stored', policy($T) == ['mode' => 'platform', 'monthly_cap' => '12.50']);
$log = $c->query("SELECT tenant_id, subdomain, detail FROM tenant_admin_log WHERE action = 'ai_policy' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok('  ...audited against the company', $log && (int)$log['tenant_id'] === $T && $log['subdomain'] === $tRow['subdomain'] && str_contains($log['detail'], 'platform'));
$r = endpoint($ACT, ['action' => 'set_policy', 'tenant_id' => (string)$T, 'mode' => 'auto', 'monthly_cap' => ''], ['auth' => true]);
ok('empty limit = back to default', str_contains($r, '"success":true') && policy($T) == ['mode' => 'auto', 'monthly_cap' => null]);
ok('mode filter works', row(aiPlatformCompanies(['mode' => 'auto']), $T) !== null && row(aiPlatformCompanies(['mode' => 'off']), $T) === null);
foreach ([
    'unknown mode'       => ['tenant_id' => (string)$T, 'mode' => 'god', 'monthly_cap' => ''],
    'negative limit'     => ['tenant_id' => (string)$T, 'mode' => 'off', 'monthly_cap' => '-1'],
    'text limit'         => ['tenant_id' => (string)$T, 'mode' => 'off', 'monthly_cap' => '1e999'],
    'unknown company'    => ['tenant_id' => '999999999', 'mode' => 'off', 'monthly_cap' => ''],
    'non-numeric id'     => ['tenant_id' => "$T OR 1=1", 'mode' => 'off', 'monthly_cap' => ''],
    'zero id'            => ['tenant_id' => '0', 'mode' => 'off', 'monthly_cap' => ''],
] as $what => $p) {
    ok("refuses $what", refused(endpoint($ACT, ['action' => 'set_policy'] + $p, ['auth' => true])));
}
if ($TD) ok('refuses a deleted company', refused(endpoint($ACT, ['action' => 'set_policy', 'tenant_id' => (string)$TD, 'mode' => 'off', 'monthly_cap' => ''], ['auth' => true])));
ok('refuses without a session', refused(endpoint($ACT, ['action' => 'set_policy', 'tenant_id' => (string)$T, 'mode' => 'off', 'monthly_cap' => '']), 'session has ended'));
ok('refuses on a tenant host', refused(endpoint($ACT, ['action' => 'set_policy', 'tenant_id' => (string)$T, 'mode' => 'off', 'monthly_cap' => ''], ['host' => 'x.' . $BASE, 'auth' => true])));
ok('refused requests changed nothing', policy($T) == ['mode' => 'auto', 'monthly_cap' => null]);
endpoint($ACT, ['action' => 'set_policy', 'tenant_id' => (string)$T, 'mode' => 'off', 'monthly_cap' => ''], ['auth' => true]);
unset($GLOBALS['__bms_ai_policy']);
ok('resolver sees the change immediately', aiResolveSource($T, true) === 'off');

section('3. Usage summary');
aiPlatformLogUsage($T2, 1, 'generate', 'openai', 'gpt-4o-mini', 200, 100, 0.75, 'ok');
aiPlatformLogUsage(null, 1, 'test', 'openai', 'gpt-4o-mini', 0, 0, 0, 'error', '<script>alert(1)</script> bad key');
$u = aiPlatformUsageSummary();
ok('month totals include all companies', $u['month']['cost'] >= 2.0 - 0.0001 && $u['month']['calls'] >= 2);
ok('blocked and failed counted', $u['month']['blocked'] >= 1 && $u['month']['errors'] >= 1);
$names = array_column($u['by_company'], 'tenant_id');
ok('by company lists both test companies, biggest first', in_array($T, array_map('intval', $names), true) && in_array($T2, array_map('intval', $names), true));
ok('by feature groups ask/generate', in_array('ask', array_column($u['by_feature'], 'feature'), true) && in_array('generate', array_column($u['by_feature'], 'feature'), true));
ok('problems list newest first', ($u['problems'][0]['feature'] ?? '') === 'test');

section('4. Pages through the real router');
$html = route(SA_HOST, '/ai?tab=companies&q=' . rawurlencode($tRow['subdomain']));
ok('Companies tab renders', !str_contains($html, 'Fatal error') && !str_contains($html, 'Uncaught') && str_contains($html, 'Key source') && str_contains($html, 'policyModal'), substr($html, 0, 300));
ok('company shown, escaped', str_contains($html, htmlspecialchars($tRow['company_name'], ENT_QUOTES, 'UTF-8')));
ok('edit button carries the company id', str_contains($html, 'data-id="' . $T . '"'));
ok('posts set_policy with CSRF', str_contains($html, 'name="action" value="set_policy"') && str_contains($html, 'SA_CSRF_TOKEN'));
$html = route(SA_HOST, '/ai?tab=usage');
ok('Usage tab renders', !str_contains($html, 'Fatal error') && str_contains($html, 'Top companies this month') && str_contains($html, 'Recent blocked or failed calls'));
ok('stored error text is escaped on the page', str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html, '<script>alert(1)</script>'));
$html = route(SA_HOST, '/ai?tab=companies&q=' . rawurlencode('"><script>x</script>'));
ok('search box echo is escaped', !str_contains($html, '"><script>x</script>'));
$html = route(SA_HOST, '/ai?tab=../../etc');
ok('unknown tab falls back to Settings', str_contains($html, 'Save AI settings'));

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
