<?php
/**
 * Subscription requests from companies (2026-10-18)
 *   php tests/test_subscription_requests_cli.php
 *
 * A company's "request" from its Subscription page is stored as a REQUEST at BJP (no
 * auto-made quotation with an empty "Created by"). BJP staff see it under Quotations →
 * "Requests from systems", open the prefilled quotation form, and save it themselves.
 *
 * A: wiring (CI). B: the real gateway, API, pages and save_quotation.php on the local DB +
 * control DB. Data is 'SRQ-TEST' / 'srqtest*' and removed. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
const SRQ_KEY = 'b1c2d3e4f5a60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
putenv('TENANT_CRED_KEY=' . SRQ_KEY);
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

section('A. wiring');
$src = fn(string $f) => (string)file_get_contents("$root/$f");
$gw = $src('core/platform_gateway.php');
ok(strpos($gw, 'srCreate(') !== false && strpos($gw, 'INSERT INTO quotations') === false, 'the gateway stores a request; it never writes a quotation');
ok(strpos($gw, "'subscription.request_received'") !== false, 'the gateway tells BJP staff (notification event)');
$api = $src('api/platform/subscription_requests.php');
ok(strpos($api, 'isPlatformVendorContext()') !== false && strpos($api, "canView('quotations')") !== false && strpos($api, "canCreate('quotations')") !== false
   && strpos($api, 'csrf_check()') !== false, 'requests API: platform root, quotations view / create, CSRF on decline');
$sq = $src('api/account/save_quotation.php');
ok(strpos($sq, 'srMarkQuoted(') !== false && strpos($sq, 'vlSetTenantCustomer(') !== false && strpos($sq, "canEdit('customers')") !== false,
   'save_quotation marks the request quoted and links the company only with customer-edit rights');
foreach (['migrations/tenant/2026_10_18_subscription_requests.php', 'migrations/2026_10_18_subscription_requests_legacy_db.php'] as $m) {
    ok(strpos($src($m), 'srEnsureTable(') !== false, "$m creates the table");
}
$qf = $src('app/bms/sales/quotations/quotation_form.php');
ok(strpos($qf, 'SR_ASK_LINK') !== false && strpos($qf, 'Swal.fire') !== false && strpos($qf, 'alert(') === false, 'the link question uses SweetAlert2, no native alert');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B ──────────────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/platform_gateway.php";
require_once "$root/core/subscription_requests.php";
require_once "$root/tests/helpers/subscription_request_quote.php";
sptEnsureSchema($pdo);
ob_start(); sptEnsureLineColumns($pdo); ob_end_clean();
srEnsureTable($pdo);
$c = getControlPdo();
if (!vlLinkReady()) { ok(false, 'control DB upgraded'); exit; }
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();

$cleanup = function () use ($pdo, $c) {
    $cust = $pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_name LIKE 'SRQ-TEST%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cust as $r) {
        $q = $pdo->query("SELECT sales_order_id FROM quotations WHERE customer_id = " . (int)$r['customer_id'])->fetchAll(PDO::FETCH_COLUMN);
        if ($q) {
            $in = implode(',', array_map('intval', $q));
            $pdo->exec("DELETE FROM quotation_items WHERE order_id IN ($in)");
            try { $pdo->exec("DELETE FROM workflow_signatures WHERE entity_type = 'quotation' AND entity_id IN ($in)"); } catch (Throwable $e) {}
            $pdo->exec("DELETE FROM quotations WHERE sales_order_id IN ($in)");
        }
        try { $pdo->exec("DELETE FROM notifications WHERE customer_id = " . (int)$r['customer_id']); } catch (Throwable $e) {}
        if ($r['ledger_account_id']) $pdo->exec("DELETE FROM accounts WHERE account_id = " . (int)$r['ledger_account_id']);
        $pdo->exec("DELETE FROM customers WHERE customer_id = " . (int)$r['customer_id']);
    }
    try { $pdo->exec("DELETE FROM notifications WHERE event_key = 'subscription.request_received' AND title LIKE '%SRQ-TEST%'"); } catch (Throwable $e) {}
    $p = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'SRQ-TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($p) {
        $in = implode(',', array_map('intval', $p));
        foreach (['service_price_terms', 'vendor_subscription_services', 'products'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN ($in)");
    }
    try { $pdo->exec("DELETE FROM subscription_requests WHERE subdomain LIKE 'srqtest%'"); } catch (Throwable $e) {}
    $t = $c->query("SELECT id FROM tenants WHERE subdomain LIKE 'srqtest%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($t) {
        $in = implode(',', array_map('intval', $t));
        $c->exec("DELETE FROM tenant_payments WHERE tenant_id IN ($in)");
        $c->exec("DELETE FROM tenant_admin_log WHERE tenant_id IN ($in)");
    }
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'srqtest%'");
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'srq_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
const ENV_KEY = "putenv('TENANT_CRED_KEY=" . SRQ_KEY . "');";
const ENV_VENDOR = "putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');";
function lastJson(string $out): ?array {
    $j = json_decode($out, true);
    if (is_array($j)) return $j;
    $p = strrpos($out, "\n{");
    return $p !== false ? json_decode(substr($out, $p + 1), true) : null;
}
/** The company's own server talking to the platform root (signed). */
function gateway(int $tenantId, array $body): array {
    global $root;
    $json = json_encode($body); $ts = time(); $sig = (string)plSignature($tenantId, $ts, $json);
    $out = runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . ENV_KEY . ENV_VENDOR . "
        require_once '$root/core/platform_gateway.php'; global \$pdo;
        [\$code, \$o] = pgHandle(\$pdo, " . var_export($json, true) . ", ['X-Platform-Tenant' => '$tenantId', 'X-Platform-Ts' => '$ts', 'X-Platform-Sig' => " . var_export($sig, true) . "]);
        echo json_encode(['code' => \$code] + \$o);");
    return lastJson($out) ?? ['code' => 0, 'message' => $out];
}
/** An endpoint as BJP staff ($session=false → signed out; $vendor=false → a company's install). */
function api(string $file, array $post, string $method = 'POST', bool $vendor = true, bool $session = true): array {
    global $root, $uid;
    $sess = $session ? "\$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';" : '';
    $out = runPhp("\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . "; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php'; " . ENV_KEY . ($vendor ? ENV_VENDOR : "putenv('BMS_VENDOR_CONTEXT');") . "
        $sess
        \$_POST = \$_REQUEST = \$_GET = " . var_export($post, true) . "; \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);");
    return lastJson($out) ?? ['success' => false, 'message' => $out];
}
/** A page as BJP staff on the platform root; returns the HTML (empty when it redirects and exits). */
function page(string $file, array $get): string {
    global $root, $uid;
    return runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . ENV_KEY . ENV_VENDOR . "
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_GET = \$_REQUEST = " . var_export($get, true) . ";
        ob_start(); include '$root/$file'; echo ob_get_clean();");
}
$apiAdmin = fn(string $f, array $p) => api($f, $p);

