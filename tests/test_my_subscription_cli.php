<?php
/**
 * "My Subscription" + the signed platform gateway (customer_subscription_plan.md Phase 5)
 *   php tests/test_my_subscription_cli.php
 *
 * A: signing + document tokens (CI, with a throwaway key).
 * B: the gateway on the platform root, the customer-copy page and the company-side API,
 *    on the local DB + control DB. Data is 'MS-TEST' / 'mstest*' and removed. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
const MS_KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
putenv('TENANT_CRED_KEY=' . MS_KEY);
$hasConfig = is_file("$root/includes/config.php");
if ($hasConfig) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
require_once "$root/core/platform_link.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

section('A. signing');
$ts = time(); $body = '{"action":"catalog"}';
$sig = plSignature(7, $ts, $body);
ok(is_string($sig) && strlen($sig) === 64, 'a request can be signed');
ok(plVerify(7, $ts, $body, $sig), 'genuine request verifies');
ok(!plVerify(8, $ts, $body, $sig), 'another tenant id does not verify (cannot ask for someone else)');
ok(!plVerify(7, $ts, '{"action":"documents"}', $sig), 'a changed body does not verify');
ok(!plVerify(7, $ts - 300, $body, plSignature(7, $ts - 300, $body)), 'a stale request (5 min) is refused');
ok(!plVerify(7, $ts, $body, ''), 'no signature → refused');
$tok = plDocToken('invoice', 42, 9);
ok(plDocTokenRead((string)$tok) === ['type' => 'invoice', 'id' => 42, 'customer_id' => 9], 'document token round-trips');
ok(plDocTokenRead(str_replace('.', 'x.', (string)$tok)) === null, 'a tampered token is refused');
ok(plDocTokenRead((string)plDocToken('invoice', 42, 9, -10)) === null, 'an expired token is refused');
ok(plDocTokenRead('') === null && plDocTokenRead('a.b') === null, 'garbage refused');
foreach (['api/my_subscription.php' => 'bmsCurrentTenantId()', 'api/platform/tenant_gateway.php' => 'pgHandle(', 'api/platform/customer_document.php' => 'plDocTokenRead('] as $f => $needle) {
    ok(strpos(file_get_contents("$root/$f"), $needle) !== false, "$f wired");
}
ok(strpos(file_get_contents("$root/api/my_subscription.php"), "if (!isAdmin())") !== false, 'company side is admin-only');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B ──────────────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/platform_gateway.php";
require_once "$root/core/vendor_bridge.php";
sptEnsureSchema($pdo);
ob_start(); sptEnsureLineColumns($pdo); ob_end_clean();
$c = getControlPdo();
if (!vlLinkReady()) { ok(false, 'control DB upgraded'); exit; }
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();

$cleanup = function () use ($pdo, $c) {
    $cust = $pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_name LIKE 'MS-TEST%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cust as $r) {
        $q = $pdo->query("SELECT sales_order_id FROM quotations WHERE customer_id = " . (int)$r['customer_id'])->fetchAll(PDO::FETCH_COLUMN);
        if ($q) {
            $in = implode(',', array_map('intval', $q));
            $pdo->exec("DELETE FROM quotation_items WHERE order_id IN ($in)");
            $pdo->exec("DELETE FROM quotations WHERE sales_order_id IN ($in)");
            try { $pdo->exec("DELETE FROM notifications WHERE customer_id = " . (int)$r['customer_id']); } catch (Throwable $e) {}
        }
        if ($r['ledger_account_id']) $pdo->exec("DELETE FROM accounts WHERE account_id = " . (int)$r['ledger_account_id']);
        $pdo->exec("DELETE FROM customers WHERE customer_id = " . (int)$r['customer_id']);
    }
    $p = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'MS-TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($p) {
        $in = implode(',', array_map('intval', $p));
        $pdo->exec("DELETE FROM service_price_terms WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM vendor_subscription_services WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
    }
    $t = $c->query("SELECT id FROM tenants WHERE subdomain LIKE 'mstest%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($t) {
        $in = implode(',', array_map('intval', $t));
        $c->exec("DELETE FROM tenant_payments WHERE tenant_id IN ($in)");
        $c->exec("DELETE FROM tenant_admin_log WHERE tenant_id IN ($in)");
    }
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'mstest%'");
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'ms_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
const ENV_KEY = "putenv('TENANT_CRED_KEY=" . MS_KEY . "');";
const ENV_VENDOR = "putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');";
function lastJson(string $out): ?array {
    $j = json_decode($out, true);
    if (is_array($j)) return $j;
    $p = strrpos($out, "\n{");
    return $p !== false ? json_decode(substr($out, $p + 1), true) : null;
}
/** Send a (signed) request to the gateway on the platform root. */
function gateway(int $tenantId, array $body, bool $vendor = true, ?int $ts = null, ?string $sig = null): array {
    global $root;
    $json = json_encode($body);
    $ts = $ts ?? time();
    $sig = $sig ?? (string)plSignature($tenantId, $ts, $json);
    $out = runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . ENV_KEY . ($vendor ? ENV_VENDOR : "putenv('BMS_VENDOR_CONTEXT');") . "
        require_once '$root/core/platform_gateway.php'; global \$pdo;
        [\$code, \$o] = pgHandle(\$pdo, " . var_export($json, true) . ", ['X-Platform-Tenant' => '$tenantId', 'X-Platform-Ts' => '$ts', 'X-Platform-Sig' => " . var_export($sig, true) . "]);
        echo json_encode(['code' => \$code] + \$o);");
    return lastJson($out) ?? ['code' => 0, 'message' => $out];
}
function api(string $file, array $post, string $method = 'POST', bool $vendor = true, string $extra = ''): array {
    global $root, $uid;
    $out = runPhp("\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . "; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php'; " . ENV_KEY . ($vendor ? ENV_VENDOR : '') . "
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['user_lang'] = 'en';
        $extra
        \$_POST = \$_REQUEST = \$_GET = " . var_export($post, true) . "; \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);");
    return lastJson($out) ?? ['success' => false, 'message' => $out];
}

