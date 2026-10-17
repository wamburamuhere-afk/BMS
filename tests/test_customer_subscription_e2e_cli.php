<?php
/**
 * The whole BJP customer-subscription story, end to end (customer_subscription_plan.md Phase 8)
 *   php tests/test_customer_subscription_e2e_cli.php
 *
 * visit → joined + web address → Make real customer → company asks for "6 Months" in My
 * Subscription → approved quotation → BJP converts to sales order → invoice → payment →
 * company trial → active for 6 months → contract → BJP signs → customer signing link → signed.
 * Every step goes through the real endpoint. Data is 'E2E-TEST' / 'e2etest*' and removed.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
const E2E_KEY = 'c1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f92';
putenv('TENANT_CRED_KEY=' . E2E_KEY);
if (!is_file("$root/includes/config.php")) { echo "SKIP: needs the local database (no includes/config.php — CI)\nPasses: 0\nFailures: 0\n"; exit(0); }
$_SERVER['HTTP_HOST'] = 'localhost';
require_once "$root/roots.php";
require_once "$root/core/vendor_bridge.php";
require_once "$root/core/subscription_contracts.php";
require_once "$root/core/platform_gateway.php";
require_once "$root/core/payment_source.php";
require_once "$root/core/field_reports_schema.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

global $pdo;
sptEnsureSchema($pdo); ob_start(); sptEnsureLineColumns($pdo); ob_end_clean(); vbEnsureOutbox($pdo); scEnsureSchema($pdo); fieldReportsEnsureSchema($pdo);
$c = getControlPdo();
$uid  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid  = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$bank = (int)(cashBankAccounts($pdo)[0]['account_id'] ?? 0);
if (!$uid || !$wid || !$bank || !vlLinkReady()) { echo "SKIP: needs admin, warehouse, bank account, upgraded control DB\n"; exit(0); }

$cleanup = function () use ($pdo, $c) {
    $in = fn($a) => $a ? implode(',', array_map('intval', $a)) : '0';
    $cust = $in($pdo->query("SELECT customer_id FROM customers WHERE customer_name LIKE 'E2E-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    $inv = $in($pdo->query("SELECT invoice_id FROM invoices WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
    $qt  = $in($pdo->query("SELECT sales_order_id FROM quotations WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
    $so  = $in($pdo->query("SELECT sales_order_id FROM sales_orders WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN));
    $docs = $pdo->query("SELECT document_id FROM customer_contracts WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_COLUMN);
    $docs = array_merge($docs, $pdo->query("SELECT id FROM documents WHERE document_name LIKE 'E2E-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    $all = $docs;
    for ($i = 0; $i < 2; $i++) foreach ($pdo->query("SELECT signed_document_id FROM document_signatures WHERE document_id IN (" . $in($all) . ") AND signed_document_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $d) $all[] = $d;
    $sids = $in($pdo->query("SELECT id FROM document_signatures WHERE document_id IN (" . $in($all) . ")")->fetchAll(PDO::FETCH_COLUMN));
    $pdo->exec("DELETE FROM document_signature_tokens WHERE signature_id IN ($sids)");
    $pdo->exec("DELETE FROM document_signatures WHERE id IN ($sids)");
    foreach ($pdo->query("SELECT file_path FROM documents WHERE id IN (" . $in($all) . ")")->fetchAll(PDO::FETCH_COLUMN) as $fp) if ($fp && strpos($fp, 'uploads/documents/') === 0) @unlink(ROOT_DIR . '/' . $fp);
    $pdo->exec("DELETE FROM documents WHERE id IN (" . $in($all) . ")");
    $pdo->exec("DELETE FROM customer_contracts WHERE customer_id IN ($cust)");
    $refs = $pdo->query("SELECT invoice_number FROM invoices WHERE invoice_id IN ($inv)")->fetchAll(PDO::FETCH_COLUMN);
    if ($refs) $c->exec("DELETE FROM vendor_bridge_alerts WHERE invoice_ref IN (" . implode(',', array_map([$c, 'quote'], $refs)) . ")");
    $pay = $in($pdo->query("SELECT payment_id FROM payments WHERE invoice_id IN ($inv)")->fetchAll(PDO::FETCH_COLUMN));
    $nums = $pdo->query("SELECT payment_number FROM payments WHERE payment_id IN ($pay)")->fetchAll(PDO::FETCH_COLUMN);
    if ($nums) $pdo->exec("DELETE FROM bank_transactions WHERE reference_number IN (" . implode(',', array_map([$pdo, 'quote'], $nums)) . ")");
    $pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                 WHERE (je.entity_type IN ('payment','payment_void') AND je.entity_id IN ($pay)) OR (je.entity_type IN ('invoice','invoice_cogs','invoice_void') AND je.entity_id IN ($inv))");
    $pdo->exec("DELETE FROM journal_entries WHERE (entity_type IN ('payment','payment_void') AND entity_id IN ($pay)) OR (entity_type IN ('invoice','invoice_cogs','invoice_void') AND entity_id IN ($inv))");
    $pdo->exec("DELETE FROM payment_allocations WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM payments WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM vendor_bridge_outbox WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM invoices WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM sales_order_items WHERE order_id IN ($so)");
    $pdo->exec("DELETE FROM sales_orders WHERE sales_order_id IN ($so)");
    $pdo->exec("DELETE FROM quotation_items WHERE order_id IN ($qt)");
    $pdo->exec("DELETE FROM quotations WHERE sales_order_id IN ($qt)");
    try { $pdo->exec("DELETE FROM workflow_signatures WHERE (entity_type='invoice' AND entity_id IN ($inv)) OR (entity_type='sales_order' AND entity_id IN ($so)) OR (entity_type='quotation' AND entity_id IN ($qt))"); } catch (Throwable $e) {}
    try { $pdo->exec("DELETE FROM notifications WHERE customer_id IN ($cust)"); } catch (Throwable $e) {}
    $pdo->exec("DELETE FROM field_visits WHERE client_name LIKE 'E2E-TEST%'");
    $p = $in($pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'E2E-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (['service_price_terms', 'vendor_subscription_services', 'products'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN ($p)");
    $pdo->exec("DELETE FROM document_templates WHERE template_name LIKE 'E2E-TEST%'");
    foreach ($pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_id IN ($cust)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['ledger_account_id']) $pdo->exec("DELETE FROM accounts WHERE account_id = " . (int)$r['ledger_account_id']);
        $pdo->exec("DELETE FROM customers WHERE customer_id = " . (int)$r['customer_id']);
    }
    $t = $in($c->query("SELECT id FROM tenants WHERE subdomain LIKE 'e2etest%'")->fetchAll(PDO::FETCH_COLUMN));
    $c->exec("DELETE FROM tenant_payments WHERE tenant_id IN ($t)");
    $c->exec("DELETE FROM tenant_admin_log WHERE tenant_id IN ($t)");
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'e2etest%'");
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string { $tmp = tempnam(sys_get_temp_dir(), 'e2e_'); file_put_contents($tmp, "<?php\n" . $code); $o = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1'); @unlink($tmp); return trim((string)$o); }
function lastJson(string $out): ?array { $j = json_decode($out, true); if (is_array($j)) return $j; $p = strrpos($out, "\n{"); return $p !== false ? json_decode(substr($out, $p + 1), true) : null; }
const E2E_ENV = "putenv('TENANT_CRED_KEY=" . E2E_KEY . "'); putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');";
/** BJP staff on the platform root. */
function bjp(string $file, array $data, string $method = 'POST'): array {
    global $root, $uid;
    return lastJson(runPhp("\$_SERVER['REQUEST_METHOD'] = '$method'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . E2E_ENV . "
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['first_name'] = 'B'; \$_SESSION['last_name'] = 'J'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_POST = \$_GET = \$_REQUEST = " . var_export($data, true) . "; \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);")) ?? ['success' => false];
}
/** The company's own server talking to the platform root (signed). */
function company(int $tid, array $body): array {
    global $root;
    $json = json_encode($body); $ts = time(); $sig = (string)plSignature($tid, $ts, $json);
    return lastJson(runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . E2E_ENV . " require_once '$root/core/platform_gateway.php'; global \$pdo;
        [\$code, \$o] = pgHandle(\$pdo, " . var_export($json, true) . ", ['X-Platform-Tenant' => '$tid', 'X-Platform-Ts' => '$ts', 'X-Platform-Sig' => " . var_export($sig, true) . "]);
        echo json_encode(['code' => \$code] + \$o);")) ?? ['success' => false];
}

echo "\n── Setup: BJP's subscription service + contract template ──\n";
$pdo->prepare("INSERT INTO document_templates (template_name, subject, content, use_letterhead, signature_align, is_active, created_by)
               VALUES ('E2E-TEST Agreement', 'BMS Subscription Agreement', '<p>{{customer_name}} subscribes to {{service_name}} for {{period}} ({{period_start}} – {{period_end}}).</p>', 1, 'left', 1, ?)")->execute([$uid]);
$tpl = (int)$pdo->lastInsertId();
$r = bjp('api/create_nip_product.php', ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'warehouse_id' => (string)$wid,
    'cost_price' => '0', 'selling_price' => '0', 'product_name' => 'E2E-TEST BMS Simple POS', 'is_subscription' => '1', 'contract_template_id' => (string)$tpl,
    'price_terms' => json_encode([
        ['term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 10000, 'is_default' => 1],
        ['term_label' => '6 Months', 'duration_value' => 6, 'duration_unit' => 'month', 'price' => 45000],
        ['term_label' => '1 Year', 'duration_value' => 1, 'duration_unit' => 'year', 'price' => 85000]])]);
$svc = (int)($r['product_id'] ?? 0);
ok($svc > 0 && vssIsSubscription($pdo, $svc), 'service with 3 price terms, marked as a BMS subscription, with a contract template');

// The visited client registers for a free trial on their own.
$trialEnd = date('Y-m-d', strtotime('+6 days'));
$c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, owner_phone, owner_first_name, owner_last_name, status, trial_ends_at)
             VALUES ('E2E-TEST Duka la Mama', 'e2etest1', 'none', 'none', 'x', 'mama@example.com', '0754000111', 'Mama', 'Juma', 'trial', ?)")->execute(["$trialEnd 23:59:59"]);
$tid = (int)$c->lastInsertId();

echo "\n── 1. Customer visit → joined → real customer ──\n";
$pdo->prepare("INSERT INTO field_visits (user_id, visit_date, location, client_name, client_phone, phone_normalized, business_type, interest, created_by)
               VALUES (?, CURDATE(), 'Moshi Mjini', 'E2E-TEST Mama Juma', '0754000111', '255754000111', 'retail', 'interested', ?)")->execute([$uid, $uid]);
$visit = (int)$pdo->lastInsertId();
ok(!$pdo->query("SELECT customer_id FROM field_visits WHERE visit_id = $visit")->fetchColumn(), 'a visit alone does not create a customer');
$r = bjp('api/field_reports/toggle_joined.php', ['visit_id' => $visit, 'joined' => '1', 'subdomain' => 'e2etest1']);
ok(!empty($r['success']) && $r['tenant_subdomain'] === 'e2etest1', 'marked joined with the company web address');
$r = bjp('api/field_reports/make_customer.php', ['visit_id' => $visit, 'mode' => 'create', 'customer_name' => 'E2E-TEST Duka la Mama',
    'contact_person' => 'Mama Juma', 'phone' => '0754000111', 'email' => 'mama@example.com']);
$cust = (int)($r['customer_id'] ?? 0);
ok($cust > 0 && (int)$c->query("SELECT vendor_customer_id FROM tenants WHERE id = $tid")->fetchColumn() === $cust, 'real customer created and linked to the company');

echo "\n── 2. Company admin chooses 6 Months in My Subscription ──\n";
$cat = company($tid, ['action' => 'catalog']);
$terms = [];
foreach ($cat['services'] ?? [] as $s) if ($s['product_id'] === $svc) foreach ($s['terms'] as $t) $terms[$t['term_label']] = $t['term_id'];
ok(count($terms) === 3, 'the company sees the 3 periods with BJP prices');
$r = company($tid, ['action' => 'request_quote', 'product_id' => $svc, 'term_id' => $terms['6 Months']]);
$qtNum = (string)($r['quotation_number'] ?? '');
$qt = $pdo->query("SELECT * FROM quotations WHERE order_number = " . $pdo->quote($qtNum))->fetch(PDO::FETCH_ASSOC) ?: [];
ok(!empty($r['success']) && ($qt['status'] ?? '') === 'approved' && (float)$qt['grand_total'] === 45000.0, "approved quotation $qtNum for 45,000 — no approval wait");

echo "\n── 3. BJP: quotation → sales order → invoice → payment ──\n";
$r = bjp('api/account/convert_quote_to_order.php', ['quotation_id' => (int)$qt['sales_order_id'], 'id' => (int)$qt['sales_order_id']]);
$so = (int)$pdo->query("SELECT converted_to_so_id FROM quotations WHERE sales_order_id = " . (int)$qt['sales_order_id'])->fetchColumn();
$soLine = $pdo->query("SELECT * FROM sales_order_items WHERE order_id = $so")->fetch(PDO::FETCH_ASSOC) ?: [];
ok($so > 0 && ($soLine['price_term_label'] ?? '') === '6 Months', 'sales order created, line still says 6 Months');
$r = bjp('api/account/save_invoice.php', ['customer_id' => $cust, 'order_id' => $so, 'invoice_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'),
    'currency' => 'TZS', 'warehouse_id' => $wid, 'items' => [[ 'product_id' => $svc, 'product_name' => $soLine['product_name'] ?? '',
        'order_item_id' => $soLine['order_item_id'] ?? 0, 'quantity' => 1, 'unit' => 'job', 'unit_price' => 45000, 'tax_rate' => 0,
        'discount_percent' => 0, 'description' => $soLine['description'] ?? '' ]]]);
$inv = (int)$pdo->query("SELECT MAX(invoice_id) FROM invoices WHERE customer_id = $cust")->fetchColumn();
// Invoices go pending → reviewed → approved (main, 2026-10-16); payment needs an approved invoice.
bjp('api/account/review_invoice.php', ['invoice_id' => $inv]);
$ap = bjp('api/account/approve_invoice.php', ['invoice_id' => $inv]);
ok(!empty($ap['success']), 'BJP reviews and approves the invoice (Directors / CFO, role-based)');
$invLine = $pdo->query("SELECT * FROM invoice_items WHERE invoice_id = $inv")->fetch(PDO::FETCH_ASSOC) ?: [];
ok($inv > 0 && (int)$invLine['term_duration_value'] === 6, 'invoice raised from the sales order; the line inherits 6 Months');
$docs = company($tid, ['action' => 'documents']);
ok(count($docs['invoices'] ?? []) === 1 && ($docs['invoices'][0]['status'] ?? '') === 'unpaid', 'the company sees the unpaid invoice in My Subscription');
ok(($c->query("SELECT status FROM tenants WHERE id = $tid")->fetchColumn()) === 'trial', 'still on trial before paying');
$r = bjp('api/account/record_payment.php', ['invoice_id' => $inv, 'amount' => 45000, 'payment_date' => date('Y-m-d'), 'payment_method' => 'mobile_money',
    'status' => 'completed', 'received_into_account_id' => $bank]);
ok(!empty($r['success']), 'customer pays the invoice');

echo "\n── 4. The company is extended automatically ──\n";
$t = $c->query("SELECT * FROM tenants WHERE id = $tid")->fetch(PDO::FETCH_ASSOC);
$expect = date('Y-m-d', strtotime("$trialEnd +6 months"));
ok($t['status'] === 'active' && $t['subscription_ends_at'] === $expect, "trial → active, access until $expect (6 months after the trial's last day)");
$st = company($tid, ['action' => 'documents']);
ok(($st['invoices'][0]['status'] ?? '') === 'paid', 'My Subscription shows the invoice paid');

echo "\n── 5. Contract: BJP signs, then the customer ──\n";
$k = $pdo->query("SELECT * FROM customer_contracts WHERE invoice_id = $inv")->fetch(PDO::FETCH_ASSOC) ?: [];
ok($k && scState($pdo, $k)['state'] === 'awaiting_bjp' && $k['period_end'] === $expect, 'contract made for exactly that period, waiting for BJP');
$file = ROOT_DIR . '/' . $pdo->query("SELECT file_path FROM documents WHERE id = " . (int)$k['document_id'])->fetchColumn();
$copy = 'uploads/documents/' . bin2hex(random_bytes(8)) . '_e2e.pdf';
copy($file, ROOT_DIR . '/' . $copy);
$pdo->prepare("INSERT INTO documents (document_name, file_path, original_filename, file_size, file_type, uploaded_by, access_level) VALUES ('E2E-TEST bjp signed', ?, 's.pdf', 1, 'pdf', ?, 'private')")->execute([$copy, $uid]);
$bjpDoc = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO document_signatures (document_id, requested_by, signed_by, signer_type, status, signed_document_id, signed_at) VALUES (?, ?, ?, 'internal', 'signed', ?, NOW())")
    ->execute([(int)$k['document_id'], $uid, $uid, $bjpDoc]);
$r = company($tid, ['action' => 'contract_sign_link', 'contract_id' => (int)$k['contract_id']]);
ok(!empty($r['success']) && strpos((string)$r['sign_url'], 'sign-document?token=') !== false, 'after BJP signs, the customer gets a signing link');
$sig = (int)$pdo->query("SELECT id FROM document_signatures WHERE document_id = $bjpDoc AND signer_type = 'external' AND status = 'pending'")->fetchColumn();
$fin = 'uploads/documents/' . bin2hex(random_bytes(8)) . '_e2ef.pdf';
copy($file, ROOT_DIR . '/' . $fin);
$pdo->prepare("INSERT INTO documents (document_name, file_path, original_filename, file_size, file_type, uploaded_by, access_level) VALUES ('E2E-TEST final', ?, 'f.pdf', 1, 'pdf', ?, 'private')")->execute([$fin, $uid]);
$pdo->prepare("UPDATE document_signatures SET status = 'signed', signed_document_id = ?, signed_at = NOW() WHERE id = ?")->execute([(int)$pdo->lastInsertId(), $sig]);
$con = company($tid, ['action' => 'contracts']);
ok(($con['contracts'][0]['state'] ?? '') === 'signed', 'contract signed by both — visible in My Subscription');

echo "\n── 6. Nothing doubled ──\n";
$out = runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . E2E_ENV . " require_once '$root/core/vendor_bridge.php'; global \$pdo; vendorOnInvoicesSettled(\$pdo, [$inv]); vendorBridgeRetry(\$pdo); echo 'ok';");
ok((int)$c->query("SELECT COUNT(*) FROM tenant_payments WHERE tenant_id = $tid")->fetchColumn() === 1
   && (int)$pdo->query("SELECT COUNT(*) FROM customer_contracts WHERE invoice_id = $inv")->fetchColumn() === 1
   && $c->query("SELECT subscription_ends_at FROM tenants WHERE id = $tid")->fetchColumn() === $expect, 'running everything again: one payment, one contract, same end date');
