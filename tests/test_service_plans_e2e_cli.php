<?php
/**
 * Service plans end to end (service_plans_plan.md Phase 7)
 *   php tests/test_service_plans_e2e_cli.php
 *
 * One company's whole life on a plan, through the real paths only:
 *   superadmin makes a Shop Mode plan → a visitor registers on it (yearly) → BJP's
 *   install creates exactly one customer for the company (link queue + kick) → the
 *   company asks for a quote from Billing → BJP reviews + approves it → BJP invoices
 *   it (save → review → approve) → records full payment → the company is active on
 *   that plan for 12 months after its trial, and Billing shows the invoice as paid.
 * A: wiring (CI).  B: local + control DB; data is 'SPE-TEST' / 'spetest*', removed after.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
const SPE_KEY = 'e1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f95';
putenv('TENANT_CRED_KEY=' . SPE_KEY);
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
ok(strpos($src('core/tenant_registration.php'), 'registrationPlanChoice(') !== false && strpos($src('core/tenant_registration.php'), 'vlQueueTenantLink(') !== false,
   'registration picks the plan and queues the BJP customer');
ok(strpos($src('core/platform_gateway.php'), "'request_quote'") !== false && strpos($src('core/platform_gateway.php'), "'documents'") !== false, 'the gateway takes quote requests and lists documents');
ok(strpos($src('core/vendor_bridge.php'), 'planApplyPaid(') !== false, 'a paid invoice applies the plan');
ok(is_file("$root/app/constant/settings/billing.php") && strpos($src('roots.php'), "'billing' =>") !== false, 'the Billing page exists and is routed');
ok(strpos($src('core/tenant_lifecycle_policy.php'), "\$status === 'trial' || empty(\$t['subscription_ends_at'])") !== false,
   'a first payment counts the trial days left, even for a company that registered as active');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B. Setup ───────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_registration.php";
require_once "$root/core/tenant_admin.php";
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
if (!vlLinkReady() || !vlQueueReady() || !vbAlertsReady() || !planCatalogReady() || !controlColumnExists('registration_jobs', 'plan_key')) {
    ok(false, 'control DB upgraded (run scripts/setup_control_db.php)'); exit;
}
$uid  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid  = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$bank = (int)(cashBankAccounts($pdo)[0]['account_id'] ?? 0);
if (!$uid || !$wid || !$bank) { echo "SKIP B: needs an admin, a warehouse and a cash/bank account\n"; exit; }

$sfx  = bin2hex(random_bytes(3));
$tag  = 'spe' . $sfx;
$PROD = $tag . '_shop';
$SUB  = 'spetest' . $sfx;
$COMPANY = "SPE-TEST Duka $sfx";
$IP   = '198.51.100.240';
$planIds = [];
$controlDb = $c->query("SELECT DATABASE()")->fetchColumn();
$tidTables = $c->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $c->quote($controlDb)
                     . " AND COLUMN_NAME = 'tenant_id'")->fetchAll(PDO::FETCH_COLUMN);
$cleanup = function () use ($pdo, $c, &$planIds, &$PROD, $tidTables, $IP) {
    $in = fn($a) => $a ? implode(',', array_map('intval', $a)) : '0';
    $cust = $in($pdo->query("SELECT customer_id FROM customers WHERE customer_name LIKE 'SPE-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    $inv = $in($pdo->query("SELECT invoice_id FROM invoices WHERE notes = 'SPE-TEST' OR customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
    $qt  = $in($pdo->query("SELECT sales_order_id FROM quotations WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
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
    $pdo->exec("DELETE FROM quotation_items WHERE order_id IN ($qt)");
    $pdo->exec("DELETE FROM quotations WHERE sales_order_id IN ($qt)");
    try { $pdo->exec("DELETE FROM workflow_signatures WHERE (entity_type='invoice' AND entity_id IN ($inv)) OR (entity_type='quotation' AND entity_id IN ($qt))"); } catch (Throwable $e) {}
    try { $pdo->exec("DELETE FROM notifications WHERE customer_id IN ($cust)"); } catch (Throwable $e) {}
    $p = $in($pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'SPE-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (['service_price_terms', 'vendor_subscription_services', 'products'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN ($p)");
    foreach ($pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['ledger_account_id']) $pdo->exec("DELETE FROM accounts WHERE account_id = " . (int)$r['ledger_account_id']);
        $pdo->exec("DELETE FROM customers WHERE customer_id = " . (int)$r['customer_id']);
    }
    $admin = getProvisioningPdo();
    foreach ($c->query("SELECT id FROM tenants WHERE subdomain LIKE 'spetest%'")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        $tid = (int)$tid;
        foreach ($tidTables as $tb) { try { $c->exec("DELETE FROM `$tb` WHERE tenant_id = $tid"); } catch (Throwable $e) {} }
        $c->exec("DELETE FROM tenants WHERE id = $tid");
        try { $admin->exec("DROP DATABASE IF EXISTS `bms_t$tid`"); } catch (Throwable $e) { echo "cleanup: bms_t$tid not dropped: {$e->getMessage()}\n"; }
        try { $admin->exec("DROP USER IF EXISTS 'bms_u$tid'@'%'"); } catch (Throwable $e) {}
    }
    $c->exec("DELETE FROM registration_attempts WHERE subdomain LIKE 'spetest%' OR ip_address = " . $c->quote($IP));
    $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'spetest%'");
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
    $tmp = tempnam(sys_get_temp_dir(), 'spe_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function lastJson(string $out): ?array { $j = json_decode($out, true); if (is_array($j)) return $j; $p = strrpos($out, "\n{"); return $p !== false ? json_decode(substr($out, $p + 1), true) : null; }
const VENDOR_ENV = "putenv('TENANT_CRED_KEY=" . SPE_KEY . "'); putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');";
/** An endpoint on BJP's install; $session = signed in as BJP staff (admin). */
function call(string $file, array $post, bool $session = true): array {
    global $root, $uid;
    $sess = $session ? "\$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';" : '';
    $j = lastJson(runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php'; " . VENDOR_ENV . "
        $sess
        \$_POST = \$_REQUEST = " . var_export($post, true) . ";
        \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; \$o = ob_get_clean();
        \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);"));
    return is_array($j) ? $j : ['success' => false, 'message' => 'no JSON'];
}
/** The company admin using Billing (api/my_subscription.php); BJP's gateway answers in-process. */
function companyApi(int $tid, array $req, string $method = 'GET'): array {
    global $root, $uid;
    $j = lastJson(runPhp("\$_SERVER['REQUEST_METHOD'] = '$method'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php'; putenv('TENANT_CRED_KEY=" . SPE_KEY . "'); putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');
        \$GLOBALS['__bms_tenant'] = ['id' => $tid];
        \$GLOBALS['__pl_transport'] = function (\$body, \$h) {
            require_once '$root/core/platform_gateway.php'; global \$pdo;
            \$keep = \$GLOBALS['__bms_tenant']; unset(\$GLOBALS['__bms_tenant']); putenv('BMS_VENDOR_CONTEXT=1');
            [\$code, \$o] = pgHandle(\$pdo, \$body, \$h);
            putenv('BMS_VENDOR_CONTEXT'); \$GLOBALS['__bms_tenant'] = \$keep;
            return \$o;
        };
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['user_lang'] = 'en';
        \$_POST = \$_REQUEST = \$_GET = " . var_export($req, true) . "; \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/api/my_subscription.php'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);"));
    return is_array($j) ? $j : ['success' => false, 'message' => 'no JSON'];
}
$msg = fn(array $r) => empty($r['success']) ? ' — ' . ($r['message'] ?? json_encode($r)) : '';
$tenant = function (int $id) use ($c) { $s = $c->prepare("SELECT * FROM tenants WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: []; };

// ── B1. Superadmin: the Shop Mode product + plan ───────────────────────────
section('B1. superadmin makes a Shop Mode plan; BJP maps its service to it');
$c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?,?,1,0,1)")->execute([$PROD, "Shop Mode $tag"]);
$r = createPlan(['name' => "Duka $tag", 'description' => 'One shop', 'product_key' => $PROD, 'max_users' => 3, 'max_storage_mb' => 500, 'max_shops' => 2,
    'feature_keys' => ['warehouses', 'pos'], 'tier_rank' => 10, 'is_public' => true, 'is_popular' => true, 'items' => [['label' => 'Point of sale', 'value' => '']], 'addons' => [],
    'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 20000, 'is_default' => 0],
                 ['duration_value' => 1, 'duration_unit' => 'year', 'price_tzs' => 200000, 'is_default' => 1]]]);
if (!empty($r['id'])) $planIds[] = (int)$r['id'];
if (empty($r['ok'])) { ok(false, 'plan fixture: ' . ($r['error'] ?? '')); exit; }
$plan = getPlan((int)$r['id']);
$Y = null; foreach (planPrices((int)$plan['id']) as $p) if ($p['term_key'] === '1y') $Y = $p;
$offer = array_values(array_filter(listPublicOffers(null, true), fn($o) => $o['product_key'] === $PROD))[0] ?? null;
ok($Y && $offer && in_array($plan['plan_key'], array_column($offer['plans'], 'plan_key'), true), 'the plan is on offer at registration (Shop Mode product, public)');

$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SPE-TEST Shop Mode', 'subscription_flag_sent' => '1', 'is_subscription' => '1',
    'platform_product_sent' => '1', 'platform_product_key' => $PROD, 'price_terms' => '']);
