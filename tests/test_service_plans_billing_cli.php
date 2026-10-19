<?php
/**
 * Paid plan invoices → the company's plan, extras and shop limit (service_plans_plan.md Phase 5)
 *   php tests/test_service_plans_billing_cli.php
 *
 * A: wiring (CI).  B: on the local database + control DB, through the real endpoints:
 *   plan / add-on lines on invoices (save → review → approve → record_payment), the
 *   document rules (one plan, qty 1, add-ons follow their plan), upgrade now /
 *   renew = extend only / downgrade at period end, add-on grants and their supersede
 *   rule, bridge alerts, the shop limit on every activate path, the company gateway
 *   with plan tokens, and the four "ordinary documents unchanged" regressions.
 * Data is 'SPB-TEST' / 'spbtest*' and removed afterwards. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
const SPB_KEY = 'c1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f93';
putenv('TENANT_CRED_KEY=' . SPB_KEY);
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
foreach (['api/account/save_quotation.php', 'api/account/save_sales_order.php', 'api/account/save_invoice.php'] as $f) {
    ok(strpos($src($f), 'sptAssertPlanLines(') !== false, "$f checks the plan-line rules");
}
ok(strpos($src('core/vendor_bridge.php'), 'planApplyPaid(') !== false && strpos($src('core/vendor_bridge.php'), 'planGrantAddon(') !== false, 'the bridge applies the plan and the extras');
ok(strpos($src('api/cron/vendor_bridge_retry.php'), 'planApplyDueScheduled(') !== false, 'the cron applies due downgrades');
ok(strpos($src('core/tenant_bootstrap.php'), 'planApplyDueScheduled(') !== false, 'a company applies its own due downgrade on its next request');
ok(substr_count($src('app/bms/stock/warehouses.php'), 'tenantCanActivateShop(') >= 3, 'web shops page: add, edit and toggle check the shop limit');
foreach (['ajax_toggle_warehouse_status.php', 'api/mobile/warehouses/create.php', 'api/mobile/warehouses/update.php'] as $f) {
    ok(strpos($src($f), 'tenantCanActivateShop(') !== false, "$f checks the shop limit");
}

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B. Setup ───────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/vendor_bridge.php";
require_once "$root/core/payment_source.php";
require_once "$root/core/plans.php";
require_once "$root/core/platform_link.php";
require_once "$root/core/platform_gateway.php";
require_once "$root/core/financial_reports.php";
sptEnsureSchema($pdo);
ob_start(); sptEnsureLineColumns($pdo); sptEnsurePlanLineColumns($pdo); ob_end_clean();
vbEnsureOutbox($pdo);
$c = getControlPdo();
if (!vlLinkReady() || !vbAlertsReady() || !planCatalogReady() || !controlColumnExists('tenants', 'scheduled_plan_key')) {
    ok(false, 'control DB upgraded (run scripts/setup_control_db.php)'); exit;
}
$uid  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid  = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$bank = (int)(cashBankAccounts($pdo)[0]['account_id'] ?? 0);
$goods = $pdo->query("SELECT product_id, product_name, cost_price FROM products WHERE COALESCE(is_service,0) = 0 AND COALESCE(track_inventory,1) = 1
                       AND status = 'active' AND cost_price > 0 ORDER BY product_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$uid || !$wid || !$bank || !$goods) { echo "SKIP B: needs an admin, a warehouse, a cash/bank account and a stocked product with a cost\n"; exit; }

$tag  = 'spb' . bin2hex(random_bytes(3));
$PROD = $tag . '_prod';
$planIds = [];
$cleanup = function () use ($pdo, $c, &$planIds, &$PROD) {
    $in = fn($a) => $a ? implode(',', array_map('intval', $a)) : '0';
    $cust = $in($pdo->query("SELECT customer_id FROM customers WHERE customer_name LIKE 'SPB-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    $inv = $in($pdo->query("SELECT invoice_id FROM invoices WHERE notes = 'SPB-TEST' OR customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
    $qt  = $in($pdo->query("SELECT sales_order_id FROM quotations WHERE reference = 'SPB-TEST' OR customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
    $so  = $in($pdo->query("SELECT sales_order_id FROM sales_orders WHERE reference = 'SPB-TEST' OR customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
    $refs = $pdo->query("SELECT invoice_number FROM invoices WHERE invoice_id IN ($inv)")->fetchAll(PDO::FETCH_COLUMN);
    if ($refs) $c->exec("DELETE FROM vendor_bridge_alerts WHERE invoice_ref IN (" . implode(',', array_map([$c, 'quote'], $refs)) . ")");
    $pay = $in($pdo->query("SELECT DISTINCT p.payment_id FROM payments p LEFT JOIN payment_allocations pa ON pa.payment_id = p.payment_id
                             WHERE p.invoice_id IN ($inv) OR (pa.target_type = 'invoice' AND pa.target_id IN ($inv))")->fetchAll(PDO::FETCH_COLUMN));
    $nums = $pdo->query("SELECT payment_number FROM payments WHERE payment_id IN ($pay)")->fetchAll(PDO::FETCH_COLUMN);
    if ($nums) $pdo->exec("DELETE FROM bank_transactions WHERE reference_number IN (" . implode(',', array_map([$pdo, 'quote'], $nums)) . ")");
    $pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                 WHERE (je.entity_type IN ('payment','payment_void') AND je.entity_id IN ($pay)) OR (je.entity_type IN ('invoice','invoice_cogs','invoice_void') AND je.entity_id IN ($inv))");
    $pdo->exec("DELETE FROM journal_entries WHERE (entity_type IN ('payment','payment_void') AND entity_id IN ($pay)) OR (entity_type IN ('invoice','invoice_cogs','invoice_void') AND entity_id IN ($inv))");
    $pdo->exec("DELETE FROM payment_allocations WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM payments WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM vendor_bridge_outbox WHERE invoice_id IN ($inv)");
    try { $pdo->exec("DELETE FROM customer_contracts WHERE invoice_id IN ($inv)"); } catch (Throwable $e) {}
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM invoices WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM sales_order_items WHERE order_id IN ($so)");
    $pdo->exec("DELETE FROM sales_orders WHERE sales_order_id IN ($so)");
    $pdo->exec("DELETE FROM quotation_items WHERE order_id IN ($qt)");
    $pdo->exec("DELETE FROM quotations WHERE sales_order_id IN ($qt)");
    try { $pdo->exec("DELETE FROM workflow_signatures WHERE (entity_type='invoice' AND entity_id IN ($inv)) OR (entity_type='sales_order' AND entity_id IN ($so)) OR (entity_type='quotation' AND entity_id IN ($qt))"); } catch (Throwable $e) {}
    try { $pdo->exec("DELETE FROM notifications WHERE customer_id IN ($cust)"); } catch (Throwable $e) {}
    $p = $in($pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'SPB-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (['service_price_terms', 'vendor_subscription_services', 'products'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN ($p)");
    foreach ($pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['ledger_account_id']) $pdo->exec("DELETE FROM accounts WHERE account_id = " . (int)$r['ledger_account_id']);
        $pdo->exec("DELETE FROM customers WHERE customer_id = " . (int)$r['customer_id']);
    }
    $w = $in($pdo->query("SELECT warehouse_id FROM warehouses WHERE warehouse_name LIKE 'SPB-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    $pdo->exec("DELETE FROM locations WHERE warehouse_id IN ($w)");
    $pdo->exec("DELETE FROM warehouses WHERE warehouse_id IN ($w)");
    $t = $in($c->query("SELECT id FROM tenants WHERE subdomain LIKE 'spbtest%'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (['tenant_addon_grants', 'tenant_payments', 'tenant_admin_log', 'vendor_bridge_alerts', 'tenant_features'] as $tb) {
        try { $c->exec("DELETE FROM $tb WHERE tenant_id IN ($t)"); } catch (Throwable $e) {}
    }
    try { $pdo->exec("DELETE FROM subscription_requests WHERE subdomain LIKE 'spbtest%'"); } catch (Throwable $e) {}
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'spbtest%'");
    foreach ($planIds as $id) {
        $id = (int)$id;
        $c->exec("DELETE pap FROM plan_addon_prices pap JOIN plan_addons pa ON pa.id = pap.addon_id WHERE pa.plan_id = $id");
        foreach (['plan_addons', 'plan_prices', 'plan_items', 'plan_features'] as $tb) $c->exec("DELETE FROM $tb WHERE plan_id = $id");
        $c->exec("DELETE FROM plans WHERE id = $id");
    }
    $c->prepare("DELETE FROM platform_products WHERE product_key = ?")->execute([$PROD]);
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'spb_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function lastJson(string $out): ?array { $j = json_decode($out, true); if (is_array($j)) return $j; $p = strrpos($out, "\n{"); return $p !== false ? json_decode(substr($out, $p + 1), true) : null; }
const VENDOR_ENV = "putenv('TENANT_CRED_KEY=" . SPB_KEY . "'); putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');";
/** An endpoint as BJP staff would call it. $tenant = act as that company's own server (shop-limit tests). */
function call(string $file, array $post, bool $vendor = true, ?array $tenant = null): array {
    global $root, $uid;
    $env = $vendor ? VENDOR_ENV : "putenv('BMS_VENDOR_CONTEXT');";
    $tn = $tenant !== null ? "\$GLOBALS['__bms_tenant'] = " . var_export($tenant, true) . ";" : '';
    $j = lastJson(runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        $env $tn
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
function makeInvoice(int $cust, array $lines): array {
    global $pdo, $wid;
    $items = [];
    foreach ($lines as $l) $items[] = $l + ['quantity' => 1, 'unit' => 'job', 'tax_rate' => 0, 'discount_percent' => 0, 'description' => ''];
    $before = (int)$pdo->query("SELECT COALESCE(MAX(invoice_id),0) FROM invoices")->fetchColumn();
    $r = call('api/account/save_invoice.php', ['customer_id' => $cust, 'invoice_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+7 days')), 'currency' => 'TZS', 'warehouse_id' => $wid, 'notes' => 'SPB-TEST', 'items' => $items]);
    $id = (int)($r['invoice_id'] ?? 0);
    if (!$id && !empty($r['success'])) $id = (int)$pdo->query("SELECT MAX(invoice_id) FROM invoices WHERE notes = 'SPB-TEST' AND invoice_id > $before")->fetchColumn();
    if (!$id) return ['invoice_id' => 0, 'invoice_number' => '', 'grand_total' => 0, 'message' => $r['message'] ?? ''];
    call('api/account/review_invoice.php', ['invoice_id' => $id]);
    call('api/account/approve_invoice.php', ['invoice_id' => $id]);
    return $pdo->query("SELECT invoice_id, invoice_number, grand_total, status FROM invoices WHERE invoice_id = $id")->fetch(PDO::FETCH_ASSOC);
}
function pay(array $inv): array {
    global $bank;
    return call('api/account/record_payment.php', ['invoice_id' => $inv['invoice_id'], 'amount' => (float)$inv['grand_total'], 'payment_date' => date('Y-m-d'),
        'payment_method' => 'bank_transfer', 'status' => 'completed', 'received_into_account_id' => $bank, 'reference_number' => 'SPB-TEST']);
}
$msg = fn(array $r) => empty($r['success']) ? ' — ' . ($r['message'] ?? '') : '';

