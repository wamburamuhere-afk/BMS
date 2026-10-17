<?php
/**
 * Customer Visits → real customer + tenant link (customer_subscription_plan.md Phase 3)
 *   php tests/test_customer_visit_link_cli.php
 *
 * A: pure helpers (CI).  B: the real endpoints on the local DB + control DB, in and
 * out of the platform-root (vendor) context. Creates 'VLINK-TEST' visits/customers and
 * 'vlinktest*' tenants; removes them (incl. the customers' ledger accounts). Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$hasConfig = is_file("$root/includes/config.php");
if ($hasConfig) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
require_once "$root/core/vendor_link.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    // exit() below stops any later shutdown function, so test data is removed here first.
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

section('A. subdomain normalising');
putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');
ok(vlNormalizeSubdomain('DukaLaJuma') === 'dukalajuma', 'lower-cased');
ok(vlNormalizeSubdomain('https://dukalajuma.bms.bjptechnologies.co.tz/login.php') === 'dukalajuma', 'pasted URL → label');
ok(vlNormalizeSubdomain(' duka-la-juma ') === 'duka-la-juma', 'trimmed, hyphens kept');
ok(vlNormalizeSubdomain('evil.other') === '' && vlNormalizeSubdomain('-bad') === '' && vlNormalizeSubdomain('') === '', 'invalid labels refused');
foreach (['api/field_reports/make_customer.php', 'api/platform/customer_platform_link.php'] as $f) {
    ok(strpos(file_get_contents("$root/$f"), 'isPlatformVendorContext()') !== false, "$f exists only on the platform root");
}
ok(strpos(file_get_contents("$root/actions/superadmin_tenant_vendor_customer.php"), 'assertSuperadminHost()') !== false, 'superadmin unlink action is host-guarded');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B ──────────────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/field_reports_schema.php";
fieldReportsEnsureSchema($pdo);
$c = getControlPdo();
if (!vlLinkReady()) { ok(false, 'control DB upgraded (run scripts/setup_control_db.php)'); exit; }

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$cleanup = function () use ($pdo, $c) {
    $ids = $pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_name LIKE 'VLINK-TEST%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ids as $r) {
        if ($r['ledger_account_id']) $pdo->prepare("DELETE FROM accounts WHERE account_id = ?")->execute([$r['ledger_account_id']]);
        $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$r['customer_id']]);
    }
    $pdo->exec("DELETE FROM field_visits WHERE client_name LIKE 'VLINK-TEST%'");
    $tids = $c->query("SELECT id FROM tenants WHERE subdomain LIKE 'vlinktest%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($tids) $c->exec("DELETE FROM tenant_admin_log WHERE tenant_id IN (" . implode(',', array_map('intval', $tids)) . ")");
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'vlinktest%'");
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

$mkTenant = function (string $sub, array $f = []) use ($c): int {
    $cols = array_merge(['company_name' => "VLINK-TEST Co $sub", 'subdomain' => $sub, 'db_name' => 'none', 'db_username' => 'none',
        'db_password_encrypted' => 'x', 'owner_email' => "$sub@example.com", 'status' => 'trial'], $f);
    $c->prepare("INSERT INTO tenants (`" . implode('`,`', array_keys($cols)) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")
      ->execute(array_values($cols));
    return (int)$c->lastInsertId();
};
$t1 = $mkTenant('vlinktest1', ['owner_phone' => '0712000111', 'owner_first_name' => 'Juma', 'owner_last_name' => 'Ali']);
$t2 = $mkTenant('vlinktest2');
$mkVisit = function (string $name) use ($pdo, $uid): int {
    $pdo->prepare("INSERT INTO field_visits (user_id, visit_date, location, client_name, client_phone, phone_normalized, business_type, created_by)
                   VALUES (?, CURDATE(), 'Moshi', ?, '0712000111', '255712000111', 'retail', ?)")->execute([$uid, $name, $uid]);
    return (int)$pdo->lastInsertId();
};
$v1 = $mkVisit('VLINK-TEST Juma');
$v2 = $mkVisit('VLINK-TEST Other');
$visit = function (int $id) use ($pdo) { $s = $pdo->prepare("SELECT * FROM field_visits WHERE visit_id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };
$tenant = function (int $id) use ($c) { $s = $c->prepare("SELECT * FROM tenants WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'vl_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
/** Call an endpoint as the admin; $vendor = on the platform root. */
function call(string $file, array $data, string $method = 'POST', bool $vendor = true): array {
    global $root, $uid;
    $env = $vendor ? "putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');" : "putenv('BMS_VENDOR_CONTEXT');";
    $out = runPhp("
        \$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . "; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        $env
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_POST = \$_REQUEST = \$_GET = " . var_export($data, true) . ";
        \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start();
        include '$root/$file';
        \$o = ob_get_clean();
        \$p = strpos(\$o, '{');
        echo \$p === false ? \$o : substr(\$o, \$p);
    ");
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => $out];
}

