<?php
/**
 * Billing page + what the company sees from BJP (service_plans_plan.md Phase 5b)
 *   php tests/test_service_plans_billing_page_cli.php
 *
 * A: wiring (CI).  B: on the local database + control DB, through the real endpoints:
 *   the plan catalog (plans, what they include, extras per plan + term), a request with
 *   extras (priced at BJP, pending, waiting) and every way a request can be wrong, the
 *   D7 visibility rules for quotations and invoices (draft / pending / reviewed hidden,
 *   approved shown, cancelled only if it had been approved), the customer-copy guard,
 *   the company API (plan summary, "new" flags, the header badge + its cache, request
 *   checks), the Billing page / menu / redirect.
 * Data is 'SPP-TEST' / 'spptest*' and removed afterwards. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
const SPP_KEY = 'd1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f94';
putenv('TENANT_CRED_KEY=' . SPP_KEY);
$hasConfig = is_file("$root/includes/config.php");
if ($hasConfig) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

// ── A. Wiring ──────────────────────────────────────────────────────────────
section('A. wiring');
$src = fn($f) => file_get_contents("$root/$f");
$hdr = $src('header.php');
$bPos = strpos($hdr, "getUrl('subscription')"); $sPos = strpos($hdr, 'id="adminDropdown"');
ok($bPos !== false && $sPos !== false && $bPos < $sPos, 'header: Subscription is a top-level item before the Settings menu');
ok(strpos($hdr, "t('Billing')") === false && substr_count($hdr, "t('Subscription')") >= 2, 'header: the item is called Subscription (top menu + phone menu)');
ok(strpos($hdr, "\$_billing_visible = isAdmin() && function_exists('bmsCurrentTenantId') && bmsCurrentTenantId() !== null") !== false, 'header: Billing only for admins on a company subdomain');
ok(substr_count($hdr, 'bms-billing-badge') >= 3 && strpos($hdr, "api/my_subscription.php') ?>?action=badge") !== false, 'header: badge in the top menu + phone menu, filled after load');
ok(strpos($src('includes/settings_admin_nav.php'), "'my_subscription'") === false, 'Settings → Admin no longer lists My Subscription');
ok(strpos($src('roots.php'), "'subscription' => SETTINGS_DIR . '/billing.php'") !== false, 'route subscription');
ok(strpos($src('roots.php'), "'billing' => SETTINGS_DIR . '/billing.php'") !== false, 'old route billing still opens the page');
ok(strpos($src('app/constant/settings/my_subscription.php'), "getUrl('subscription')") !== false, 'old My Subscription redirects to Subscription');
ok(strpos($src('app/constant/settings/billing.php'), "\$page_title = 'Subscription';") !== false, 'page title is Subscription');
ok(strpos($src('app/constant/settings/billing.php'), 'settingsAdminShellOpen') === false, 'Billing stands on its own (not inside the Settings shell)');
ok(strpos($src('api/platform/customer_document.php'), "['draft', 'pending', 'reviewed']") !== false, 'customer copy refuses documents BJP has not approved');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B. Setup ───────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/vendor_bridge.php";
require_once "$root/core/plans.php";
require_once "$root/core/platform_link.php";
require_once "$root/core/platform_gateway.php";
require_once "$root/core/financial_reports.php";
sptEnsureSchema($pdo);
ob_start(); sptEnsureLineColumns($pdo); sptEnsurePlanLineColumns($pdo); ob_end_clean();
$c = getControlPdo();
if (!vlLinkReady() || !planCatalogReady() || !controlColumnExists('tenants', 'scheduled_plan_key')) { ok(false, 'control DB upgraded (run scripts/setup_control_db.php)'); exit; }
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
if (!$uid || !$wid) { echo "SKIP B: needs an admin and a warehouse\n"; exit; }

$tag  = 'spp' . bin2hex(random_bytes(3));
$PROD = $tag . '_prod';
$planIds = [];
$seenBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'billing_seen_at'")->fetchColumn();
$cleanup = function () use ($pdo, $c, &$planIds, &$PROD, $seenBefore) {
    $in = fn($a) => $a ? implode(',', array_map('intval', $a)) : '0';
    $cust = $in($pdo->query("SELECT customer_id FROM customers WHERE customer_name LIKE 'SPP-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    $inv = $in($pdo->query("SELECT invoice_id FROM invoices WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
    $qt  = $in($pdo->query("SELECT sales_order_id FROM quotations WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
    $pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                 WHERE je.entity_type IN ('invoice','invoice_cogs','invoice_void') AND je.entity_id IN ($inv)");
    $pdo->exec("DELETE FROM journal_entries WHERE entity_type IN ('invoice','invoice_cogs','invoice_void') AND entity_id IN ($inv)");
    $pdo->exec("DELETE FROM vendor_bridge_outbox WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM invoices WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM quotation_items WHERE order_id IN ($qt)");
    $pdo->exec("DELETE FROM quotations WHERE sales_order_id IN ($qt)");
    try { $pdo->exec("DELETE FROM workflow_signatures WHERE (entity_type='invoice' AND entity_id IN ($inv)) OR (entity_type='quotation' AND entity_id IN ($qt))"); } catch (Throwable $e) {}
    try { $pdo->exec("DELETE FROM notifications WHERE customer_id IN ($cust)"); } catch (Throwable $e) {}
    $p = $in($pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'SPP-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (['service_price_terms', 'vendor_subscription_services', 'products'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN ($p)");
    foreach ($pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['ledger_account_id']) $pdo->exec("DELETE FROM accounts WHERE account_id = " . (int)$r['ledger_account_id']);
        $pdo->exec("DELETE FROM customers WHERE customer_id = " . (int)$r['customer_id']);
    }
    $t = $in($c->query("SELECT id FROM tenants WHERE subdomain LIKE 'spptest%'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (['tenant_addon_grants', 'tenant_payments', 'tenant_admin_log', 'vendor_bridge_alerts', 'tenant_features'] as $tb) {
        try { $c->exec("DELETE FROM $tb WHERE tenant_id IN ($t)"); } catch (Throwable $e) {}
    }
    try { $pdo->exec("DELETE FROM subscription_requests WHERE subdomain LIKE 'spptest%'"); } catch (Throwable $e) {}
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'spptest%'");
    foreach ($planIds as $id) {
        $id = (int)$id;
        $c->exec("DELETE pap FROM plan_addon_prices pap JOIN plan_addons pa ON pa.id = pap.addon_id WHERE pa.plan_id = $id");
        foreach (['plan_addons', 'plan_prices', 'plan_items', 'plan_features'] as $tb) $c->exec("DELETE FROM $tb WHERE plan_id = $id");
        $c->exec("DELETE FROM plans WHERE id = $id");
    }
    $c->prepare("DELETE FROM platform_products WHERE product_key = ?")->execute([$PROD]);
    // This install's own "last seen" marker goes back to what it was.
    if ($seenBefore === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'billing_seen_at'");
    else $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'billing_seen_at'")->execute([$seenBefore]);
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'spp_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function lastJson(string $out): ?array { $j = json_decode($out, true); if (is_array($j)) return $j; $p = strrpos($out, "\n{"); return $p !== false ? json_decode(substr($out, $p + 1), true) : null; }
const VENDOR_ENV = "putenv('TENANT_CRED_KEY=" . SPP_KEY . "'); putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');";
/** An endpoint as BJP staff would call it. */
function call(string $file, array $post): array {
    global $root, $uid;
    $j = lastJson(runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php'; " . VENDOR_ENV . "
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_POST = \$_REQUEST = " . var_export($post, true) . ";
        \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; \$o = ob_get_clean();
        \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);"));
    return is_array($j) ? $j : ['success' => false, 'message' => 'no JSON'];
}
/** The company's own server talking to the platform root (signed). */
function company(int $tid, array $body): array {
    global $root;
    $json = json_encode($body); $ts = time(); $sig = (string)plSignature($tid, $ts, $json);
    return lastJson(runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . VENDOR_ENV . " require_once '$root/core/platform_gateway.php'; global \$pdo;
        [\$code, \$o] = pgHandle(\$pdo, " . var_export($json, true) . ", ['X-Platform-Tenant' => '$tid', 'X-Platform-Ts' => '$ts', 'X-Platform-Sig' => " . var_export($sig, true) . "]);
        echo json_encode(['code' => \$code] + \$o);")) ?? ['success' => false];
}
/**
 * The company admin using api/my_subscription.php on its own subdomain. BJP's gateway answers
 * in-process (same local DB plays both sides), exactly as the HTTPS call would.
 */
function companyApi(int $tid, array $req, string $method = 'GET', string $extra = ''): array {
    global $root, $uid;
    $j = lastJson(runPhp("\$_SERVER['REQUEST_METHOD'] = '$method'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php'; putenv('TENANT_CRED_KEY=" . SPP_KEY . "'); putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');
        \$GLOBALS['__bms_tenant'] = ['id' => $tid];
        \$GLOBALS['__pl_transport'] = function (\$body, \$h) {
            require_once '$root/core/platform_gateway.php'; global \$pdo;
            \$keep = \$GLOBALS['__bms_tenant']; unset(\$GLOBALS['__bms_tenant']); putenv('BMS_VENDOR_CONTEXT=1');
            [\$code, \$o] = pgHandle(\$pdo, \$body, \$h);
            putenv('BMS_VENDOR_CONTEXT'); \$GLOBALS['__bms_tenant'] = \$keep;
            return \$o;
        };
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['user_lang'] = 'en';
        $extra
        \$_POST = \$_REQUEST = \$_GET = " . var_export($req, true) . "; \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/api/my_subscription.php'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);"));
    return is_array($j) ? $j : ['success' => false, 'message' => 'no JSON'];
}
$qCount = fn(int $cust) => (int)$pdo->query("SELECT COUNT(*) FROM quotations WHERE customer_id = $cust")->fetchColumn();

// The platform product, Basic + Pro (public, with an extra-shop add-on), a private plan.
$c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?,?,1,0,1)")->execute([$PROD, "Test Shop $tag"]);
$mk = function (array $in) use (&$planIds, $PROD) {
    $r = createPlan($in + ['product_key' => $PROD, 'is_popular' => false, 'items' => [], 'addons' => []]);
    if (!empty($r['id'])) $planIds[] = (int)$r['id'];
    return $r;
};
$rb = $mk(['name' => "Basic $tag", 'max_users' => 2, 'max_storage_mb' => 500, 'feature_keys' => ['warehouses', 'pos'], 'max_shops' => 1, 'tier_rank' => 10, 'is_public' => true,
    'items' => [['label' => 'Point of sale', 'value' => '']],
    'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 15000, 'is_default' => 0],
                 ['duration_value' => 1, 'duration_unit' => 'year', 'price_tzs' => 150000, 'is_default' => 1]],
    'addons' => [['name' => 'Extra shop', 'quota' => 'max_shops', 'units' => 1, 'prices' => ['1m' => 5000, '1y' => 40000]]]]);
$rp = $mk(['name' => "Pro $tag", 'max_users' => 5, 'max_storage_mb' => 2000, 'feature_keys' => ['warehouses', 'pos', 'pos_advanced'], 'max_shops' => 3, 'tier_rank' => 20, 'is_public' => true,
    'is_popular' => true, 'items' => [['label' => 'Point of sale', 'value' => ''], ['label' => 'Shops', 'value' => '3']],
    'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 30000, 'is_default' => 0],
                 ['duration_value' => 1, 'duration_unit' => 'year', 'price_tzs' => 300000, 'is_default' => 1]],
    'addons' => [['name' => 'Extra shop', 'quota' => 'max_shops', 'units' => 1, 'prices' => ['1m' => 8000, '1y' => 60000]]]]);