// Setup: a subscription service with two periods, a customer, a linked + an unlinked company.
$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$r = api('api/create_nip_product.php', $svcBase + ['product_name' => 'SRQ-TEST BMS Simple POS', 'is_subscription' => '1',
    'price_terms' => json_encode([
        ['term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 10000, 'is_default' => 1],
        ['term_label' => '1 Year', 'duration_value' => 1, 'duration_unit' => 'year', 'price' => 85000],
    ])]);
$sub = (int)($r['product_id'] ?? 0);
$terms = [];
foreach (sptForProduct($pdo, $sub) as $t) $terms[$t['term_label']] = (int)$t['term_id'];
$pdo->beginTransaction();
$cust = vlCreateCustomer($pdo, ['customer_name' => 'SRQ-TEST Duka'], $uid);
$cust2 = vlCreateCustomer($pdo, ['customer_name' => 'SRQ-TEST Mpya'], $uid);
$pdo->commit();
$mk = function (string $s, string $phone) use ($c): int {
    $c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, owner_phone, status, trial_ends_at)
                 VALUES (?, ?, 'none', 'none', 'x', ?, ?, 'trial', DATE_ADD(NOW(), INTERVAL 5 DAY))")->execute(["SRQ-TEST $s", $s, "$s@example.com", $phone]);
    return (int)$c->lastInsertId();
};
$t1 = $mk('srqtest1', '0754111222'); $t2 = $mk('srqtest2', '0754333444');
vlSetTenantCustomer($t1, $cust, 'test', 'SRQ-TEST Duka');
ok($sub > 0 && count($terms) === 2 && $cust > 0 && $cust2 > 0, 'setup: subscription service, two customers, a linked + an unlinked company');

