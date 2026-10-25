<?php
/**
 * tests/test_platform_ai_phase7_cli.php — Platform AI, Phase 7 (platform_ai_plan.md):
 * the chat is titled after the company's product (Ask Smart POS / Ask Smart BMS),
 * the AI replies in the language the user chose, it is told the user's role and
 * gets ONLY the data functions that role may open (re-checked on the server),
 * shop/warehouse scope narrows its figures, and AI formatting is rendered as
 * text nodes only. Runs the real api/ai/ask.php against the FAKE provider.
 *
 *   php tests/test_platform_ai_phase7_cli.php
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
    if ($argv[2] === '--product') {
        require_once "$root/core/ai_service.php";
        echo '@@PRODUCT=' . aiProductName();
        exit;
    }
    if ($argv[2] === '--insights') {
        require_once "$root/core/ai_insights.php";
        $names = array_column(aiInsightCatalog(), 'name');
        $out = ['catalog' => $names, 'payroll' => aiRunInsight('payroll_summary', ['period' => 'this_year'])];
        foreach (['low_stock', 'revenue'] as $n) $out[$n] = aiRunInsight($n, ['from' => '2000-01-01', 'to' => date('Y-m-d'), 'limit' => 50]);
        echo '@@JSON=' . json_encode($out);
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

echo "\nBMS — Platform AI, Phase 7 (product title, reply language, role + shop limits)\n";

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_pai7_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$FAKE = $tmp . DIRECTORY_SEPARATOR . 'fake.json';
$CAPF = $tmp . DIRECTORY_SEPARATOR . 'capture.jsonl';
putenv('AI_TEST_FAKE_PROVIDER=' . $FAKE);
putenv('AI_TEST_FAKE_CAPTURE=' . $CAPF);
file_put_contents($FAKE, json_encode(['status' => 200, 'body' => ['choices' => [['message' => ['content' => "Habari! Naweza kukusaidia."]]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20]]]));

$T = (int)$c->query("SELECT id FROM tenants WHERE status <> 'deleted' ORDER BY id LIMIT 1")->fetchColumn();
$snapPS   = $c->query("SELECT setting_key, setting_value, updated_by FROM platform_settings")->fetchAll(PDO::FETCH_ASSOC);
$snapPol  = $c->query("SELECT * FROM tenant_ai_policy")->fetchAll(PDO::FETCH_ASSOC);
$maxPU    = (int)$c->query("SELECT COALESCE(MAX(id),0) FROM platform_ai_usage")->fetchColumn();
$snapPlan = $c->query("SELECT plan FROM tenants WHERE id = $T")->fetchColumn();
$snapAI   = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'ai\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$snapSimple = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$maxTU    = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM ai_usage_log")->fetchColumn();

$c->exec("INSERT INTO plans (plan_key, product_key, name, is_active) VALUES ('__pai7_shop', 'bms_shop', 'pai7 shop', 0), ('__pai7_full', 'bms_full', 'pai7 full', 0)");
$pdo->exec("INSERT INTO roles (role_name, is_admin, description, created_at, updated_at) VALUES ('Cashier pai7', 0, 'phase 7 test', NOW(), NOW())");
$role = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (username, email, first_name, last_name, role_id, is_active, password, created_at) VALUES ('__pai7_staff', 'pai7staff@example.test', 'Neema', 'Staff', ?, 1, 'x', NOW())")->execute([$role]);
$staffUid = (int)$pdo->lastInsertId();

register_shutdown_function(function () use ($c, $pdo, $T, $snapPS, $snapPol, $maxPU, $snapPlan, $snapAI, $snapSimple, $maxTU, $tmp, $staffUid, $role) {
    $c->exec("DELETE FROM platform_settings");
    $i = $c->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)");
    foreach ($snapPS as $r) $i->execute([$r['setting_key'], $r['setting_value'], $r['updated_by']]);
    $c->exec("DELETE FROM tenant_ai_policy");
    $i = $c->prepare("INSERT INTO tenant_ai_policy (tenant_id, mode, monthly_cap, updated_by, updated_at) VALUES (?,?,?,?,?)");
    foreach ($snapPol as $r) $i->execute([$r['tenant_id'], $r['mode'], $r['monthly_cap'], $r['updated_by'], $r['updated_at']]);
    $c->prepare("DELETE FROM platform_ai_usage WHERE id > ?")->execute([$maxPU]);
    $c->prepare("UPDATE tenants SET plan = ? WHERE id = ?")->execute([$snapPlan === false ? null : $snapPlan, $T]);
    $c->exec("DELETE FROM plans WHERE plan_key IN ('__pai7_shop', '__pai7_full')");
    $pdo->exec("DELETE FROM system_settings WHERE setting_key LIKE 'ai\\_%'");
    $i = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, updated_at) VALUES (?,?,'ai','0',NOW())");
    foreach ($snapAI as $k => $v) $i->execute([$k, $v]);
    if ($snapSimple === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    else $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$snapSimple]);
    $pdo->prepare("DELETE FROM ai_usage_log WHERE id > ?")->execute([$maxTU]);
    $pdo->prepare('DELETE FROM user_scope_overrides WHERE user_id = ?')->execute([$staffUid]);
    $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$staffUid]);
    $pdo->prepare('DELETE FROM roles WHERE role_id = ?')->execute([$role]);
    foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$TOKEN = bin2hex(random_bytes(16));
$S_ADMIN = ['user_id' => $adminUid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1, 'role' => 'admin', 'csrf_token' => $TOKEN];
$S_STAFF = ['user_id' => $staffUid, 'username' => '__pai7_staff', 'is_admin' => false, 'role_id' => $role, 'role' => 'Cashier pai7', 'csrf_token' => $TOKEN,
            'permissions' => ['ai_assistant' => ['view' => true]]];
$ON = ['ai_assistant' => true];
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
function tag(string $out, string $k): string { return preg_match('~@@' . $k . '=(.*)$~s', $out, $m) ? trim($m[1]) : ''; }
/** The system prompt of the last captured provider call. */
function lastPrompt(): string {
    global $CAPF;
    $lines = array_filter(explode("\n", (string)@file_get_contents($CAPF)));
    if (!$lines) return '';
    $req = json_decode((string)end($lines), true);
    $body = is_array($req['body'] ?? null) ? $req['body'] : json_decode((string)($req['body'] ?? ''), true);
    return (string)($body['messages'][0]['content'] ?? '');
}
function setSimple(string $v): void {
    global $pdo;
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, updated_at) VALUES ('pos_simple_mode', ?, 'pos', '0', NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
}
$r = aiPlatformSave(['enabled' => '1', 'provider' => 'openai', 'model' => 'zz-p7-model', 'base_url' => '', 'temperature' => '0.4',
                     'tenant_cap' => '5', 'global_cap' => '0', 'api_key' => 'sk-plat-P7KEY0123456789'], 1);