$rh = $mk(['name' => "Private $tag", 'max_users' => 9, 'max_storage_mb' => 500, 'feature_keys' => ['warehouses', 'pos'], 'max_shops' => 9, 'tier_rank' => 30, 'is_public' => false,
    'prices' => [['duration_value' => 1, 'duration_unit' => 'year', 'price_tzs' => 999000, 'is_default' => 1]]]);
if (empty($rb['ok']) || empty($rp['ok']) || empty($rh['ok'])) { ok(false, 'plan fixtures: ' . ($rb['error'] ?? '') . ($rp['error'] ?? '') . ($rh['error'] ?? '')); exit; }
$basic = getPlan((int)$rb['id']); $pro = getPlan((int)$rp['id']); $priv = getPlan((int)$rh['id']);
$price = function (array $plan, string $termKey) { foreach (planPrices((int)$plan['id']) as $p) if ($p['term_key'] === $termKey) return $p; return null; };
$B1y = $price($basic, '1y'); $P1y = $price($pro, '1y'); $P1m = $price($pro, '1m');
$Pshop = planAddons((int)$pro['id'])[0]; $Bshop = planAddons((int)$basic['id'])[0];
$tP1y = "p{$P1y['id']}"; $tP1m = "p{$P1m['id']}"; $tB1y = "p{$B1y['id']}";
$xP1y = "a{$Pshop['id']}-{$P1y['id']}"; $xP1m = "a{$Pshop['id']}-{$P1m['id']}"; $xB1y = "a{$Bshop['id']}-{$B1y['id']}";

