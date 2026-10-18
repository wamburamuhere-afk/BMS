<?php
/**
 * tests/test_smart_products_cli.php — Smart POS / Smart BMS products.
 *
 *   php tests/test_smart_products_cli.php
 *
 * Proves:
 *   1. platformProductProfile(): fixed by product_key (bms_shop / bms_full / none)
 *   2. a product fixes its plan's modules (Smart POS = POS + Warehouses, Smart BMS =
 *      every default-on module); only internal plans keep their ticks
 *   3. updatePlatformProduct(): validation + the registration switch
 *   4. actions/superadmin_plans.php save_product — guard + positive control
 *   5. setup_control_db.php renames the old product names (criteria-based, idempotent)
 *   6. applyPlanToTenant() sets Simple / Shop / Supplier modes from the product,
 *      on a real throwaway tenant
 *   7. the public offer lists the modules applying would turn on
 *   8. plans.php renders the Products section; login has no invented prices
 *
 * CLI ONLY. Creates throwaway plans and one throwaway tenant; removes both and
 * restores every product row it touched. Do not run alongside other
 * tenant-provisioning suites.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Endpoint worker ────────────────────────────────────────────────────────
if (($argv[1] ?? '') === '--endpoint') {
    $file = (string)$argv[2];
    $post = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST']      = (string)$argv[4];
    $_SERVER['REQUEST_URI']    = '/' . $file;
    if ((string)($argv[5] ?? '0') === '1') {
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

// ─── Router worker ──────────────────────────────────────────────────────────
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = '';
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

// ─── Runner ─────────────────────────────────────────────────────────────────
// Needs a real control DB + tenant provisioning; CI has no includes/config.php.
if (!is_file(__DIR__ . '/../includes/config.php')) { echo "SKIP: no includes/config.php (CI)\n"; exit(0); }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/plans.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function endpoint(string $file, array $post, bool $auth): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint ' . escapeshellarg($file) . ' '
         . escapeshellarg(base64_encode(json_encode($post))) . ' ' . escapeshellarg(SA_HOST) . ' ' . ($auth ? '1' : '0');
    $out = []; exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}
function route(string $host, string $uri): string {
    $out = []; exec('php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg($host) . ' ' . escapeshellarg($uri) . ' 2>&1', $out);
    return implode("\n", $out);
}
function tenantSetting(int $tenantId, string $key): ?string {
    $q = getControlPdo()->prepare("SELECT db_host, db_name, db_username, db_password_encrypted FROM tenants WHERE id = ?");
    $q->execute([$tenantId]);
    $t = $q->fetch(PDO::FETCH_ASSOC);
    $pdo = new PDO('mysql:host=' . $t['db_host'] . ';dbname=' . $t['db_name'] . ';charset=utf8mb4',
                   $t['db_username'], decryptTenantSecret((string)$t['db_password_encrypted']),
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function simpleLocked(int $tenantId): int {
    $q = getControlPdo()->prepare("SELECT pos_simple_mode_locked FROM tenants WHERE id = ?");
    $q->execute([$tenantId]);
    return (int)$q->fetchColumn();
}

echo "\nBMS — Smart POS / Smart BMS products\n";

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

if (!planCatalogReady() || !getPlatformProduct('bms_shop') || !getPlatformProduct('bms_full')) {
    echo "  SKIP  plan catalogue not set up here (php scripts/setup_control_db.php)\n";
    exit(0);
}

// Every product row this test touches is restored exactly.
$origProducts = [];
foreach (['bms_shop', 'bms_full'] as $k) $origProducts[$k] = getPlatformProduct($k);
$createdPlanIds = [];
register_shutdown_function(function () use ($c, &$createdPlanIds, $origProducts) {
    foreach ($createdPlanIds as $id) {
        foreach (['plan_features', 'plan_items', 'plan_prices'] as $tbl) $c->exec("DELETE FROM $tbl WHERE plan_id = " . (int)$id);
        $c->exec("DELETE FROM plans WHERE id = " . (int)$id);
    }
    $up = $c->prepare("UPDATE platform_products SET name = ?, description = ?, show_on_registration = ?, sort_order = ?, is_active = ? WHERE product_key = ?");
    foreach ($origProducts as $k => $p) {
        $up->execute([$p['name'], $p['description'], $p['show_on_registration'], $p['sort_order'], $p['is_active'], $k]);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
section('1. platformProductProfile()');
$shop = platformProductProfile('bms_shop');
ok('Smart POS: Simple Mode on + locked', $shop['simple_mode'] === true && $shop['simple_locked'] === true);
ok('Smart POS: Shop wording on, Supplier Access on', $shop['shop_mode'] === true && $shop['supplier_access'] === true);
ok('Smart POS: fixed modules = Point of Sale + Warehouses (self-registration)', $shop['fixed_features'] === ['pos', 'warehouses']);
$full = platformProductProfile('bms_full');
$defaults = planDefaultOnFeatures();
ok('Smart BMS: Simple Mode off, Shop wording off', $full['simple_mode'] === false && $full['shop_mode'] === false);
ok('Smart BMS: Supplier Access left alone', $full['supplier_access'] === null);
ok('Smart BMS: fixed modules = every default-on module', $defaults !== [] && $full['fixed_features'] === $defaults, implode(',', $full['fixed_features']));
$none = platformProductProfile(null);
ok('no product: changes nothing (ticks rule)', $none['simple_mode'] === null && $none['shop_mode'] === null && $none['fixed_features'] === null);
ok('unknown product key: changes nothing', platformProductProfile('nope')['fixed_features'] === null);

// ─────────────────────────────────────────────────────────────────────────────
section('2. Plan module list follows its product');
$sorted = function (array $a) { sort($a); return $a; };
$r = createPlan(['name' => 'Smart BMS Test Plan', 'max_users' => 5, 'feature_keys' => ['field_reports'],
                 'product_key' => 'bms_full', 'is_public' => 0, 'tier_rank' => 5]);
ok('Smart BMS plan created', $r['ok'] === true, (string)($r['error'] ?? ''));
$fullPlanId = (int)$r['id']; $createdPlanIds[] = $fullPlanId;
$keys = planFeatureKeys($fullPlanId);
ok('…it stores exactly every default-on module', $sorted($keys) === $sorted(featureDependencyClosure($defaults)), implode(',', $keys));
ok('…a stray tick is ignored', !in_array('field_reports', $keys, true));

$ur = updatePlan($fullPlanId, ['name' => 'Smart BMS Test Plan', 'max_users' => 5, 'feature_keys' => [],
                               'product_key' => 'bms_full', 'is_public' => 0, 'tier_rank' => 5]);
ok('an update with no ticks…', $ur['ok'] === true, (string)($ur['error'] ?? ''));
ok('…still keeps every default module (server decides)', count(array_diff($defaults, planFeatureKeys($fullPlanId))) === 0);

$r = createPlan(['name' => 'Smart POS Test Plan', 'max_users' => 2, 'feature_keys' => ['hr', 'finance'],
                 'product_key' => 'bms_shop', 'is_public' => 0, 'tier_rank' => 1]);
ok('Smart POS plan created', $r['ok'] === true, (string)($r['error'] ?? ''));
$shopPlanId = (int)$r['id']; $createdPlanIds[] = $shopPlanId;
ok('…it stores exactly Point of Sale + Warehouses, whatever was ticked', $sorted(planFeatureKeys($shopPlanId)) === ['pos', 'warehouses'], implode(',', planFeatureKeys($shopPlanId)));

$r = createPlan(['name' => 'Internal Test Plan', 'max_users' => 2, 'feature_keys' => ['hr'], 'is_public' => 0]);
ok('internal plan (no product) created', $r['ok'] === true, (string)($r['error'] ?? ''));
$createdPlanIds[] = (int)$r['id'];
ok('…it keeps its own ticks', planFeatureKeys((int)$r['id']) === ['hr'], implode(',', planFeatureKeys((int)$r['id'])));

// ─────────────────────────────────────────────────────────────────────────────
section('3. updatePlatformProduct()');
$base = $origProducts['bms_shop'];
$r = updatePlatformProduct('bms_shop', ['name' => '', 'description' => 'x']);
ok('rejects an empty name', $r['ok'] === false);
$r = updatePlatformProduct('bms_shop', ['name' => str_repeat('a', 101)]);
ok('rejects a name over 100 characters', $r['ok'] === false);
$r = updatePlatformProduct('bms_shop', ['name' => 'Smart POS', 'description' => str_repeat('d', 256)]);
ok('rejects a description over 255 characters', $r['ok'] === false);
$r = updatePlatformProduct('no_such_product', ['name' => 'X']);
ok('rejects an unknown product', $r['ok'] === false);
$r = updatePlatformProduct('bms_shop', ['name' => 'Smart POS Edited', 'description' => 'Desc', 'sort_order' => 99999,
                                        'show_on_registration' => true, 'is_active' => true]);
$p = getPlatformProduct('bms_shop');
ok('a valid save succeeds', $r['ok'] === true, (string)($r['error'] ?? ''));
ok('…name, description and registration switch stored', $p['name'] === 'Smart POS Edited' && $p['description'] === 'Desc' && (int)$p['show_on_registration'] === 1);
ok('…sort order clamped to 9999', (int)$p['sort_order'] === 9999);
ok('…product_key never changes', $p['product_key'] === 'bms_shop');
updatePlatformProduct('bms_shop', ['name' => 'Smart POS Edited', 'show_on_registration' => false, 'is_active' => true]);
ok('registration switch turns off again', (int)getPlatformProduct('bms_shop')['show_on_registration'] === 0);

// ─────────────────────────────────────────────────────────────────────────────
section('4. actions/superadmin_plans.php save_product');
$out = endpoint('actions/superadmin_plans.php', ['action' => 'save_product', 'product_key' => 'bms_shop', 'name' => 'Hacked'], false);
ok('no superadmin session → refused', !str_contains($out, '"success":true'), substr($out, 0, 200));
ok('…and nothing changed', getPlatformProduct('bms_shop')['name'] === 'Smart POS Edited');
$out = endpoint('actions/superadmin_plans.php', ['action' => 'save_product', 'product_key' => 'bms_shop', 'name' => 'Hacked', '_csrf' => 'bad'], true);
ok('bad CSRF → refused', !str_contains($out, '"success":true'), substr($out, 0, 200));
$out = endpoint('actions/superadmin_plans.php', ['action' => 'save_product', 'product_key' => 'bms_shop', 'name' => '',
                                                 'show_on_registration' => '1', 'is_active' => '1'], true);
ok('empty name → validation message', str_contains($out, '"success":false') && str_contains($out, 'name'), substr($out, 0, 200));
$out = endpoint('actions/superadmin_plans.php', ['action' => 'save_product', 'product_key' => 'bms_shop', 'name' => 'Smart POS',
                                                 'description' => 'Via action', 'sort_order' => '1',
                                                 'show_on_registration' => '1', 'is_active' => '1'], true);
$p = getPlatformProduct('bms_shop');
ok('positive control: saved through the action', str_contains($out, '"success":true') && $p['name'] === 'Smart POS'
   && $p['description'] === 'Via action' && (int)$p['show_on_registration'] === 1, substr($out, 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('5. setup_control_db.php renames old product names');
$c->exec("UPDATE platform_products SET name = 'BMS Shop Mode' WHERE product_key = 'bms_shop'");
$c->exec("UPDATE platform_products SET name = 'BMS Full' WHERE product_key = 'bms_full'");
$o = []; exec('php ' . escapeshellarg(__DIR__ . '/../scripts/setup_control_db.php') . ' 2>&1', $o, $rc);
ok('setup script exits cleanly', $rc === 0, implode("\n", array_slice($o, -5)));
ok('BMS Shop Mode → Smart POS', getPlatformProduct('bms_shop')['name'] === 'Smart POS');
ok('BMS Full → Smart BMS', getPlatformProduct('bms_full')['name'] === 'Smart BMS');
$c->exec("UPDATE platform_products SET name = 'My Own Name' WHERE product_key = 'bms_full'");
$o = []; exec('php ' . escapeshellarg(__DIR__ . '/../scripts/setup_control_db.php') . ' 2>&1', $o, $rc);
ok('a name the operator chose is never overwritten (criteria-based)', getPlatformProduct('bms_full')['name'] === 'My Own Name');

// ─────────────────────────────────────────────────────────────────────────────
section('6. applyPlanToTenant() sets the modes from the product');
$sub = 'smartprod' . bin2hex(random_bytes(3));
$pr = provisionTenant('Smart Products Test Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $pr['ok'] === true, (string)($pr['error'] ?? ''));
$tenantId = (int)$pr['tenant_id'];
register_shutdown_function(function () use ($tenantId) {
    try { $t = getTenant($tenantId); if ($t) deleteTenant($tenantId, $t['company_name']); }
    catch (Throwable $e) { error_log('smart products test cleanup: ' . $e->getMessage()); }
});

$ar = applyPlanToTenant($tenantId, $shopPlanId);
ok('apply Smart POS plan', $ar['ok'] === true, (string)($ar['error'] ?? ''));
$t = getTenant($tenantId);
ok('…Simple Mode on', tenantSetting($tenantId, 'pos_simple_mode') === '1');
ok('…Simple Mode locked', simpleLocked($tenantId) === 1);
ok('…Shop wording on', tenantSetting($tenantId, 'shop_mode') === '1');
ok('…Supplier Access on', tenantSetting($tenantId, 'pos_supplier_access') === '1');
$on = [];
foreach (tenantFeatureMatrix($tenantId) as $f) if ($f['effective']) $on[] = $f['key'];
sort($on);
ok('…only Point of Sale + Warehouses on (same as self-registration)', $on === ['pos', 'warehouses'], implode(',', $on));

$ar = applyPlanToTenant($tenantId, $fullPlanId);
ok('apply Smart BMS plan', $ar['ok'] === true, (string)($ar['error'] ?? ''));
$t = getTenant($tenantId);
ok('…Simple Mode off', tenantSetting($tenantId, 'pos_simple_mode') === '0');
ok('…Simple Mode unlocked', simpleLocked($tenantId) === 0);
ok('…Shop wording off', tenantSetting($tenantId, 'shop_mode') === '0');
$on = [];
foreach (tenantFeatureMatrix($tenantId) as $f) if ($f['effective']) $on[] = $f['key'];
ok('…every default-on module enabled', count(array_diff($defaults, $on)) === 0, 'missing: ' . implode(',', array_diff($defaults, $on)));
$log = $c->prepare("SELECT detail FROM tenant_admin_log WHERE tenant_id = ? AND action = 'apply_plan' ORDER BY id DESC LIMIT 1");
$log->execute([$tenantId]);
ok('…the audit log names the modes set', str_contains((string)$log->fetchColumn(), 'Simple Mode off'));

// ─────────────────────────────────────────────────────────────────────────────
section('7. The public offer shows what applying gives');
$plan  = getPlan($fullPlanId);
$shape = planOfferShape($plan);
$reg   = bmsFeatureRegistry();
$want  = [];
foreach ($defaults as $k) if (isset($reg[$k])) $want[] = (string)$reg[$k]['label'];
ok('Smart BMS offer lists every default module', count(array_diff($want, $shape['modules'])) === 0);

// ─────────────────────────────────────────────────────────────────────────────
section('8. Pages');
$html = route(SA_HOST, '/plans');
ok('plans.php renders the Products section', str_contains($html, 'Edit product') && str_contains($html, 'id="productModal"'), substr($html, 0, 300));
ok('…plans grouped by product', str_contains($html, 'Smart POS plans') || str_contains($html, 'Smart BMS plans') || str_contains($html, 'My Own Name plans'));
ok('…no "Billing" wording left', !str_contains($html, 'Billing'));
ok('…plan form has the read-only product modules box', str_contains($html, 'id="productModules"') && str_contains($html, 'id="moduleTicks"'));
ok('…each product\'s fixed modules handed to the form', (bool)preg_match('/const PRODUCT_MODULES = \{[^;]*"bms_shop":\["Point of Sale","Warehouses"\]/', $html), substr((string)strstr($html, 'const PRODUCT_MODULES'), 0, 200));
ok('…scrollable dialog fix for the wrapping form', str_contains($html, '.modal-dialog-scrollable .modal-content > form'));
$login = (string)file_get_contents(__DIR__ . '/../login.php');
foreach (['10,000', '25,000', '45,000', '85,000'] as $n) ok("login.php has no invented price $n", !str_contains($login, ">$n<"));
ok('login.php has the "pricing is being prepared" state', str_contains($login, "pm_soon_title"));

echo "\n" . str_repeat('─', 60) . "\nPASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