$sys = (int)($r['product_id'] ?? 0);
ok($sys && vssPlatformProduct($pdo, $sys) === $PROD, 'BJP\'s "Shop Mode" service sells the platform product' . $msg($r));

// ── B2. A visitor registers on the plan ────────────────────────────────────
section('B2. a visitor registers on the plan, yearly');
do { $phone = '0789' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); } while (vlCustomerMatches($pdo, $phone, ''));
$mail = "owner@$SUB.test";
$putTenancy = function (bool $on) { if ($on) { putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.local'); } else { putenv('TENANT_MODE'); putenv('TENANT_BASE_DOMAIN'); } };
$putTenancy(true);
try {
    $reg = registerTenant(['company_name' => $COMPANY, 'subdomain' => $SUB, 'owner_email' => $mail, 'owner_phone' => $phone,
        'owner_first_name' => 'Neema', 'owner_last_name' => 'Mushi', 'owner_password' => 'OwnerPass1',
        'plan_key' => $plan['plan_key'], 'plan_term' => '1y', 'skip_welcome_email' => true], $IP);
} finally { $putTenancy(false); }
$tid = (int)($reg['tenant_id'] ?? 0);
ok(!empty($reg['ok']) && $tid > 0, 'registration succeeded' . (empty($reg['ok']) ? ' — ' . ($reg['error'] ?? '') : ''));
if (!$tid) exit;
$t = $tenant($tid);
ok($t['plan'] === $plan['plan_key'] && (int)$t['max_users'] === 3 && (int)$t['max_shops'] === 2, 'the company starts on the chosen plan: 3 users, 2 shops');
$f = []; foreach (tenantFeatureMatrix($tid) as $row) $f[$row['key']] = (bool)$row['effective'];
ok(!empty($f['pos']) && !empty($f['warehouses']) && empty($f['hr']), "the plan's modules only (pos + warehouses on, hr off)");
ok(!empty($t['trial_ends_at']) && $t['subscription_ends_at'] === null, "on trial until {$t['trial_ends_at']}, nothing paid yet");
$trialEnd = substr((string)$t['trial_ends_at'], 0, 10);
ok((int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE tenant_id = $tid AND action = 'registration_plan'")->fetchColumn() === 1, 'audit: signed up on the plan');

// ── B3. One BJP customer for the company ───────────────────────────────────
section('B3. BJP\'s install creates exactly one customer for the company');
$r = call('api/platform/vendor_link_kick.php', [], false);
$t = $tenant($tid);
$cust = (int)($t['vendor_customer_id'] ?? 0);
$cu = $cust ? $pdo->query("SELECT * FROM customers WHERE customer_id = $cust")->fetch(PDO::FETCH_ASSOC) : [];
ok(!empty($r['ok']) && $cust > 0 && ($cu['customer_name'] ?? '') === $COMPANY, 'the register.php beacon kick links a new customer named after the company');
ok(($cu['phone'] ?? '') === $phone && ($cu['email'] ?? '') === $mail && !empty($cu['ledger_account_id']), 'with the owner\'s phone, contact email and a ledger sub-account');
call('api/platform/vendor_link_kick.php', [], false);
ok((int)$pdo->query("SELECT COUNT(*) FROM customers WHERE customer_name = " . $pdo->quote($COMPANY))->fetchColumn() === 1, 'kicking again: still one customer (D3)');

// ── B4. The company asks for a quote from Billing ──────────────────────────
section('B4. Billing: the company asks for its yearly plan; BJP approves the quotation');
$s = companyApi($tid, ['action' => 'status']);
ok(!empty($s['success']) && ($s['plan']['plan_key'] ?? '') === $plan['plan_key'], 'Billing shows the plan the company is on' . $msg($s));
$r = companyApi($tid, ['action' => 'request', 'product_id' => $sys, 'term_id' => "p{$Y['id']}"], 'POST');
$q = $pdo->query("SELECT * FROM quotations WHERE customer_id = $cust ORDER BY sales_order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$qid = (int)($q['sales_order_id'] ?? 0);
ok(!empty($r['success']) && (float)($r['total'] ?? 0) === 200000.0 && ($q['status'] ?? '') === 'pending', 'request priced at 200,000, a pending quotation for BJP' . $msg($r));
$d = companyApi($tid, ['action' => 'documents']);
ok(count($d['quotations'] ?? []) === 0 && count($d['requests'] ?? []) === 1, 'not shown to the company until approved (D7)');
call('api/account/review_quotation.php', ['quotation_id' => $qid, 'id' => $qid]);
$ap = call('api/account/approve_quotation.php', ['quotation_id' => $qid, 'id' => $qid]);
$d = companyApi($tid, ['action' => 'documents']);
ok(!empty($ap['success']) && ($d['quotations'][0]['status'] ?? '') === 'approved' && count($d['requests'] ?? []) === 0, 'approved → the company sees the quotation' . $msg($ap));

// ── B5. BJP invoices the quotation ─────────────────────────────────────────
section('B5. BJP invoices it: save → review → approve');
$items = [];
foreach ($pdo->query("SELECT * FROM quotation_items WHERE order_id = $qid ORDER BY order_item_id")->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $items[] = ['product_id' => (int)$l['product_id'], 'product_name' => 'SPE-TEST Shop Mode', 'quantity' => (float)$l['quantity'], 'unit' => 'job',
        'unit_price' => (float)$l['unit_price'], 'tax_rate' => 0, 'discount_percent' => 0, 'description' => '', 'price_term_id' => "p{$l['plan_price_id']}"];
}
$before = (int)$pdo->query("SELECT COALESCE(MAX(invoice_id),0) FROM invoices")->fetchColumn();
$r = call('api/account/save_invoice.php', ['customer_id' => $cust, 'invoice_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+7 days')),
    'currency' => 'TZS', 'warehouse_id' => $wid, 'notes' => 'SPE-TEST', 'items' => $items]);
$inv = (int)$pdo->query("SELECT COALESCE(MAX(invoice_id),0) FROM invoices WHERE customer_id = $cust AND invoice_id > $before")->fetchColumn();
ok(count($items) === 1 && $inv > 0, 'invoice saved from the quotation\'s line' . $msg($r));
$il = $pdo->query("SELECT * FROM invoice_items WHERE invoice_id = $inv")->fetch(PDO::FETCH_ASSOC) ?: [];
ok(($il['plan_key'] ?? '') === $plan['plan_key'] && (int)$il['plan_price_id'] === (int)$Y['id'] && (int)$il['term_duration_value'] === 1 && $il['term_duration_unit'] === 'year',
   'the line carries the plan and its 1-year term');
call('api/account/review_invoice.php', ['invoice_id' => $inv]);
$ap = call('api/account/approve_invoice.php', ['invoice_id' => $inv]);
$I = $pdo->query("SELECT * FROM invoices WHERE invoice_id = $inv")->fetch(PDO::FETCH_ASSOC);
ok(!empty($ap['success']) && $I['status'] === 'approved' && (float)$I['grand_total'] === 200000.0, "invoice {$I['invoice_number']} approved for 200,000" . $msg($ap));
$posted = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type = 'invoice' AND entity_id = $inv AND status = 'posted'")->fetchColumn();
ok($posted === 1, 'approval posted the invoice to the ledger');
$d = companyApi($tid, ['action' => 'documents']);
ok(($d['invoices'][0]['number'] ?? '') === $I['invoice_number'] && $d['invoices'][0]['status'] === 'unpaid', 'Billing shows the invoice as unpaid');
ok($tenant($tid)['subscription_ends_at'] === null, 'nothing applied before it is paid (D7)');

// ── B6. Payment → the plan for a year ──────────────────────────────────────
section('B6. full payment → active on the plan for 12 months after the trial');
$r = call('api/account/record_payment.php', ['invoice_id' => $inv, 'amount' => 200000, 'payment_date' => date('Y-m-d'), 'payment_method' => 'bank_transfer',
    'status' => 'completed', 'received_into_account_id' => $bank, 'reference_number' => 'SPE-TEST']);
$t = $tenant($tid);
$want = date('Y-m-d', strtotime("$trialEnd +12 months"));
ok(!empty($r['success']) && $t['status'] === 'active' && $t['subscription_ends_at'] === $want, "paid → active until $want (got " . ($t['subscription_ends_at'] ?? 'null') . ')' . $msg($r));
ok($t['plan'] === $plan['plan_key'] && (int)$t['max_shops'] === 2 && (int)$t['max_users'] === 3 && $t['scheduled_plan_key'] === null, 'same plan renewed: limits kept, nothing scheduled (D5)');
ok((int)$c->query("SELECT duration_months FROM tenant_payments WHERE tenant_id = $tid AND source_ref = " . $c->quote($I['invoice_number']))->fetchColumn() === 12, 'one subscription payment of 12 months recorded');
ok($pdo->query("SELECT status FROM invoices WHERE invoice_id = $inv")->fetchColumn() === 'paid', 'the invoice is paid');
$d = companyApi($tid, ['action' => 'documents']);
ok(($d['invoices'][0]['status'] ?? '') === 'paid', 'Billing shows the invoice as paid');
$s = companyApi($tid, ['action' => 'status']);
ok(!empty($s['success']) && ($s['plan']['plan_key'] ?? '') === $plan['plan_key'], 'Billing still shows the plan');
$pays = implode(',', array_map('intval', $pdo->query("SELECT DISTINCT p.payment_id FROM payments p LEFT JOIN payment_allocations pa ON pa.payment_id = p.payment_id
    WHERE p.invoice_id = $inv OR (pa.target_type = 'invoice' AND pa.target_id = $inv)")->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
$g = $pdo->query("SELECT COUNT(DISTINCT je.entry_id) n, SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE 0 END) dr, SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END) cr
    FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
    WHERE (je.entity_type = 'invoice' AND je.entity_id = $inv) OR (je.entity_type = 'payment' AND je.entity_id IN ($pays))")->fetch(PDO::FETCH_ASSOC);
ok((int)$g['n'] === 2 && abs((float)$g['dr'] - (float)$g['cr']) < 0.01 && (float)$g['dr'] === 400000.0, 'ledger: invoice + payment posted, Σ Dr = Σ Cr (2 × 200,000)');

section('B7. the bridge running again changes nothing');
runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . VENDOR_ENV . " require_once '$root/core/vendor_bridge.php'; global \$pdo; vendorOnInvoicesSettled(\$pdo, [$inv]); vendorBridgeRetry(\$pdo);");
ok($tenant($tid)['subscription_ends_at'] === $want && (int)$c->query("SELECT COUNT(*) FROM tenant_payments WHERE tenant_id = $tid")->fetchColumn() === 1, 'same end date, one payment');