$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SPP-TEST Shop System', 'subscription_flag_sent' => '1', 'is_subscription' => '1',
    'platform_product_sent' => '1', 'platform_product_key' => $PROD, 'price_terms' => '']);
$sys = (int)($r['product_id'] ?? 0);
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SPP-TEST Hosting', 'is_subscription' => '1', 'price_terms' => json_encode([
    ['term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 10000, 'is_default' => 1]])]);
$hosting = (int)($r['product_id'] ?? 0);
$hTerm = (int)(sptForProduct($pdo, $hosting)[0]['term_id'] ?? 0);

$pdo->beginTransaction();
$cust = vlCreateCustomer($pdo, ['customer_name' => 'SPP-TEST Duka'], $uid);
$pdo->commit();
$c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, status, trial_ends_at, plan, max_users, max_shops)
             VALUES ('SPP-TEST Duka', 'spptest1', 'none', 'none', 'x', 'spp@example.com', 'trial', DATE_ADD(NOW(), INTERVAL 5 DAY), ?, 2, 1)")->execute([$basic['plan_key']]);
$tid = (int)$c->lastInsertId();
vlSetTenantCustomer($tid, $cust, 'test', 'SPP-TEST Duka');
ok($sys && vssPlatformProduct($pdo, $sys) === $PROD && $hosting && $hTerm && $cust && $tid, 'fixtures: plans, the mapped service, a period-priced service, a linked company on Basic');

