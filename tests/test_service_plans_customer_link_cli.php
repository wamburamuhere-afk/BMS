<?php
/**
 * One BJP customer per platform company (service_plans_plan.md Phase 4, D3/D4)
 *   php tests/test_service_plans_customer_link_cli.php
 *
 * A: static wiring (CI).  B: the real paths on the local DB + control DB —
 * registration queue → kick / background jobs / cron → exactly one customer,
 * Customer Visits never makes a second one, parallel calls make one, a phone/email
 * look-alike waits for a person (Customers page review card + endpoint).
 * Creates 'VLQ-TEST' customers and 'vlqtest*' tenants; removes them (incl. ledger
 * accounts and every control-DB row keyed by their tenant_id). Exit 0 = pass.
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
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

section('A. wiring');
$src = fn(string $f) => (string)file_get_contents("$root/$f");
ok(strpos($src('core/tenant_registration.php'), 'vlQueueTenantLink($tenantId)') !== false, 'finishSelfRegistration() queues the company (web + mobile registration share it)');
ok(strpos($src('register.php'), "sendBeacon('/api/platform/vendor_link_kick.php')") !== false, 'register.php nudges the queue when the account is ready');
ok(strpos($src('api/run_background_jobs.php'), 'vlDrainLinkQueue') !== false, 'background jobs drain the queue');
ok(strpos($src('api/cron/vendor_bridge_retry.php'), 'vlDrainLinkQueue') !== false, 'cron drains the queue');
ok(strpos($src('cron/process_registration_jobs.php'), 'vlDrainLinkQueue') !== false, 'async registration worker drains after finishing');
ok(strpos($src('api/field_reports/make_customer.php'), 'vlEnsureCustomerForTenant') !== false
   && strpos($src('api/field_reports/make_customer.php'), 'vlCreateCustomer(') === false, 'Customer Visits creates only through vlEnsureCustomerForTenant');
ok(strpos($src('app/superadmin/dashboard.php'), "a.kind <> 'possible_match'") !== false, 'superadmin dashboard leaves look-alikes to BJP staff');
foreach (['api/platform/vendor_link_kick.php', 'api/platform/platform_match_review.php'] as $f) {
    ok(strpos($src($f), 'isPlatformVendorContext()') !== false && strpos($src($f), 'scope-audit: skip') !== false, "$f: platform root only + scope-audit marker");
}
ok(vlTenantContactEmail(['owner_email' => '0712000111', 'owner_contact_email' => 'a@b.co']) === 'a@b.co'
   && vlTenantContactEmail(['owner_email' => 'x@y.co']) === 'x@y.co'
   && vlTenantContactEmail(['owner_email' => '0712000111']) === '', 'contact email: real address only (owner_email often holds the login phone)');
ok(vlTenantAlertRef(42) === 'tenant:42', 'tenant alert ref');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B ──────────────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/tenant_registration.php";
$c = getControlPdo();
if (!vlLinkReady() || !vlQueueReady()) { ok(false, 'control DB upgraded (run scripts/setup_control_db.php)'); exit; }
$hasContact = controlColumnExists('tenants', 'owner_contact_email');

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$controlDb = $c->query("SELECT DATABASE()")->fetchColumn();
$tidTables = $c->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $c->quote($controlDb)
                     . " AND COLUMN_NAME = 'tenant_id'")->fetchAll(PDO::FETCH_COLUMN);
$cleanup = function () use ($pdo, $c, $tidTables) {
    $ids = $pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_name LIKE 'VLQ-TEST%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ids as $r) {
        if ($r['ledger_account_id']) $pdo->prepare("DELETE FROM accounts WHERE account_id = ?")->execute([$r['ledger_account_id']]);
        $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$r['customer_id']]);
    }
    $pdo->exec("DELETE FROM field_visits WHERE client_name LIKE 'VLQ-TEST%'");
    $tids = $c->query("SELECT id FROM tenants WHERE subdomain LIKE 'vlqtest%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($tids) {
        $in = implode(',', array_map('intval', $tids));
        foreach ($tidTables as $tb) { try { $c->exec("DELETE FROM `$tb` WHERE tenant_id IN ($in)"); } catch (Throwable $e) {} }
    }
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'vlqtest%'");
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

// Phone numbers no real local customer has (matching is on the last 9 digits).
$freshPhone = function () use ($pdo): string {
    do { $p = '0788' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); } while (vlCustomerMatches($pdo, $p, ''));
    return $p;
};
$mkTenant = function (string $sub, array $f = []) use ($c): int {
    $cols = array_merge(['company_name' => "VLQ-TEST Co $sub", 'subdomain' => $sub, 'db_name' => 'none', 'db_username' => 'none',
        'db_password_encrypted' => 'x', 'owner_email' => "$sub@example.com", 'status' => 'trial'], $f);
    $c->prepare("INSERT INTO tenants (`" . implode('`,`', array_keys($cols)) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")
      ->execute(array_values($cols));
    return (int)$c->lastInsertId();
};
$tenant = function (int $id) use ($c) { $s = $c->prepare("SELECT * FROM tenants WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };
$queue  = function (int $id) use ($c) { $s = $c->prepare("SELECT * FROM vendor_link_queue WHERE tenant_id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; };
$custsNamed = function (string $name) use ($pdo): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE customer_name = ? AND status <> 'deleted'"); $s->execute([$name]); return (int)$s->fetchColumn();
};
$openAlerts = function (int $tid) use ($c): array {
    return $c->query("SELECT * FROM vendor_bridge_alerts WHERE tenant_id = $tid AND kind = 'possible_match' AND resolved_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
};
$linkLogs = fn(int $tid) => (int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE tenant_id = $tid AND action = 'vendor_customer_linked'")->fetchColumn();

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'vlq_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function vendorEnv(bool $vendor): string {
    return $vendor ? "putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');"
                   : "putenv('BMS_VENDOR_CONTEXT');";
}
/** Call an endpoint; $vendor = on the platform root; $session = signed in as the admin. */
function call(string $file, array $data, string $method = 'POST', bool $vendor = true, bool $session = true, string $pre = ''): array {
    global $root, $uid;
    $sess = $session ? "\$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_lang'] = 'en';" : '';
    $out = runPhp("
        \$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . "; \$_SERVER['HTTP_HOST'] = 'localhost';
        $pre
        require '$root/roots.php';
        " . vendorEnv($vendor) . "
        $sess
        \$_POST = \$_REQUEST = \$_GET = " . var_export($data, true) . ";
        \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start();
        // Endpoints exit(): wrap the body + status code on the way out.
        register_shutdown_function(function () {
            \$o = ''; while (ob_get_level()) \$o = ob_get_clean() . \$o;
            \$p = strpos(\$o, '{');
            echo json_encode(['code' => http_response_code(), 'body' => \$p === false ? \$o : substr(\$o, \$p)]);
        });
        include '$root/$file';
    ");
    $at = strrpos($out, '{"code"');
    $w = $at === false ? null : json_decode(substr($out, $at), true);
    $j = is_array($w) ? json_decode((string)$w['body'], true) : null;
    return (is_array($j) ? $j : ['success' => false, 'message' => $out]) + ['_code' => is_array($w) ? (int)$w['code'] : 0];
}
$kick = fn() => call('api/platform/vendor_link_kick.php', [], 'POST', true, false);

// ── B1 ─────────────────────────────────────────────────────────────────────
section('B1. registration → exactly one customer');
$pA = $freshPhone();
$tA = $mkTenant('vlqtesta', ['owner_phone' => $pA, 'owner_first_name' => 'Asha', 'owner_last_name' => 'Juma', 'owner_email' => $pA]);
finishSelfRegistration($tA, null, 'vlqtesta-owner@example.com');
$q = $queue($tA);
ok($q && $q['state'] === 'pending', 'finishing registration queues the company');
finishSelfRegistration($tA, null, 'vlqtesta-owner@example.com');
ok((int)$c->query("SELECT COUNT(*) FROM vendor_link_queue WHERE tenant_id = $tA")->fetchColumn() === 1, 'queuing twice keeps one row');
ok($custsNamed('VLQ-TEST Co vlqtesta') === 0, 'no customer yet — only BJP\'s own install creates it');

$r = call('api/platform/vendor_link_kick.php', [], 'POST', false, false);
ok(($r['_code'] ?? 0) === 404 && $queue($tA)['state'] === 'pending', 'kick off the platform root: 404, nothing done');
$r = call('api/platform/vendor_link_kick.php', [], 'GET', true, false);
ok(($r['_code'] ?? 0) === 405, 'kick by GET: 405');
$r = $kick();
ok(!empty($r['ok']) && ($r['processed'] ?? 0) >= 1, 'kick (anonymous, as the register.php beacon) processes the queue');
$t = $tenant($tA);
$cidA = (int)$t['vendor_customer_id'];
$cu = $pdo->query("SELECT * FROM customers WHERE customer_id = $cidA")->fetch(PDO::FETCH_ASSOC) ?: [];
ok($cidA > 0 && ($cu['customer_name'] ?? '') === 'VLQ-TEST Co vlqtesta', 'customer created and linked to the company');
ok(($cu['contact_person'] ?? '') === 'Asha Juma' && ($cu['phone'] ?? '') === $pA
   && ($cu['email'] ?? '') === ($hasContact ? 'vlqtesta-owner@example.com' : null), 'details from the registration (owner, phone, real contact email)');
ok(preg_match('/CUST-\d{4}$/', (string)($cu['customer_code'] ?? '')) === 1 && $cu['status'] === 'active' && !empty($cu['ledger_account_id']),
   'made like Customers → Add: company code, active, ledger sub-account');
ok(strpos((string)$t['vendor_customer_label'], (string)$cu['customer_code']) === 0, 'superadmin label set');
ok($queue($tA)['state'] === 'done' && (int)$queue($tA)['attempts'] === 1, 'queue row done after one attempt');
ok($linkLogs($tA) === 1, 'one link row in the tenant audit log');

$kick();
$again = json_decode(runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; require_once '$root/core/vendor_link.php';
    echo json_encode(vlEnsureCustomerForTenant(\$pdo, $tA, 'test'));"), true);
ok(($again['state'] ?? '') === 'linked' && (int)$again['customer_id'] === $cidA, 'asked again: "linked" to the same customer');
ok($custsNamed('VLQ-TEST Co vlqtesta') === 1 && $linkLogs($tA) === 1, 'still one customer, one log row');

// ── B2 ─────────────────────────────────────────────────────────────────────
section('B2. a visit for an already-registered company links, never creates');
require_once "$root/core/field_reports_schema.php";
fieldReportsEnsureSchema($pdo);
$pdo->prepare("INSERT INTO field_visits (user_id, visit_date, location, client_name, client_phone, phone_normalized, business_type, created_by,
               joined, tenant_id, tenant_subdomain) VALUES (?, CURDATE(), 'Arusha', 'VLQ-TEST Asha', ?, ?, 'retail', ?, 1, ?, 'vlqtesta')")
    ->execute([$uid, $pA, '255' . substr($pA, 1), $uid, $tA]);
$vid = (int)$pdo->lastInsertId();
$r = call('api/field_reports/make_customer.php', ['visit_id' => $vid], 'GET');
ok((int)($r['linked_customer']['customer_id'] ?? 0) === $cidA, 'preview shows the customer registration already made');
$r = call('api/field_reports/make_customer.php', ['visit_id' => $vid, 'mode' => 'create', 'customer_name' => 'VLQ-TEST Visit Copy', 'phone' => $pA]);
ok(!empty($r['success']) && !empty($r['already']) && (int)$r['customer_id'] === $cidA, '"Make real customer" answers "already a customer"');
ok($custsNamed('VLQ-TEST Visit Copy') === 0 && (int)$pdo->query("SELECT customer_id FROM field_visits WHERE visit_id = $vid")->fetchColumn() === $cidA,
   'no second customer; the visit points to the existing one');

// ── B3 ─────────────────────────────────────────────────────────────────────
section('B3. two at once → one customer');
$tB = $mkTenant('vlqtestb', ['owner_phone' => $freshPhone()]);
$start = microtime(true) + 2.0;
$procs = [];
for ($i = 0; $i < 2; $i++) {
    $tmp = tempnam(sys_get_temp_dir(), 'vlqp_');
    file_put_contents($tmp, "<?php \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; require_once '$root/core/vendor_link.php';
        while (microtime(true) < $start) usleep(2000);
        echo json_encode(vlEnsureCustomerForTenant(\$pdo, $tB, 'parallel $i'));");
    $procs[] = [proc_open('php ' . escapeshellarg($tmp), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes, $tmp];
}
$states = [];
foreach ($procs as [$p, $pipes, $tmp]) {
    $o = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p); @unlink($tmp);
    $states[] = json_decode($o, true)['state'] ?? ('? ' . $o);
}
sort($states);
ok($states === ['created', 'linked'], 'one call created, the other found it (' . implode(', ', $states) . ')');
ok($custsNamed('VLQ-TEST Co vlqtestb') === 1 && (int)$tenant($tB)['vendor_customer_id'] > 0, 'exactly one customer, linked');
ok($linkLogs($tB) === 1, 'one audit row');

// ── B4 ─────────────────────────────────────────────────────────────────────
section('B4. same phone / email as an old customer → a person decides');
$pX = $freshPhone();
$pdo->beginTransaction();
$cidX = vlCreateCustomer($pdo, ['customer_name' => 'VLQ-TEST Existing', 'phone' => $pX, 'email' => 'vlqtest-existing@example.com'], $uid);
$pdo->commit();
$tC = $mkTenant('vlqtestc', ['owner_phone' => '+255 ' . substr($pX, 1, 3) . ' ' . substr($pX, 4)]);   // same number, other format
$tD = $mkTenant('vlqtestd', $hasContact ? ['owner_phone' => $freshPhone(), 'owner_email' => '0712999888', 'owner_contact_email' => 'VLQTEST-Existing@example.com']
                                        : ['owner_phone' => $freshPhone(), 'owner_email' => 'vlqtest-existing@example.com']);
vlQueueTenantLink($tC); vlQueueTenantLink($tD);
$kick();
foreach (['C' => $tC, 'D' => $tD] as $k => $tid) {
    $a = $openAlerts($tid);
    ok($tenant($tid)['vendor_customer_id'] === null && $custsNamed("VLQ-TEST Co vlqtest" . strtolower($k)) === 0, "$k: no customer created, not linked");
    ok($queue($tid)['state'] === 'needs_review', "$k: queue waits for review");
    ok(count($a) === 1 && (int)$a[0]['customer_id'] === $cidX && $a[0]['invoice_ref'] === "tenant:$tid"
       && strpos($a[0]['message'], 'VLQ-TEST Existing') !== false, "$k: one open possible_match alert naming the look-alike ("
       . ($k === 'C' ? 'phone in another format' : 'email, case-insensitive') . ')');
}
$c->exec("UPDATE vendor_link_queue SET state = 'pending' WHERE tenant_id = $tC");
$kick();
ok(count($openAlerts($tC)) === 1, 'run again: still one alert (no duplicates)');

// ── B5 ─────────────────────────────────────────────────────────────────────
section('B5. Customers page review');
$r = call('api/platform/platform_match_review.php', [], 'GET');
$items = []; foreach ($r['items'] ?? [] as $it) $items[$it['tenant']['id']] = $it;
ok(!empty($r['success']) && isset($items[$tC], $items[$tD]) && !empty($r['can_edit']) && !empty($r['can_create']), 'lists both companies, with permissions');
ok(in_array($cidX, array_map(fn($m) => (int)$m['customer_id'], $items[$tC]['matches'] ?? []), true)
   && ($items[$tC]['tenant']['subdomain'] ?? '') === 'vlqtestc', 'each with its look-alike customers');
ok(($items[$tD]['tenant']['email'] ?? '') === ($hasContact ? 'VLQTEST-Existing@example.com' : 'vlqtest-existing@example.com'), 'shows the real contact email');
$r = call('api/platform/platform_match_review.php', [], 'GET', false);
ok(($r['_code'] ?? 0) === 404, 'not available off the platform root');
$r = call('api/platform/platform_match_review.php', [], 'GET', true, false);
ok(($r['_code'] ?? 0) === 401, 'needs a signed-in user');

$alertC = (int)$items[$tC]['alert_id']; $alertD = (int)$items[$tD]['alert_id'];
$r = call('api/platform/platform_match_review.php', ['action' => 'link', 'alert_id' => $alertC, 'customer_id' => 999999999]);
ok(empty($r['success']) && $tenant($tC)['vendor_customer_id'] === null, 'link to a missing customer is refused');
$r = call('api/platform/platform_match_review.php', ['action' => 'link', 'alert_id' => $alertC, 'customer_id' => $cidX]);
ok(!empty($r['success']) && (int)$tenant($tC)['vendor_customer_id'] === $cidX, 'Link: the company now pays as the existing customer');
ok(!$openAlerts($tC) && $queue($tC)['state'] === 'done' && $linkLogs($tC) === 1, 'alert resolved, queue done, logged once');
$r = call('api/platform/platform_match_review.php', ['action' => 'link', 'alert_id' => $alertC, 'customer_id' => $cidX]);
ok(($r['_code'] ?? 0) === 404 && stripos($r['message'] ?? '', 'already dealt with') !== false, 'a second click: "already dealt with"');

$r = call('api/platform/platform_match_review.php', ['action' => 'create', 'alert_id' => $alertD]);
$cidD = (int)($r['customer_id'] ?? 0);
ok(!empty($r['success']) && $cidD > 0 && $cidD !== $cidX && (int)$tenant($tD)['vendor_customer_id'] === $cidD, 'Create new: a separate customer, linked');
ok($custsNamed('VLQ-TEST Co vlqtestd') === 1 && $custsNamed('VLQ-TEST Existing') === 1, 'the old customer is untouched');
ok(!$openAlerts($tD) && $queue($tD)['state'] === 'done', 'alert resolved, queue done');
$r = call('api/platform/platform_match_review.php', ['action' => 'nope', 'alert_id' => $alertD]);
ok(($r['_code'] ?? 0) === 404, 'resolved alert refuses any further action');

// ── B6 ─────────────────────────────────────────────────────────────────────
section('B6. background jobs + cron drain the queue; unknown company fails cleanly');
$tE = $mkTenant('vlqteste', ['owner_phone' => $freshPhone()]);
vlQueueTenantLink($tE);
$r = call('api/run_background_jobs.php', [], 'POST');
ok(!empty($r['ok']) && in_array('vendor_customer_links', $r['ran'] ?? [], true) && (int)$tenant($tE)['vendor_customer_id'] > 0,
   'a BJP staff page load (background jobs) makes the customer');
$tF = $mkTenant('vlqtestf', ['owner_phone' => $freshPhone()]);
vlQueueTenantLink($tF);
$r = call('api/run_background_jobs.php', [], 'POST', false);
ok(!in_array('vendor_customer_links', $r['ran'] ?? [], true) && $queue($tF)['state'] === 'pending', 'off the platform root, background jobs leave it');
$cron = "putenv('CRON_SECRET=vlqtest-secret'); \$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer vlqtest-secret';";
$r = call('api/cron/vendor_bridge_retry.php', [], 'GET', true, false, $cron);
ok(!empty($r['ok']) && isset($r['customer_links']['processed']) && (int)$tenant($tF)['vendor_customer_id'] > 0, 'cron makes the customer and reports customer_links');
$tG = $mkTenant('vlqtestg', ['owner_phone' => $freshPhone(), 'status' => 'deleted']);
vlQueueTenantLink($tG);
$kick();
ok($queue($tG)['state'] === 'failed' && $custsNamed('VLQ-TEST Co vlqtestg') === 0, 'a deleted company: queue failed, no customer');

// ── B7 ─────────────────────────────────────────────────────────────────────
section('B7. pages');
function render(string $file, bool $vendor): string {
    global $root, $uid;
    return runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        " . vendorEnv($vendor) . "
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        ob_start(); include '$root/$file'; echo ob_get_clean();
    ");
}
$h = render('app/bms/customer/customers.php', true);
ok(strpos($h, 'id="platformMatchCard"') !== false && strpos($h, 'platform_match_review.php') !== false, 'Customers on the platform root: review card present');
ok(strpos($h, 'Fatal error') === false && strpos($h, 'Warning:') === false && strpos($h, 'Notice:') === false, 'renders cleanly');
$h = render('app/bms/customer/customers.php', false);
ok(strpos($h, 'id="platformMatchCard"') === false && strpos($h, 'Fatal error') === false, 'everywhere else: unchanged');