// The platform product, three plans (Basic, Pro, a private one).
$c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?,?,1,0,1)")->execute([$PROD, "Test Shop $tag"]);
$mk = function (array $in) use (&$planIds, $PROD) {
    $r = createPlan($in + ['product_key' => $PROD, 'is_popular' => false, 'items' => [], 'addons' => []]);
    if (!empty($r['id'])) $planIds[] = (int)$r['id'];
    return $r;
};
$rb = $mk(['name' => "Basic $tag", 'max_users' => 2, 'max_storage_mb' => 500, 'feature_keys' => ['warehouses', 'pos'], 'max_shops' => 1, 'tier_rank' => 10, 'is_public' => true,
    'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 15000, 'is_default' => 0],
                 ['duration_value' => 1, 'duration_unit' => 'year', 'price_tzs' => 150000, 'is_default' => 1]],
    'addons' => [['name' => 'Extra shop', 'quota' => 'max_shops', 'units' => 1, 'prices' => ['1m' => 5000, '1y' => 40000]]]]);
$rp = $mk(['name' => "Pro $tag", 'max_users' => 5, 'max_storage_mb' => 2000, 'feature_keys' => ['warehouses', 'pos', 'pos_advanced'], 'max_shops' => 3, 'tier_rank' => 20, 'is_public' => true,
    'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 30000, 'is_default' => 0],
                 ['duration_value' => 1, 'duration_unit' => 'year', 'price_tzs' => 300000, 'is_default' => 1]],
    'addons' => [['name' => 'Extra shop', 'quota' => 'max_shops', 'units' => 1, 'prices' => ['1m' => 8000, '1y' => 60000]]]]);