section('B1. joined + subdomain');
$r = call('api/field_reports/toggle_joined.php', ['visit_id' => $v1, 'joined' => '1', 'subdomain' => 'no-such-company']);
ok(empty($r['success']) && stripos($r['message'] ?? '', 'web address') !== false, 'an unknown subdomain is refused');
ok((int)$visit($v1)['joined'] === 0, '…and the visit is left unchanged');
$r = call('api/field_reports/toggle_joined.php', ['visit_id' => $v1, 'joined' => '1', 'subdomain' => 'https://VLINKTEST1.bms.bjptechnologies.co.tz/']);
$v = $visit($v1);
ok(!empty($r['success']) && (int)$v['joined'] === 1 && (int)$v['tenant_id'] === $t1 && $v['tenant_subdomain'] === 'vlinktest1', 'joined + linked to the company (pasted URL accepted)');
$r = call('api/field_reports/toggle_joined.php', ['visit_id' => $v2, 'joined' => '1', 'subdomain' => 'vlinktest2'], 'POST', false);
ok(!empty($r['success']) && (int)$visit($v2)['joined'] === 1 && $visit($v2)['tenant_id'] === null, 'off the platform root, "joined" works as before and ignores any subdomain');

section('B2. make real customer');
$r = call('api/field_reports/make_customer.php', ['visit_id' => $v1], 'GET');
ok(!empty($r['success']) && ($r['tenant']['subdomain'] ?? '') === 'vlinktest1', 'preview shows the company');
ok(($r['prefill']['customer_name'] ?? '') === 'VLINK-TEST Co vlinktest1' && ($r['prefill']['contact_person'] ?? '') === 'Juma Ali'
   && ($r['prefill']['phone'] ?? '') === '0712000111', 'form prefilled from the company registration');
ok(($r['linked_customer'] ?? null) === null, 'no customer linked yet');
$r = call('api/field_reports/make_customer.php', ['visit_id' => $v1], 'GET', false);
ok(empty($r['success']), 'not available off the platform root');
$r = call('api/field_reports/make_customer.php', ['visit_id' => $v2], 'GET');
ok(empty($r['success']) && stripos($r['message'] ?? '', 'web address') !== false, 'a joined visit with no company asks for the web address first');

$r = call('api/field_reports/make_customer.php', ['visit_id' => $v1, 'mode' => 'create', 'customer_name' => 'VLINK-TEST Duka la Juma',
    'contact_person' => 'Juma Ali', 'phone' => '0712000111', 'email' => 'vlinktest1@example.com', 'tin_number' => '123-456-789']);
