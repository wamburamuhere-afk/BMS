<?php
/**
 * tests/test_support_center_phase1_cli.php — Help & Support Center, Phase 1
 * (support_center_plan.md): control-DB schema, support settings, the
 * superadmin Support page + save action, and the fixed help.php contact card.
 *
 *   php tests/test_support_center_phase1_cli.php
 *
 * CLI ONLY. Snapshots platform_settings and restores it afterwards.
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
require_once __DIR__ . '/../core/support.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Help & Support Center, Phase 1\n";

$root = dirname(__DIR__);
$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$snapshot = $c->query("SELECT setting_key, setting_value, updated_by FROM platform_settings")->fetchAll();
register_shutdown_function(function () use ($c, $snapshot) {
    $c->exec("DELETE FROM platform_settings");
    $ins = $c->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)");
    foreach ($snapshot as $row) $ins->execute([$row['setting_key'], $row['setting_value'], $row['updated_by']]);
});
$c->exec("DELETE FROM platform_settings WHERE setting_key LIKE 'support\\_%'");
unset($GLOBALS['__bms_platform_settings_cache']);

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

// ─────────────────────────────────────────────────────────────────────────────
section('1. Every new/changed file lints clean');
foreach (['core/support.php', 'app/superadmin/support.php', 'actions/superadmin_support_settings.php',
          'app/constant/settings/help.php', 'core/superadmin_ui.php', 'core/superadmin_auth.php',
          'scripts/setup_control_db.php', 'lang/sw.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. supportEnsureSchema() — idempotent, all tables present');
$db = controlDbName();
$t1 = supportEnsureSchema($c, $db);
$t2 = supportEnsureSchema($c, $db);
ok('returns the same 8 tables on a second run (no error = idempotent)', count($t1) === 8 && $t1 === $t2);
$have = $c->query("SELECT table_name FROM information_schema.tables WHERE table_schema = " . $c->quote($db) . " AND table_name LIKE 'support\\_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($t1 as $t) ok("table $t exists", in_array($t, $have, true));
$cols = $c->query("SELECT column_name FROM information_schema.columns WHERE table_schema = " . $c->quote($db) . " AND table_name = 'support_tickets'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['ref_no', 'tenant_id', 'product_key', 'page_key', 'forwarded_from', 'first_response_at', 'rating'] as $col) {
    ok("support_tickets.$col exists", in_array($col, $cols, true));
}
$mcols = $c->query("SELECT column_name FROM information_schema.columns WHERE table_schema = " . $c->quote($db) . " AND table_name = 'support_ticket_messages'")->fetchAll(PDO::FETCH_COLUMN);
ok('messages carry is_internal (internal notes)', in_array('is_internal', $mcols, true));
$setup = file_get_contents("$root/scripts/setup_control_db.php");
ok('setup_control_db.php (run on every deploy) creates the support tables', str_contains($setup, 'supportEnsureSchema($admin, $controlDb)'));

// ─────────────────────────────────────────────────────────────────────────────
section('3. supportValidateSettings() — pure validation');
$good = supportValidateSettings([
    'support_email' => ' help@example.test ', 'support_phone' => '+255 700 000 000', 'support_whatsapp' => '255700000000',
    'support_hours' => 'Mon–Fri 08:00–17:00', 'support_recipients' => 'a@example.test; b@example.test,a@example.test',
    'support_sla_urgent' => '2', 'support_sla_high' => '4', 'support_sla_normal' => '8', 'support_sla_low' => '16',
    'support_autoclose_days' => '5', 'support_attach_max_mb' => '5',
]);
ok('valid input accepted', $good['ok'] === true, json_encode($good['errors']));
ok('email trimmed', $good['values']['support_email'] === 'help@example.test');
ok('recipients normalised and de-duplicated', $good['values']['support_recipients'] === 'a@example.test,b@example.test');
ok('empty contact fields are allowed', supportValidateSettings([])['ok'] === true);
ok('bad email refused', isset(supportValidateSettings(['support_email' => 'nope'])['errors']['support_email']));
ok('bad phone refused', isset(supportValidateSettings(['support_phone' => 'call me'])['errors']['support_phone']));
ok('bad recipient refused', isset(supportValidateSettings(['support_recipients' => 'a@example.test, junk'])['errors']['support_recipients']));
ok('SLA of 0 refused', isset(supportValidateSettings(['support_sla_urgent' => '0'])['errors']['support_sla_urgent']));
ok('non-numeric SLA refused', isset(supportValidateSettings(['support_sla_high' => '4h'])['errors']['support_sla_high']));
ok('attachment limit above 20 MB refused', isset(supportValidateSettings(['support_attach_max_mb' => '50'])['errors']['support_attach_max_mb']));
ok('only known keys are returned (nothing else can be written)', array_keys($good['values']) == array_keys(supportSettingDefaults()) || !array_diff(array_keys($good['values']), array_keys(supportSettingDefaults())));

// ─────────────────────────────────────────────────────────────────────────────
section('4. supportSettings() — defaults and round-trip');
$d = supportSettings();
ok('defaults when nothing saved: SLA urgent 2h', $d['support_sla_urgent'] === '2');
ok('defaults when nothing saved: no contact email', $d['support_email'] === '');

// ─────────────────────────────────────────────────────────────────────────────
section('5. actions/superadmin_support_settings.php — guards + save');
$payload = ['action' => 'save_support', 'support_email' => 'care@example.test', 'support_phone' => '+255 711 111 111',
            'support_whatsapp' => '', 'support_hours' => 'Mon–Fri 08:00–17:00', 'support_recipients' => 'agent@example.test',
            'support_sla_urgent' => '3', 'support_sla_high' => '4', 'support_sla_normal' => '8', 'support_sla_low' => '16',
            'support_autoclose_days' => '7', 'support_attach_max_mb' => '5'];
$r = endpoint('actions/superadmin_support_settings.php', $payload, ['auth' => true]);
ok('POSITIVE CONTROL: operator can save', str_contains($r, '"success":true'), substr($r, 0, 300));
unset($GLOBALS['__bms_platform_settings_cache']);
$s = supportSettings();
ok('  ...email persisted', $s['support_email'] === 'care@example.test');
ok('  ...SLA persisted', $s['support_sla_urgent'] === '3' && $s['support_autoclose_days'] === '7');

ok('refuses without a superadmin session', refused(endpoint('actions/superadmin_support_settings.php', $payload), 'session has ended'));
ok('refuses GET', refused(endpoint('actions/superadmin_support_settings.php', $payload, ['method' => 'GET', 'auth' => true]), 'Method not allowed'));
ok('refuses a wrong CSRF token', refused(endpoint('actions/superadmin_support_settings.php', $payload + ['_csrf' => 'wrong-token'], ['auth' => true])));
ok('refuses on a tenant host', refused(endpoint('actions/superadmin_support_settings.php', $payload, ['host' => 'sometenant.' . $BASE, 'auth' => true])));
ok('refuses an unknown action', refused(endpoint('actions/superadmin_support_settings.php', ['action' => 'drop'] + $payload, ['auth' => true]), 'Unknown action'));
$bad = endpoint('actions/superadmin_support_settings.php', ['support_email' => 'nope'] + $payload, ['auth' => true]);
ok('validation error returned with a clear message', refused($bad, 'valid support email'));
unset($GLOBALS['__bms_platform_settings_cache']);
ok('  ...and nothing was overwritten by the rejected save', supportSettings()['support_email'] === 'care@example.test');

// ─────────────────────────────────────────────────────────────────────────────
section('6. Superadmin Support page renders through the real router');
$html = route(SA_HOST, '/support?tab=settings');
ok('renders with no PHP fatal', !str_contains($html, 'Fatal error') && !str_contains($html, 'Uncaught'), substr($html, 0, 300));
ok('page heading present', str_contains($html, 'Contact details') && str_contains($html, 'First-reply targets'));
ok('Support nav item marked active', (bool)preg_match('~nav-link active[^>]*>\s*<i class="bi bi-headset~', $html));
ok('saved values pre-filled', str_contains($html, 'care@example.test') && str_contains($html, 'agent@example.test'));
ok('posts to the support action with CSRF', str_contains($html, 'superadmin_support_settings.php') && str_contains($html, 'SA_CSRF_TOKEN'));
$htmlSettings = route(SA_HOST, '/settings');
ok('Support link also shown on other panel pages', str_contains($htmlSettings, 'bi-headset'));
ok('saUrl maps support', saUrl('support') !== '' && isset(superadminRouteMap()['support']));

// ─────────────────────────────────────────────────────────────────────────────
section('7. help.php contact card — no fake addresses, admin-only, from settings');
$help = file_get_contents("$root/app/constant/settings/help.php");
ok('no email built from the tenant company name', !str_contains($help, "mailto:support@<?= strtolower"));
ok('no placeholder phone number', !str_contains($help, '+255000000000'));
ok('reads BJP contacts from supportSettings()', str_contains($help, 'supportSettings()'));
ok('contacts gated to company admins', str_contains($help, 'isAdmin() ? supportSettings() : []'));
ok('card hidden when nothing is configured', str_contains($help, '<?php if ($__sup_has): ?>'));
ok('every printed contact value is escaped', substr_count($help, "safe_output(\$__sup") >= 5);

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