$rh = $mk(['name' => "Private $tag", 'max_users' => 9, 'max_storage_mb' => 500, 'feature_keys' => ['warehouses', 'pos'], 'max_shops' => 9, 'tier_rank' => 30, 'is_public' => false,
    'prices' => [['duration_value' => 1, 'duration_unit' => 'year', 'price_tzs' => 999000, 'is_default' => 1]]]);
if (empty($rb['ok']) || empty($rp['ok']) || empty($rh['ok'])) { ok(false, 'plan fixtures: ' . ($rb['error'] ?? '') . ($rp['error'] ?? '') . ($rh['error'] ?? '')); exit; }
$basic = getPlan((int)$rb['id']); $pro = getPlan((int)$rp['id']); $priv = getPlan((int)$rh['id']);
$price = function (array $plan, string $termKey) { foreach (planPrices((int)$plan['id']) as $p) if ($p['term_key'] === $termKey) return $p; return null; };
$addon = fn(array $plan) => planAddons((int)$plan['id'])[0];
$B1y = $price($basic, '1y'); $P1y = $price($pro, '1y'); $P1m = $price($pro, '1m'); $H1y = $price($priv, '1y');
$Pshop = $addon($pro); $Bshop = $addon($basic);

// BJP's service that sells the platform product, an ordinary service, a term-priced service.
$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SPB-TEST Shop System', 'subscription_flag_sent' => '1', 'is_subscription' => '1',
    'platform_product_sent' => '1', 'platform_product_key' => $PROD, 'price_terms' => '']);
$sys = (int)($r['product_id'] ?? 0);
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SPB-TEST Training', 'selling_price' => '30000']);
$plain = (int)($r['product_id'] ?? 0);
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SPB-TEST Hosting', 'price_terms' => json_encode([
    ['term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 10000, 'is_default' => 1],
    ['term_label' => '1 Year', 'duration_value' => 1, 'duration_unit' => 'year', 'price' => 85000]])]);
$hosting = (int)($r['product_id'] ?? 0);
$hTerm = [];
foreach (sptForProduct($pdo, $hosting) as $t) $hTerm[$t['term_label']] = (int)$t['term_id'];
ok($sys && vssPlatformProduct($pdo, $sys) === $PROD && $plain && $hosting && count($hTerm) === 2, 'fixtures: platform plans, the mapped service, an ordinary and a term-priced service');

$pdo->beginTransaction();
$cust  = vlCreateCustomer($pdo, ['customer_name' => 'SPB-TEST Duka'], $uid);
$other = vlCreateCustomer($pdo, ['customer_name' => 'SPB-TEST Walk-in'], $uid);
$pdo->commit();
$trialEnd = date('Y-m-d', strtotime('+5 days'));
$c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, status, trial_ends_at)
             VALUES ('SPB-TEST Duka', ?, 'none', 'none', 'x', 'spb@example.com', 'trial', ?)")->execute([$tag === '' ? '' : 'spbtest1', "$trialEnd 23:59:59"]);