// Setup: a subscription service (month terms + one weekly term that must not be offered), a customer, two companies.
$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$r = api('api/create_nip_product.php', $svcBase + ['product_name' => 'MS-TEST BMS Simple POS', 'is_subscription' => '1',
    'price_terms' => json_encode([
        ['term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 10000, 'is_default' => 1],
        ['term_label' => '1 Year', 'duration_value' => 1, 'duration_unit' => 'year', 'price' => 85000],
    ])]);
$sub = (int)($r['product_id'] ?? 0);
$terms = [];
foreach (sptForProduct($pdo, $sub) as $t) $terms[$t['term_label']] = (int)$t['term_id'];
$pdo->beginTransaction();
$cust = vlCreateCustomer($pdo, ['customer_name' => 'MS-TEST Duka'], $uid);
$pdo->commit();
$mk = function (string $s) use ($c): int {
    $c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, status, trial_ends_at)
                 VALUES (?, ?, 'none', 'none', 'x', ?, 'trial', DATE_ADD(NOW(), INTERVAL 5 DAY))")->execute(["MS-TEST $s", $s, "$s@example.com"]);
    return (int)$c->lastInsertId();
};
$t1 = $mk('mstest1'); $t2 = $mk('mstest2');
vlSetTenantCustomer($t1, $cust, 'test', 'MS-TEST Duka');
ok($sub > 0 && count($terms) === 2, 'setup: subscription service, customer, linked + unlinked company');

section('B1. gateway guards');
ok(gateway($t1, ['action' => 'catalog'], true, null, 'deadbeef')['code'] === 401, 'bad signature → 401');
ok(gateway($t1, ['action' => 'catalog'], true, time() - 600)['code'] === 401, 'stale request → 401');
ok(gateway(999999999, ['action' => 'catalog'])['code'] === 404, 'unknown company → 404');
ok(gateway($t1, ['action' => 'catalog'], false)['code'] === 404, 'not served anywhere but the platform root');

section('B2. catalog');
$r = gateway($t1, ['action' => 'catalog']);
$svc = array_values(array_filter($r['services'] ?? [], fn($s) => $s['product_id'] === $sub))[0] ?? null;
ok($r['code'] === 200 && $svc && count($svc['terms']) === 2 && $svc['terms'][1]['months'] === 12 && (float)$svc['terms'][0]['price'] === 10000.0,
   'subscription periods with months and catalogue prices');

section('B3. request a period → a pending quotation BJP reviews (service_plans_plan.md D7)');
$r = gateway($t1, ['action' => 'request_quote', 'product_id' => $sub, 'term_id' => $terms['1 Year']]);
ok(!empty($r['success']) && (float)$r['total'] === 85000.0, 'quotation ' . ($r['quotation_number'] ?? '?') . ' for 85,000');
$q = $pdo->query("SELECT * FROM quotations WHERE order_number = " . $pdo->quote((string)($r['quotation_number'] ?? ''))) ->fetch(PDO::FETCH_ASSOC) ?: [];
$qi = $q ? $pdo->query("SELECT * FROM quotation_items WHERE order_id = " . (int)$q['sales_order_id'])->fetch(PDO::FETCH_ASSOC) : [];
ok(($q['status'] ?? '') === 'pending' && $q['approved_at'] === null && (int)$q['customer_id'] === $cust && $q['reference'] === "SELF-SERVICE T$t1", 'pending (not approved), for the linked customer, marked self-service');
ok(($r['status'] ?? '') === 'waiting' && !isset($r['view_token']), 'the company is told it waits for BJP — no link to an unapproved document');
ok((int)($qi['price_term_id'] ?? 0) === $terms['1 Year'] && (int)$qi['term_duration_value'] === 1 && $qi['term_duration_unit'] === 'year'
   && strpos((string)$qi['description'], 'Term: 1 Year') !== false, 'line carries the term (so the paid invoice will extend by 12 months)');