if (!$r['ok']) echo "  !! platform save " . json_encode($r['errors']) . "\n";
$pdo->exec("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'ai_enabled'");
$c->prepare("DELETE FROM tenant_ai_policy WHERE tenant_id = ?")->execute([$T]);
$c->prepare("DELETE FROM platform_ai_usage WHERE tenant_id = ?")->execute([$T]);
$crashed = fn(string $h) => str_contains($h, 'Fatal error') || str_contains($h, 'Uncaught') || str_contains($h, 'Parse error');

section('1. Lint');
foreach (['core/ai_insights.php', 'core/ai_service.php', 'api/ai/ask.php', 'api/support_help.php', 'includes/ask_widget.php', 'lang/sw.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

section('2. Product name: Ask Smart POS / Ask Smart BMS');
$c->prepare("UPDATE tenants SET plan = '__pai7_shop' WHERE id = ?")->execute([$T]);
ok('tenant on a Smart POS plan → "Smart POS"', tag(tw('--product', ['session' => $S_ADMIN, 'tenant' => $T]), 'PRODUCT') === 'Smart POS');
$h = tw($PAGE, ['session' => $S_ADMIN + ['user_lang' => 'en'], 'method' => 'GET', 'tenant' => $T, 'features' => $ON]);
ok('  ...chat title reads "Ask Smart POS"', !$crashed($h) && str_contains($h, 'id="bmsAskTitle">Ask Smart POS<'), substr($h, 0, 300));
ok('  ...no "Ask BMS" in the chat title', !str_contains($h, 'id="bmsAskTitle">Ask BMS<'));
$h = tw($PAGE, ['session' => $S_ADMIN + ['user_lang' => 'sw'], 'method' => 'GET', 'tenant' => $T, 'features' => $ON]);
ok('  ...Swahili: "Uliza Smart POS"', str_contains($h, 'id="bmsAskTitle">Uliza Smart POS<'), substr($h, 0, 300));
$c->prepare("UPDATE tenants SET plan = '__pai7_full' WHERE id = ?")->execute([$T]);
ok('tenant on a Smart BMS plan → "Smart BMS"', tag(tw('--product', ['session' => $S_ADMIN, 'tenant' => $T]), 'PRODUCT') === 'Smart BMS');
$h = tw($PAGE, ['session' => $S_ADMIN + ['user_lang' => 'en'], 'method' => 'GET', 'tenant' => $T, 'features' => $ON]);
ok('  ...chat title reads "Ask Smart BMS"', str_contains($h, 'id="bmsAskTitle">Ask Smart BMS<'), substr($h, 0, 300));
setSimple('1');
ok('single-company install in Simple POS mode → "Smart POS"', tag(tw('--product', ['session' => $S_ADMIN]), 'PRODUCT') === 'Smart POS');
setSimple('0');
ok('single-company install, full mode → "Smart BMS"', tag(tw('--product', ['session' => $S_ADMIN]), 'PRODUCT') === 'Smart BMS');
$sw = require "$root/lang/sw.php";
ok('sw: Ask Smart POS / Ask Smart BMS translated', ($sw['Ask Smart POS'] ?? '') === 'Uliza Smart POS' && ($sw['Ask Smart BMS'] ?? '') === 'Uliza Smart BMS');
$c->prepare("UPDATE tenants SET plan = '__pai7_shop' WHERE id = ?")->execute([$T]);

section('3. Reply language follows the user\'s chosen language');
@unlink($CAPF);
$r = api('api/ai/ask.php', ['question' => 'What can you do for me?'], ['session' => $S_ADMIN + ['user_lang' => 'sw'], 'tenant' => $T, 'features' => $ON]);
$p = lastPrompt();
ok('Swahili user: answer returned', ($r['success'] ?? false) === true, json_encode($r));
ok('  ...AI told to ALWAYS answer in Swahili', str_contains($p, 'ALWAYS write your answer in Swahili (Kiswahili)'), substr($p, 0, 200));
ok('  ...even when the question is typed in another language', str_contains($p, 'even when the question is written in another language'));
ok('  ...told it is inside Smart POS', str_contains($p, 'You are the assistant inside Smart POS'));
@unlink($CAPF);
api('api/ai/ask.php', ['question' => 'Naweza kufanya nini?'], ['session' => $S_ADMIN + ['user_lang' => 'en'], 'tenant' => $T, 'features' => $ON]);
ok('English user (Swahili question): AI told to answer in English', str_contains(lastPrompt(), 'ALWAYS write your answer in English'));
$r = api('api/support_help.php', ['action' => 'home', 'page_key' => ''], ['session' => $S_ADMIN + ['user_lang' => 'sw'], 'tenant' => $T, 'features' => $ON]);
ok('help endpoint loads the user\'s language (was always English)', ($r['success'] ?? false) === true, json_encode($r));
ok('ask.php and support_help.php load the saved language', str_contains((string)file_get_contents("$root/api/ai/ask.php"), "loadLanguage(\$_SESSION['user_lang'])")
    && str_contains((string)file_get_contents("$root/api/support_help.php"), "loadLanguage(\$_SESSION['user_lang'])"));

section('4. The AI only gets what the role may open');
@unlink($CAPF);
api('api/ai/ask.php', ['question' => 'What can you do?'], ['session' => $S_ADMIN, 'tenant' => $T]);
$p = lastPrompt();
ok('admin: told they are the company administrator', str_contains($p, 'the company administrator'), substr($p, 0, 300));
ok('  ..."what can you do" limited to the listed functions', str_contains($p, "'What can you do?'") && str_contains($p, 'never offer data that is not in the list'));
@unlink($CAPF);
$r = api('api/ai/ask.php', ['question' => 'What can you do?'], ['session' => $S_STAFF, 'tenant' => $T, 'features' => $ON]);
$p = lastPrompt();
ok('staff with only AI Assistant: answer returned', ($r['success'] ?? false) === true, json_encode($r));
ok('  ...told they are staff, not an administrator, with their role', str_contains($p, 'a staff member (role: Cashier pai7), not an administrator'));
ok('  ...no money/HR functions in the prompt', !str_contains($p, '"revenue"') && !str_contains($p, '"payroll_summary"') && !str_contains($p, '"cash_position"') && !str_contains($p, '"profit"'));
ok('  ...how-to help still available', str_contains($p, '"search_help"'));
ok('  ...no personal name sent to the provider', !str_contains($p, 'Neema'));
$o = json_decode(tag(tw('--insights', ['session' => $S_STAFF]), 'JSON'), true) ?: [];
ok('server re-checks: staff cannot run payroll even if the AI asks', ($o['payroll']['ok'] ?? true) === false && str_contains((string)($o['payroll']['error'] ?? ''), 'not available to your role'), json_encode($o['payroll'] ?? null));
ok('  ...staff catalog is how-to help only', ($o['catalog'] ?? null) === ['search_help'], json_encode($o['catalog'] ?? null));
$o = json_decode(tag(tw('--insights', ['session' => $S_ADMIN]), 'JSON'), true) ?: [];
ok('admin (all modules on) gets the full catalog', count($o['catalog'] ?? []) >= 20 && in_array('payroll_summary', $o['catalog'], true) && in_array('revenue', $o['catalog'], true), json_encode($o['catalog'] ?? null));
require_once "$root/core/feature_registry.php";
$NOHR = $ON + array_fill_keys(featureForPageKey('payroll'), false);
$o = json_decode(tag(tw('--insights', ['session' => $S_ADMIN, 'tenant' => $T, 'features' => $NOHR]), 'JSON'), true) ?: [];
ok('admin, but the HR module switched off for the company → its data is not offered', !in_array('payroll_summary', $o['catalog'] ?? [], true), json_encode($o['catalog'] ?? null));
$S_STOCK = $S_STAFF; $S_STOCK['permissions'] += ['products' => ['view' => true], 'sales_report' => ['view' => true]];
$o = json_decode(tag(tw('--insights', ['session' => $S_STOCK]), 'JSON'), true) ?: [];
ok('staff with Products + Sales report: gets stock and sales, still no payroll', in_array('low_stock', $o['catalog'] ?? [], true) && in_array('revenue', $o['catalog'] ?? [], true) && !in_array('payroll_summary', $o['catalog'] ?? [], true), json_encode($o['catalog'] ?? null));

section('5. Shop/warehouse scope narrows the figures');
$wh = $pdo->query("SELECT warehouse_id FROM product_stocks GROUP BY warehouse_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
if ($wh === false) {
    ok('scope test skipped: no stock rows', true);
} else {
    $wh = (int)$wh;
    $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by, granted_at) VALUES (?, 'warehouse', ?, ?, NOW())")->execute([$staffUid, $wh, $adminUid]);
    $o = json_decode(tag(tw('--insights', ['session' => $S_STOCK]), 'JSON'), true) ?: [];
    $exp = (int)$pdo->query("SELECT COUNT(*) FROM products p
              LEFT JOIN (SELECT product_id, SUM(stock_quantity - reserved_quantity) av FROM product_stocks WHERE warehouse_id = $wh GROUP BY product_id) s ON s.product_id = p.product_id
             WHERE p.is_service = 0 AND p.status = 'active' AND p.min_stock_level > 0 AND COALESCE(s.av, 0) <= p.min_stock_level
               AND p.project_id IS NULL AND (p.warehouse_id IS NULL OR p.warehouse_id = $wh)")->fetchColumn();
    ok("low stock counts only the assigned shop (#$wh): $exp", ($o['low_stock']['ok'] ?? false) && (int)$o['low_stock']['data']['below_minimum_count'] === $exp, json_encode($o['low_stock'] ?? null));
    $today = $pdo->quote(date('Y-m-d'));
    $pos = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM pos_sales WHERE sale_status = 'completed' AND DATE(sale_date) BETWEEN '2000-01-01' AND $today
               AND project_id IS NULL AND (warehouse_id IS NULL OR warehouse_id = $wh)")->fetchColumn();
    $all = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM pos_sales WHERE sale_status = 'completed' AND DATE(sale_date) BETWEEN '2000-01-01' AND $today")->fetchColumn();
    ok("POS sales cover only that shop ($pos of $all)", ($o['revenue']['ok'] ?? false) && abs((float)$o['revenue']['data']['pos_sales'] - $pos) < 0.01, json_encode($o['revenue'] ?? null));
    $other = (int)$pdo->query("SELECT COUNT(*) FROM pos_sales WHERE sale_status = 'completed' AND warehouse_id IS NOT NULL AND warehouse_id <> $wh")->fetchColumn();
    ok('  ...another shop\'s sales are excluded', $other === 0 || $pos < $all);
    $pdo->prepare('DELETE FROM user_scope_overrides WHERE user_id = ?')->execute([$staffUid]);
}
$src = (string)file_get_contents("$root/core/ai_insights.php");
ok('sales read invoices AND POS sales', str_contains($src, 'FROM pos_sales ps') && str_contains($src, '_aiSalesRows('));
ok('profit and expenses come from the Income Statement engine', substr_count($src, 'glProfitLoss($pdo') >= 2);
ok('cash comes from the Balance Sheet engine, not stored balances', str_contains($src, 'glBalanceSheet($pdo') && !str_contains($src, 'current_balance'));
ok('ledger reads use project + shop scope', str_contains($src, "function _aiJeScope(): string { return _aiScope('je') . _aiWhScope('je'); }"));

section('6. Formatting rendered safely');
$w = (string)file_get_contents("$root/includes/ask_widget.php");
ok('AI answers go through rich(), not raw text', str_contains($w, "w.appendChild(rich(m.t))"));
ok('rich() builds text nodes and <strong>/<ul>/<ol> only', str_contains($w, "el('strong', null, part.slice(2, -2))") && str_contains($w, 'document.createTextNode('));
ok('still only one innerHTML (server-escaped article bodies)', substr_count($w, '.innerHTML') === 1);
ok('"Talk to a person" transcript drops ** marks', str_contains($w, "String(m.t).replace(/\\*\\*/g, '')"));
$ask = (string)file_get_contents("$root/api/ai/ask.php");
ok('AI told to use simple lists/bold, no HTML or links', str_contains($ask, 'No tables, HTML, links or code blocks'));
ok('role read from the server session only', str_contains($ask, "(int)(\$_SESSION['user_id'] ?? 0)") && !preg_match('~\$_(POST|GET|REQUEST)\[[\'"]role~', $ask));

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