// ── B1. Catalog ────────────────────────────────────────────────────────────
section('B1. the plan catalog the company sees');
$cat = company($tid, ['action' => 'catalog']);
$svc = array_values(array_filter($cat['services'] ?? [], fn($s) => $s['product_id'] === $sys))[0] ?? null;
$planKeys = array_column($svc['plans'] ?? [], 'plan_key');
ok($svc && $planKeys === [$basic['plan_key'], $pro['plan_key']], 'public plans in tier order; the private plan is not offered');
$proSum = array_values(array_filter($svc['plans'] ?? [], fn($p) => $p['plan_key'] === $pro['plan_key']))[0] ?? [];
ok(($proSum['max_users'] ?? 0) === 5 && ($proSum['max_shops'] ?? 0) === 3 && !empty($proSum['is_popular']) && count($proSum['items'] ?? []) === 2, 'each plan says what it includes (limits, popular, items)');
$t = array_values(array_filter($svc['terms'] ?? [], fn($t) => $t['term_id'] === $tP1y))[0] ?? [];
ok(($t['plan_key'] ?? '') === $pro['plan_key'] && (int)($t['plan_price_id'] ?? 0) === (int)$P1y['id'] && (float)$t['price'] === 300000.0, 'periods carry their plan + plan_price_id + price');
$x = array_values(array_filter($svc['extras'] ?? [], fn($e) => $e['term_id'] === $xP1y))[0] ?? [];
ok(count($svc['extras'] ?? []) === 4 && (int)($x['plan_price_id'] ?? 0) === (int)$P1y['id'] && (float)$x['price'] === 60000.0 && $x['quota'] === 'max_shops',
   'extras listed per plan + period (Basic 1m/1y, Pro 1m/1y) with their price');
$hs = array_values(array_filter($cat['services'] ?? [], fn($s) => $s['product_id'] === $hosting))[0] ?? null;
ok($hs && !isset($hs['plans']) && count($hs['terms']) === 1, 'a period-priced service is listed as before (no plans)');

