<?php
/**
 * Customer & Supplier registration — Simple POS single-area form — CLI suite
 *   php tests/test_customer_supplier_simple_pos_cli.php
 *
 * 2026-09-17 request: collapse the 4-tab Add/Edit Customer and Add/Edit
 * Supplier modals to one single-area form when Simple POS is on and the new
 * superadmin "Advanced Customer"/"Advanced Supplier" overrides
 * (core/pos_nav.php::advancedCustomerEnabled()/advancedSupplierEnabled())
 * are off — same governance shape products_simple_pos_plan.md already
 * established for Products.
 *
 *   A. STATIC   — files lint clean; source wiring for both branches present;
 *                 the hidden-preserve inputs on Edit exist for every field
 *                 hidden from view, with ids matching the population JS.
 *   B. RENDERED — the real Add/Edit modals, three states each: Simple POS,
 *                 normal, and Simple POS + Advanced override (full 4-tab
 *                 form back) — for BOTH customers.php and suppliers.php.
 *   C. RUNTIME  — real end-to-end creation through api/add_customer.php /
 *                 api/add_supplier.php with exactly the payload the Simple
 *                 POS form's DOM would produce, verifying every default
 *                 lands correctly; then the critical data-integrity check —
 *                 an "advanced" record with rich fields (company name, full
 *                 address, TIN/VAT, bank details) is edited through the
 *                 Simple POS Edit form's payload shape (only name/phone
 *                 changed, every other field resubmitted UNCHANGED — exactly
 *                 what editCustomer()/editSupplier()'s hidden-preserve
 *                 inputs do in a real browser) and none of those advanced
 *                 fields are wiped.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function _csp_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'custsupp_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return (string)$out;
}
function _csp_set_settings(string $root, string $simple, string $advCust, string $advSupp): void {
    _csp_run_php("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($simple, true) . "); save_setting('pos_advanced_customer', " . var_export($advCust, true) . "); save_setting('pos_advanced_supplier', " . var_export($advSupp, true) . "); echo 'SAVED';");
}
function _csp_render(string $root, int $uid, string $file): string {
    return _csp_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        ob_start();
        include '$root/$file';
        echo ob_get_clean();
    ");
}
function _csp_post(string $root, int $uid, string $file, array $post): array {
    $postCode = var_export($post, true);
    $out = _csp_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_POST = $postCode;
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        ob_start();
        include '$root/$file';
        echo ob_get_clean();
    ");
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : ['success' => false, 'message' => 'NON_JSON_OUTPUT: ' . substr($out, 0, 300)];
}

$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
$wasSimple  = get_setting('pos_simple_mode', '0');
$wasAdvCust = get_setting('pos_advanced_customer', '0');
$wasAdvSupp = get_setting('pos_advanced_supplier', '0');
$cleanupSettings = function () use ($root, $wasSimple, $wasAdvCust, $wasAdvSupp) {
    _csp_set_settings($root, $wasSimple, $wasAdvCust, $wasAdvSupp);
};
register_shutdown_function($cleanupSettings);

$createdCustomerIds = [];
$createdSupplierIds = [];
$cleanupRecords = function () use ($pdo, &$createdCustomerIds, &$createdSupplierIds) {
    foreach ($createdCustomerIds as $id) { $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$id]); }
    foreach ($createdSupplierIds as $id) { $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?")->execute([$id]); }
};
register_shutdown_function($cleanupRecords);

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint clean');
foreach ([
    'app/bms/customer/customers.php',
    'app/bms/Suppliers/suppliers.php',
    'core/pos_nav.php',
    'core/tenant_admin.php',
    'actions/superadmin_tenant_advanced_customer.php',
    'actions/superadmin_tenant_advanced_supplier.php',
    'app/superadmin/tenant_view.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring — Customer');
$custSrc = src($root, 'app/bms/customer/customers.php');
has($custSrc, '$simpleCustomerForm = posSimpleModeEnabled() && !advancedCustomerEnabled();', 'gating flag ANDs Simple POS with the Advanced Customer override');
has($custSrc, "<?php if (\$simpleCustomerForm): ?>", 'the simple branch is a real PHP conditional');
has($custSrc, 'input type="hidden" name="customer_type" value="individual"', 'Create defaults customer_type to individual (not the API\'s own "business" default)');
foreach (['edit_company_name','edit_acronym','edit_category_id','edit_customer_type','edit_status','edit_year','edit_project_id',
          'edit_default_price_group_id','edit_contact_person','edit_contact_title','edit_email','edit_company_email','edit_mobile',
          'edit_fax','edit_website','edit_country','edit_state','edit_city','edit_ward','edit_village','edit_postal_code',
          'edit_address','edit_postal_address','edit_tax_id','edit_vat_number','edit_default_wht_rate_id','edit_payment_terms',
          'edit_currency','edit_bank_name','edit_bank_account','edit_bank_address'] as $id) {
    has($custSrc, "id=\"$id\"", "Edit hidden-preserve field present: #$id");
}

section('2b. Source wiring — Supplier');
$suppSrc = src($root, 'app/bms/Suppliers/suppliers.php');
has($suppSrc, '$simpleSupplierForm = posSimpleModeEnabled() && !advancedSupplierEnabled();', 'gating flag ANDs Simple POS with the Advanced Supplier override');
has($suppSrc, "<?php if (\$simpleSupplierForm): ?>", 'the simple branch is a real PHP conditional');
has($suppSrc, 'id="bank_name" name="bank_name"', 'Bank Name stays VISIBLE on the simple Create form (a shop genuinely pays its suppliers)');
has($suppSrc, 'id="bank_account" name="bank_account"', 'Bank Account stays VISIBLE on the simple Create form');
foreach (['edit_company_name','edit_acronym','edit_supplier_type','edit_supplier_year','edit_category_id','edit_status',
          'edit_project_id','edit_credit_limit','edit_contact_person','edit_contact_title','edit_email','edit_company_email',
          'edit_mobile','edit_fax','edit_website','edit_country','edit_state','edit_city','edit_ward','edit_village',
          'edit_postal_code','edit_address','edit_postal_address','edit_tax_id','edit_vat_number','edit_default_wht_rate_id',
          'edit_payment_terms','edit_currency','edit_bank_address'] as $id) {
    has($suppSrc, "id=\"$id\"", "Edit hidden-preserve field present: #$id");
}
hasnt($suppSrc, 'id="edit_bank_name" name="bank_name">', 'Bank Name has NO hidden-preserve duplicate on Edit (it\'s already the real visible field there)');

// ─────────────────────────────────────────────────────────────────────────
if ($adminUid <= 0) {
    fail('No admin user found — skipping rendered + runtime sections');
} else {
    section('3. Rendered — Customer modal across 3 states');
    _csp_set_settings($root, '1', '0', '0');
    $simpleHtml = _csp_render($root, $adminUid, 'app/bms/customer/customers.php');
    (!str_contains($simpleHtml, 'Fatal error')) ? pass('Simple POS: renders with no PHP fatal') : fail('Simple POS: PHP fatal — ' . substr($simpleHtml, 0, 300));
    (str_contains($simpleHtml, 'id="customer_name"') && !str_contains($simpleHtml, 'id="customerTabs"'))
        ? pass('Simple POS: single-area Add form, no tabs')
        : fail('Simple POS: tabs still present or form missing');

    _csp_set_settings($root, '0', '0', '0');
    $normalHtml = _csp_render($root, $adminUid, 'app/bms/customer/customers.php');
    (str_contains($normalHtml, 'id="customerTabs"')) ? pass('Normal tenant: 4-tab form unchanged') : fail('Normal tenant: tabs missing — regression risk');

    _csp_set_settings($root, '1', '1', '0');
    $advHtml = _csp_render($root, $adminUid, 'app/bms/customer/customers.php');
    (str_contains($advHtml, 'id="customerTabs"')) ? pass('Simple POS + Advanced Customer: full 4-tab form restored') : fail('Advanced Customer override did not restore the full form');

    section('3b. Rendered — Supplier modal across 3 states');
    _csp_set_settings($root, '1', '0', '0');
    $simpleSHtml = _csp_render($root, $adminUid, 'app/bms/Suppliers/suppliers.php');
    (!str_contains($simpleSHtml, 'Fatal error')) ? pass('Simple POS: renders with no PHP fatal') : fail('Simple POS: PHP fatal — ' . substr($simpleSHtml, 0, 300));
    (str_contains($simpleSHtml, 'id="supplier_name"') && !str_contains($simpleSHtml, 'id="addSupplierTabs"'))
        ? pass('Simple POS: single-area Add form, no tabs')
        : fail('Simple POS: tabs still present or form missing');

    _csp_set_settings($root, '0', '0', '0');
    $normalSHtml = _csp_render($root, $adminUid, 'app/bms/Suppliers/suppliers.php');
    (str_contains($normalSHtml, 'id="addSupplierTabs"')) ? pass('Normal tenant: 4-tab form unchanged') : fail('Normal tenant: tabs missing — regression risk');

    _csp_set_settings($root, '1', '0', '1');
    $advSHtml = _csp_render($root, $adminUid, 'app/bms/Suppliers/suppliers.php');
    (str_contains($advSHtml, 'id="addSupplierTabs"')) ? pass('Simple POS + Advanced Supplier: full 4-tab form restored') : fail('Advanced Supplier override did not restore the full form');
    (str_contains($advSHtml, 'id="saAdvancedCustomerEnabled"') === false) ? pass('(sanity) customer page HTML never leaked into supplier render') : null;

    // ─────────────────────────────────────────────────────────────────────
    section('4. Runtime — Customer: create via the exact Simple POS payload');
    _csp_set_settings($root, '1', '0', '0');
    $nameA = 'CustSimpleTest ' . bin2hex(random_bytes(3));
    $res = _csp_post($root, $adminUid, 'api/add_customer.php', [
        'customer_name' => $nameA, 'phone' => '+255700000001', 'credit_limit' => '50000',
        'description' => 'Simple POS test note', 'customer_type' => 'individual',
    ]);
    ($res['success'] ?? false) === true ? pass('add_customer.php succeeds with the minimal Simple POS payload') : fail('create failed: ' . ($res['message'] ?? json_encode($res)));
    $custIdA = (int)($res['customer_id'] ?? 0);
    if ($custIdA > 0) $createdCustomerIds[] = $custIdA;
    if ($custIdA > 0) {
        $row = $pdo->query("SELECT * FROM customers WHERE customer_id = $custIdA")->fetch(PDO::FETCH_ASSOC);
        ($row['customer_name'] ?? '') === $nameA ? pass('name stored correctly') : fail('name mismatch');
        ($row['phone'] ?? '') === '+255700000001' ? pass('phone stored correctly') : fail('phone mismatch');
        (float)($row['credit_limit'] ?? -1) === 50000.0 ? pass('credit_limit stored correctly') : fail('credit_limit mismatch: ' . ($row['credit_limit'] ?? 'null'));
        ($row['customer_type'] ?? '') === 'individual' ? pass('customer_type defaults to individual, not business') : fail('customer_type default is wrong: ' . ($row['customer_type'] ?? 'null'));
        ($row['status'] ?? '') === 'active' ? pass('status defaults to active') : fail('status default is wrong');
        ($row['country'] ?? '') === 'Tanzania' ? pass('country defaults to Tanzania') : fail('country default is wrong: ' . ($row['country'] ?? 'null'));
    }

    section('4b. Runtime — Customer: editing in Simple POS never wipes advanced fields');
    // Create an "advanced" customer with rich data first (as if Advanced Customer was on, or pre-dating Simple Mode).
    $nameB = 'CustAdvTest ' . bin2hex(random_bytes(3));
    _csp_set_settings($root, '0', '0', '0'); // full form for the initial create
    $resB = _csp_post($root, $adminUid, 'api/add_customer.php', [
        'customer_name' => $nameB, 'company_name' => 'Rich Co Ltd', 'phone' => '+255700000002',
        'email' => 'rich@example.com', 'tax_id' => 'TIN-999888', 'bank_name' => 'CRDB', 'bank_account' => '0123456789',
        'city' => 'Ilala', 'state' => 'Dar es Salaam', 'credit_limit' => '10000',
    ]);
    ($resB['success'] ?? false) === true ? pass('a rich "advanced" customer record was created for the preservation test') : fail('setup create failed: ' . ($resB['message'] ?? json_encode($resB)));
    $custIdB = (int)($resB['customer_id'] ?? 0);
    if ($custIdB > 0) $createdCustomerIds[] = $custIdB;

    if ($custIdB > 0) {
        _csp_set_settings($root, '1', '0', '0'); // Simple POS for the edit itself
        $before = $pdo->query("SELECT * FROM customers WHERE customer_id = $custIdB")->fetch(PDO::FETCH_ASSOC);
        $newPhone = '+255700099999';
        // Exactly what the hidden-preserve Simple POS Edit form submits: only
        // name/phone/credit_limit/description are meant to change; every
        // other field rides along UNCHANGED (this is what editCustomer()'s
        // population code puts into those hidden inputs in a real browser).
        $editRes = _csp_post($root, $adminUid, 'api/process_edit_customer.php', [
            'customer_id' => $custIdB, 'customer_name' => $before['customer_name'], 'phone' => $newPhone,
            'credit_limit' => $before['credit_limit'], 'description' => $before['notes'] ?? '',
            'customer_type' => $before['customer_type'], 'company_name' => $before['company_name'],
            'status' => $before['status'], 'email' => $before['email'], 'tax_id' => $before['tax_id'],
            'bank_name' => $before['bank_name'], 'bank_account' => $before['bank_account'],
            'city' => $before['city'], 'state' => $before['state'], 'country' => $before['country'],
        ]);
        ($editRes['success'] ?? false) === true ? pass('process_edit_customer.php succeeds with the Simple POS hidden-preserve payload') : fail('edit failed: ' . ($editRes['message'] ?? json_encode($editRes)));
        $after = $pdo->query("SELECT * FROM customers WHERE customer_id = $custIdB")->fetch(PDO::FETCH_ASSOC);
        ($after['phone'] ?? '') === $newPhone ? pass('the one field actually being changed (phone) DID change') : fail('phone did not update');
        ($after['company_name'] ?? '') === 'Rich Co Ltd' ? pass('company_name PRESERVED through a Simple POS edit') : fail('company_name was WIPED — data-loss regression: ' . ($after['company_name'] ?? 'null'));
        ($after['tax_id'] ?? '') === 'TIN-999888' ? pass('tax_id PRESERVED through a Simple POS edit') : fail('tax_id was WIPED — data-loss regression');
        ($after['bank_name'] ?? '') === 'CRDB' ? pass('bank_name PRESERVED through a Simple POS edit') : fail('bank_name was WIPED — data-loss regression');
        ($after['city'] ?? '') === 'Ilala' ? pass('city PRESERVED through a Simple POS edit') : fail('city was WIPED — data-loss regression');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('5. Runtime — Supplier: create via the exact Simple POS payload');
    _csp_set_settings($root, '1', '0', '0');
    $snameA = 'SuppSimpleTest ' . bin2hex(random_bytes(3));
    $sres = _csp_post($root, $adminUid, 'api/add_supplier.php', [
        'supplier_name' => $snameA, 'phone' => '+255700000003',
        'bank_name' => 'NMB', 'bank_account' => '99887766', 'description' => 'Simple POS test note',
    ]);
    ($sres['success'] ?? false) === true ? pass('add_supplier.php succeeds with the minimal Simple POS payload') : fail('create failed: ' . ($sres['message'] ?? json_encode($sres)));
    $suppIdA = (int)($sres['supplier_id'] ?? 0);
    if ($suppIdA > 0) $createdSupplierIds[] = $suppIdA;
    if ($suppIdA > 0) {
        $srow = $pdo->query("SELECT * FROM suppliers WHERE supplier_id = $suppIdA")->fetch(PDO::FETCH_ASSOC);
        ($srow['supplier_name'] ?? '') === $snameA ? pass('name stored correctly') : fail('name mismatch');
        ($srow['bank_name'] ?? '') === 'NMB' ? pass('bank_name stored correctly (visible field even in Simple mode)') : fail('bank_name mismatch');
        ($srow['bank_account'] ?? '') === '99887766' ? pass('bank_account stored correctly') : fail('bank_account mismatch');
        ($srow['status'] ?? '') === 'active' ? pass('status defaults to active') : fail('status default is wrong');
    }

    section('5b. Runtime — Supplier: editing in Simple POS never wipes advanced fields');
    $snameB = 'SuppAdvTest ' . bin2hex(random_bytes(3));
    _csp_set_settings($root, '0', '0', '0');
    $sresB = _csp_post($root, $adminUid, 'api/add_supplier.php', [
        'supplier_name' => $snameB, 'company_name' => 'Rich Supplier Ltd', 'phone' => '+255700000004',
        'tax_id' => 'TIN-777666', 'bank_name' => 'CRDB', 'bank_account' => '55443322',
        'city' => 'Kinondoni', 'state' => 'Dar es Salaam',
    ]);
    ($sresB['success'] ?? false) === true ? pass('a rich "advanced" supplier record was created for the preservation test') : fail('setup create failed: ' . ($sresB['message'] ?? json_encode($sresB)));
    $suppIdB = (int)($sresB['supplier_id'] ?? 0);
    if ($suppIdB > 0) $createdSupplierIds[] = $suppIdB;

    if ($suppIdB > 0) {
        _csp_set_settings($root, '1', '0', '0');
        $sbefore = $pdo->query("SELECT * FROM suppliers WHERE supplier_id = $suppIdB")->fetch(PDO::FETCH_ASSOC);
        $newSPhone = '+255700088888';
        $seditRes = _csp_post($root, $adminUid, 'api/update_supplier.php', [
            'supplier_id' => $suppIdB, 'supplier_name' => $sbefore['supplier_name'], 'phone' => $newSPhone,
            'bank_name' => $sbefore['bank_name'], 'bank_account' => $sbefore['bank_account'],
            'company_name' => $sbefore['company_name'], 'status' => $sbefore['status'],
            'tax_id' => $sbefore['tax_id'], 'city' => $sbefore['city'], 'state' => $sbefore['state'],
            'country' => $sbefore['country'],
        ]);
        ($seditRes['success'] ?? false) === true ? pass('update_supplier.php succeeds with the Simple POS hidden-preserve payload') : fail('edit failed: ' . ($seditRes['message'] ?? json_encode($seditRes)));
        $safter = $pdo->query("SELECT * FROM suppliers WHERE supplier_id = $suppIdB")->fetch(PDO::FETCH_ASSOC);
        // update_supplier.php's own clean_phone() strips non-digits (pre-existing,
        // unrelated to this feature) — compare against its normalized form.
        ($safter['phone'] ?? '') === preg_replace('/[^0-9]/', '', $newSPhone) ? pass('the one field actually being changed (phone) DID change') : fail('phone did not update: ' . ($safter['phone'] ?? 'null'));
        ($safter['company_name'] ?? '') === 'Rich Supplier Ltd' ? pass('company_name PRESERVED through a Simple POS edit') : fail('company_name was WIPED — data-loss regression: ' . ($safter['company_name'] ?? 'null'));
        ($safter['tax_id'] ?? '') === 'TIN-777666' ? pass('tax_id PRESERVED through a Simple POS edit') : fail('tax_id was WIPED — data-loss regression');
        ($safter['city'] ?? '') === 'Kinondoni' ? pass('city PRESERVED through a Simple POS edit') : fail('city was WIPED — data-loss regression');
    }
}

$cleanupRecords();
$cleanupSettings();