// ── B1 ─────────────────────────────────────────────────────────────────────
section('B1. a company asks → a stored request + a staff notification, no quotation');
$qBefore = (int)$pdo->query("SELECT COUNT(*) FROM quotations")->fetchColumn();
$r1 = gateway($t1, ['action' => 'request_quote', 'product_id' => $sub, 'term_id' => $terms['1 Year']]);
$req1 = srTestRequest($pdo, (string)($r1['request_number'] ?? ''));
ok(!empty($r1['success']) && $req1 && $req1['status'] === 'pending' && (float)$req1['total'] === 85000.0, 'linked company: request ' . ($r1['request_number'] ?? '?') . ' pending for 85,000');
ok((int)$pdo->query("SELECT COUNT(*) FROM quotations")->fetchColumn() === $qBefore, 'no quotation is created (nobody would be its creator)');
ok($req1 && $req1['phone'] === '0754111222' && $req1['subdomain'] === 'srqtest1' && $req1['customer_id'] === $cust, 'the request keeps the company, web address, phone and linked customer');
$n = $pdo->prepare("SELECT action_url FROM notifications WHERE event_key = 'subscription.request_received' AND message LIKE ? ORDER BY notification_id DESC LIMIT 1");
$n->execute(['%' . ($r1['request_number'] ?? 'x') . '%']);
$url = (string)$n->fetchColumn();
ok($url !== '' && strpos($url, 'requests=1') !== false, 'staff get an in-app notification that opens Quotations → Requests');
$r2 = gateway($t2, ['action' => 'request_quote', 'product_id' => $sub, 'term_id' => $terms['1 Month']]);
$req2 = srTestRequest($pdo, (string)($r2['request_number'] ?? ''));
ok(!empty($r2['success']) && $req2 && $req2['customer_id'] === null, 'an unlinked company can ask too');

// ── B2 ─────────────────────────────────────────────────────────────────────
section('B2. requests API: guards, list');
ok(empty(api('api/platform/subscription_requests.php', [], 'GET', true, false)['success']), 'signed out → refused');
ok(stripos((string)(api('api/platform/subscription_requests.php', [], 'GET', false)['message'] ?? ''), 'not available') !== false, "a company's own install → not available");
$l = api('api/platform/subscription_requests.php', [], 'GET');
$byNo = array_column($l['requests'] ?? [], null, 'number');
$a = $byNo[$r1['request_number'] ?? ''] ?? []; $b = $byNo[$r2['request_number'] ?? ''] ?? [];
ok(!empty($l['success']) && !empty($l['can_create']) && $a && $b, 'BJP staff see both pending requests');
ok(($a['phone'] ?? '') === '0754111222' && ($a['subdomain'] ?? '') === 'srqtest1' && strpos((string)($a['customer'] ?? ''), 'SRQ-TEST Duka') !== false
   && $b && array_key_exists('customer', $b) && $b['customer'] === null && ($a['lines'][0]['term'] ?? '') === '1 Year', 'each shows company, web address, phone, what was asked, and the linked customer (or none)');

