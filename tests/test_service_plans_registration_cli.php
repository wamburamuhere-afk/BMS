<?php
/**
 * tests/test_service_plans_registration_cli.php — service_plans_plan.md Phase 3:
 * a new company picks a plan at signup and starts its trial on it.
 *
 *   php tests/test_service_plans_registration_cli.php
 *
 * Proves:
 *   1. with no public plan on offer, everything behaves as before (no plan,
 *      a "pricing is being prepared" box on login.php, no picker on register.php)
 *   2. api/platform/public_offers.php lists only sellable plans, no internal
 *      ids, and answers 404 on a tenant's own address
 *   3. registrationPlanChoice(): default, valid, forged, retired, private and
 *      wrong-term keys
 *   4. a web signup with a plan: tenants.plan, quotas and modules come from the
 *      plan; a forged plan or a re-used email creates nothing
 *   5. a mobile signup stores the keys on the job and the worker applies the
 *      same plan the web form would
 *   6. login.php (pricing popup) and register.php (picker) render the live
 *      plans, pre-select ?plan=&term=, and their inline JS parses
 *
 * CLI ONLY. Creates throwaway products/plans/tenants/jobs; removes all.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);

// ─── Endpoint worker: one HTTP request in a fresh process ───────────────────
if (($argv[1] ?? '') === '--endpoint') {
    $_SERVER['REQUEST_METHOD'] = (string)$argv[2];
    $_SERVER['HTTP_HOST']      = (string)$argv[3];
    $_SERVER['REQUEST_URI']    = '/' . $argv[4];
    $_SERVER['REMOTE_ADDR']    = '198.51.100.230';
    $_SERVER['CONTENT_TYPE']   = 'application/x-www-form-urlencoded';
    $_POST = json_decode((string)base64_decode((string)$argv[5]), true) ?: [];
    require "$root/" . $argv[4];
    exit(0);
}

require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_registration.php";
require_once "$root/core/tenant_admin.php";
require_once "$root/core/plans.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

/** Multi-tenancy on, for the duration of a closure (same as test_tenant_registration_cli). */
function withTenancy(callable $fn)
{
    putenv('TENANT_MODE=on');
    putenv('TENANT_BASE_DOMAIN=bms.local');
    try { return $fn(); }
    finally { putenv('TENANT_MODE'); putenv('TENANT_BASE_DOMAIN'); }
}

/** Runs an endpoint in its own process with tenancy on; returns [status-less body]. */
function hit(string $method, string $host, string $file, array $post = []): string
{
    putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.local');
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --endpoint '
         . escapeshellarg($method) . ' ' . escapeshellarg($host) . ' ' . escapeshellarg($file) . ' '
         . escapeshellarg(base64_encode(json_encode($post)));
    $out = (string)shell_exec($cmd . ' 2>&1');
    putenv('TENANT_MODE'); putenv('TENANT_BASE_DOMAIN');
    return $out;
}

/** Renders login.php / register.php on a host, hermetic env (see test_tenant_registration_cli §9). */
function renderPage(string $page, string $host, array $get = []): string
{
    global $root;
    $probe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_sp_render_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($probe, "<?php\n"
        . "ini_set('session.save_path', sys_get_temp_dir());\n"
        . "\$_SERVER['HTTP_HOST'] = " . var_export($host, true) . ";\n"
        . "\$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "\$_GET = " . var_export($get, true) . ";\n"
        . "chdir(" . var_export($root, true) . ");\n"
        . "\$want = [];\n"
        . "foreach (['TENANT_MODE','TENANT_BASE_DOMAIN'] as \$k) { \$v = getenv(\$k); \$want[\$k] = (\$v === false) ? null : \$v; }\n"
        . "require_once " . var_export("$root/includes/config.php", true) . ";\n"
        . "foreach (\$want as \$k => \$v) { if (\$v === null) { putenv(\$k); } else { putenv(\"\$k=\$v\"); } }\n"
        . "require_once " . var_export("$root/roots.php", true) . ";   // getUrl(), as the router provides\n"
        . "ob_start(); require " . var_export("$root/$page", true) . "; echo ob_get_clean();\n");
    putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.local');
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
    putenv('TENANT_MODE'); putenv('TENANT_BASE_DOMAIN');
    @unlink($probe);
    return $out;
}