$first = $q['order_number'] ?? '';
$r2 = gateway($t1, ['action' => 'request_quote', 'product_id' => $sub, 'term_id' => $terms['1 Month']]);
$old = $pdo->query("SELECT status FROM quotations WHERE order_number = " . $pdo->quote($first))->fetchColumn();
ok(!empty($r2['success']) && $old === 'cancelled', 'a second request replaces the open one (one open request per company)');
ok(empty(gateway($t1, ['action' => 'request_quote', 'product_id' => $sub, 'term_id' => 999999])['success']), 'an unknown period is refused');
ok(empty(gateway($t2, ['action' => 'request_quote', 'product_id' => $sub, 'term_id' => $terms['1 Month']])['success']), 'an unlinked company is told to contact BJP');

section('B4. documents + customer copy');
$r = gateway($t1, ['action' => 'documents']);
ok(!empty($r['success']) && count($r['quotations']) === 0 && count($r['requests']) === 1 && $r['requests'][0]['number'] === $r2['quotation_number']
   && !isset($r['requests'][0]['view_token']), 'before approval: no quotation shown, the open request listed as waiting (the replaced one nowhere)');
$qid = (int)$pdo->query("SELECT sales_order_id FROM quotations WHERE order_number = " . $pdo->quote((string)$r2['quotation_number']))->fetchColumn();
$view = function (string $token, bool $vendor = true) use ($root) {
    return runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . ENV_KEY . ($vendor ? ENV_VENDOR : '') . "
        \$_GET = ['t' => " . var_export($token, true) . "]; ob_start(); include '$root/api/platform/customer_document.php'; echo ob_get_clean();");
};
ok(strpos($view((string)plDocToken('quotation', $qid, $cust)), (string)$r2['quotation_number']) === false, 'even a valid link cannot open a pending quotation');
$pdo->exec("UPDATE quotations SET status = 'approved', approved_at = NOW() WHERE sales_order_id = $qid");   // BJP approves it
$r = gateway($t1, ['action' => 'documents']);
ok(!empty($r['success']) && count($r['quotations']) === 1 && !empty($r['quotations'][0]['view_token']) && $r['quotations'][0]['status'] === 'approved'
   && count($r['requests']) === 0, "once approved: this company's quotation, with a view link; no longer waiting");
ok(gateway($t2, ['action' => 'documents'])['linked'] === false, 'an unlinked company sees nothing from BJP');
$html = $view($r['quotations'][0]['view_token']);
ok(strpos($html, (string)$r2['quotation_number']) !== false && strpos($html, 'QUOTATION') !== false && strpos($html, 'Term: 1 Month') !== false, 'customer copy shows the quotation with its term');
ok(strpos($view((string)plDocToken('quotation', $qid, $cust + 1)), (string)$r2['quotation_number']) === false, "a token for another customer cannot open it");
ok(strpos($view((string)plDocToken('quotation', $qid, $cust, -5)), 'expired') !== false, 'an expired link says so');
ok(strpos($view($r['quotations'][0]['view_token'], false), (string)$r2['quotation_number']) === false, 'customer copies are served only by the platform root');

section('B5. company side');
$asTenant = fn(int $tid) => "\$GLOBALS['__bms_tenant'] = ['id' => $tid];";
$r = api('api/my_subscription.php', ['action' => 'status'], 'GET', false, $asTenant($t1));
ok(!empty($r['success']) && $r['tenant']['subdomain'] === 'mstest1' && $r['days_left'] !== null, 'status: own access period from the control DB');
$r = api('api/my_subscription.php', ['action' => 'status'], 'GET', false, '');
ok(empty($r['success']), 'not available without a tenant (root / single-tenant)');
$r = api('api/my_subscription.php', ['action' => 'status'], 'GET', false, $asTenant($t1) . " \$_SESSION['role_id'] = 2; \$_SESSION['is_admin'] = false;");
ok(empty($r['success']), 'non-admin staff cannot use it');
$html = runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . $asTenant($t1) . "
    \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_lang'] = 'en';
    ob_start(); include '$root/app/constant/settings/billing.php'; echo ob_get_clean();");
ok(strpos($html, 'id="catalog"') !== false && strpos($html, 'id="tblInvoices"') !== false && strpos($html, 'Fatal error') === false, 'Billing page renders for a company admin');
ok(strpos(file_get_contents("$root/app/constant/settings/my_subscription.php"), "getUrl('billing')") !== false, 'the old My Subscription link redirects to Billing');
