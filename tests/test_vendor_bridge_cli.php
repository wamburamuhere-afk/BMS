<?php
/**
 * Paid BJP invoice → tenant subscription (customer_subscription_plan.md Phase 4)
 *   php tests/test_vendor_bridge_cli.php
 *
 * A: wiring (CI).  B: real invoices + real payments (record_payment, save_receipt,
 * void_payment, update_invoice_status) on the platform root, checked against the
 * control DB. Everything is 'VB-TEST' / 'vbtest*' and removed afterwards, including
 * the ledger, bank-register and allocation rows the payments create. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$hasConfig = is_file("$root/includes/config.php");
if ($hasConfig) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }

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

section('A. wiring');
$src = fn($f) => file_get_contents("$root/$f");
foreach (['api/account/record_payment.php', 'api/account/save_receipt.php', 'api/account/apply_customer_advance.php'] as $f) {
    $s = $src($f);
    ok(strpos($s, 'vendorOnInvoicesSettled(') !== false && strpos($s, 'vendorOnInvoicesSettled(') > strpos($s, '$pdo->commit()'), "$f calls the bridge after its commit");
}
ok(strpos($src('api/account/void_payment.php'), 'vendorOnPaymentVoided(') !== false, 'void_payment alerts instead of shortening access');
ok(strpos($src('api/account/update_invoice_status.php'), 'vendorOn') === false, 'marking an invoice paid by hand never reaches the bridge');
ok(strpos($src('core/vendor_bridge.php'), 'vbInvoicePaidTotal(') !== false, 'the bridge recomputes the money actually received');
ok(strpos($src('api/cron/vendor_bridge_retry.php'), "hash_equals('Bearer '") !== false, 'retry cron is token-guarded');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B ──────────────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/vendor_bridge.php";
require_once "$root/core/payment_source.php";
sptEnsureSchema($pdo);
ob_start(); sptEnsureLineColumns($pdo); ob_end_clean();
vbEnsureOutbox($pdo);
$c = getControlPdo();
if (!vlLinkReady() || !vbAlertsReady() || !controlColumnExists('tenant_payments', 'source_ref')) { ok(false, 'control DB upgraded (run scripts/setup_control_db.php)'); exit; }

$uid  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid  = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$bank = (int)(cashBankAccounts($pdo)[0]['account_id'] ?? 0);
if (!$uid || !$wid || !$bank) { echo "SKIP B: needs an admin, a warehouse and a cash/bank account\n"; exit; }

$made = ['inv' => [], 'pay' => []];
$cleanup = function () use ($pdo, $c, &$made) {
    $in = fn($a) => $a ? implode(',', array_map('intval', $a)) : '0';
    // invoices + payments created by the test
    $made['inv'] = array_merge($made['inv'], $pdo->query("SELECT invoice_id FROM invoices WHERE notes = 'VB-TEST'")->fetchAll(PDO::FETCH_COLUMN));
    $inv = $in($made['inv']);
    // Alerts are keyed by invoice number — remove them while the invoices still exist.
    $refs = $pdo->query("SELECT invoice_number FROM invoices WHERE invoice_id IN ($inv)")->fetchAll(PDO::FETCH_COLUMN);
    if ($refs) $c->exec("DELETE FROM vendor_bridge_alerts WHERE invoice_ref IN (" . implode(',', array_map([$c, 'quote'], $refs)) . ")");
    $made['pay'] = array_merge($made['pay'], $pdo->query("SELECT DISTINCT p.payment_id FROM payments p LEFT JOIN payment_allocations pa ON pa.payment_id = p.payment_id
                                                            WHERE p.invoice_id IN ($inv) OR (pa.target_type = 'invoice' AND pa.target_id IN ($inv))")->fetchAll(PDO::FETCH_COLUMN));
    $pay = $in($made['pay']);
    $nums = $pdo->query("SELECT payment_number FROM payments WHERE payment_id IN ($pay)")->fetchAll(PDO::FETCH_COLUMN);
    if ($nums) {
        $q = implode(',', array_map([$pdo, 'quote'], $nums));
        $pdo->exec("DELETE FROM bank_transactions WHERE reference_number IN ($q)");
    }
    $pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                 WHERE (je.entity_type IN ('payment','payment_void') AND je.entity_id IN ($pay))
                    OR (je.entity_type IN ('invoice','invoice_cogs','invoice_void') AND je.entity_id IN ($inv))");
    $pdo->exec("DELETE FROM journal_entries WHERE (entity_type IN ('payment','payment_void') AND entity_id IN ($pay))
                    OR (entity_type IN ('invoice','invoice_cogs','invoice_void') AND entity_id IN ($inv))");
    $pdo->exec("DELETE FROM payment_allocations WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM payments WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM vendor_bridge_outbox WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ($inv)");
    try { $pdo->exec("DELETE FROM workflow_signatures WHERE entity_type = 'invoice' AND entity_id IN ($inv)"); } catch (Throwable $e) {}
    $pdo->exec("DELETE FROM invoices WHERE invoice_id IN ($inv)");
    // services, customers (+ ledger accounts), control rows
    $p = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'VB-TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($p) {
        $pi = $in($p);
        $pdo->exec("DELETE FROM service_price_terms WHERE product_id IN ($pi)");
        $pdo->exec("DELETE FROM vendor_subscription_services WHERE product_id IN ($pi)");
        $pdo->exec("DELETE FROM products WHERE product_id IN ($pi)");
    }
    foreach ($pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_name LIKE 'VB-TEST%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['ledger_account_id']) $pdo->exec("DELETE FROM accounts WHERE account_id = " . (int)$r['ledger_account_id']);
        $pdo->exec("DELETE FROM customers WHERE customer_id = " . (int)$r['customer_id']);
    }
    $t = $c->query("SELECT id FROM tenants WHERE subdomain LIKE 'vbtest%'")->fetchAll(PDO::FETCH_COLUMN);
    $ti = $in($t);
    $c->exec("DELETE FROM tenant_payments WHERE tenant_id IN ($ti)");
    $c->exec("DELETE FROM tenant_admin_log WHERE tenant_id IN ($ti)");
    $c->exec("DELETE FROM vendor_bridge_alerts WHERE tenant_id IN ($ti)");
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'vbtest%'");
};
$alertCleanupRefs = [];
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = (function () use ($cleanup, $c, &$alertCleanupRefs) {
    if ($alertCleanupRefs) {
        $q = implode(',', array_map([$c, 'quote'], $alertCleanupRefs));
        $c->exec("DELETE FROM vendor_bridge_alerts WHERE invoice_ref IN ($q)");
    }
    $cleanup();
});

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'vb_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
const VENDOR_ENV = "putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');";
function call(string $file, array $post, bool $vendor = true): array {
    global $root, $uid;
    $env = $vendor ? VENDOR_ENV : "putenv('BMS_VENDOR_CONTEXT');";
    $out = runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        $env
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_POST = \$_REQUEST = " . var_export($post, true) . ";
        \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; \$o = ob_get_clean();
        \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);
    ");
    $j = json_decode($out, true);
    if (!is_array($j)) {   // error_log() lines go to stderr and land before the JSON
        $p = strrpos($out, "\n{");
        $j = $p !== false ? json_decode(substr($out, $p + 1), true) : null;
    }
    return is_array($j) ? $j : ['success' => false, 'message' => $out];
}
/** Run bridge code directly in a vendor-context subprocess. */
function vendorRun(string $php): string {
    global $root;
    return runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . VENDOR_ENV . " require_once '$root/core/vendor_bridge.php'; global \$pdo; $php");
}