// ── B2. A request with extras ──────────────────────────────────────────────
section('B2. request Pro 1 year + 2 extra shops');
require_once "$root/tests/helpers/subscription_request_quote.php";
$qBefore = $qCount($cust);
$r = company($tid, ['action' => 'request_quote', 'product_id' => $sys, 'term_id' => $tP1y, 'extras' => [$xP1y => 2]]);
ok(!empty($r['success']) && ($r['status'] ?? '') === 'waiting' && !isset($r['view_token']) && (float)$r['total'] === 420000.0, 'priced at BJP: 300,000 + 2 × 60,000 = 420,000; waiting, no link');
$req = srTestRequest($pdo, (string)($r['request_number'] ?? ''));
ok($req && $req['status'] === 'pending' && (float)$req['total'] === 420000.0 && count($req['lines']) === 2 && $qCount($cust) === $qBefore,
   'a PENDING request for BJP — no quotation written yet');
$s = srTestQuote(fn($f, $p) => call($f, $p), $pdo, (string)($r['request_number'] ?? ''), $cust);
$q = $s['quotation'];
$qi = $q ? $pdo->query("SELECT * FROM quotation_items WHERE order_id = " . (int)$q['sales_order_id'] . " ORDER BY order_item_id")->fetchAll(PDO::FETCH_ASSOC) : [];
ok(($q['status'] ?? '') === 'pending' && $q['approved_at'] === null && (int)$q['created_by'] === $uid && strpos((string)$q['reference'], (string)$r['request_number']) !== false
   && (float)$q['grand_total'] === 420000.0, 'staff save it → a PENDING quotation, created by them, referencing the request');
ok(count($qi) === 2 && (int)$qi[0]['quantity'] === 1 && (int)$qi[0]['plan_price_id'] === (int)$P1y['id'] && $qi[0]['plan_addon_id'] === null
   && (int)$qi[1]['quantity'] === 2 && (int)$qi[1]['plan_addon_id'] === (int)$Pshop['id'] && (int)$qi[1]['plan_price_id'] === (int)$P1y['id'],
   'lines: the plan (qty 1) + the extra (qty 2), both stamped with plan + term');
$firstQ = (int)($q['sales_order_id'] ?? 0);

section('B3. a wrong request is refused, nothing written');
$n = $qCount($cust);
$nReq = fn() => (int)$pdo->query("SELECT COUNT(*) FROM subscription_requests WHERE tenant_id = $tid")->fetchColumn();
$nr = $nReq();
$bad = [
    'an extra of another period'  => [$tP1y, [$xP1m => 1], 'not sold with the plan'],
    'an extra of another plan'    => [$tP1y, [$xB1y => 1], 'not sold with the plan'],
    'a fractional quantity'       => [$tP1y, [$xP1y => 1.5], 'whole number'],
    'more than 99'                => [$tP1y, [$xP1y => 100], 'whole number'],
    'a negative quantity'         => [$tP1y, [$xP1y => -1], 'whole number'],
    'an unknown extra'            => [$tP1y, ['a999999-' . $P1y['id'] => 1], 'not sold with the plan'],
    'the private plan'            => ['p' . $price($priv, '1y')['id'], [], 'not available'],
];
foreach ($bad as $label => [$term, $extras, $want]) {
    $r = company($tid, ['action' => 'request_quote', 'product_id' => $sys, 'term_id' => $term, 'extras' => $extras]);
    ok(empty($r['success']) && stripos((string)($r['message'] ?? ''), $want) !== false, "$label → refused (" . ($r['message'] ?? '?') . ')');
}
$r = company($tid, ['action' => 'request_quote', 'product_id' => $hosting, 'term_id' => $hTerm, 'extras' => [$xP1y => 1]]);
ok(empty($r['success']) && stripos((string)$r['message'], 'only with a plan') !== false, 'extras on a period-priced service → refused');
ok($qCount($cust) === $n && $nReq() === $nr && (srTestRequest($pdo, (string)$req['request_number'])['status'] ?? '') === 'quoted', 'no quotation or request written; the handled request untouched');

// ── B4. What the company sees — quotations ─────────────────────────────────
section('B4. quotations: shown only once BJP approves (D7)');
$d = company($tid, ['action' => 'documents']);
ok(count($d['quotations'] ?? []) === 0 && count($d['requests'] ?? []) === 1 && count($d['requests'][0]['lines'] ?? []) === 2 && ($d['requests'][0]['status'] ?? '') === 'waiting',
   'pending → hidden; listed as a request waiting for BJP, with its two lines');