/** Every inline <script> block parses (node --check). Returns '' or the error. */
function inlineJsError(string $html): string
{
    if (!preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#si', $html, $m)) return '';
    foreach ($m[1] as $i => $js) {
        if (trim($js) === '') continue;
        $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_sp_js_' . bin2hex(random_bytes(4)) . '.js';
        file_put_contents($f, $js);
        exec('node --check ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
        @unlink($f);
        if ($rc !== 0) return "script #$i: " . implode(' ', array_slice($o, 0, 4));
    }
    return '';
}

$c   = getControlPdo();
$sfx = bin2hex(random_bytes(3));
$tag = 'spr' . $sfx;
$PROD = $tag . '_shop';

$made = ['plans' => [], 'tenants' => [], 'jobs' => []];
register_shutdown_function(function () use ($c, &$made, $PROD) {
    global $pass, $fail;
    try {
        foreach ($made['jobs'] as $j) $c->prepare("DELETE FROM registration_jobs WHERE job_id = ?")->execute([$j]);
        $admin = getProvisioningPdo();
        foreach ($made['tenants'] as $tid) {
            $tid = (int)$tid;
            foreach (['tenant_features', 'tenant_admin_log', 'tenant_provisioning_log'] as $t) {
                try { $c->exec("DELETE FROM $t WHERE tenant_id = $tid"); } catch (Throwable $e) {}
            }
            $c->exec("DELETE FROM tenants WHERE id = $tid");
            try { $admin->exec("DROP DATABASE IF EXISTS `bms_t$tid`"); } catch (Throwable $e) {}
            try { $admin->exec("DROP USER IF EXISTS 'bms_u$tid'@'%'"); } catch (Throwable $e) {}
        }
        $c->exec("DELETE FROM registration_attempts WHERE subdomain LIKE 'sprtest%' OR ip_address LIKE '198.51.100.2%'");
        $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'sprtest%'");
        foreach ($made['plans'] as $id) {
            $id = (int)$id;
            $c->exec("DELETE pap FROM plan_addon_prices pap JOIN plan_addons pa ON pa.id = pap.addon_id WHERE pa.plan_id = $id");
            foreach (['plan_addons', 'plan_prices', 'plan_items', 'plan_features'] as $t) $c->exec("DELETE FROM $t WHERE plan_id = $id");
            $c->exec("DELETE FROM plans WHERE id = $id");
        }
        $c->prepare("DELETE FROM platform_products WHERE product_key = ?")->execute([$PROD]);
    } catch (Throwable $e) { echo "  cleanup: " . $e->getMessage() . "\n"; }
    echo "\nPasses:   $pass\nFailures: $fail\n";
});

echo "\nBMS — Service plans Phase 3: plan chosen at registration\n";

try {
    // ─────────────────────────────────────────────────────────────────────────
    section('0. Schema');
    ok('registration_jobs.plan_key exists', controlColumnExists('registration_jobs', 'plan_key'));
    ok('registration_jobs.plan_term exists', controlColumnExists('registration_jobs', 'plan_term'));
    ok('tenants.owner_contact_email exists', controlColumnExists('tenants', 'owner_contact_email'));

    // ─────────────────────────────────────────────────────────────────────────
    section('1. Nothing on offer -> registration works exactly as before');
    $preexisting = listPublicOffers(null, true);
    if ($preexisting) {
        echo "  (skipped: this database already has public plans on offer)\n";
    } else {
        $n = registrationPlanChoice('', '');
        ok('no key, no offers: ok with no plan', $n['ok'] === true && $n['plan'] === null);
        $n = registrationPlanChoice('anything', '1m');
        ok('a key with no offers is refused', $n['ok'] === false && str_contains((string)$n['error'], 'no longer available'));
        $j = json_decode(hit('GET', 'bms.local', 'api/platform/public_offers.php'), true);
        ok('endpoint answers success with an empty product list', ($j['success'] ?? null) === true && ($j['products'] ?? null) === []);
        $html = renderPage('login.php', 'bms.local');
        ok('login.php shows "pricing is being prepared", never invented prices', !str_contains($html, 'id="pmPlans"') && str_contains($html, 'pricingModal')
           && str_contains($html, 'pm-soon') && !str_contains($html, '85,000'));
        ok('login.php emits no plan-picker script', !str_contains($html, '#pmPlans .pm-plan'));
        $err = inlineJsError($html);
        ok('login.php inline JS parses (no offers)', $err === '', $err);
        $html = renderPage('register.php', 'bms.local');
        ok('register.php renders the form', str_contains($html, 'id="company_name"'), substr(strip_tags($html), 0, 300));
        ok('register.php shows no plan picker', !str_contains($html, 'id="plan_choice"'));
        $err = inlineJsError($html);
        ok('register.php inline JS parses (no offers)', $err === '', $err);
    }

    // ─────────────────────────────────────────────────────────────────────────
    section('2. Fixtures: a product shown at registration, 2 sellable + 2 unsellable plans');
    $c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?,?,1,0,1)")
      ->execute([$PROD, "Shop $tag"]);
    $mk = function (array $in) use (&$made) {
        $r = createPlan(array_merge([
            'description' => 'x', 'max_storage_mb' => 500, 'product_key' => $GLOBALS['PROD'],
            'is_public' => true, 'is_popular' => false, 'items' => [], 'addons' => [],
        ], $in));
        if (!empty($r['id'])) $made['plans'][] = (int)$r['id'];
        return $r;
    };
    $rB = $mk(['name' => "Basic $tag", 'max_users' => 2, 'max_shops' => 1, 'tier_rank' => 10, 'feature_keys' => ['pos'],
               'tagline' => "Owner's <first> shop",
               'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 10000, 'is_default' => 1],
                            ['duration_value' => 1, 'duration_unit' => 'year', 'price_tzs' => 85000, 'is_default' => 0]]]);
    $rP = $mk(['name' => "Pro $tag", 'max_users' => 5, 'max_shops' => 3, 'tier_rank' => 20, 'is_popular' => true,
               'feature_keys' => ['pos', 'warehouses', 'hr'],
               'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 25000, 'is_default' => 0],
                            ['duration_value' => 6, 'duration_unit' => 'month', 'price_tzs' => 120000, 'is_default' => 1]]]);
    $rH = $mk(['name' => "Hidden $tag", 'max_users' => 2, 'max_shops' => 1, 'tier_rank' => 30, 'is_public' => false, 'feature_keys' => ['pos'],
               'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 1000, 'is_default' => 1]]]);
    $rR = $mk(['name' => "Retired $tag", 'max_users' => 2, 'max_shops' => 1, 'tier_rank' => 40, 'feature_keys' => ['pos'],
               'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 1000, 'is_default' => 1]]]);
    foreach (['Basic' => $rB, 'Pro' => $rP, 'Hidden' => $rH, 'Retired' => $rR] as $l => $r) {
        ok("fixture plan $l created", $r['ok'] === true, (string)($r['error'] ?? ''));
    }
    if (!$rB['ok'] || !$rP['ok'] || !$rH['ok'] || !$rR['ok']) throw new RuntimeException('cannot continue');
    $BASIC = getPlan((int)$rB['id'])['plan_key'];
    $PRO   = getPlan((int)$rP['id'])['plan_key'];
    $HID   = getPlan((int)$rH['id'])['plan_key'];
    $RET   = getPlan((int)$rR['id'])['plan_key'];
    $c->prepare("UPDATE plans SET is_active = 0 WHERE id = ?")->execute([(int)$rR['id']]);

    // ─────────────────────────────────────────────────────────────────────────
    section('3. api/platform/public_offers.php');
    $raw = hit('GET', 'bms.local', 'api/platform/public_offers.php');
    $j = json_decode($raw, true);
    ok('root domain: success + TZS', ($j['success'] ?? null) === true && ($j['currency'] ?? '') === 'TZS', substr($raw, 0, 300));
    $mine = null;
    foreach ($j['products'] ?? [] as $p) if ($p['product_key'] === $PROD) $mine = $p;
    ok('the fixture product is listed', $mine !== null);
    $keys = array_column($mine['plans'] ?? [], 'plan_key');
    ok('Basic and Pro listed, tier order', $keys === [$BASIC, $PRO], implode(',', $keys));
    ok('private plan not listed', !in_array($HID, $keys, true));
    ok('retired plan not listed', !in_array($RET, $keys, true));
    ok('no internal ids anywhere in the payload', !preg_match('/"(id|plan_id|price_id|product_id|addon_id)"\s*:/', $raw));
    $bp = $mine['plans'][0] ?? [];
    ok('plan shape carries prices with term keys', array_column($bp['prices'] ?? [], 'term_key') === ['1m', '1y']);
    ok('plan shape carries quotas + modules', (int)($bp['max_users'] ?? 0) === 2 && (int)($bp['max_shops'] ?? 0) === 1 && ($bp['modules'] ?? null) !== null);
    ok("tagline text kept raw (escaped on display, not here)", ($bp['tagline'] ?? '') === "Owner's <first> shop");

    $raw = hit('GET', 'sprtest' . $sfx . 'x.bms.local', 'api/platform/public_offers.php');
    ok('an unknown tenant subdomain gets 404 Not found', str_contains($raw, '"Not found"'), substr($raw, 0, 200));
    $raw = hit('POST', 'bms.local', 'api/platform/public_offers.php');
    ok('POST is refused (405)', str_contains($raw, 'Method not allowed'));

    // ─────────────────────────────────────────────────────────────────────────
    section('4. registrationPlanChoice()');
    $all = [];
    foreach (listPublicOffers(null, true) as $prod) foreach ($prod['plans'] as $p) $all[] = $p;
    $expect = null;
    foreach ($all as $p) if ($p['is_popular']) { $expect = $p; break; }
    $expect = $expect ?? $all[0];
    $d = registrationPlanChoice('', '');
    ok('no key -> the first "Most popular" plan', $d['ok'] && $d['plan']['plan_key'] === $expect['plan_key'], json_encode($d['plan']['plan_key'] ?? null));
    if ($expect['plan_key'] === $PRO) ok('... on its default term (6m)', $d['term_key'] === '6m', (string)$d['term_key']);
    $v = registrationPlanChoice($BASIC, '1y');
    ok('valid key + term accepted', $v['ok'] && $v['plan']['plan_key'] === $BASIC && $v['term_key'] === '1y');
    $v = registrationPlanChoice($BASIC, '');
    ok('valid key, no term -> its default term', $v['ok'] && $v['term_key'] === '1m');
    foreach (['forged key' => ['no-such-plan-' . $sfx, '1m'], 'private plan' => [$HID, '1m'], 'retired plan' => [$RET, '1m'],
              'term not offered' => [$BASIC, '6m'], 'blank plan' => ['blank', '1m'], "SQL-ish key" => ["' OR 1=1 -- ", '1m']] as $l => [$k, $t]) {
        $v = registrationPlanChoice($k, $t);
        ok("refuses: $l", $v['ok'] === false && str_contains((string)$v['error'], 'no longer available'), json_encode($v));
    }

    // ─────────────────────────────────────────────────────────────────────────
    section('5. Web signup on a chosen plan');
    $sub = 'sprtestw' . $sfx;
    $mail = "owner@$sub.test";
    $res = withTenancy(fn() => registerTenant([
        'company_name' => 'Plan Signup Ltd', 'subdomain' => $sub, 'owner_email' => $mail,
        'owner_phone' => '2557' . random_int(10000000, 99999999), 'owner_password' => 'OwnerPass1',
        'plan_key' => $PRO, 'plan_term' => '1m',
    ], '198.51.100.201'));
    ok('registration succeeded', $res['ok'] === true, (string)($res['error'] ?? ''));
    if (!$res['ok']) throw new RuntimeException('cannot continue');
    $tid = (int)$res['tenant_id'];
    $made['tenants'][] = $tid;
    $t = $c->query("SELECT * FROM tenants WHERE id = $tid")->fetch(PDO::FETCH_ASSOC);
    ok('tenants.plan = the chosen plan', $t['plan'] === $PRO, (string)$t['plan']);
    ok('quotas come from the plan (5 users, 3 shops)', (int)$t['max_users'] === 5 && (int)$t['max_shops'] === 3,
       "users={$t['max_users']} shops={$t['max_shops']}");
    ok('owner_contact_email saved', $t['owner_contact_email'] === $mail);
    $matrix = [];
    foreach (tenantFeatureMatrix($tid) as $f) $matrix[$f['key']] = $f;
    foreach (['pos', 'warehouses', 'hr'] as $k) ok("plan module '$k' is ON", !empty($matrix[$k]['effective']), (string)($matrix[$k]['reason'] ?? 'missing'));
    foreach (['procurement', 'projects', 'crm'] as $k) {
        if (isset($matrix[$k])) ok("module '$k' not in the plan is OFF", $matrix[$k]['effective'] === false, (string)$matrix[$k]['reason']);
    }
    $log = $c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE tenant_id = $tid AND action = 'registration_plan'")->fetchColumn();
    ok('the plan choice is in the tenant admin log', (int)$log === 1);
    $s  = controlDbSettings();
    $tp = new PDO("mysql:host={$s['host']};dbname={$t['db_name']};charset=utf8mb4", $s['user'], $s['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $owner = $tp->query("SELECT * FROM users ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok('the owner account exists and the password verifies', $owner && password_verify('OwnerPass1', $owner['password']));

    section('5b. Refusals create nothing');
    $before = (int)$c->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $r = withTenancy(fn() => registerTenant([
        'company_name' => 'Forged Ltd', 'subdomain' => 'sprtestf' . $sfx, 'owner_email' => "f@sprtestf$sfx.test",
        'owner_phone' => '2557' . random_int(10000000, 99999999), 'owner_password' => 'OwnerPass1', 'plan_key' => $HID, 'plan_term' => '1m',
    ], '198.51.100.202'));
    ok('a private plan key is refused', $r['ok'] === false && str_contains((string)$r['error'], 'no longer available'), (string)$r['error']);
    $r = withTenancy(fn() => registerTenant([
        'company_name' => 'Retired Ltd', 'subdomain' => 'sprtestr' . $sfx, 'owner_email' => "r@sprtestr$sfx.test",
        'owner_phone' => '2557' . random_int(10000000, 99999999), 'owner_password' => 'OwnerPass1', 'plan_key' => $RET, 'plan_term' => '1m',
    ], '198.51.100.203'));
    ok('a retired plan key is refused', $r['ok'] === false && str_contains((string)$r['error'], 'no longer available'));
    $r = withTenancy(fn() => registerTenant([
        'company_name' => 'Same Mail Ltd', 'subdomain' => 'sprtestd' . $sfx, 'owner_email' => strtoupper($mail),
        'owner_phone' => '2557' . random_int(10000000, 99999999), 'owner_password' => 'OwnerPass1', 'plan_key' => $BASIC, 'plan_term' => '1m',
    ], '198.51.100.204'));
    ok('the same owner email (any case) is refused', $r['ok'] === false && str_contains((string)$r['error'], 'already registered'), (string)$r['error']);
    ok('no tenant was created by any refusal', (int)$c->query("SELECT COUNT(*) FROM tenants")->fetchColumn() === $before);

    section('5c. No key -> the default plan, same as the popup pre-selects');
    $sub2 = 'sprtestn' . $sfx;
    $res2 = withTenancy(fn() => registerTenant([
        'company_name' => 'Default Plan Ltd', 'subdomain' => $sub2, 'owner_email' => "o@$sub2.test", 'owner_phone' => '2557' . random_int(10000000, 99999999),
        'owner_password' => 'OwnerPass1',
    ], '198.51.100.205'));
    ok('registration without a key succeeded', $res2['ok'] === true, (string)($res2['error'] ?? ''));
    if ($res2['ok']) {
        $made['tenants'][] = (int)$res2['tenant_id'];
        $p2 = $c->query("SELECT plan FROM tenants WHERE id = " . (int)$res2['tenant_id'])->fetchColumn();
        ok('it starts on the default plan', $p2 === $expect['plan_key'], (string)$p2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    section('6. Mobile signup: keys on the job, the worker applies the same plan');
    $phone = '2556' . random_int(10000000, 99999999);
    $mobBase = ['company_name' => 'Mobile Plan Ltd', 'owner_phone' => $phone, 'owner_password' => 'OwnerPass1',
                'owner_password_confirm' => 'OwnerPass1', 'subdomain' => 'sprtestm' . $sfx];
    $raw = hit('POST', 'bms.local', 'api/mobile/register.php', $mobBase + ['plan_key' => $RET, 'plan_term' => '1m']);
    ok('mobile: retired plan refused', str_contains($raw, 'no longer available'), substr($raw, 0, 200));
    $raw = hit('POST', 'bms.local', 'api/mobile/register.php', $mobBase + ['owner_email' => strtoupper($mail), 'plan_key' => $BASIC]);
    ok('mobile: an email already registered is refused', str_contains($raw, 'already registered'), substr($raw, 0, 200));
    ok('mobile: no job queued by a refusal', (int)$c->query("SELECT COUNT(*) FROM registration_jobs WHERE owner_phone = " . $c->quote($phone))->fetchColumn() === 0);

    $raw = hit('POST', 'bms.local', 'api/mobile/register.php', $mobBase + ['plan_key' => $BASIC, 'plan_term' => '1y']);
    $j = json_decode($raw, true);
    ok('mobile: queued (provisioning)', ($j['status'] ?? '') === 'provisioning', substr($raw, 0, 300));
    $jobId = (string)($j['job_id'] ?? '');
    if ($jobId !== '') $made['jobs'][] = $jobId;
    $job = $c->query("SELECT * FROM registration_jobs WHERE job_id = " . $c->quote($jobId))->fetch(PDO::FETCH_ASSOC);
    ok('the job stores plan_key + plan_term', $job && $job['plan_key'] === $BASIC && $job['plan_term'] === '1y');

    // Run the worker ourselves (the endpoint's own kick may have claimed it first).
    $deadline = time() + 300;
    do {
        withTenancy(fn() => shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/cron/process_registration_jobs.php") . ' 2>&1'));
        $job = $c->query("SELECT * FROM registration_jobs WHERE job_id = " . $c->quote($jobId))->fetch(PDO::FETCH_ASSOC);
    } while ($job && !in_array($job['status'], ['ready', 'failed'], true) && time() < $deadline && (sleep(3) || true));
    ok('the worker finished the job', ($job['status'] ?? '') === 'ready', (string)($job['error_message'] ?? $job['status'] ?? ''));
    if (!empty($job['tenant_id'])) {
        $mt = (int)$job['tenant_id'];
        $made['tenants'][] = $mt;
        $t = $c->query("SELECT * FROM tenants WHERE id = $mt")->fetch(PDO::FETCH_ASSOC);
        ok('mobile tenant is on the chosen plan', $t['plan'] === $BASIC, (string)$t['plan']);
        ok('mobile tenant quotas from the plan (2 users, 1 shop)', (int)$t['max_users'] === 2 && (int)$t['max_shops'] === 1);
        $mm = [];
        foreach (tenantFeatureMatrix($mt) as $f) $mm[$f['key']] = $f;
        ok("mobile: 'pos' ON", !empty($mm['pos']['effective']));
        ok("mobile: 'hr' (not in Basic) OFF", isset($mm['hr']) && $mm['hr']['effective'] === false);
        ok('mobile: the plan choice is logged', (int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE tenant_id = $mt AND action = 'registration_plan'")->fetchColumn() === 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    section('7. login.php pricing popup + register.php picker render the live plans');
    $html = renderPage('login.php', 'bms.local');
    ok('popup lists the live plans', str_contains($html, 'id="pmPlans"') && str_contains($html, 'data-plan="' . $BASIC . '"')
       && str_contains($html, 'data-plan="' . $PRO . '"'));
    ok('popup never lists private/retired plans', !str_contains($html, $HID) && !str_contains($html, $RET));
    ok('tagline is HTML-escaped', str_contains($html, 'Owner&#039;s &lt;first&gt; shop') && !str_contains($html, '<first>'));
    ok('term pills offered (1m, 6m, 1y)', substr_count($html, 'class="btn-check" name="pm_term"') === 3, (string)substr_count($html, 'class="btn-check" name="pm_term"'));
    ok('Continue button present', str_contains($html, 'id="pmContinue"'));
    $err = inlineJsError($html);
    ok('login.php inline JS parses (with plans)', $err === '', $err);

    $html = renderPage('register.php', 'bms.local', ['plan' => $BASIC, 'term' => '1y']);
    ok('register.php shows the plan picker', str_contains($html, 'id="plan_choice"'));
    ok('?plan=&term= pre-selects that option', (bool)preg_match('#value="' . preg_quote($BASIC, '#') . '\|1y"\s+selected#', $html));
    ok('hidden plan_key/plan_term inputs present', str_contains($html, 'name="plan_key"') && str_contains($html, 'name="plan_term"'));
    ok('picker never lists private/retired plans', !str_contains($html, $HID) && !str_contains($html, $RET));
    $html = renderPage('register.php', 'bms.local', ['plan' => $HID, 'term' => '1m']);
    ok('a forged ?plan= falls back to the default selection',
       (bool)preg_match('#value="' . preg_quote($expect['plan_key'], '#') . '\|' . preg_quote((string)$d['term_key'], '#') . '"\s+selected#', $html));
    $err = inlineJsError($html);
    ok('register.php inline JS parses (with plans)', $err === '', $err);

} catch (Throwable $e) {
    $fail++;
    echo "\nFATAL: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}

exit($fail === 0 ? 0 : 1);