$tid = (int)$c->lastInsertId();
vlSetTenantCustomer($tid, $cust, 'test', 'SPB-TEST Duka');
$tenant = function () use ($c, $tid) { $s = $c->prepare("SELECT * FROM tenants WHERE id = ?"); $s->execute([$tid]); return $s->fetch(PDO::FETCH_ASSOC); };
$feat = function () use ($tid) { $m = []; foreach (tenantFeatureMatrix($tid) as $r) $m[$r['key']] = (bool)$r['effective']; return $m; };
$logs = fn(string $action) => (int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE tenant_id = $tid AND action = " . $c->quote($action))->fetchColumn();
$grants = fn() => $c->query("SELECT * FROM tenant_addon_grants WHERE tenant_id = $tid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$openAlerts = function (string $ref) use ($c) { $s = $c->prepare("SELECT kind FROM vendor_bridge_alerts WHERE invoice_ref = ? AND resolved_at IS NULL"); $s->execute([$ref]); return $s->fetchAll(PDO::FETCH_COLUMN); };
$line = fn($tok, int $price, $qty = 1, string $name = 'SPB-TEST Shop System') => ['product_id' => $sys, 'product_name' => $name, 'quantity' => $qty, 'unit_price' => $price, 'price_term_id' => $tok];
$invLines = fn(int $inv) => $pdo->query("SELECT * FROM invoice_items WHERE invoice_id = $inv ORDER BY invoice_item_id")->fetchAll(PDO::FETCH_ASSOC);
$invCount = fn() => (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE notes = 'SPB-TEST'")->fetchColumn();

// ── B1. Document rules ─────────────────────────────────────────────────────
section('B1. plan lines on an invoice — the rules');
$n0 = $invCount();
$cases = [
    'plan qty 2'                      => [[$line("p{$P1y['id']}", 300000, 2)], 'quantity 1'],
    'plan qty 0.5'                    => [[$line("p{$P1y['id']}", 300000, 0.5)], 'quantity 1'],
    'two plan lines'                  => [[$line("p{$P1y['id']}", 300000), $line("p{$B1y['id']}", 150000)], 'only one plan'],
    'an extra without its plan'       => [[$line("a{$Pshop['id']}-{$P1y['id']}", 60000)], 'same document'],
    'an extra on another term'        => [[$line("p{$P1m['id']}", 30000), $line("a{$Pshop['id']}-{$P1y['id']}", 60000)], 'same term'],
    'an extra with qty 1.5'           => [[$line("p{$P1y['id']}", 300000), $line("a{$Pshop['id']}-{$P1y['id']}", 60000, 1.5)], 'whole number'],
    "another plan's extra"            => [[$line("p{$P1y['id']}", 300000), $line("a{$Bshop['id']}-{$P1y['id']}", 60000)], 'not sold with'],
    'a plan token on an unmapped service' => [[['product_id' => $plain, 'product_name' => 'SPB-TEST Training', 'unit_price' => 300000, 'price_term_id' => "p{$P1y['id']}"]], 'does not belong'],
    'a made-up price id'              => [[$line('p999999999', 1)], 'does not belong'],
];
foreach ($cases as $label => [$lines, $needle]) {
    $items = array_map(fn($l) => $l + ['quantity' => 1, 'unit' => 'job', 'tax_rate' => 0, 'discount_percent' => 0, 'description' => ''], $lines);
    $r = call('api/account/save_invoice.php', ['customer_id' => $cust, 'invoice_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'),
        'currency' => 'TZS', 'warehouse_id' => $wid, 'notes' => 'SPB-TEST', 'items' => $items]);
    ok(empty($r['success']) && stripos((string)($r['message'] ?? ''), $needle) !== false, "refused: $label — " . ($r['message'] ?? ''));
}
ok($invCount() === $n0, 'no refused invoice left a row behind (the whole save rolled back)');

// ── B2. First paid plan: upgrade applies now ───────────────────────────────
section('B2. pay Pro yearly + 2 extra shops (trial → Pro)');
$inv1 = makeInvoice($cust, [$line("p{$P1y['id']}", 300000), $line("a{$Pshop['id']}-{$P1y['id']}", 60000, 2, 'SPB-TEST Extra shop')]);
$l1 = $invLines((int)$inv1['invoice_id']);
ok($inv1['invoice_id'] && (float)$inv1['grand_total'] === 420000.0 && ($inv1['status'] ?? '') === 'approved', "invoice {$inv1['invoice_number']} approved for 300,000 + 2 × 60,000");
ok(($l1[0]['plan_key'] ?? '') === $pro['plan_key'] && (int)$l1[0]['plan_price_id'] === (int)$P1y['id'] && $l1[0]['price_term_id'] === null
   && (int)$l1[0]['term_duration_value'] === 1 && $l1[0]['term_duration_unit'] === 'year', 'plan line: plan snapshot + 1 year, no local term id');
ok((int)($l1[1]['plan_addon_id'] ?? 0) === (int)$Pshop['id'] && $l1[1]['addon_quota'] === 'max_shops' && (int)$l1[1]['addon_units'] === 1, 'extra line: add-on snapshot (quota, units)');
ok(strpos((string)$l1[0]['description'], "Term: Pro $tag — ") !== false, 'the line description says which plan and term');
ok($tenant()['status'] === 'trial', 'nothing changes before payment');
$r = pay($inv1);
$t = $tenant();
$end1 = date('Y-m-d', strtotime("$trialEnd +12 months"));
ok(!empty($r['success']) && $t['status'] === 'active' && $t['subscription_ends_at'] === $end1, "paid → active until $end1 (12 months after the trial)" . $msg($r));
ok($t['plan'] === $pro['plan_key'] && (int)$t['max_shops'] === 3 && (int)$t['max_users'] === 5, 'Pro applied now: plan, 3 shops, 5 users');
$f = $feat();
ok(!empty($f['pos']) && !empty($f['pos_advanced']) && !empty($f['warehouses']), "Pro's modules switched on");
$g = $grants();
ok(count($g) === 1 && (int)$g[0]['units'] === 2 && $g[0]['quota'] === 'max_shops' && $g[0]['starts_at'] === date('Y-m-d') && $g[0]['ends_at'] === $end1
   && $g[0]['source_ref'] === $inv1['invoice_number'], '+2 shops granted from today to the end of the paid period');
ok((int)$c->query("SELECT duration_months FROM tenant_payments WHERE tenant_id = $tid AND source_ref = " . $c->quote($inv1['invoice_number']))->fetchColumn() === 12,
   'extras add no months of their own (12, not 36)');
ok($logs('plan_upgraded') === 1 && $logs('addon_granted') === 1, 'audit: plan_upgraded + addon_granted');
ok($t['scheduled_plan_key'] === null, 'no change scheduled');

// The limit a company sees = plan + extras.
$lim = runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; \$GLOBALS['__bms_tenant'] = " . var_export($t, true) . "; echo json_encode([tenantShopLimit(), tenantUserLimit()]);");
ok(lastJson($lim) === [5, 5], 'tenantShopLimit() = 3 + 2 extras; users unchanged = 5 (' . $lim . ')');

section('B3. paying again changes nothing');
runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . VENDOR_ENV . " require_once '$root/core/vendor_bridge.php'; global \$pdo; vendorOnInvoicesSettled(\$pdo, [{$inv1['invoice_id']}]); vendorBridgeRetry(\$pdo);");
ok($tenant()['subscription_ends_at'] === $end1 && count($grants()) === 1 && $logs('plan_upgraded') === 1, 'bridge re-run: same end date, one grant, one upgrade');

// ── B4. Renew the same plan: extend only ───────────────────────────────────
section('B4. renew Pro monthly + 1 extra shop — modules untouched');
// Superadmin's custom grants on top of the plan must survive a renewal.
$c->prepare("UPDATE tenants SET max_users = 9 WHERE id = ?")->execute([$tid]);
setTenantFeatures($tid, ['pos_advanced' => false]);
$inv2 = makeInvoice($cust, [$line("p{$P1m['id']}", 30000), ['product_id' => $sys, 'product_name' => 'SPB-TEST Extra shop', 'unit_price' => 8000, 'price_term_id' => "a{$Pshop['id']}-{$P1m['id']}"]]);
// The extra is the 1-month Pro extra — check it exists for that term (the fixture gave it 1m).
$r = pay($inv2);
$t = $tenant();
$end2 = date('Y-m-d', strtotime("$end1 +1 month"));
ok(!empty($r['success']) && $t['subscription_ends_at'] === $end2, "renewed → access until $end2" . $msg($r));
ok($t['plan'] === $pro['plan_key'] && (int)$t['max_users'] === 9 && empty($feat()['pos_advanced']), "same plan: superadmin's custom limits and modules left as they are");
ok($logs('plan_renewed') === 1 && $logs('plan_upgraded') === 1, 'audit: plan_renewed (no second apply)');
$g = $grants();
$today = date('Y-m-d'); $yesterday = date('Y-m-d', strtotime('-1 day'));
ok(count($g) === 2 && $g[0]['ends_at'] === $yesterday && (int)$g[1]['units'] === 1 && $g[1]['starts_at'] === $today && $g[1]['ends_at'] === $end2,
   'the newest purchase replaces the older one from today (1 extra now, not 2 + 1)');
ok(tenantAddonUnits($tid, 'max_shops') === 1, 'active extras = 1');

// ── B5. Downgrade waits for the end of the paid period ─────────────────────
section('B5. pay Basic yearly while on Pro → scheduled');
$c->prepare("UPDATE tenants SET max_users = 5 WHERE id = ?")->execute([$tid]);
$inv3 = makeInvoice($cust, [$line("p{$B1y['id']}", 150000)]);
$r = pay($inv3);
$t = $tenant();
$tp = $c->query("SELECT starts_at, ends_at FROM tenant_payments WHERE tenant_id = $tid AND source_ref = " . $c->quote($inv3['invoice_number']))->fetch(PDO::FETCH_ASSOC);
$end3 = date('Y-m-d', strtotime("$end2 +12 months"));
ok(!empty($r['success']) && $t['subscription_ends_at'] === $end3, "12 months added → $end3" . $msg($r));
ok($t['plan'] === $pro['plan_key'] && (int)$t['max_shops'] === 3, 'still on Pro today (the paid Pro period is not cut short)');
ok($t['scheduled_plan_key'] === $basic['plan_key'] && $t['scheduled_plan_at'] === $tp['starts_at'] && $tp['starts_at'] > $today,
   "Basic scheduled for {$tp['starts_at']} (when the new period starts)");
ok($logs('plan_change_scheduled') === 1, 'audit: plan_change_scheduled');
ok(planApplyDueScheduled($tid) === 0 && $tenant()['plan'] === $pro['plan_key'], 'before the date, the scheduler leaves it alone');

$c->prepare("UPDATE tenants SET scheduled_plan_at = ? WHERE id = ?")->execute([$yesterday, $tid]);
ok(planApplyDueScheduled($tid) === 1, 'the date has come → the scheduler applies it');
$t = $tenant();
ok($t['plan'] === $basic['plan_key'] && (int)$t['max_shops'] === 1 && (int)$t['max_users'] === 2 && empty($feat()['pos_advanced']) && !empty($feat()['pos']),
   "Basic applied: 1 shop, 2 users, Pro-only module off");
ok($t['scheduled_plan_key'] === null && $t['scheduled_plan_at'] === null && $logs('plan_downgrade_applied') === 1, 'schedule cleared; audit: plan_downgrade_applied');
ok(planApplyDueScheduled($tid) === 0, 'running again does nothing');

section('B6. a manual plan change cancels a waiting one');
$c->prepare("UPDATE tenants SET scheduled_plan_key = ?, scheduled_plan_at = ? WHERE id = ?")->execute([$pro['plan_key'], $end3, $tid]);
$a = applyPlanToTenant($tid, (int)$basic['id']);
ok($a['ok'] && $tenant()['scheduled_plan_key'] === null, 'superadmin applies a plan → the scheduled change is dropped');

// ── B7. Alerts instead of guesses ──────────────────────────────────────────
section('B7. the bridge alerts when it cannot tell');
$before = $tenant()['subscription_ends_at'];
$inv4 = makeInvoice($cust, [$line("p{$B1y['id']}", 150000)]);
$pdo->exec("UPDATE invoice_items SET quantity = 1.5 WHERE invoice_id = " . (int)$inv4['invoice_id']);   // e.g. edited in the database
pay($inv4);
ok(in_array('no_term', $openAlerts($inv4['invoice_number']), true) && $tenant()['subscription_ends_at'] === $before, 'fractional quantity → alert, no months added');
$inv5 = makeInvoice($cust, [$line("p{$B1y['id']}", 150000), $line("a{$Bshop['id']}-{$B1y['id']}", 40000, 1, 'SPB-TEST Extra shop')]);
$pdo->exec("DELETE FROM invoice_items WHERE invoice_id = " . (int)$inv5['invoice_id'] . " AND plan_addon_id IS NULL");
pay($inv5);
ok(in_array('no_term', $openAlerts($inv5['invoice_number']), true) && $tenant()['subscription_ends_at'] === $before, 'extras only (no plan line) → alert, no months added');

// ── B8. Shop limit on every activate path ──────────────────────────────────
section('B8. the shop limit (plan + extras)');
$active = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status = 'active'")->fetchColumn();
$pdo->prepare("INSERT INTO warehouses (warehouse_name, warehouse_code, status, created_by, created_at) VALUES ('SPB-TEST Shop', ?, 'inactive', ?, NOW())")->execute([strtoupper($tag), $uid]);
$wh = (int)$pdo->lastInsertId();
$atLimit = ['id' => $tid, 'max_shops' => $active, 'max_users' => null, 'max_storage_mb' => null];   // no grants for a test id → limit = active count
$room    = ['id' => $tid, 'max_shops' => $active + 1] + $atLimit;
$whStatus = fn() => $pdo->query("SELECT status FROM warehouses WHERE warehouse_id = $wh")->fetchColumn();
$c->exec("DELETE FROM tenant_addon_grants WHERE tenant_id = $tid");
$r = call('ajax_toggle_warehouse_status.php', ['warehouse_id' => $wh, 'new_status' => 'active', 'csrf_token' => 'tok'], false, $atLimit);
ok(empty($r['success']) && stripos($r['message'] ?? '', 'Subscription') !== false && $whStatus() === 'inactive', 'web toggle at the limit refused: ' . ($r['message'] ?? ''));
$r = call('api/mobile/warehouses/update.php', ['warehouse_id' => $wh, 'warehouse_name' => 'SPB-TEST Shop', 'status' => 'active'], false, $atLimit);
ok(empty($r['success']) && ($r['code'] ?? '') === 'shop_limit_reached' && $whStatus() === 'inactive', 'mobile update to active refused (shop_limit_reached)');
$n = (int)$pdo->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
$r = call('api/mobile/warehouses/create.php', ['warehouse_name' => 'SPB-TEST New', 'status' => 'active'], false, $atLimit);
ok(empty($r['success']) && ($r['code'] ?? '') === 'shop_limit_reached' && (int)$pdo->query("SELECT COUNT(*) FROM warehouses")->fetchColumn() === $n, 'mobile create active refused, nothing created');
$r = call('api/mobile/warehouses/update.php', ['warehouse_id' => $wh, 'warehouse_name' => 'SPB-TEST Shop', 'status' => 'inactive'], false, $atLimit);
ok(!empty($r['success']), 'editing an inactive shop (staying inactive) still saves' . $msg($r));
$c->prepare("INSERT INTO tenant_addon_grants (tenant_id, quota, units, starts_at, ends_at, source_ref) VALUES (?, 'max_shops', 1, CURDATE(), CURDATE(), 'SPB-TEST')")->execute([$tid]);
$r = call('ajax_toggle_warehouse_status.php', ['warehouse_id' => $wh, 'new_status' => 'active', 'csrf_token' => 'tok'], false, $atLimit);
ok(!empty($r['success']) && $whStatus() === 'active', 'one extra shop bought → the same toggle succeeds' . $msg($r));
$c->exec("DELETE FROM tenant_addon_grants WHERE tenant_id = $tid AND source_ref = 'SPB-TEST'");
$r = call('api/mobile/warehouses/update.php', ['warehouse_id' => $wh, 'warehouse_name' => 'SPB-TEST Shop', 'status' => 'active'], false, ['max_shops' => $active] + $atLimit);
ok(!empty($r['success']), 'an already-active shop over the limit can still be edited' . $msg($r));
$pdo->exec("UPDATE warehouses SET status = 'inactive' WHERE warehouse_id = $wh");
$r = call('ajax_toggle_warehouse_status.php', ['warehouse_id' => $wh, 'new_status' => 'active', 'csrf_token' => 'tok'], false, $room);
ok(!empty($r['success']) && $whStatus() === 'active', 'a bigger plan (room for one more) → allowed');
$pdo->exec("UPDATE warehouses SET status = 'inactive' WHERE warehouse_id = $wh");
$r = call('ajax_toggle_warehouse_status.php', ['warehouse_id' => $wh, 'new_status' => 'active', 'csrf_token' => 'tok'], false, null);
ok(!empty($r['success']), 'BJP itself / single-tenant (no tenant) → no limit');
$pdo->exec("UPDATE warehouses SET status = 'inactive' WHERE warehouse_id = $wh");

// ── B9. The company asks for a plan (gateway) ──────────────────────────────
section('B9. My Subscription: plan tokens through the gateway');
$cat = company($tid, ['action' => 'catalog']);
$opts = [];
foreach ($cat['services'] ?? [] as $s) if ((int)$s['product_id'] === $sys) foreach ($s['terms'] as $o) $opts[(string)$o['term_id']] = $o;
ok(isset($opts["p{$P1y['id']}"], $opts["p{$B1y['id']}"]) && !isset($opts["p{$H1y['id']}"]), 'catalog lists the public plans as tokens, not the private one');
ok((int)($opts["p{$P1y['id']}"]['price'] ?? 0) === 300000 && ($opts["p{$P1y['id']}"]['plan_key'] ?? '') === $pro['plan_key'], 'price and plan come from the control DB');
require_once "$root/tests/helpers/subscription_request_quote.php";
$r = company($tid, ['action' => 'request_quote', 'product_id' => $sys, 'term_id' => "p{$P1y['id']}"]);
$req = srTestRequest($pdo, (string)($r['request_number'] ?? ''));
ok(!empty($r['success']) && $req && (float)$req['total'] === 300000.0 && $req['lines'][0]['token'] === "p{$P1y['id']}", 'request for Pro yearly → a request whose line carries the plan token' . $msg($r));
$s = srTestQuote(fn($f, $p) => call($f, $p), $pdo, (string)($r['request_number'] ?? ''), $cust);
$q = $s['quotation'];
$ql = $q ? $pdo->query("SELECT * FROM quotation_items WHERE order_id = " . (int)$q['sales_order_id'])->fetch(PDO::FETCH_ASSOC) : [];
ok(!empty($s['response']['success']) && (float)($q['grand_total'] ?? 0) === 300000.0 && (int)($ql['plan_price_id'] ?? 0) === (int)$P1y['id'] && ($ql['plan_key'] ?? '') === $pro['plan_key'],
   'staff save it → a quotation whose line carries the plan' . $msg($s['response']));
$r = company($tid, ['action' => 'request_quote', 'product_id' => $sys, 'term_id' => "p{$H1y['id']}"]);
ok(empty($r['success']), 'a private plan token is refused');
$r = company($tid, ['action' => 'request_quote', 'product_id' => $sys, 'term_id' => "a{$Pshop['id']}-{$P1y['id']}"]);
ok(empty($r['success']), 'an add-on token alone is refused');
$r = company($tid, ['action' => 'request_quote', 'product_id' => $sys, 'term_id' => (int)$hTerm['1 Month']]);
ok(empty($r['success']), "a local term id on the plan service is refused");
$r = company($tid, ['action' => 'request_quote', 'product_id' => $hosting, 'term_id' => "p{$P1y['id']}"]);
ok(empty($r['success']), 'a plan token on another service is refused');

// ── B10. Ordinary documents are unchanged ──────────────────────────────────
section('B10. regression: ordinary documents');
$payBefore = (int)$c->query("SELECT COUNT(*) FROM tenant_payments")->fetchColumn();
$ledger = function (int $inv, string $type) use ($pdo) {
    return $pdo->query("SELECT jei.type, SUM(jei.amount) FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                         WHERE je.entity_type = " . $pdo->quote($type) . " AND je.entity_id = $inv AND je.status = 'posted' GROUP BY jei.type")->fetchAll(PDO::FETCH_KEY_PAIR);
};
$noPlanCols = fn(array $lines) => array_reduce($lines, fn($ok, $l) => $ok && $l['plan_key'] === null && $l['plan_price_id'] === null && $l['plan_addon_id'] === null, true);

// 1. Stock goods
$g1 = makeInvoice($other, [['product_id' => (int)$goods['product_id'], 'product_name' => $goods['product_name'], 'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 1000]]);
$rev = $ledger((int)$g1['invoice_id'], 'invoice'); $cogs = $ledger((int)$g1['invoice_id'], 'invoice_cogs');
ok(($g1['status'] ?? '') === 'approved' && abs((float)($rev['debit'] ?? 0) - 2000) < 0.01 && abs((float)($rev['credit'] ?? 0) - 2000) < 0.01, 'goods invoice: revenue posted, balanced (2,000)');
ok(abs((float)($cogs['debit'] ?? 0) - 2 * (float)$goods['cost_price']) < 0.01 && abs((float)($cogs['credit'] ?? 0) - 2 * (float)$goods['cost_price']) < 0.01, 'goods invoice: COGS = 2 × cost, balanced');
ok($noPlanCols($invLines((int)$g1['invoice_id'])) && $invLines((int)$g1['invoice_id'])[0]['price_term_id'] === null, 'goods line: no term, no plan');
ok(!empty(pay($g1)['success']) && $pdo->query("SELECT status FROM invoices WHERE invoice_id = {$g1['invoice_id']}")->fetchColumn() === 'paid', 'goods invoice paid as before');

// 2. Ordinary service
$g2 = makeInvoice($other, [['product_id' => $plain, 'product_name' => 'SPB-TEST Training', 'unit_price' => 30000]]);
$rev = $ledger((int)$g2['invoice_id'], 'invoice');
ok(($g2['status'] ?? '') === 'approved' && abs((float)($rev['debit'] ?? 0) - 30000) < 0.01 && !$ledger((int)$g2['invoice_id'], 'invoice_cogs'), 'service invoice: revenue posted, no COGS');
ok(!empty(pay($g2)['success']) && $noPlanCols($invLines((int)$g2['invoice_id'])), 'service invoice paid; no plan columns');

// 3. Quotation → sales order → invoice, goods + term-priced service
$qtItems = [['product_id' => (int)$goods['product_id'], 'product_name' => $goods['product_name'], 'sku' => '', 'quantity' => 3, 'unit' => 'pcs', 'unit_price' => 1000, 'tax_rate' => 0, 'discount_percent' => 0, 'description' => '', 'price_term_id' => null],
            ['product_id' => $hosting, 'product_name' => 'SPB-TEST Hosting', 'sku' => '', 'quantity' => 1, 'unit' => 'job', 'unit_price' => 85000, 'tax_rate' => 0, 'discount_percent' => 0, 'description' => 'Cloud', 'price_term_id' => $hTerm['1 Year']]];
$r = call('api/account/save_quotation.php', ['quotation_id' => '', 'customer_id' => $other, 'order_date' => date('Y-m-d'), 'warehouse_id' => $wid, 'currency' => 'TZS', 'reference' => 'SPB-TEST', 'items' => json_encode($qtItems)]);
$qt = (int)($r['quotation_id'] ?? $r['id'] ?? 0) ?: (int)$pdo->query("SELECT MAX(sales_order_id) FROM quotations WHERE reference = 'SPB-TEST'")->fetchColumn();
ok(!empty($r['success']) && $qt > 0, 'mixed quotation saved' . $msg($r));
$pdo->prepare("UPDATE quotations SET status = 'approved' WHERE sales_order_id = ?")->execute([$qt]);
$r = call('api/account/convert_quote_to_order.php', ['quotation_id' => $qt, 'id' => $qt]);
$so = (int)$pdo->query("SELECT converted_to_so_id FROM quotations WHERE sales_order_id = $qt")->fetchColumn();
$soLines = $pdo->query("SELECT * FROM sales_order_items WHERE order_id = $so ORDER BY order_item_id")->fetchAll(PDO::FETCH_ASSOC);
ok($so > 0 && count($soLines) === 2 && $noPlanCols($soLines), 'converted to a sales order, two lines, no plan columns' . $msg($r));
$items = [];
foreach ($soLines as $sl) $items[] = ['product_id' => (int)$sl['product_id'], 'product_name' => $sl['product_name'], 'order_item_id' => (int)$sl['order_item_id'],
    'quantity' => (float)$sl['quantity'], 'unit' => $sl['unit'] ?? 'pcs', 'unit_price' => (float)$sl['unit_price'], 'tax_rate' => 0, 'discount_percent' => 0, 'description' => (string)$sl['description']];
$r = call('api/account/save_invoice.php', ['customer_id' => $other, 'order_id' => $so, 'invoice_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'),
    'currency' => 'TZS', 'warehouse_id' => $wid, 'notes' => 'SPB-TEST', 'items' => $items]);
$g3 = (int)($r['invoice_id'] ?? 0);
call('api/account/review_invoice.php', ['invoice_id' => $g3]);
call('api/account/approve_invoice.php', ['invoice_id' => $g3]);
$l3 = $invLines($g3);
$hl = array_values(array_filter($l3, fn($l) => (int)$l['product_id'] === $hosting))[0] ?? [];
$gl = array_values(array_filter($l3, fn($l) => (int)$l['product_id'] === (int)$goods['product_id']))[0] ?? [];
ok(!empty($r['success']) && (int)($hl['price_term_id'] ?? 0) === $hTerm['1 Year'] && $gl && $gl['price_term_id'] === null && $noPlanCols($l3), 'invoice from the SO: the service keeps its 1 Year term, goods none, no plan columns' . $msg($r));
$rev = $ledger($g3, 'invoice'); $cogs = $ledger($g3, 'invoice_cogs');
ok(abs((float)($rev['debit'] ?? 0) - 88000) < 0.01 && abs((float)($cogs['debit'] ?? 0) - 3 * (float)$goods['cost_price']) < 0.01, 'revenue 88,000 and COGS 3 × cost posted on approval');

// 4. Term-priced ordinary service
$g4 = makeInvoice($other, [['product_id' => $hosting, 'product_name' => 'SPB-TEST Hosting', 'quantity' => 2, 'unit_price' => 10000, 'price_term_id' => $hTerm['1 Month']]]);
$l4 = $invLines((int)$g4['invoice_id']);
ok((float)$g4['grand_total'] === 20000.0 && (int)$l4[0]['price_term_id'] === $hTerm['1 Month'] && $l4[0]['price_term_label'] === '1 Month' && $noPlanCols($l4), 'term-priced service: 2 × 1 Month = 20,000, term stamped, no plan');
ok(!empty(pay($g4)['success']), 'paid as before');
ok((int)$c->query("SELECT COUNT(*) FROM tenant_payments")->fetchColumn() === $payBefore, 'none of the ordinary invoices touched any company subscription');
ok(assertLedgerBalanced($pdo)['ok'] ?? false, 'the ledger still balances');
