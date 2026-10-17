<?php
/**
 * Subscription contracts after payment (customer_subscription_plan.md Phase 6)
 *   php tests/test_subscription_contracts_cli.php
 *
 * A: wiring (CI).  B: template → paid invoice → contract PDF → BJP signs → customer gets a
 * one-time signing link → final copy, on the platform root. Data is 'SC-TEST' / 'sctest*'
 * and removed (documents, files, signatures, ledger rows, control rows). Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
const SC_KEY = 'b1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f91';
putenv('TENANT_CRED_KEY=' . SC_KEY);
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
$src = fn($f) => file_get_contents("$root/$f");
ok(strpos($src('core/vendor_bridge.php'), 'scCreateForInvoice(') !== false, 'the bridge makes the contract after applying a payment');
ok(strpos($src('core/subscription_contracts.php'), 'generateLetterPdf(') !== false, 'contracts are real PDFs on the letterhead');
ok(strpos($src('core/subscription_contracts.php'), "'signer_type' ") === false && strpos($src('core/subscription_contracts.php'), "signer_type = 'internal' AND status = 'signed'") !== false,
   'BJP\'s signature is read from the existing E-Signatures records (nothing is auto-signed)');
ok(strpos($src('core/platform_gateway.php'), "'contract_sign_link'") !== false && strpos($src('api/my_subscription.php'), "'contract_sign'") !== false, 'customer signs from My Subscription');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B ──────────────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/vendor_bridge.php";
require_once "$root/core/subscription_contracts.php";
require_once "$root/core/platform_gateway.php";
require_once "$root/core/payment_source.php";
sptEnsureSchema($pdo); ob_start(); sptEnsureLineColumns($pdo); ob_end_clean(); vbEnsureOutbox($pdo); scEnsureSchema($pdo);
$c = getControlPdo();
$uid  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid  = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$bank = (int)(cashBankAccounts($pdo)[0]['account_id'] ?? 0);
if (!$uid || !$wid || !$bank || !vlLinkReady()) { echo "SKIP B: needs admin, warehouse, bank account, upgraded control DB\n"; exit; }

$cleanup = function () use ($pdo, $c) {
    $in = fn($a) => $a ? implode(',', array_map('intval', $a)) : '0';
    $inv = $in($pdo->query("SELECT invoice_id FROM invoices WHERE notes = 'SC-TEST'")->fetchAll(PDO::FETCH_COLUMN));
    $refs = $pdo->query("SELECT invoice_number FROM invoices WHERE invoice_id IN ($inv)")->fetchAll(PDO::FETCH_COLUMN);
    if ($refs) $c->exec("DELETE FROM vendor_bridge_alerts WHERE invoice_ref IN (" . implode(',', array_map([$c, 'quote'], $refs)) . ")");
    // contract documents (+ signed copies) and their signatures/tokens/files
    $docs = $pdo->query("SELECT document_id FROM customer_contracts WHERE invoice_id IN ($inv)")->fetchAll(PDO::FETCH_COLUMN);
    $docs = array_merge($docs, $pdo->query("SELECT id FROM documents WHERE document_name LIKE 'SC-TEST%' OR description LIKE '%SC-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    $all = $docs;
    if ($docs) {
        $sig = $pdo->query("SELECT id, signed_document_id FROM document_signatures WHERE document_id IN (" . $in($docs) . ")")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sig as $s) if ($s['signed_document_id']) $all[] = $s['signed_document_id'];
        $sig2 = $pdo->query("SELECT id, signed_document_id FROM document_signatures WHERE document_id IN (" . $in($all) . ")")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sig2 as $s) if ($s['signed_document_id']) $all[] = $s['signed_document_id'];
        $sids = $in(array_merge(array_column($sig, 'id'), array_column($sig2, 'id')));
        $pdo->exec("DELETE FROM document_signature_tokens WHERE signature_id IN ($sids)");
        $pdo->exec("DELETE FROM document_signatures WHERE id IN ($sids)");
        foreach ($pdo->query("SELECT file_path FROM documents WHERE id IN (" . $in($all) . ")")->fetchAll(PDO::FETCH_COLUMN) as $fp) {
            if ($fp && strpos($fp, 'uploads/documents/') === 0) @unlink(ROOT_DIR . '/' . $fp);
        }
        $pdo->exec("DELETE FROM documents WHERE id IN (" . $in($all) . ")");
    }
    $pdo->exec("DELETE FROM customer_contracts WHERE invoice_id IN ($inv)");
    try { $pdo->exec("DELETE FROM notifications WHERE title LIKE '%SC-TEST%'"); } catch (Throwable $e) {}
    $pay = $in($pdo->query("SELECT DISTINCT p.payment_id FROM payments p WHERE p.invoice_id IN ($inv)")->fetchAll(PDO::FETCH_COLUMN));
    $nums = $pdo->query("SELECT payment_number FROM payments WHERE payment_id IN ($pay)")->fetchAll(PDO::FETCH_COLUMN);
    if ($nums) $pdo->exec("DELETE FROM bank_transactions WHERE reference_number IN (" . implode(',', array_map([$pdo, 'quote'], $nums)) . ")");
    $pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                 WHERE (je.entity_type IN ('payment','payment_void') AND je.entity_id IN ($pay)) OR (je.entity_type IN ('invoice','invoice_cogs','invoice_void') AND je.entity_id IN ($inv))");
    $pdo->exec("DELETE FROM journal_entries WHERE (entity_type IN ('payment','payment_void') AND entity_id IN ($pay)) OR (entity_type IN ('invoice','invoice_cogs','invoice_void') AND entity_id IN ($inv))");
    $pdo->exec("DELETE FROM payment_allocations WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM payments WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM vendor_bridge_outbox WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ($inv)");
    try { $pdo->exec("DELETE FROM workflow_signatures WHERE entity_type = 'invoice' AND entity_id IN ($inv)"); } catch (Throwable $e) {}
    $pdo->exec("DELETE FROM invoices WHERE invoice_id IN ($inv)");
    $p = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'SC-TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($p) { $pi = $in($p); foreach (['service_price_terms', 'vendor_subscription_services', 'products'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN ($pi)"); }
    $pdo->exec("DELETE FROM document_templates WHERE template_name LIKE 'SC-TEST%'");
    foreach ($pdo->query("SELECT customer_id, ledger_account_id FROM customers WHERE customer_name LIKE 'SC-TEST%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['ledger_account_id']) $pdo->exec("DELETE FROM accounts WHERE account_id = " . (int)$r['ledger_account_id']);
        $pdo->exec("DELETE FROM customers WHERE customer_id = " . (int)$r['customer_id']);
    }
    $t = $in($c->query("SELECT id FROM tenants WHERE subdomain LIKE 'sctest%'")->fetchAll(PDO::FETCH_COLUMN));
    $c->exec("DELETE FROM tenant_payments WHERE tenant_id IN ($t)");
    $c->exec("DELETE FROM tenant_admin_log WHERE tenant_id IN ($t)");
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'sctest%'");
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'sc_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
const SC_ENV = "putenv('TENANT_CRED_KEY=" . SC_KEY . "'); putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');";
function lastJson(string $out): ?array { $j = json_decode($out, true); if (is_array($j)) return $j; $p = strrpos($out, "\n{"); return $p !== false ? json_decode(substr($out, $p + 1), true) : null; }
function call(string $file, array $post): array {
    global $root, $uid;
    $out = runPhp("\$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . SC_ENV . "
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_POST = \$_REQUEST = \$_GET = " . var_export($post, true) . "; \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);");
    return lastJson($out) ?? ['success' => false, 'message' => $out];
}
function gateway(int $tid, array $body): array {
    global $root;
    $json = json_encode($body); $ts = time(); $sig = (string)plSignature($tid, $ts, $json);
    $out = runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . SC_ENV . " require_once '$root/core/platform_gateway.php'; global \$pdo;
        [\$code, \$o] = pgHandle(\$pdo, " . var_export($json, true) . ", ['X-Platform-Tenant' => '$tid', 'X-Platform-Ts' => '$ts', 'X-Platform-Sig' => " . var_export($sig, true) . "]);
        echo json_encode(['code' => \$code] + \$o);");
    return lastJson($out) ?? ['code' => 0, 'message' => $out];
}

// Template, service, customer, company.
$pdo->prepare("INSERT INTO document_templates (template_name, subject, content, use_letterhead, signature_align, is_active, created_by)
               VALUES ('SC-TEST Subscription agreement', 'SC-TEST BMS Subscription Agreement',
                       '<p>This agreement is between {{company_name}} and <b>{{customer_name}}</b> for {{service_name}} ({{period}}), from {{period_start}} to {{period_end}}, paid {{amount}} on invoice {{invoice_number}} for {{web_address}}.</p>',
                       1, 'left', 1, ?)")->execute([$uid]);
$tpl = (int)$pdo->lastInsertId();
$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SC-TEST BMS Simple POS', 'is_subscription' => '1', 'contract_template_id' => (string)$tpl,
    'price_terms' => json_encode([['term_label' => '6 Months', 'duration_value' => 6, 'duration_unit' => 'month', 'price' => 45000, 'is_default' => 1]])]);
$sub = (int)($r['product_id'] ?? 0);
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SC-TEST No Contract', 'is_subscription' => '1',
    'price_terms' => json_encode([['term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 10000, 'is_default' => 1]])]);
$subNoTpl = (int)($r['product_id'] ?? 0);
ok($sub && vssContractTemplate($pdo, $sub) === $tpl && vssContractTemplate($pdo, $subNoTpl) === null, 'contract template saved on the subscription service (and optional)');
$termA = (int)sptForProduct($pdo, $sub)[0]['term_id'];
$termB = (int)sptForProduct($pdo, $subNoTpl)[0]['term_id'];
$pdo->beginTransaction();
$cust = vlCreateCustomer($pdo, ['customer_name' => 'SC-TEST Duka', 'email' => 'sctest@example.com', 'tin_number' => '111-222-333'], $uid);
$other = vlCreateCustomer($pdo, ['customer_name' => 'SC-TEST Other'], $uid);
$pdo->commit();
$c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, status, trial_ends_at)
             VALUES ('SC-TEST Duka', 'sctest1', 'none', 'none', 'x', 'sctest1@example.com', 'trial', DATE_ADD(NOW(), INTERVAL 3 DAY))")->execute();
$t1 = (int)$c->lastInsertId();
vlSetTenantCustomer($t1, $cust, 'test', 'SC-TEST Duka');

$invoice = function (int $pid, int $termId, float $price) use ($cust, $wid, $pdo) {
    call('api/account/save_invoice.php', ['customer_id' => $cust, 'invoice_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'),
        'currency' => 'TZS', 'warehouse_id' => $wid, 'notes' => 'SC-TEST', 'items' => [[
            'product_id' => $pid, 'product_name' => 'SC-TEST service', 'quantity' => 1, 'unit' => 'job', 'unit_price' => $price,
            'tax_rate' => 0, 'discount_percent' => 0, 'description' => '', 'price_term_id' => $termId]]]);
    return (int)$pdo->query("SELECT MAX(invoice_id) FROM invoices WHERE notes = 'SC-TEST'")->fetchColumn();
};
$pay = fn(int $inv, float $amt) => call('api/account/record_payment.php', ['invoice_id' => $inv, 'amount' => $amt, 'payment_date' => date('Y-m-d'),
    'payment_method' => 'bank_transfer', 'status' => 'completed', 'received_into_account_id' => $bank]);

section('B1. payment → contract');
$inv = $invoice($sub, $termA, 45000);
$r = $pay($inv, 45000);
$k = $pdo->query("SELECT * FROM customer_contracts WHERE invoice_id = $inv")->fetch(PDO::FETCH_ASSOC) ?: [];
ok(!empty($r['success']) && $k, 'paying the invoice created the contract');
$doc = $k ? $pdo->query("SELECT * FROM documents WHERE id = " . (int)$k['document_id'])->fetch(PDO::FETCH_ASSOC) : [];
ok(($doc['source'] ?? '') === 'subscription_contract' && ($doc['file_type'] ?? '') === 'pdf', 'stored as a PDF document in Documents');
$file = $doc ? ROOT_DIR . '/' . $doc['file_path'] : '';
ok($file && is_file($file) && substr((string)file_get_contents($file, false, null, 0, 5), 0, 4) === '%PDF', 'the PDF file exists');
$body = (string)($doc['content'] ?? '');
ok(strpos($body, 'SC-TEST Duka') !== false && strpos($body, '6 Months') !== false && strpos($body, '45,000.00 TZS') !== false
   && strpos($body, (string)$k['invoice_ref']) !== false && strpos($body, 'sctest1') !== false && strpos($body, '{{') === false,
   'template tokens filled: customer, period, amount, invoice, web address');
$tenantEnd = $c->query("SELECT subscription_ends_at FROM tenants WHERE id = $t1")->fetchColumn();
ok($k && $k['period_end'] === $tenantEnd && (int)$k['tenant_id'] === $t1, 'contract period = the period the company was extended by');
ok(scState($pdo, $k)['state'] === 'awaiting_bjp', 'first waiting for BJP\'s signature');

section('B2. once per invoice; none without a template');
$r = scCreateForInvoice($pdo, $inv, ['id' => $t1, 'subdomain' => 'sctest1'], null, null);
ok($r['created'] === false && (int)$pdo->query("SELECT COUNT(*) FROM customer_contracts WHERE invoice_id = $inv")->fetchColumn() === 1, 'never twice for one invoice');
$inv2 = $invoice($subNoTpl, $termB, 10000);
$pay($inv2, 10000);
ok((int)$pdo->query("SELECT COUNT(*) FROM customer_contracts WHERE invoice_id = $inv2")->fetchColumn() === 0, 'a service without a contract template makes no contract (the payment still applies)');

section('B3. customer cannot sign before BJP');
$r = gateway($t1, ['action' => 'contracts']);
ok(!empty($r['success']) && count($r['contracts']) === 1 && $r['contracts'][0]['state'] === 'awaiting_bjp' && $r['contracts'][0]['view_token'] === null,
   'My Subscription lists it as being prepared, with nothing to open yet');
ok(empty(gateway($t1, ['action' => 'contract_sign_link', 'contract_id' => (int)$k['contract_id']])['success']), 'no signing link until BJP has signed');

section('B4. BJP signs (E-Signatures) → customer signs');
// What save_signed_pdf.php records when a BJP user signs: a signed copy + an internal signature row.
$copy = 'uploads/documents/' . bin2hex(random_bytes(8)) . '_scbjp.pdf';
copy($file, ROOT_DIR . '/' . $copy);
$pdo->prepare("INSERT INTO documents (document_name, description, file_path, original_filename, file_size, file_type, uploaded_by, access_level)
               VALUES ('SC-TEST signed by BJP', 'SC-TEST', ?, 'signed.pdf', ?, 'pdf', ?, 'private')")->execute([$copy, filesize($file), $uid]);
$bjpDoc = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO document_signatures (document_id, requested_by, signed_by, signer_type, status, signed_document_id, signed_at)
               VALUES (?, ?, ?, 'internal', 'signed', ?, NOW())")->execute([(int)$k['document_id'], $uid, $uid, $bjpDoc]);
ok(scState($pdo, $k)['state'] === 'awaiting_customer', 'now waiting for the customer');
$r = gateway($t1, ['action' => 'contracts']);
ok(!empty($r['contracts'][0]['view_token']), 'the customer can open BJP\'s signed copy');
$l1 = gateway($t1, ['action' => 'contract_sign_link', 'contract_id' => (int)$k['contract_id']]);
ok(!empty($l1['success']) && preg_match('/sign-document\?token=([0-9a-f]{64})$/', (string)$l1['sign_url'], $m1), 'signing link issued for the existing signing page');
$sig = $pdo->query("SELECT * FROM document_signatures WHERE document_id = $bjpDoc AND signer_type = 'external'")->fetch(PDO::FETCH_ASSOC);
ok($sig && $sig['status'] === 'pending' && $sig['signer_email'] === 'sctest@example.com', 'external signature request for the customer\'s email, on BJP\'s signed copy');
$tokRow = $pdo->prepare("SELECT * FROM document_signature_tokens WHERE token_hash = ?");
$tokRow->execute([hash('sha256', $m1[1] ?? '')]);
ok((bool)$tokRow->fetch(), 'only the token\'s hash is stored');
$l2 = gateway($t1, ['action' => 'contract_sign_link', 'contract_id' => (int)$k['contract_id']]);
preg_match('/token=([0-9a-f]{64})$/', (string)($l2['sign_url'] ?? ''), $m2);
$tokRow->execute([hash('sha256', $m1[1] ?? '')]);
$first = $tokRow->fetch(PDO::FETCH_ASSOC);
ok(!empty($m2[1]) && $first && strtotime($first['expires_at']) <= time(), 'asking again gives a new link and the old one stops working');
ok((int)$pdo->query("SELECT COUNT(*) FROM document_signatures WHERE document_id = $bjpDoc AND signer_type = 'external'")->fetchColumn() === 1, 'still one signature request');
$page = runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . SC_ENV . " \$_GET = ['token' => '{$m2[1]}']; ob_start(); include '$root/sign_document.php'; echo ob_get_clean();");
ok(strpos($page, $copy) !== false || strpos($page, 'docFrame') !== false, 'the signing page opens the document with the new link');

section('B5. signed by both');
$final = 'uploads/documents/' . bin2hex(random_bytes(8)) . '_scfinal.pdf';
copy($file, ROOT_DIR . '/' . $final);
$pdo->prepare("INSERT INTO documents (document_name, description, file_path, original_filename, file_size, file_type, uploaded_by, access_level)
               VALUES ('SC-TEST signed by both', 'SC-TEST', ?, 'final.pdf', ?, 'pdf', ?, 'private')")->execute([$final, filesize($file), $uid]);
$finalDoc = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE document_signatures SET status = 'signed', signed_document_id = ?, signed_at = NOW() WHERE id = ?")->execute([$finalDoc, (int)$sig['id']]);
$st = scState($pdo, $k);
ok($st['state'] === 'signed' && $st['final_document_id'] === $finalDoc, 'contract signed by both');
ok(empty(gateway($t1, ['action' => 'contract_sign_link', 'contract_id' => (int)$k['contract_id']])['success']), 'no more signing links once signed');
$r = gateway($t1, ['action' => 'contracts']);
$pdf = runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . SC_ENV . "
    \$_GET = ['t' => " . var_export((string)$r['contracts'][0]['view_token'], true) . "]; ob_start(); include '$root/api/platform/customer_document.php'; echo ob_get_clean();");
ok(strpos($pdf, '%PDF') === 0, 'the customer\'s copy opens as the final signed PDF');
$bad = runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . SC_ENV . "
    require_once '$root/core/platform_link.php'; \$_GET = ['t' => plDocToken('contract', " . (int)$k['contract_id'] . ", $other)]; ob_start(); include '$root/api/platform/customer_document.php'; echo ob_get_clean();");
ok(strpos($bad, '%PDF') !== 0, "another customer's token cannot open it");

section('B6. BJP customer page');
$r = call('api/platform/customer_platform_link.php', ['customer_id' => $cust]);
$r = lastJson(runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . SC_ENV . "
    \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_GET = \$_REQUEST = ['customer_id' => '$cust'];
    ob_start(); include '$root/api/platform/customer_platform_link.php'; echo ob_get_clean();")) ?? [];
ok(!empty($r['success']) && count($r['contracts'] ?? []) === 1 && $r['contracts'][0]['state'] === 'signed', 'the Platform company card lists the contract and its state');