// Services: a subscription with terms, and an ordinary service.
$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'VB-TEST BMS Simple POS', 'is_subscription' => '1',
    'price_terms' => json_encode([
        ['term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 10000, 'is_default' => 1],
        ['term_label' => '6 Months', 'duration_value' => 6, 'duration_unit' => 'month', 'price' => 45000],
        ['term_label' => '1 Year', 'duration_value' => 1, 'duration_unit' => 'year', 'price' => 85000],
    ])]);
$sub = (int)($r['product_id'] ?? 0);
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'VB-TEST Training', 'selling_price' => '30000']);
$plain = (int)($r['product_id'] ?? 0);
$term = [];
foreach (sptForProduct($pdo, $sub) as $t) $term[$t['term_label']] = (int)$t['term_id'];
ok($sub && $plain && vssIsSubscription($pdo, $sub) && count($term) === 3, 'subscription service (flagged on the platform root) + ordinary service set up');

// Customers and companies.
$pdo->beginTransaction();
$cust1 = vlCreateCustomer($pdo, ['customer_name' => 'VB-TEST Duka One'], $uid);
$cust2 = vlCreateCustomer($pdo, ['customer_name' => 'VB-TEST Duka Two'], $uid);
$cust3 = vlCreateCustomer($pdo, ['customer_name' => 'VB-TEST Group'], $uid);
$pdo->commit();
$mkTenant = function (string $s, array $f) use ($c): int {
    $cols = array_merge(['company_name' => "VB-TEST $s", 'subdomain' => $s, 'db_name' => 'none', 'db_username' => 'none',
        'db_password_encrypted' => 'x', 'owner_email' => "$s@example.com"], $f);
    $c->prepare("INSERT INTO tenants (`" . implode('`,`', array_keys($cols)) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute(array_values($cols));
    return (int)$c->lastInsertId();
};
$trialEnd = date('Y-m-d', strtotime('+5 days'));
$t1 = $mkTenant('vbtest1', ['status' => 'trial', 'trial_ends_at' => "$trialEnd 23:59:59"]);
$t2 = $mkTenant('vbtest2', ['status' => 'trial', 'trial_ends_at' => "$trialEnd 23:59:59"]);
$t3a = $mkTenant('vbtest3a', ['status' => 'active', 'subscription_ends_at' => $trialEnd]);
$t3b = $mkTenant('vbtest3b', ['status' => 'active', 'subscription_ends_at' => $trialEnd]);
vlSetTenantCustomer($t1, $cust1, 'test', 'VB-TEST Duka One');
vlSetTenantCustomer($t3a, $cust3, 'test', 'VB-TEST Group');
vlSetTenantCustomer($t3b, $cust3, 'test', 'VB-TEST Group');
$tenant = function (int $id) use ($c) { $s = $c->prepare("SELECT * FROM tenants WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };
$outbox = function (int $inv) use ($pdo) { $s = $pdo->prepare("SELECT * FROM vendor_bridge_outbox WHERE invoice_id = ?"); $s->execute([$inv]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; };
$alerts = function (string $ref, ?bool $open = true) use ($c) {
    $s = $c->prepare("SELECT * FROM vendor_bridge_alerts WHERE invoice_ref = ?" . ($open === null ? '' : ($open ? ' AND resolved_at IS NULL' : ' AND resolved_at IS NOT NULL')));
    $s->execute([$ref]); return $s->fetchAll(PDO::FETCH_ASSOC);
};

function makeInvoice(int $cust, array $lines, bool $vendor = true): array {
    global $pdo, $wid, $made;
    $items = [];
    foreach ($lines as $l) $items[] = $l + ['quantity' => 1, 'unit' => 'job', 'tax_rate' => 0, 'discount_percent' => 0, 'description' => ''];
    $r = call('api/account/save_invoice.php', ['customer_id' => $cust, 'invoice_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+7 days')), 'currency' => 'TZS', 'warehouse_id' => $wid, 'notes' => 'VB-TEST', 'items' => $items], $vendor);
    $id = (int)($r['invoice_id'] ?? 0) ?: (int)$pdo->query("SELECT MAX(invoice_id) FROM invoices WHERE notes = 'VB-TEST' AND customer_id = $cust")->fetchColumn();
    $made['inv'][] = $id;
    $row = $pdo->query("SELECT invoice_id, invoice_number, grand_total, status FROM invoices WHERE invoice_id = $id")->fetch(PDO::FETCH_ASSOC);
    return $row ?: ['invoice_id' => 0, 'invoice_number' => '', 'grand_total' => 0];
}
function pay(int $invoiceId, float $amount, bool $vendor = true): array {
    global $bank;
    return call('api/account/record_payment.php', ['invoice_id' => $invoiceId, 'amount' => $amount, 'payment_date' => date('Y-m-d'),
        'payment_method' => 'bank_transfer', 'status' => 'completed', 'received_into_account_id' => $bank, 'reference_number' => 'VB-TEST'], $vendor);
}
$subLine = fn(string $termName, int $qty = 1) => ['product_id' => $sub, 'product_name' => 'VB-TEST BMS Simple POS', 'quantity' => $qty,
    'unit_price' => ['1 Month' => 10000, '6 Months' => 45000, '1 Year' => 85000][$termName], 'price_term_id' => $term[$termName]];

section('B1. partial then full payment (record_payment)');
$inv1 = makeInvoice($cust1, [$subLine('6 Months')]);
$alertCleanupRefs[] = $inv1['invoice_number'];
ok($inv1['invoice_id'] && (float)$inv1['grand_total'] === 45000.0, 'invoice ' . $inv1['invoice_number'] . ' for 6 Months = 45,000');
$r = pay($inv1['invoice_id'], 20000);
ok(!empty($r['success']), 'part payment recorded' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
ok($tenant($t1)['status'] === 'trial' && $tenant($t1)['subscription_ends_at'] === null && $outbox($inv1['invoice_id']) === null, 'a part payment changes nothing');
$r = pay($inv1['invoice_id'], 25000);
$t = $tenant($t1);
$expected = date('Y-m-d', strtotime("$trialEnd +6 months"));
ok(!empty($r['success']) && $t['status'] === 'active', 'paid in full → company active');
ok($t['subscription_ends_at'] === $expected, "access runs to $expected (6 months after the trial's last day — trial days kept)");
$tp = $c->query("SELECT * FROM tenant_payments WHERE tenant_id = $t1")->fetchAll(PDO::FETCH_ASSOC);
ok(count($tp) === 1 && $tp[0]['source'] === 'vendor_invoice' && $tp[0]['source_ref'] === $inv1['invoice_number']
   && (int)$tp[0]['duration_months'] === 6 && (int)$tp[0]['amount_tzs'] === 45000, 'tenant payment recorded with the invoice as its source');
ok(($outbox($inv1['invoice_id'])['status'] ?? '') === 'done', 'outbox row done');
ok((int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE tenant_id = $t1 AND action = 'payment_recorded' AND actor_email = 'BJP invoice bridge'")->fetchColumn() === 1,
   'audit log names the bridge');

section('B2. never twice');
vendorRun("vendorOnInvoicesSettled(\$pdo, [{$inv1['invoice_id']}]); vendorBridgeRetry(\$pdo);");
ok((int)$c->query("SELECT COUNT(*) FROM tenant_payments WHERE tenant_id = $t1")->fetchColumn() === 1 && $tenant($t1)['subscription_ends_at'] === $expected,
   'running the bridge again for the same invoice changes nothing');

section('B3. receipt across invoices, quantity × term (save_receipt)');
$inv2 = makeInvoice($cust1, [$subLine('1 Month', 2), ['product_id' => $plain, 'product_name' => 'VB-TEST Training', 'unit_price' => 30000]]);
$alertCleanupRefs[] = $inv2['invoice_number'];
$r = call('api/account/save_receipt.php', ['customer_id' => $cust1, 'payment_date' => date('Y-m-d'), 'amount' => 50000,
    'payment_method' => 'cash', 'received_into_account_id' => $bank, 'allocations' => [['invoice_id' => $inv2['invoice_id'], 'amount' => 50000]]]);
$expected2 = date('Y-m-d', strtotime("$expected +2 months"));
ok(!empty($r['success']), 'receipt recorded' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
ok($tenant($t1)['subscription_ends_at'] === $expected2, "2 × 1 Month on the line → +2 months, from the current end ($expected2); the training line adds nothing");

section('B4. marked paid by hand — no money, no months');
$inv3 = makeInvoice($cust1, [$subLine('1 Year')]);
$alertCleanupRefs[] = $inv3['invoice_number'];
call('api/account/update_invoice_status.php', ['invoice_id' => $inv3['invoice_id'], 'status' => 'paid']);
ok($pdo->query("SELECT status FROM invoices WHERE invoice_id = {$inv3['invoice_id']}")->fetchColumn() === 'paid', 'invoice shows paid');
vendorRun("vendorOnInvoicesSettled(\$pdo, [{$inv3['invoice_id']}]);");
ok($tenant($t1)['subscription_ends_at'] === $expected2, 'access unchanged — even calling the bridge directly finds no money received');

section('B5. customer not linked yet → alert, then applies on linking');
$inv4 = makeInvoice($cust2, [$subLine('1 Month')]);
$alertCleanupRefs[] = $inv4['invoice_number'];
pay($inv4['invoice_id'], 10000);
ok(($outbox($inv4['invoice_id'])['status'] ?? '') === 'alerted' && count($alerts($inv4['invoice_number'])) === 1
   && $alerts($inv4['invoice_number'])[0]['kind'] === 'not_linked', 'paid but not linked → outbox alerted + one superadmin alert');
ok($tenant($t2)['status'] === 'trial', 'no company touched');
$r = call('api/platform/customer_platform_link.php', ['customer_id' => $cust2, 'action' => 'link', 'subdomain' => 'vbtest2']);
$t = $tenant($t2);
ok(!empty($r['success']) && $t['status'] === 'active' && $t['subscription_ends_at'] === date('Y-m-d', strtotime("$trialEnd +1 month")),
   'linking the customer applies the waiting payment at once');
ok(count($alerts($inv4['invoice_number'])) === 0 && count($alerts($inv4['invoice_number'], false)) === 1, 'the alert closed itself');

section('B6. customer paying for several companies → alert, nothing guessed');
$inv5 = makeInvoice($cust3, [$subLine('1 Month')]);
$alertCleanupRefs[] = $inv5['invoice_number'];
pay($inv5['invoice_id'], 10000);
ok(($alerts($inv5['invoice_number'])[0]['kind'] ?? '') === 'many_tenants', 'many_tenants alert');
ok($tenant($t3a)['subscription_ends_at'] === $trialEnd && $tenant($t3b)['subscription_ends_at'] === $trialEnd, 'neither company extended');

section('B7. ordinary service invoice');
$inv6 = makeInvoice($cust1, [['product_id' => $plain, 'product_name' => 'VB-TEST Training', 'unit_price' => 30000]]);
pay($inv6['invoice_id'], 30000);
ok(($outbox($inv6['invoice_id'])['status'] ?? '') === 'skipped' && $tenant($t1)['subscription_ends_at'] === $expected2, 'skipped — not a subscription');

section('B8. voiding a payment that extended a company');
$payId = (int)$pdo->query("SELECT payment_id FROM payments WHERE invoice_id = {$inv1['invoice_id']} AND status = 'completed' ORDER BY payment_id DESC LIMIT 1")->fetchColumn();
$r = call('api/account/void_payment.php', ['payment_id' => $payId]);
ok(!empty($r['success']), 'payment voided' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
ok(($alerts($inv1['invoice_number'])[0]['kind'] ?? '') === 'payment_voided', 'superadmin alerted');
ok($tenant($t1)['subscription_ends_at'] === $expected2, 'access NOT shortened automatically');

section('B9. off the platform root nothing happens');
$inv7 = makeInvoice($cust1, [$subLine('1 Month')], false);
pay($inv7['invoice_id'], 10000, false);
ok($outbox($inv7['invoice_id']) === null && $tenant($t1)['subscription_ends_at'] === $expected2, 'a tenant\'s own invoices never reach the platform');

section('B10. retry (cron path)');
$pdo->exec("INSERT INTO vendor_bridge_outbox (invoice_id) VALUES ({$inv7['invoice_id']})");
$alertCleanupRefs[] = $inv7['invoice_number'];
$out = vendorRun("echo json_encode(vendorBridgeRetry(\$pdo));");
ok(($outbox($inv7['invoice_id'])['status'] ?? '') === 'done' && $tenant($t1)['subscription_ends_at'] === date('Y-m-d', strtotime("$expected2 +1 month")),
   'a queued paid invoice is applied by the retry run');
