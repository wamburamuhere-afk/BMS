<?php
/**
 * A company's BJP documents on its own Billing page (core/customer_documents.php):
 * official templates, clean addresses, real payments + proof, EFD receipts, emails, security.
 *   php tests/test_billing_customer_documents_cli.php
 * Data is 'CDTEST' customers/documents and 'cdtest*' tenants, removed at the end. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
const CD_KEY = 'c1d2e3f4a5b60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
putenv('TENANT_CRED_KEY=' . CD_KEY); putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');
$_SERVER['HTTP_HOST'] = 'localhost';
require_once "$root/roots.php";
require_once "$root/core/customer_documents.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

global $pdo;
$c = getControlPdo();
if (!vlLinkReady()) { ok(false, 'control DB has tenants.vendor_customer_id'); exit; }
cdEfdEnsureSchema($pdo);
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$mailFile = sys_get_temp_dir() . '/cdtest_mail_' . getmypid() . '.jsonl';
$tplBefore = ['invoice' => get_setting('customer_copy_template_invoice', 'default'), 'quotation' => get_setting('customer_copy_template_quotation', 'default')];
$files = [];

$cleanup = function () use ($pdo, $c, &$files, $mailFile, $tplBefore) {
    $cust = $pdo->query("SELECT customer_id FROM customers WHERE customer_name LIKE 'CDTEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($cust) {
        $in = implode(',', array_map('intval', $cust));
        $inv = $pdo->query("SELECT invoice_id FROM invoices WHERE customer_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($inv) {
            $ii = implode(',', array_map('intval', $inv));
            $pay = $pdo->query("SELECT payment_id FROM payments WHERE invoice_id IN ($ii)")->fetchAll(PDO::FETCH_COLUMN);
            if ($pay) $pdo->exec("DELETE FROM payment_attachments WHERE payment_id IN (" . implode(',', array_map('intval', $pay)) . ")");
            $pdo->exec("DELETE FROM payments WHERE invoice_id IN ($ii)");
            $pdo->exec("DELETE FROM invoice_efd_receipts WHERE invoice_id IN ($ii)");
            $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ($ii)");
            $pdo->exec("DELETE FROM invoices WHERE invoice_id IN ($ii)");
        }
        $q = $pdo->query("SELECT sales_order_id FROM quotations WHERE customer_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($q) {
            $qi = implode(',', array_map('intval', $q));
            $pdo->exec("DELETE FROM quotation_items WHERE order_id IN ($qi)");
            $pdo->exec("DELETE FROM quotations WHERE sales_order_id IN ($qi)");
        }
        $pdo->exec("DELETE FROM customers WHERE customer_id IN ($in)");
    }
    $t = $c->query("SELECT id FROM tenants WHERE subdomain LIKE 'cdtest%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($t) $c->exec("DELETE FROM tenant_admin_log WHERE tenant_id IN (" . implode(',', array_map('intval', $t)) . ")");
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'cdtest%'");
    foreach ($files as $f) @unlink($f);
    @unlink($mailFile);
    foreach ($tplBefore as $k => $v) save_setting('customer_copy_template_' . $k, $v);
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

// ── Fixtures ────────────────────────────────────────────────────────────────
$mkCust = function (string $name, ?string $email) use ($pdo) {
    $pdo->prepare("INSERT INTO customers (customer_name, company_name, email, status) VALUES (?, ?, ?, 'active')")->execute([$name, $name, $email]);
    return (int)$pdo->lastInsertId();
};
$cust = $mkCust('CDTEST Shop', 'shop@example.com');
$other = $mkCust('CDTEST Other', 'other@example.com');
$unlinked = $mkCust('CDTEST Unlinked', 'nolink@example.com');
$mkTenant = function (string $sub, int $cid) use ($c) {
    $c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, status, vendor_customer_id, created_at)
                 VALUES (?, ?, 'none', 'none', 'x', 'owner@example.com', 'active', ?, NOW())")->execute(["CD $sub", $sub, $cid]);
    return (int)$c->lastInsertId();
};
$tid = $mkTenant('cdtest1', $cust);
$tidOther = $mkTenant('cdtest2', $other);

$mkInv = function (int $cid, string $num, string $status) use ($pdo, $uid) {
    $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date, subtotal, tax_amount, grand_total, paid_amount, balance_due, status, currency, created_by, approved_by, approved_at)
                   VALUES (?, ?, CURDATE(), CURDATE(), 100000, 0, 100000, 40000, 60000, ?, 'TZS', ?, ?, " . ($status === 'pending' ? 'NULL' : 'NOW()') . ")")
        ->execute([$num, $cid, $status, $uid, $uid]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoice_items (invoice_id, product_name, quantity, unit_price, line_total) VALUES (?, 'CDTEST Smart POS — Standard', 1, 100000, 100000)")->execute([$id]);
    return $id;
};
$inv = $mkInv($cust, 'CDTEST-INV-1', 'partial');
$invPending = $mkInv($cust, 'CDTEST-INV-2', 'pending');
$invOther = $mkInv($other, 'CDTEST-INV-3', 'approved');
$mkQt = function (int $cid, string $num, string $status) use ($pdo, $uid) {
    $pdo->prepare("INSERT INTO quotations (order_number, customer_id, order_date, quote_valid_until, subtotal, tax_amount, grand_total, status, currency, created_by, approved_by, approved_at)
                   VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 50000, 0, 50000, ?, 'TZS', ?, ?, " . ($status === 'pending' ? 'NULL' : 'NOW()') . ")")
        ->execute([$num, $cid, $status, $uid, $uid]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO quotation_items (order_id, product_name, quantity, unit_price, line_total) VALUES (?, 'CDTEST Smart POS — Pro', 1, 50000, 50000)")->execute([$id]);
    return $id;
};
$qt = $mkQt($cust, 'CDTEST-QT-1', 'approved');
$qtPending = $mkQt($cust, 'CDTEST-QT-2', 'pending');

// Files: a real PDF + PNG, plus a disguised HTML file and a path that escapes uploads/.
$dir = bmsUploadsDir('finance/invoices');
$put = function (string $name, string $bytes) use ($dir, &$files) { file_put_contents($dir . $name, $bytes); $files[] = $dir . $name; return bmsUploadsRel('finance/invoices') . $name; };
$pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$pdfPath = $put('CDTEST_proof_' . getmypid() . '.pdf', $pdfBytes);
$im = imagecreatetruecolor(4, 4); ob_start(); imagepng($im); $png = ob_get_clean();
$pngPath = $put('CDTEST_proof_' . getmypid() . '.png', $png);
$htmlPath = $put('CDTEST_evil_' . getmypid() . '.pdf', '<html><script>alert(1)</script></html>');

$mkPay = function (int $invId, int $cid, string $status, string $num) use ($pdo, $uid) {
    $pdo->prepare("INSERT INTO payments (payment_number, invoice_id, customer_id, payment_date, amount, payment_method, reference_number, status, created_by)
                   VALUES (?, ?, ?, CURDATE(), 40000, 'bank_transfer', 'CDTEST-REF', ?, ?)")->execute([$num, $invId, $cid, $status, $uid]);
    return (int)$pdo->lastInsertId();
};
$pay = $mkPay($inv, $cust, 'completed', 'CDTEST-PAY-1');
$payPending = $mkPay($inv, $cust, 'pending', 'CDTEST-PAY-2');
$payOther = $mkPay($invOther, $other, 'completed', 'CDTEST-PAY-3');
$att = function (int $payId, string $path, string $name) use ($pdo) {
    $pdo->prepare("INSERT INTO payment_attachments (payment_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, 'application/pdf', 10)")->execute([$payId, $name, $path]);
    return (int)$pdo->lastInsertId();
};
$attPdf = $att($pay, $pdfPath, 'Bank slip');
$attPng = $att($pay, $pngPath, 'M-Pesa screenshot');
$attEvil = $att($pay, $htmlPath, 'evil');
$attEscape = $att($pay, '../includes/config.php', 'escape');
$attPendingPay = $att($payPending, $pdfPath, 'not completed');
$attOther = $att($payOther, $pdfPath, 'other customer');

$efdDir = bmsUploadsDir('finance/efd');
file_put_contents($efdDir . 'CDTEST_efd_' . getmypid() . '.pdf', $pdfBytes); $files[] = $efdDir . 'CDTEST_efd_' . getmypid() . '.pdf';
$pdo->prepare("INSERT INTO invoice_efd_receipts (invoice_id, receipt_number, receipt_date, file_name, file_path, file_type, file_size, uploaded_by) VALUES (?, 'EFD-001', CURDATE(), 'efd.pdf', ?, 'application/pdf', 10, ?)")
    ->execute([$inv, bmsUploadsRel('finance/efd') . 'CDTEST_efd_' . getmypid() . '.pdf', $uid]);
$efd = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO invoice_efd_receipts (invoice_id, receipt_number, file_name, file_path, file_type, status) VALUES (?, 'EFD-DEL', 'x.pdf', ?, 'application/pdf', 'deleted')")
    ->execute([$inv, bmsUploadsRel('finance/efd') . 'CDTEST_efd_' . getmypid() . '.pdf']);
$efdDeleted = (int)$pdo->lastInsertId();

// ── Helpers: the company's own server, with BJP's gateway answering in-process ──
function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'cdt_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return (string)$out;
}
function companyRun(int $tid, string $body, bool $admin = true): string {
    global $root, $uid;
    return runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php'; putenv('TENANT_CRED_KEY=" . CD_KEY . "'); putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');
        \$GLOBALS['__bms_tenant'] = ['id' => $tid];
        \$GLOBALS['__pl_transport'] = function (\$body, \$h) {
            require_once '$root/core/platform_gateway.php'; global \$pdo;
            \$keep = \$GLOBALS['__bms_tenant']; unset(\$GLOBALS['__bms_tenant']); putenv('BMS_VENDOR_CONTEXT=1');
            [\$code, \$o] = pgHandle(\$pdo, \$body, \$h);
            putenv('BMS_VENDOR_CONTEXT'); \$GLOBALS['__bms_tenant'] = \$keep;
            return \$o;
        };
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = " . ($admin ? 1 : 2) . "; \$_SESSION['is_admin'] = " . ($admin ? 'true' : 'false') . "; \$_SESSION['user_lang'] = 'en';
        $body");
}
function companyApi(int $tid, array $req): array {
    global $root;
    $o = companyRun($tid, "\$_GET = \$_REQUEST = " . var_export($req, true) . ";
        ob_start(); include '$root/api/my_subscription.php'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);");
    return json_decode(trim($o), true) ?? ['success' => false, 'message' => $o];
}
// Output travels base64-encoded: a Windows pipe stops at byte 0x1A, which would cut binary files.
function companyPage(int $tid, string $kind, int $id, bool $admin = true): string {
    global $root;
    return (string)base64_decode(trim(companyRun($tid, "\$GLOBALS['__sd_route'] = ['kind' => '$kind', 'id' => $id];
        ob_start(); register_shutdown_function(function () { echo base64_encode((string)ob_get_clean()); });
        include '$root/app/constant/settings/billing_document.php';", $admin)));
}
function vendorRun(string $code): string {
    global $root;
    return runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
        putenv('TENANT_CRED_KEY=" . CD_KEY . "'); putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz'); putenv('BMS_VENDOR_CONTEXT=1');
        require_once '$root/core/customer_documents.php'; global \$pdo; $code");
}

section('A. documents list: ids, payments, receipts — own customer only');
$r = companyApi($tid, ['action' => 'documents']);
$invNums = array_column($r['invoices'] ?? [], 'number');
ok(!empty($r['success']) && in_array('CDTEST-INV-1', $invNums, true), 'approved invoice listed');
ok(!in_array('CDTEST-INV-2', $invNums, true) && !in_array('CDTEST-INV-3', $invNums, true), 'pending invoice and another customer\'s invoice not listed');
$row = array_values(array_filter($r['invoices'] ?? [], fn($d) => $d['number'] === 'CDTEST-INV-1'))[0] ?? [];
ok(($row['view_url'] ?? '') !== '' && str_ends_with($row['view_url'], "/subscription/invoice/$inv"), 'invoice opens on the company\'s own clean address /subscription/invoice/<id>');
$json = json_encode($r);
ok(strpos($json, 'customer_document.php') === false && strpos($json, 'view_token') === false && strpos($json, 'api/platform') === false, 'no BJP address or token handed to the browser');
$pays = $r['payments'] ?? [];
ok(count($pays) === 1 && $pays[0]['number'] === 'CDTEST-PAY-1' && (float)$pays[0]['amount'] === 40000.0, 'Payments: the completed payment only (not the pending one, not another customer\'s)');
ok(count($pays[0]['proofs'] ?? []) === 4 && str_ends_with((string)$pays[0]['proofs'][0]['url'], "/subscription/payment-proof/$attPdf"), 'each proof of payment has its own clean address');
ok(str_ends_with((string)($pays[0]['invoice_url'] ?? ''), "/subscription/invoice/$inv"), 'payment links to its invoice');
$rcs = $r['receipts'] ?? [];
ok(count($rcs) === 1 && $rcs[0]['number'] === 'EFD-001' && str_ends_with((string)$rcs[0]['url'], "/subscription/receipt/$efd"), 'Receipts: the active EFD receipt (deleted one hidden) on a clean address');
$q = array_column($r['quotations'] ?? [], 'number');
ok(in_array('CDTEST-QT-1', $q, true) && !in_array('CDTEST-QT-2', $q, true), 'approved quotation listed, pending one not');

section('B. official template, chosen under Color Setting (Default unless changed)');
save_setting('customer_copy_template_invoice', 'default'); save_setting('customer_copy_template_quotation', 'default');
$h = companyPage($tid, 'invoice', $inv);
ok(strpos($h, 'CDTEST-INV-1') !== false && stripos($h, '<title>Tax Invoice') !== false, 'invoice opens as BJP\'s Default print template');
ok(strpos($h, 'Unauthorized') === false && strpos($h, 'Access Denied') === false && stripos($h, 'Database Error') === false, 'rendered without a BJP staff login');
preg_match_all('/<img\b[^>]*\bsrc\s*=\s*"([^"]*)"/i', $h, $im);
ok(!array_filter($im[1], fn($s) => $s !== '' && !str_starts_with($s, 'data:')), 'every image is embedded (no link back to BJP\'s server)');
save_setting('customer_copy_template_invoice', 'onyx');
$h = companyPage($tid, 'invoice', $inv);
ok(strpos($h, get_setting('print_template_color_inv_onyx', '#1c1c1c')) !== false && strpos($h, 'CDTEST-INV-1') !== false, 'switching to Onyx: the customer now gets the Onyx template');
save_setting('customer_copy_template_invoice', 'no-such-template');
ok(cdTemplateKey('invoice') === 'default', 'an unknown template key falls back to Default');
save_setting('customer_copy_template_quotation', 'meadow');
$h = companyPage($tid, 'quotation', $qt);
ok(strpos($h, 'CDTEST-QT-1') !== false && strpos($h, get_setting('print_template_color_qt_meadow', '#2f7d4f')) !== false, 'quotation opens in the chosen Meadow template');
$logged = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE description LIKE '%CDTEST-QT-1%'")->fetchColumn();
ok($logged === 0, 'a customer copy does not write a staff "printed" log line');
save_setting('customer_copy_template_quotation', 'default');

section('C. refusals: other customer, not approved, not admin');
ok(strpos(companyPage($tid, 'invoice', $invOther), 'CDTEST-INV-3') === false, 'another customer\'s invoice id does not open');
ok(strpos(companyPage($tid, 'invoice', $invPending), 'CDTEST-INV-2') === false, 'a pending invoice does not open');
ok(strpos(companyPage($tid, 'quotation', $qtPending), 'CDTEST-QT-2') === false, 'a pending quotation does not open');
ok(strpos(companyPage($tidOther, 'invoice', $inv), 'CDTEST-INV-1') === false, 'the other company cannot open this company\'s invoice');
$h = companyPage($tid, 'invoice', $inv, false);
ok(strpos($h, 'CDTEST-INV-1') === false && stripos($h, 'company admin') !== false, 'non-admin staff are refused');

section('D. files: proof, receipt — checked by owner, status and real content');
ok(companyPage($tid, 'payment-proof', $attPdf) === $pdfBytes, 'proof of payment (PDF) served byte-for-byte on the company\'s address');
$gotPng = companyPage($tid, 'payment-proof', $attPng);
ok($gotPng === $png, 'proof of payment (PNG) served' . ($gotPng === $png ? '' : ' — got ' . strlen($gotPng) . ' bytes vs ' . strlen($png) . ': ' . substr(bin2hex($gotPng), 0, 40) . ' / ' . substr(bin2hex($png), 0, 40)));
ok(companyPage($tid, 'receipt', $efd) === $pdfBytes, 'EFD receipt served');
ok(strpos(companyPage($tid, 'payment-proof', $attEvil), '<script>') === false, 'an HTML file disguised as .pdf is never served');
ok(strpos(companyPage($tid, 'payment-proof', $attEscape), 'DB_') === false && stripos(companyPage($tid, 'payment-proof', $attEscape), 'not found') !== false, 'a stored path escaping uploads/ is refused');
ok(stripos(companyPage($tid, 'payment-proof', $attPendingPay), 'not found') !== false, 'proof on a payment that is not completed is refused');
ok(stripos(companyPage($tid, 'payment-proof', $attOther), 'not found') !== false, 'another customer\'s proof is refused');
ok(stripos(companyPage($tid, 'receipt', $efdDeleted), 'not found') !== false, 'a removed receipt is refused');
ok(cdCustomerFile($pdo, 'evil', $attPdf, $cust) === null, 'an unknown file kind returns nothing');

section('E. clean address routing');
$route = runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; global \$routes;
    echo isset(\$routes['subscription-document']) && is_file(\$routes['subscription-document']) ? 'route-ok' : 'route-missing';");
ok(strpos($route, 'route-ok') !== false, 'subscription-document route points at an existing page');
$src = file_get_contents("$root/roots.php");
ok(strpos($src, "#^subscription/(invoice|quotation|payment-proof|receipt|contract)/([1-9][0-9]{0,18})$#") !== false, 'only /subscription/<known kind>/<positive number> matches');
ok(!is_file("$root/api/platform/customer_document.php"), 'the old BJP-address viewer is gone');

section('F. emails to the company');
@unlink($mailFile);
$o = vendorRun("define('CUSTOMER_DOC_MAIL_CAPTURE_FILE', " . var_export($mailFile, true) . ");
    cdNotifyIfLinked(\$pdo, 'invoice', $inv); cdNotifyIfLinked(\$pdo, 'quotation', $qt); cdNotifyIfLinked(\$pdo, 'receipt', $inv);
    cdNotifyIfLinked(\$pdo, 'invoice', " . $mkInv($unlinked, 'CDTEST-INV-4', 'approved') . ");");
$mails = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents($mailFile))));
ok(count($mails) === 3, 'three emails: invoice, quotation, EFD receipt (customer with no company gets none) — got ' . count($mails) . trim($o));
ok(($mails[0]['to'] ?? '') === 'shop@example.com' && strpos($mails[0]['subject'] ?? '', 'CDTEST-INV-1') !== false, 'sent to the customer\'s email, naming the invoice');
ok(strpos($mails[0]['html'] ?? '', 'https://cdtest1.bms.bjptechnologies.co.tz/subscription#billing-invoices') !== false, 'links to the company\'s own Billing page, no PDF attached');
ok(strpos($mails[1]['html'] ?? '', '#billing-quotations') !== false && strpos($mails[2]['html'] ?? '', '#billing-receipts') !== false, 'quotation and receipt emails open the right tab');
$o = runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; require_once '$root/core/customer_documents.php'; global \$pdo;
    define('CUSTOMER_DOC_MAIL_CAPTURE_FILE', " . var_export($mailFile . '.x', true) . "); cdNotifyIfLinked(\$pdo, 'invoice', $inv); echo is_file(" . var_export($mailFile . '.x', true) . ") ? 'sent' : 'none';");
ok(strpos($o, 'none') !== false, 'outside BJP\'s install (a company\'s own system) no email is sent');
foreach (['api/account/approve_invoice.php' => "cdNotifyIfLinked(\$pdo, 'invoice'", 'api/account/approve_quotation.php' => "cdNotifyIfLinked(\$pdo, 'quotation'",
          'api/account/upload_efd_receipt.php' => "cdNotifyIfLinked(\$pdo, 'receipt'"] as $f => $needle) {
    ok(strpos(file_get_contents("$root/$f"), $needle) !== false, "$f emails the company");
}

section('G. EFD upload and proof upload refuse bad input');
$upload = function (int $invId, string $file, string $name) use ($root, $uid) {
    $o = vendorRun("\$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['user_lang'] = 'en';
        \$_POST = ['invoice_id' => $invId, '_csrf' => 'tok']; \$_SESSION['csrf_token'] = 'tok';
        \$_FILES = ['file' => ['name' => " . var_export($name, true) . ", 'tmp_name' => " . var_export($file, true) . ", 'error' => 0, 'size' => filesize(" . var_export($file, true) . ")]];
        ob_start(); include '$root/api/account/upload_efd_receipt.php'; \$o = ob_get_clean(); echo substr(\$o, strpos(\$o, '{'));");
    return json_decode(trim($o), true) ?? ['success' => false, 'message' => $o];
};
$evilTmp = $dir . basename($htmlPath);
$r = $upload($inv, $evilTmp, 'receipt.pdf');
ok(empty($r['success']) && stripos($r['message'] ?? '', 'PDF, JPG or PNG') !== false, 'EFD upload: HTML renamed to .pdf is refused');
$r = $upload($invPending, $dir . basename($pdfPath), 'receipt.pdf');
ok(empty($r['success']) && stripos($r['message'] ?? '', 'Approve the invoice') !== false, 'EFD upload: refused on an invoice not yet approved');
$r = $upload($mkInv($unlinked, 'CDTEST-INV-5', 'approved'), $dir . basename($pdfPath), 'receipt.pdf');
ok(empty($r['success']) && stripos($r['message'] ?? '', 'not linked') !== false, 'EFD upload: refused for a customer with no company');
$before = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id = $inv")->fetchColumn();
$o = runPhp("\$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
    \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['user_lang'] = 'en';
    \$_POST = ['invoice_id' => $inv, 'amount' => 1000, 'payment_date' => date('Y-m-d'), 'payment_method' => 'cash', 'status' => 'pending', '_csrf' => 'tok']; \$_SESSION['csrf_token'] = 'tok';
    \$_FILES = ['attachments' => ['name' => ['slip.pdf'], 'tmp_name' => [" . var_export($evilTmp, true) . "], 'error' => [0], 'size' => [100], 'type' => ['application/pdf']]];
    ob_start(); include '$root/api/account/record_payment.php'; \$o = ob_get_clean(); echo substr(\$o, strpos(\$o, '{'));");
$r = preg_match('/\{"success".*\}/s', $o, $jm) ? (json_decode($jm[0], true) ?? []) : [];
$after = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id = $inv")->fetchColumn();
ok(empty($r['success']) && stripos($r['message'] ?? '', 'not allowed') !== false && $after === $before, 'payment with a disguised proof file is refused and nothing is saved' . (' — ' . substr(trim($o), 0, 300) . " before=$before after=$after"));

section('H. Billing page + words');
$html = companyRun($tid, "\$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_lang'] = 'sw';
    ob_start(); include '$root/app/constant/settings/billing.php'; echo ob_get_clean();");
ok(strpos($html, 'id="tabReceipts"') !== false && strpos($html, 'id="tblReceipts"') !== false && strpos($html, 'Fatal error') === false, 'Billing page has the Receipts tab');
ok(strpos($html, 'Risiti') !== false && strpos($html, 'Uthibitisho wa malipo') !== false, 'new tab words in Swahili');
$sw = include "$root/lang/sw.php";
$srcs = '';
foreach (['core/customer_documents.php', 'app/constant/settings/billing_document.php', 'api/account/upload_efd_receipt.php', 'api/account/delete_efd_receipt.php',
          'app/constant/settings/billing.php', 'app/constant/settings/color_settings.php'] as $f) $srcs .= file_get_contents("$root/$f");
preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'\\)/", $srcs, $m);
$missing = array_values(array_filter(array_unique($m[1]), fn($s) => preg_match('/\p{L}/u', $s) && !isset($sw[stripslashes($s)])));
ok(!$missing, 'every new t() string has a Swahili line' . ($missing ? ' — missing: ' . implode(' | ', array_slice($missing, 0, 12)) : ''));