$view = function (string $type, int $id) use ($root, $cust) {
    return runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . VENDOR_ENV . "
        \$_GET = ['t' => " . var_export((string)plDocToken($type, $id, $cust), true) . "]; ob_start(); include '$root/api/platform/customer_document.php'; echo ob_get_clean();");
};
$qNum = (string)$q['order_number'];
ok(strpos($view('quotation', $firstQ), $qNum) === false, 'a valid link still cannot open the pending quotation');
$rv = call('api/account/review_quotation.php', ['quotation_id' => $firstQ, 'id' => $firstQ]);
$d = company($tid, ['action' => 'documents']);
ok(!empty($rv['success']) && count($d['quotations'] ?? []) === 0 && count($d['requests'] ?? []) === 1, 'reviewed → still hidden, still waiting');
ok(strpos($view('quotation', $firstQ), $qNum) === false, 'customer copy of a reviewed quotation refused');
$ap = call('api/account/approve_quotation.php', ['quotation_id' => $firstQ, 'id' => $firstQ]);
$d = company($tid, ['action' => 'documents']);
$dq = $d['quotations'][0] ?? [];
ok(!empty($ap['success']) && count($d['quotations'] ?? []) === 1 && ($dq['status'] ?? '') === 'approved' && !empty($dq['view_token']) && count($d['requests'] ?? []) === 0,
   'approved (real endpoints) → shown with a link; no longer waiting');
ok(count($dq['lines'] ?? []) === 2 && strpos((string)$dq['lines'][0]['term'], 'Pro') !== false && (int)$dq['lines'][1]['qty'] === 2 && !empty($dq['visible_at']) && $d['latest'] === $dq['visible_at'],
   'each document says what it is for (plan + term, extra × 2) and when it became visible');
$html = $view('quotation', $firstQ);
ok(strpos($html, $qNum) !== false && strpos($html, 'Extra shop') !== false, 'customer copy opens once approved');
// A new request replaces the approved one → that one stays visible as "Replaced".
// A new request does not touch the approved quotation; it waits until BJP quotes it.
$r = company($tid, ['action' => 'request_quote', 'product_id' => $sys, 'term_id' => $tB1y]);
$d = company($tid, ['action' => 'documents']);
ok(!empty($r['success']) && count($d['quotations']) === 1 && $d['quotations'][0]['status'] === 'approved' && count($d['requests']) === 1 && $d['requests'][0]['number'] === $r['request_number'],
   'a new request: the approved quotation stays, the new request waits');
// A pending request replaced before BJP handles it never appears.
$r2 = company($tid, ['action' => 'request_quote', 'product_id' => $sys, 'term_id' => $tB1y]);
$d = company($tid, ['action' => 'documents']);
ok(count($d['quotations']) === 1 && count($d['requests']) === 1 && $d['requests'][0]['number'] === $r2['request_number']
   && (srTestRequest($pdo, (string)$r['request_number'])['status'] ?? '') === 'replaced', 'a request replaced while still pending is never shown');

// ── B5. Invoices ───────────────────────────────────────────────────────────
section('B5. invoices: shown only once approved (D7)');
$mkInv = function () use ($cust, $wid, $sys, $tP1y, $pdo) {
    $before = (int)$pdo->query("SELECT COALESCE(MAX(invoice_id),0) FROM invoices")->fetchColumn();
    call('api/account/save_invoice.php', ['customer_id' => $cust, 'invoice_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+7 days')),
        'currency' => 'TZS', 'warehouse_id' => $wid, 'notes' => 'SPP-TEST', 'items' => [['product_id' => $sys, 'product_name' => 'SPP-TEST Shop System',
        'quantity' => 1, 'unit' => 'job', 'unit_price' => 300000, 'tax_rate' => 0, 'discount_percent' => 0, 'description' => '', 'price_term_id' => $tP1y]]]);
    return (int)$pdo->query("SELECT MAX(invoice_id) FROM invoices WHERE customer_id = $cust AND invoice_id > $before")->fetchColumn();
};
$inv = $mkInv();
$st = fn(int $id) => $pdo->query("SELECT status FROM invoices WHERE invoice_id = $id")->fetchColumn();
$invNum = (string)$pdo->query("SELECT invoice_number FROM invoices WHERE invoice_id = $inv")->fetchColumn();
$d = company($tid, ['action' => 'documents']);
ok($inv > 0 && $st($inv) === 'pending' && count($d['invoices']) === 0, 'a new (pending) invoice → hidden');
ok(strpos($view('invoice', $inv), $invNum) === false, 'customer copy of a pending invoice refused');
call('api/account/review_invoice.php', ['invoice_id' => $inv]);
$d = company($tid, ['action' => 'documents']);
ok($st($inv) === 'reviewed' && count($d['invoices']) === 0, 'reviewed → still hidden');
$ap = call('api/account/approve_invoice.php', ['invoice_id' => $inv]);
$d = company($tid, ['action' => 'documents']);
$di = $d['invoices'][0] ?? [];
ok(!empty($ap['success']) && count($d['invoices']) === 1 && $di['status'] === 'unpaid' && !empty($di['view_token']) && strpos((string)($di['lines'][0]['term'] ?? ''), 'Pro') !== false,
   'approved → shown as unpaid, with a link and what it is for');