$cid = (int)($r['customer_id'] ?? 0);
ok(!empty($r['success']) && $cid > 0, 'customer created' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
$cust = $pdo->query("SELECT * FROM customers WHERE customer_id = $cid")->fetch(PDO::FETCH_ASSOC) ?: [];
ok(preg_match('/CUST-\d{4}$/', (string)($cust['customer_code'] ?? '')) === 1 && $cust['status'] === 'active' && $cust['country'] === 'Tanzania'
   && $cust['currency'] === 'TZS', 'created like Customers → Add: company code + defaults (' . ($cust['customer_code'] ?? '') . ')');
ok(!empty($cust['ledger_account_id']), 'the customer got its ledger sub-account');
ok((int)$visit($v1)['customer_id'] === $cid, 'visit points to the customer');
$t = $tenant($t1);
ok((int)$t['vendor_customer_id'] === $cid && strpos((string)$t['vendor_customer_label'], 'VLINK-TEST Duka la Juma') !== false, 'company linked to the customer in the control DB, with a label for superadmin');
ok((int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE tenant_id = $t1 AND action = 'vendor_customer_linked'")->fetchColumn() === 1, 'link written to the tenant audit log');

$r = call('api/field_reports/make_customer.php', ['visit_id' => $v1, 'mode' => 'create', 'customer_name' => 'VLINK-TEST Duplicate']);
ok(empty($r['success']) && (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE customer_name = 'VLINK-TEST Duplicate'")->fetchColumn() === 0,
   'a company already linked cannot get a second customer');
$r = call('api/field_reports/make_customer.php', ['visit_id' => $v1], 'GET');
ok((int)($r['linked_customer']['customer_id'] ?? 0) === $cid, 'preview now offers the linked customer');
ok(count(array_filter($r['matches'] ?? [], fn($m) => (int)$m['customer_id'] === $cid)) === 1, 'and finds it by phone as a match');

section('B3. un-joining keeps a real customer\'s link');
call('api/field_reports/toggle_joined.php', ['visit_id' => $v1, 'joined' => '0']);
$v = $visit($v1);
ok((int)$v['joined'] === 0 && (int)$v['tenant_id'] === $t1 && (int)$v['customer_id'] === $cid, 'tenant + customer kept');
call('api/field_reports/toggle_joined.php', ['visit_id' => $v2, 'joined' => '0']);
ok($visit($v2)['tenant_id'] === null, 'a visit that never became a customer just un-joins');

section('B4. customer page: link / unlink companies');
$r = call('api/platform/customer_platform_link.php', ['customer_id' => $cid], 'GET');
ok(!empty($r['success']) && count($r['tenants'] ?? []) === 1 && $r['tenants'][0]['subdomain'] === 'vlinktest1', 'lists the linked company');
$r = call('api/platform/customer_platform_link.php', ['customer_id' => $cid, 'action' => 'link', 'subdomain' => 'vlinktest2']);
ok(!empty($r['success']) && count($r['tenants'] ?? []) === 2, 'a self-registered company linked from the customer page');
$other = $pdo->query("SELECT customer_id FROM customers WHERE status = 'active' AND customer_id <> $cid ORDER BY customer_id LIMIT 1")->fetchColumn();
$r = call('api/platform/customer_platform_link.php', ['customer_id' => $other, 'action' => 'link', 'subdomain' => 'vlinktest1']);
ok(empty($r['success']) && (int)$tenant($t1)['vendor_customer_id'] === $cid, 'a company linked to one customer cannot be taken by another');
$r = call('api/platform/customer_platform_link.php', ['customer_id' => $cid, 'action' => 'unlink', 'tenant_id' => $t2]);
ok(!empty($r['success']) && $tenant($t2)['vendor_customer_id'] === null && $tenant($t2)['vendor_customer_label'] === null, 'unlink clears id + label');
$r = call('api/platform/customer_platform_link.php', ['customer_id' => $cid], 'GET', false);
ok(empty($r['success']), 'not available off the platform root');

section('B5. superadmin view');
require_once "$root/core/tenant_admin.php";
$g = getTenant($t1);
ok((int)($g['vendor_customer_id'] ?? 0) === $cid && strpos((string)($g['vendor_customer_label'] ?? ''), 'Duka la Juma') !== false, 'getTenant() carries the paying customer for the panel');
$r = vlSetTenantCustomer($t1, null, 'test');
ok($r['ok'] && $tenant($t1)['vendor_customer_id'] === null, 'superadmin unlink path clears the link');

section('B6. pages');
function render(string $file, array $get, bool $vendor): string {
    global $root, $uid;
    $env = $vendor ? "putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1');" : "putenv('BMS_VENDOR_CONTEXT');";
    return runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        $env
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_GET = " . var_export($get, true) . ";
        ob_start(); include '$root/$file'; echo ob_get_clean();
    ");
}
$h = render('app/bms/field_reports/field_visits.php', [], true);
ok(strpos($h, 'id="mkCustModal"') !== false && strpos($h, 'IS_VENDOR = true') !== false, 'Customer Visits on the platform root: Make real customer is available');
ok(strpos($h, 'Fatal error') === false && strpos($h, 'Warning:') === false, 'renders cleanly');
$h = render('app/bms/field_reports/field_visits.php', [], false);
ok(strpos($h, 'id="mkCustModal"') === false && strpos($h, 'IS_VENDOR = false') !== false, 'everywhere else: unchanged');
$h = render('app/bms/customer/customer_details.php', ['id' => (string)$cid], true);
ok(strpos($h, 'id="platformLinkCard"') !== false && strpos($h, 'Fatal error') === false, 'Customer Details on the platform root shows the Platform company card');
$h = render('app/bms/customer/customer_details.php', ['id' => (string)$cid], false);
ok(strpos($h, 'id="platformLinkCard"') === false, 'and not elsewhere');
