<?php
/**
 * tests/test_signup_shop_cli.php — a Smart POS sign-up gets its first shop; a restaurant gets
 * Restaurant POS + a Restaurant-mode shop; plan changes keep Restaurant POS; Smart BMS is unchanged.
 *
 *   php tests/test_signup_shop_cli.php
 *
 *   A. Pure rules + wiring (sign-up page, web action, mobile API, worker, control DB column)
 *   B. LIVE web sign-up, RETAIL — real registerTenant(): one shop from the form (name, phone, email,
 *      address, country, owner as contact), main + active, capacity 999999, Retail, default location,
 *      Restaurant POS still OFF, a normal editable row
 *   C. LIVE web sign-up, RESTAURANT — Restaurant-mode shop, Restaurant POS ON, ready-made roles made
 *   D. Plan change keeps Restaurant POS (Smart POS plan applied to the restaurant) and never adds it to retail
 *   E. LIVE Smart BMS sign-up — no shop, held for approval (unchanged)
 *   F. LIVE mobile path — the real worker (cron/process_registration_jobs.php) with pos_business=restaurant
 *
 * Mail is captured, never sent. Every company, database, DB user, plan and product this test makes is
 * removed at the end.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);

$GLOBALS['__sent'] = [];
function sendEmail($to, string $subject, string $htmlBody, array $opts = []): bool { $GLOBALS['__sent'][] = ['to' => (string)$to, 'subject' => $subject]; return true; }
function platformMailerOpts(): array {
    return ['configured' => true, 'opts' => ['smtp' => ['host' => 'smtp.invalid', 'port' => 587, 'username' => 'x', 'password' => 'x',
        'encryption' => 'tls', 'from_email' => 'noreply@invalid', 'from_name' => 'Test']]];
}

require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_migration_runner.php";
require_once "$root/core/tenant_registration.php";
require_once "$root/core/tenant_admin.php";
require_once "$root/core/plans.php";
require_once "$root/core/signup_shop.php";
require_once "$root/core/tenant_crypto.php";

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});
$src = fn(string $rel) => (string)@file_get_contents("$root/$rel");

// ─────────────────────────────────────────────────────────────────────────────
section('A. Rules and wiring');
ok(signupPosBusiness('restaurant') === 'restaurant' && signupPosBusiness(' Restaurant ') === 'restaurant', 'restaurant recognised');
ok(signupPosBusiness('retail') === 'retail' && signupPosBusiness('') === 'retail' && signupPosBusiness('hybrid') === 'retail' && signupPosBusiness(null) === 'retail', 'anything else is retail (whitelist)');
ok(signupShopCapacity() === 999999, 'capacity default 999999');
$reg = $src('register.php');
ok(strpos($reg, 'name="pos_business" value="retail" checked') !== false && strpos($reg, 'name="pos_business" value="restaurant"') !== false, 'sign-up page: Retail (default) / Restaurant choice');
ok(strpos($reg, "const isPos = !prod() || prod().key !== 'bms_full';") !== false && strpos($reg, "\$('#posBizBox').toggleClass('d-none-i', !isPos).prop('disabled', !isPos);") !== false, 'sign-up page: choice hidden and not sent for Smart BMS');
ok(strpos($src('actions/register_tenant.php'), "'pos_business'             => \$_POST['pos_business'] ?? 'retail'") !== false, 'web action passes the choice');
ok(strpos($src('api/mobile/register.php'), "signupPosBusiness(\$body['pos_business'] ?? 'retail')") !== false, 'mobile API stores the choice');
ok(strpos($src('cron/process_registration_jobs.php'), "'signup_shop'      => \$signupShop,") !== false, 'mobile worker asks for the first shop');
ok(strpos($src('scripts/setup_control_db.php'), "'pos_business'   => \"ADD COLUMN `pos_business`") !== false, 'control DB gains registration_jobs.pos_business on deploy');
$prov = $src('core/tenant_provisioner.php');
ok(strpos($prov, "'apply_tenant_migrations'") < strpos($prov, "'seed_signup_shop'"), 'shop is made after the company database is up to date (step 9.8 after 9.7)');

// ─────────────────────────────────────────────────────────────────────────────
putenv('TENANT_MODE=on');
if (!tenantBaseDomain()) putenv('TENANT_BASE_DOMAIN=bmstest.local');
if ($why = selfRegistrationClosedReason()) { echo "  \033[33m⚠ signup closed here ($why) — live sections skipped\033[0m\n"; exit; }

$c = getControlPdo();
$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$PROD = "sst{$tag}_shop";
$tenants = []; $planIds = []; $jobIds = [];
$cleanup = function () use (&$tenants, &$planIds, &$jobIds, $c, $PROD) {
    foreach ($tenants as $tid) {
        try { getProvisioningPdo()->exec("DROP DATABASE IF EXISTS `bms_t$tid`"); getProvisioningPdo()->exec("DROP USER IF EXISTS 'bms_u$tid'@'%'"); } catch (Throwable $e) {}
        foreach (['tenant_admin_log', 'tenant_provisioning_log', 'tenant_migration_log', 'vendor_link_queue', 'tenant_features', 'tenant_lifecycle_log', 'registration_attempts'] as $tb) {
            try { $c->prepare("DELETE FROM $tb WHERE tenant_id = ?")->execute([$tid]); } catch (Throwable $e) {}
        }
        try { $c->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tid]); } catch (Throwable $e) {}
    }
    foreach ($jobIds as $j) { try { $c->prepare("DELETE FROM registration_jobs WHERE job_id = ?")->execute([$j]); } catch (Throwable $e) {} }
    foreach ($planIds as $id) {
        foreach (['plan_prices', 'plan_items', 'plan_features', 'plan_addons'] as $tb) { try { $c->exec("DELETE FROM $tb WHERE plan_id = " . (int)$id); } catch (Throwable $e) {} }
        try { $c->exec("DELETE FROM plans WHERE id = " . (int)$id); } catch (Throwable $e) {}
    }
    try { $c->prepare("DELETE FROM platform_products WHERE product_key = ?")->execute([$PROD]); } catch (Throwable $e) {}
};

$tdb = function (int $tid): PDO {
    $p = new PDO('mysql:host=' . DB_SERVER . ';dbname=bms_t' . $tid . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $p;
};
$restaurantOn = function (int $tid): bool { return planTenantHasRestaurant($tid); };
$step = function (int $tid, string $s) use ($c) { $q = $c->prepare("SELECT status, message FROM tenant_provisioning_log WHERE tenant_id = ? AND step = ? ORDER BY id DESC LIMIT 1"); $q->execute([$tid, $s]); return $q->fetch(PDO::FETCH_ASSOC) ?: null; };
$ip = fn() => '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);

try {
    // A public product + plan to sign up on (the local catalogue has no public Smart POS plan).
    $c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?, ?, 1, 99, 1)")->execute([$PROD, "Signup test $tag"]);
    $pm = fn(int $v, string $u, int $tzs, int $def = 0) => ['duration_value' => $v, 'duration_unit' => $u, 'price_tzs' => $tzs, 'is_default' => $def];
    $mk = function (string $prod, string $name, bool $public) use (&$planIds, $tag, $pm) {
        $r = createPlan(['name' => "$name $tag", 'product_key' => $prod, 'max_users' => 3, 'max_storage_mb' => 500, 'max_shops' => 2,
                         'feature_keys' => ['warehouses', 'pos'], 'tier_rank' => 0, 'is_public' => $public, 'is_popular' => false,
                         'items' => [], 'addons' => [], 'prices' => [$pm(1, 'month', 10000, 1)]]);
        if (empty($r['ok'])) return null;
        $planIds[] = (int)$r['id'];
        return getPlan((int)$r['id']);
    };
    $signPlan = $mk($PROD, 'SST Shop', true);
    $posPlan  = $mk('bms_shop', 'SST Smart POS', false);
    $bmsPlan  = $mk('bms_full', 'SST Smart BMS', true);
    ok($signPlan && $posPlan && $bmsPlan, 'test plans created');
    if (!$signPlan || !$posPlan || !$bmsPlan) exit;

    $signup = function (string $sub, string $biz, array $plan, array $extra = []) use ($ip) {
        return registerTenant(array_merge([
            'company_name' => "Mama $sub Store", 'subdomain' => $sub, 'owner_username' => 'Owner' . substr($sub, -4),
            'owner_phone' => '2557' . random_int(10000000, 99999999), 'owner_email' => "owner.$sub@example.test",
            'owner_password' => 'Wambura@123', 'owner_password_confirm' => 'Wambura@123',
            'owner_first_name' => 'Asha', 'owner_last_name' => 'Juma', 'country' => 'Tanzania',
            'company_physical_address' => 'Plot 12, Sokoine Road, Arusha', 'company_postal_address' => 'P.O. Box 123',
            'business_type' => 'Hardware', 'pos_business' => $biz,
            'product_key' => $plan['product_key'], 'plan_key' => $plan['plan_key'], 'plan_term' => '1m', 'defer_emails' => true,
        ], $extra), $ip());
    };

    // ─────────────────────────────────────────────────────────────────────────
    section('B. Web sign-up — retail');
    $subR = 'sstr' . $tag;
    $r = $signup($subR, 'retail', $signPlan);
    $tR = (int)($r['tenant_id'] ?? 0); if ($tR) $tenants[] = $tR;
    ok(!empty($r['ok']) && empty($r['pending_approval']), 'company created, not held' . (!empty($r['ok']) ? '' : ' — ' . ($r['error'] ?? '?')));
    if ($tR) {
        $s = $step($tR, 'seed_signup_shop');
        ok($s && $s['status'] === 'ok', 'provisioning step seed_signup_shop ok (' . ($s['message'] ?? 'missing') . ')');
        $p = $tdb($tR);
        $ws = $p->query("SELECT * FROM warehouses WHERE status <> 'deleted'")->fetchAll(PDO::FETCH_ASSOC);
        ok(count($ws) === 1, 'exactly one shop');
        $w = $ws[0] ?? [];
        ok(($w['warehouse_name'] ?? '') === "Mama $subR Store", 'shop name = company name');
        ok(($w['pos_mode'] ?? '') === 'retail', 'Retail mode');
        ok((float)($w['capacity'] ?? 0) === 999999.0, 'capacity 999999');
        ok((int)($w['is_primary'] ?? 0) === 1 && ($w['status'] ?? '') === 'active', 'main shop, active');
        ok(($w['email'] ?? '') === "owner.$subR@example.test" && preg_match('/^2557\d{8}$/', (string)($w['phone'] ?? '')), 'phone and email from the form');
        ok(($w['address'] ?? '') === 'Plot 12, Sokoine Road, Arusha' && ($w['country'] ?? '') === 'Tanzania', 'physical address and country from the form');
        ok(($w['contact_person'] ?? '') === 'Asha Juma', "owner's name as contact person");
        ok(preg_match('/WH-?\d+/', (string)($w['warehouse_code'] ?? '')) === 1, 'sequential shop code (' . ($w['warehouse_code'] ?? '') . ')');
        $owner = (int)$p->query("SELECT user_id FROM users WHERE username = 'Owner" . substr($subR, -4) . "'")->fetchColumn();
        ok($owner > 0 && (int)($w['created_by'] ?? 0) === $owner, 'created by the owner');
        $loc = $p->prepare("SELECT location_code FROM locations WHERE warehouse_id = ?"); $loc->execute([(int)($w['warehouse_id'] ?? 0)]);
        ok($loc->fetchColumn() === 'MAIN', 'default storage location (as the Shops page makes)');
        ok($restaurantOn($tR) === false, 'Restaurant POS stays OFF for retail');
        // Editable: an ordinary row — the same UPDATE the Shops page and mobile API run.
        $p->prepare("UPDATE warehouses SET warehouse_name = ?, capacity = ?, phone = ? WHERE warehouse_id = ?")->execute(['Renamed shop', 500, '0712000000', (int)$w['warehouse_id']]);
        $e = $p->query("SELECT warehouse_name, capacity, phone FROM warehouses WHERE warehouse_id = " . (int)$w['warehouse_id'])->fetch(PDO::FETCH_ASSOC);
        ok($e['warehouse_name'] === 'Renamed shop' && (float)$e['capacity'] === 500.0, 'shop can be edited afterwards (name, capacity, phone)');
        // Idempotent: running the step again never makes a second shop.
        $again = seedSignupShop($p, ['name' => 'X', 'pos_mode' => 'retail']);
        ok($again['created'] === false && (int)$p->query("SELECT COUNT(*) FROM warehouses")->fetchColumn() === 1, 'second run makes no second shop');
    }

    // ─────────────────────────────────────────────────────────────────────────
    section('C. Web sign-up — restaurant');
    $subT = 'sstt' . $tag;
    $r = $signup($subT, 'restaurant', $signPlan);
    $tT = (int)($r['tenant_id'] ?? 0); if ($tT) $tenants[] = $tT;
    ok(!empty($r['ok']), 'company created' . (!empty($r['ok']) ? '' : ' — ' . ($r['error'] ?? '?')));
    if ($tT) {
        $p = $tdb($tT);
        $w = $p->query("SELECT * FROM warehouses WHERE status <> 'deleted'")->fetch(PDO::FETCH_ASSOC) ?: [];
        ok(($w['pos_mode'] ?? '') === 'restaurant', 'Restaurant-mode shop');
        ok((float)($w['capacity'] ?? 0) === 999999.0, 'capacity 999999');
        ok($restaurantOn($tT) === true, 'Restaurant POS ON (Superadmin switch)');
        $roles = $p->query("SELECT role_name FROM roles WHERE role_name IN ('Waiter','Cashier','Cook','Restaurant Manager')")->fetchAll(PDO::FETCH_COLUMN);
        ok(count($roles) === 4, 'ready-made roles made: ' . implode(', ', $roles));
        $lg = $c->prepare("SELECT COUNT(*) FROM tenant_admin_log WHERE tenant_id = ? AND action = 'registration_restaurant'"); $lg->execute([$tT]);
        ok((int)$lg->fetchColumn() === 1, 'Superadmin log: signed up as a restaurant');
        $sm = $p->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
        ok($sm === '1', 'Smart POS (Simple Mode) on, as every Smart POS sign-up');
    }

    // ─────────────────────────────────────────────────────────────────────────
    section('D. Plan changes keep Restaurant POS');
    if ($tT && $tR) {
        $a = applyPlanToTenant($tT, (int)$posPlan['id']);
        ok(!empty($a['ok']) && $restaurantOn($tT) === true, 'Smart POS plan applied to the restaurant → Restaurant POS still ON');
        $a = applyPlanToTenant($tT, (int)$signPlan['id']);
        ok(!empty($a['ok']) && $restaurantOn($tT) === true, 'another plan applied → still ON');
        $a = applyPlanToTenant($tR, (int)$posPlan['id']);
        ok(!empty($a['ok']) && $restaurantOn($tR) === false, 'retail company on the same plan → Restaurant POS stays OFF (never added)');
        setTenantFeatures($tT, ['restaurant_pos' => false]);
        applyPlanToTenant($tT, (int)$posPlan['id']);
        ok($restaurantOn($tT) === false, 'Superadmin switched it off → a plan change does not switch it back on');
    }

    // ─────────────────────────────────────────────────────────────────────────
    section('E. Smart BMS sign-up — unchanged');
    $subB = 'sstb' . $tag;
    $r = $signup($subB, 'restaurant', $bmsPlan);   // a crafted restaurant choice must be ignored
    $tB = (int)($r['tenant_id'] ?? 0); if ($tB) $tenants[] = $tB;
    ok(!empty($r['ok']) && !empty($r['pending_approval']), 'held for approval' . (!empty($r['ok']) ? '' : ' — ' . ($r['error'] ?? '?')));
    if ($tB) {
        $p = $tdb($tB);
        ok((int)$p->query("SELECT COUNT(*) FROM warehouses")->fetchColumn() === 0, 'no shop made');
        ok($step($tB, 'seed_signup_shop') === null, 'shop step not run');
        ok($restaurantOn($tB) === false, 'Restaurant POS not switched on (even with a crafted choice)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    section('F. Mobile app path (real worker)');
    $others = (int)$c->query("SELECT COUNT(*) FROM registration_jobs WHERE status = 'pending'")->fetchColumn();
    $hasCol = controlColumnExists('registration_jobs', 'pos_business');
    if (!$hasCol) {
        echo "  \033[33m⚠ registration_jobs.pos_business missing locally — run php scripts/setup_control_db.php\033[0m\n";
        ok(false, 'control DB upgraded (registration_jobs.pos_business)');
    } elseif ($others > 0) {
        echo "  \033[33m⚠ $others other pending sign-up job(s) here — the worker would take theirs first; mobile section skipped\033[0m\n";
    } else {
        $subM = 'sstm' . $tag;
        $job = bin2hex(random_bytes(32)); $jobIds[] = $job;
        $c->prepare("INSERT INTO registration_jobs (job_id, status, company_name, subdomain, owner_phone, owner_pass_enc, owner_first_name, owner_last_name,
                        owner_email, phys_address, post_address, device_name, ip_address, tenant_url, plan_key, plan_term, pos_business)
                     VALUES (?, 'pending', ?, ?, ?, ?, 'Neema', 'Ally', ?, 'Kariakoo, Dar es Salaam', '', 'test', '127.0.0.1', '', ?, '1m', 'restaurant')")
          ->execute([$job, "Neema $subM Grill", $subM, '2556' . random_int(10000000, 99999999), encryptTenantSecret('Wambura@123'),
                     "owner.$subM@example.test", $signPlan['plan_key']]);
        $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/cron/process_registration_jobs.php") . ' 2>&1');
        $tid = (int)$c->query("SELECT id FROM tenants WHERE subdomain = " . $c->quote($subM))->fetchColumn();
        if ($tid) $tenants[] = $tid;
        ok($tid > 0, 'worker created the company' . ($tid ? '' : ' — ' . substr(trim($out), 0, 300)));
        if ($tid) {
            $p = $tdb($tid);
            $w = $p->query("SELECT * FROM warehouses WHERE status <> 'deleted'")->fetch(PDO::FETCH_ASSOC) ?: [];
            ok(($w['warehouse_name'] ?? '') === "Neema $subM Grill" && ($w['pos_mode'] ?? '') === 'restaurant' && (float)($w['capacity'] ?? 0) === 999999.0, 'restaurant shop from the app sign-up');
            ok(($w['address'] ?? '') === 'Kariakoo, Dar es Salaam', 'address from the app');
            ok($restaurantOn($tid) === true, 'Restaurant POS ON');
        }
    }
} finally {
    $cleanup();
    $left = (int)$c->query("SELECT COUNT(*) FROM tenants WHERE subdomain LIKE 'sst%$tag'")->fetchColumn();
    ok($left === 0, 'all test companies removed');
}