ok(strpos($view('invoice', $inv), $invNum) !== false, 'customer copy opens once approved');
// Cancelled before approval → never shown; cancelled after approval → shown as cancelled.
$inv2 = $mkInv();
$pdo->exec("UPDATE invoices SET status = 'cancelled' WHERE invoice_id = $inv2");
$d = company($tid, ['action' => 'documents']);
ok(count($d['invoices']) === 1 && $d['invoices'][0]['number'] === $invNum, 'an invoice cancelled before approval is never shown');
$inv3 = $mkInv();
call('api/account/review_invoice.php', ['invoice_id' => $inv3]);
call('api/account/approve_invoice.php', ['invoice_id' => $inv3]);
$pdo->exec("UPDATE invoices SET status = 'cancelled' WHERE invoice_id = $inv3");
$d = company($tid, ['action' => 'documents']);
$c3 = array_values(array_filter($d['invoices'], fn($x) => $x['status'] === 'cancelled'));
ok(count($d['invoices']) === 2 && count($c3) === 1, 'an invoice cancelled after approval stays visible, as cancelled');

// ── B6. The company API ────────────────────────────────────────────────────
section('B6. company API: plan summary, "new" flags, badge, request checks');
$s = companyApi($tid, ['action' => 'status']);
$pl = $s['plan'] ?? [];
ok(!empty($s['success']) && ($pl['plan_key'] ?? '') === $basic['plan_key'] && ($pl['name'] ?? '') === "Basic $tag" && $pl['max_users'] === 2 && $pl['max_shops'] === 1
   && $pl['extra_shops'] === 0 && count($pl['items']) === 1 && $pl['scheduled'] === null, 'status: the plan the company is on, its limits, items, no change waiting');
$c->prepare("UPDATE tenants SET scheduled_plan_key = ?, scheduled_plan_at = DATE_ADD(NOW(), INTERVAL 5 DAY) WHERE id = ?")->execute([$pro['plan_key'], $tid]);
$pl = companyApi($tid, ['action' => 'status'])['plan'] ?? [];
ok(($pl['scheduled']['name'] ?? '') === "Pro $tag" && !empty($pl['scheduled']['at']), 'a change waiting for period end is shown');
$c->prepare("UPDATE tenants SET scheduled_plan_key = NULL, scheduled_plan_at = NULL WHERE id = ?")->execute([$tid]);

$pdo->exec("DELETE FROM system_settings WHERE setting_key = 'billing_seen_at'");
$seen = fn() => $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'billing_seen_at'")->fetchColumn();
$b = companyApi($tid, ['action' => 'badge']);
ok(!empty($b['success']) && $b['new'] === 0 && $seen() === $d['latest'], 'first badge: old history counts as seen (0), marker set to the newest');
$b = companyApi($tid, ['action' => 'badge'], 'GET', "\$_SESSION['billing_badge'] = ['at' => time(), 'n' => 7];");
ok(($b['new'] ?? null) === 7, 'badge answered from the 10-minute session cache');
$b = companyApi($tid, ['action' => 'badge'], 'GET', "\$_SESSION['billing_badge'] = ['at' => time() - 601, 'n' => 7];");
ok(($b['new'] ?? null) === 0, 'an old cache is asked again');
// BJP approves a new invoice → one new document.
$pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'billing_seen_at'")->execute([(string)company($tid, ['action' => 'documents'])['latest']]);
sleep(1);   // visible_at is to the second
$inv4 = $mkInv();
call('api/account/review_invoice.php', ['invoice_id' => $inv4]);
call('api/account/approve_invoice.php', ['invoice_id' => $inv4]);
$num4 = (string)$pdo->query("SELECT invoice_number FROM invoices WHERE invoice_id = $inv4")->fetchColumn();
$b = companyApi($tid, ['action' => 'badge']);
ok(($b['new'] ?? null) === 1, 'a newly approved invoice → badge 1');
$docs = companyApi($tid, ['action' => 'documents']);
$new = array_column(array_filter($docs['invoices'] ?? [], fn($x) => !empty($x['is_new'])), 'number');
ok(!empty($docs['success']) && $new === [$num4] && !empty($docs['invoices'][0]['view_url']) && !isset($docs['invoices'][0]['view_token']), 'Billing marks it "New" (only that one); links, never raw tokens');
ok(companyApi($tid, ['action' => 'badge'])['new'] === 0 && companyApi($tid, ['action' => 'documents'])['invoices'][0]['is_new'] === false, 'opening Billing clears the badge and the "New" chips');