// ── B3 ─────────────────────────────────────────────────────────────────────
section('B3. Quotations page shows the requests sign');
$html = page('app/bms/sales/quotations/quotations.php', ['requests' => '1']);
ok(strpos($html, 'id="srToggle"') !== false && strpos($html, 'Requests from systems') !== false && preg_match('/id="srCount">\s*(\d+)/', $html, $m) && (int)$m[1] >= 2,
   'header button "Requests from systems" with the pending count (' . ($m[1] ?? '?') . ')');
ok(strpos($html, 'id="srPanel"') !== false && preg_match('/class="collapse show[^"]*" [^>]*id="srPanel"|id="srPanel"/', $html) && strpos($html, 'collapse show') !== false
   && strpos($html, 'id="srTable"') !== false, '?requests=1 opens the panel with its table');
ok(strpos($html, 'Fatal error') === false && strpos($html, 'Warning:') === false, 'page renders cleanly');

// ── B4 ─────────────────────────────────────────────────────────────────────
section('B4. "Create quotation" opens the prefilled form');
$f = page('app/bms/sales/quotations/quotation_form.php', ['request' => (string)$req1['id']]);
ok(strpos($f, 'From request') !== false && strpos($f, (string)$req1['request_number']) !== false && strpos($f, 'srqtest1') !== false && strpos($f, '0754111222') !== false,
   'a "From request" strip with the company, web address and phone');
ok(preg_match('/name="subscription_request_id" value="' . (int)$req1['id'] . '"/', $f) && strpos($f, 'Request ' . $req1['request_number'] . ' (srqtest1)') !== false,
   'the request id travels with the form; the reference names the request');
ok(strpos($f, '"price_term_id":"' . $terms['1 Year'] . '"') !== false || strpos($f, '"price_term_id":' . $terms['1 Year']) !== false, 'the line comes with its price term');
ok(strpos($f, 'const SR_ASK_LINK = false') !== false, 'linked company: no link question');
$f2 = page('app/bms/sales/quotations/quotation_form.php', ['request' => (string)$req2['id']]);
ok(strpos($f2, 'const SR_ASK_LINK = true') !== false, 'unlinked company: staff will be asked to link it');
ok(strpos($f, 'Fatal error') === false && strpos($f, 'Warning:') === false, 'form renders cleanly');

// ── B5 ─────────────────────────────────────────────────────────────────────
section('B5. staff save it → their quotation; the request is quoted (real save)');
$s = srTestQuote($apiAdmin, $pdo, (string)$req1['request_number'], $cust);
$q = $s['quotation'];
$qi = $q ? $pdo->query("SELECT * FROM quotation_items WHERE order_id = " . (int)$q['sales_order_id'])->fetchAll(PDO::FETCH_ASSOC) : [];
ok(!empty($s['response']['success']) && strpos((string)$s['response']['message'], 'is now quoted') !== false, 'saved: ' . ($s['response']['message'] ?? ''));
ok(($q['status'] ?? '') === 'pending' && (int)$q['created_by'] === $uid && (int)$q['customer_id'] === $cust && (float)$q['grand_total'] === 85000.0,
   'a pending quotation for 85,000 — "Created by" is the staff member');
ok(count($qi) === 1 && (int)$qi[0]['price_term_id'] === $terms['1 Year'], 'its line keeps the 1 Year term');
ok(($s['request']['status'] ?? '') === 'quoted' && (int)$s['request']['quotation_id'] === (int)$q['sales_order_id'] && (int)$s['request']['handled_by'] === $uid,
   'the request is quoted, pointing at the quotation and who did it');
ok(empty(srTestQuote($apiAdmin, $pdo, (string)$req1['request_number'], $cust)['response']['success']), 'saving the same request twice is refused');
$f = page('app/bms/sales/quotations/quotation_form.php', ['request' => (string)$req1['id']]);
ok(trim($f) === '' && strpos($src('app/bms/sales/quotations/quotation_form.php'), "getUrl('quotations') . \"?requests=1&error=") !== false, 'opening a handled request renders no form; it goes back to the list');
$l = api('api/platform/subscription_requests.php', [], 'GET');
ok(!in_array($req1['request_number'], array_column($l['requests'] ?? [], 'number'), true), 'a quoted request leaves the pending list');

// ── B6 ─────────────────────────────────────────────────────────────────────
section('B6. the company waits until BJP approves');
$d = gateway($t1, ['action' => 'documents']);
ok(count($d['quotations'] ?? []) === 0 && count($d['requests'] ?? []) === 1 && ($d['requests'][0]['status'] ?? '') === 'waiting', 'pending quotation → still "waiting for BJP"');
$qid = (int)$q['sales_order_id'];
$rv = api('api/account/review_quotation.php', ['quotation_id' => $qid, 'id' => $qid]);
$d = gateway($t1, ['action' => 'documents']);
ok(!empty($rv['success']) && count($d['quotations'] ?? []) === 0 && count($d['requests'] ?? []) === 1, 'reviewed → still waiting');
$ap = api('api/account/approve_quotation.php', ['quotation_id' => $qid, 'id' => $qid]);
$d = gateway($t1, ['action' => 'documents']);
ok(!empty($ap['success']) && count($d['quotations'] ?? []) === 1 && ($d['quotations'][0]['status'] ?? '') === 'approved' && count($d['requests'] ?? []) === 0,
   'approved (real endpoints) → the company sees its quotation, no longer waiting');

// ── B7 ─────────────────────────────────────────────────────────────────────
section('B7. an unlinked company: staff choose the customer and link it');
$s2 = srTestQuote($apiAdmin, $pdo, (string)$req2['request_number'], $cust2, true);
$linked = (int)$c->query("SELECT vendor_customer_id FROM tenants WHERE id = $t2")->fetchColumn();
ok(!empty($s2['response']['success']) && (int)($s2['quotation']['customer_id'] ?? 0) === $cust2 && $linked === $cust2
   && strpos((string)$s2['response']['message'], 'now linked') !== false, '"Yes, link" → quotation for the chosen customer and srqtest2 linked to it');
ok((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'vendor_customer_linked' AND new_values LIKE '%" . $req2['request_number'] . "%'")->fetchColumn() >= 1
   || (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE details LIKE '%from request " . $req2['request_number'] . "%'")->fetchColumn() >= 1, 'the link is recorded');

// ── B8 ─────────────────────────────────────────────────────────────────────
section('B8. decline: the company sees the reason');
$r3 = gateway($t1, ['action' => 'request_quote', 'product_id' => $sub, 'term_id' => $terms['1 Month']]);
$req3 = srTestRequest($pdo, (string)($r3['request_number'] ?? ''));
$dn = api('api/platform/subscription_requests.php', ['action' => 'decline', 'id' => (string)$req3['id'], 'reason' => '']);
ok(empty($dn['success']) && stripos((string)$dn['message'], 'reason') !== false, 'a decline without a reason is refused');
$dn = api('api/platform/subscription_requests.php', ['action' => 'decline', 'id' => (string)$req3['id'], 'reason' => 'Please call us first']);
ok(!empty($dn['success']) && srGet($pdo, $req3['id'])['status'] === 'declined', 'declined: ' . ($dn['message'] ?? ''));
ok(empty(api('api/platform/subscription_requests.php', ['action' => 'decline', 'id' => (string)$req3['id'], 'reason' => 'again'])['success']), 'declining twice is refused');
$d = gateway($t1, ['action' => 'documents']);
$dr = array_values(array_filter($d['requests'] ?? [], fn($x) => $x['number'] === $req3['request_number']))[0] ?? [];
ok(($dr['status'] ?? '') === 'declined' && ($dr['reason'] ?? '') === 'Please call us first', 'the company sees "declined" with the reason');
ok(empty(srTestQuote($apiAdmin, $pdo, (string)$req3['request_number'], $cust)['response']['success']), 'a declined request cannot become a quotation');