$n = $qCount($cust);
$req = fn(array $p) => companyApi($tid, $p + ['action' => 'request', 'product_id' => $sys], 'POST');
$r = $req(['term_id' => $tP1y, 'extras' => json_encode(['bogus' => 1])]);
ok(empty($r['success']) && stripos((string)$r['message'], 'whole number') !== false, 'request: a malformed extra token → refused before BJP is asked');
$r = $req(['term_id' => $tP1y, 'extras' => json_encode([$xP1y => '1.5'])]);
ok(empty($r['success']), 'request: a fractional extra → refused');
$r = companyApi($tid, ['action' => 'request', 'product_id' => $hosting, 'term_id' => (string)$hTerm, 'extras' => json_encode([$xP1y => 1])], 'POST');
ok(empty($r['success']) && stripos((string)$r['message'], 'only with a plan') !== false, 'request: extras with a period-priced service → refused');
ok($qCount($cust) === $n, 'nothing written for the refused requests');
$r = $req(['term_id' => $tP1y, 'extras' => json_encode([$xP1y => 1, $xP1m => 0])]);
ok(!empty($r['success']) && (float)$r['total'] === 360000.0 && !isset($r['view_url']) && ($r['status'] ?? '') === 'waiting', 'request: Pro 1y + 1 extra (a 0 is dropped) → 360,000, waiting');
$r = companyApi($tid, ['action' => 'request', 'product_id' => $sys, 'term_id' => $tP1y], 'GET');
ok(empty($r['success']), 'request by GET → refused');

// ── B7. Page, menu, redirect ───────────────────────────────────────────────
section('B7. Billing page + menu');
$page = function (string $file, bool $tenant, bool $admin = true) use ($root, $uid, $tid) {
    return runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
        " . ($tenant ? "\$GLOBALS['__bms_tenant'] = ['id' => $tid];" : '') . "
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = " . ($admin ? 1 : 2) . "; \$_SESSION['is_admin'] = " . ($admin ? 'true' : 'false') . ";
        \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_lang'] = 'en';
        ob_start(); include '$root/$file'; echo ob_get_clean();");
};
$html = $page('app/constant/settings/billing.php', true);
ok(strpos($html, 'id="catalog"') !== false && strpos($html, 'id="curPlanBody"') !== false && strpos($html, 'id="waitCard"') !== false
   && strpos($html, 'id="tblInvoices"') !== false && strpos($html, 'Fatal error') === false && strpos($html, 'Warning:') === false, 'Billing renders: plan, renew, waiting, documents');
ok(strpos($html, 'bms-billing-badge') !== false && strpos($html, '?action=badge') !== false, 'the menu shows Billing (+ badge) for a company admin');
$root0 = $page('app/constant/settings/billing.php', false);
ok(strpos($root0, 'bms-billing-badge') === false && strpos($root0, 'Nothing to show on this installation') !== false, 'on the platform root: no Billing menu, nothing to show');
$staff = $page('app/constant/settings/billing.php', true, false);
ok(strpos($staff, 'id="catalog"') === false, 'non-admin staff cannot open Billing');
$dash = $page('app/constant/settings/available_modules.php', true, false);
ok(strpos($dash, 'bms-billing-badge') === false, 'non-admin staff do not see Billing in the menu');

// The one ledger still balances after all of the above.
$bal = assertLedgerBalanced($pdo, date('Y-m-d'));
ok(!empty($bal['balanced'] ?? $bal), 'ledger still balanced');
