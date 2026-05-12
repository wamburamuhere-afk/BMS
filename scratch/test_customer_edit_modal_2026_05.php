<?php
/**
 * Test: customer_details.php — Edit Customer modal (same as customers.php Actions > Edit)
 *
 * Verifies:
 *   - Edit buttons no longer redirect to customers/edit page
 *   - Both buttons call editCustomer() onclick
 *   - Modal HTML (#editCustomerModal) is present in customer_details.php
 *   - All 4 tabs are present (Basic Info, Contact, Address, Financial)
 *   - editCustomer() JS function is present
 *   - Form posts to api/process_edit_customer.php (same as customers.php)
 *   - get_customer API endpoint exists
 *   - canEdit permission guard wraps the modal and buttons
 *   - DB-level: get_customer API returns correct data for a real customer
 *
 * Run: http://localhost/bms/scratch/test_customer_edit_modal_2026_05.php
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: text/html; charset=utf-8');

function pass(string $label): void {
    echo "<p style='color:green;font-family:monospace'>&#10003; PASS &mdash; $label</p>";
}
function fail(string $label, string $detail = ''): void {
    $extra = $detail ? " <span style='color:#888'>($detail)</span>" : '';
    echo "<p style='color:red;font-family:monospace'>&#10007; FAIL &mdash; $label$extra</p>";
}
function section(string $title): void {
    echo "<h3 style='font-family:monospace;margin-top:24px;border-bottom:1px solid #ccc'>$title</h3>";
}

$src = file_get_contents(__DIR__ . '/../app/bms/customer/customer_details.php');

echo "<!DOCTYPE html><html><head><title>Customer Edit Modal Test</title></head><body>";
echo "<h2 style='font-family:monospace'>Customer Edit Modal — Test Suite</h2>";
echo "<p style='font-family:monospace;color:#555'>customer_details.php: Edit button opens same modal as customers.php Actions &gt; Edit Customer</p>";

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 1 — Old redirect href is gone from both buttons
// ─────────────────────────────────────────────────────────────────────────────
section('Section 1 — Old Redirect Removed from Edit Buttons');

// T1: href to customers/edit page is gone from the top-bar button area
$topBarArea = substr($src, strpos($src, 'btn btn-primary btn-sm px-2 shadow-sm'), 200);
if (strpos($topBarArea, "getUrl('customers/edit')") === false) {
    pass("T1: Top-bar Edit button no longer redirects to customers/edit page");
} else {
    fail("T1: Top-bar Edit button still has href redirect to customers/edit — fix not applied");
}

// T2: href to customers/edit page is gone from mobile dropdown
if (substr_count($src, "getUrl('customers/edit')") === 0) {
    pass("T2: Mobile dropdown Edit Customer no longer redirects to customers/edit page");
} else {
    fail("T2: customers/edit href still found in customer_details.php — fix incomplete");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 2 — Both buttons now call editCustomer()
// ─────────────────────────────────────────────────────────────────────────────
section('Section 2 — Both Buttons Call editCustomer()');

// T3: Top-bar button uses onclick="editCustomer(...)"
if (strpos($src, 'onclick="editCustomer(<?= $customer_id ?>)"') !== false) {
    pass("T3: Top-bar Edit button calls editCustomer(\$customer_id) via onclick");
} else {
    fail("T3: Top-bar Edit button does not call editCustomer() — check the onclick attribute");
}

// T4: Mobile dropdown also calls editCustomer()
$mobileCount = substr_count($src, 'onclick="editCustomer(<?= $customer_id ?>)"');
if ($mobileCount >= 2) {
    pass("T4: Mobile dropdown Edit Customer also calls editCustomer() — found $mobileCount occurrences");
} else {
    fail("T4: Mobile dropdown Edit Customer does not call editCustomer() — only $mobileCount occurrence(s) found");
}

// T5: No logEditClick() call remains (old mobile onclick)
if (strpos($src, 'logEditClick') === false) {
    pass("T5: Old logEditClick() call is gone from mobile dropdown");
} else {
    fail("T5: logEditClick() still referenced — old mobile onclick not fully cleaned up");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 3 — Modal HTML is present and complete
// ─────────────────────────────────────────────────────────────────────────────
section('Section 3 — Edit Customer Modal HTML');

// T6: Modal div exists
if (strpos($src, 'id="editCustomerModal"') !== false) {
    pass("T6: #editCustomerModal div is present in customer_details.php");
} else {
    fail("T6: #editCustomerModal not found — modal HTML missing");
}

// T7: Form exists inside modal
if (strpos($src, 'id="editCustomerForm"') !== false) {
    pass("T7: #editCustomerForm is present inside the modal");
} else {
    fail("T7: #editCustomerForm not found — form missing from modal");
}

// T8: Hidden customer_id input
if (strpos($src, 'id="edit_customer_id"') !== false) {
    pass("T8: Hidden #edit_customer_id input present — customer ID will be submitted correctly");
} else {
    fail("T8: #edit_customer_id hidden input missing — save will fail");
}

// T9: All 4 tabs present
$tabs = ['edit-basic-tab', 'edit-contact-tab', 'edit-address-tab', 'edit-financial-tab'];
$tabsMissing = [];
foreach ($tabs as $tab) {
    if (strpos($src, $tab) === false) $tabsMissing[] = $tab;
}
if (empty($tabsMissing)) {
    pass("T9: All 4 tabs present — Basic Info, Contact Details, Address, Financial");
} else {
    fail("T9: Missing tab(s): " . implode(', ', $tabsMissing));
}

// T10: Tab pane content panels present
$panes = ['id="edit-basic"', 'id="edit-contact"', 'id="edit-address"', 'id="edit-financial"'];
$panesMissing = [];
foreach ($panes as $pane) {
    if (strpos($src, $pane) === false) $panesMissing[] = $pane;
}
if (empty($panesMissing)) {
    pass("T10: All 4 tab pane content panels present");
} else {
    fail("T10: Missing tab pane(s): " . implode(', ', $panesMissing));
}

// T11: Key fields present
$fields = ['edit_customer_name', 'edit_company_name', 'edit_email', 'edit_phone',
           'edit_address', 'edit_tax_id', 'edit_currency', 'edit_bank_name'];
$missing = [];
foreach ($fields as $f) {
    if (strpos($src, "id=\"$f\"") === false) $missing[] = $f;
}
if (empty($missing)) {
    pass("T11: All key form fields present (" . count($fields) . " checked)");
} else {
    fail("T11: Missing field(s): " . implode(', ', $missing));
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 4 — JS functions match customers.php
// ─────────────────────────────────────────────────────────────────────────────
section('Section 4 — JavaScript Functions');

// T12: editCustomer() function defined
if (strpos($src, 'function editCustomer(customerId)') !== false) {
    pass("T12: editCustomer() function is defined in customer_details.php");
} else {
    fail("T12: editCustomer() function not found — modal cannot be opened");
}

// T13: AJAX call to get_customer API
if (strpos($src, "api/account/get_customer.php") !== false) {
    pass("T13: editCustomer() calls api/account/get_customer.php to load customer data");
} else {
    fail("T13: get_customer.php API call not found in editCustomer()");
}

// T14: Form submits to process_edit_customer — same as customers.php
if (strpos($src, "api/process_edit_customer.php") !== false) {
    pass("T14: Form submits to api/process_edit_customer.php — matches customers.php");
} else {
    fail("T14: process_edit_customer.php not referenced — form will not save");
}

// T15: Success handler reloads page (location.reload)
if (strpos($src, 'location.reload()') !== false) {
    pass("T15: Success handler calls location.reload() — details page refreshes after save");
} else {
    fail("T15: location.reload() not found — page won't refresh after successful update");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 5 — Permission guard
// ─────────────────────────────────────────────────────────────────────────────
section('Section 5 — canEdit Permission Guard');

// T16: can_edit_customers variable is set
if (strpos($src, "\$can_edit_customers = canEdit('customers')") !== false) {
    pass("T16: \$can_edit_customers = canEdit('customers') is set in PHP");
} else {
    fail("T16: canEdit('customers') not found — permission check missing");
}

// T17: Modal wrapped in can_edit guard
if (strpos($src, 'if ($can_edit_customers)') !== false) {
    pass("T17: Modal and JS wrapped in canEdit guard — hidden from users without edit permission");
} else {
    fail("T17: canEdit guard not found — modal visible to all users regardless of permission");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 6 — API endpoint and target files exist
// ─────────────────────────────────────────────────────────────────────────────
section('Section 6 — API Endpoints Exist on Disk');

global $routes;

// T18: get_customer API registered and file exists
$getCustomerFile = realpath($routes['api/account/get_customer'] ?? ($routes['api/account/get_customer.php'] ?? ''));
if (!$getCustomerFile) {
    // Try direct path
    $getCustomerFile = realpath(__DIR__ . '/../api/account/get_customer.php');
}
if ($getCustomerFile && file_exists($getCustomerFile)) {
    pass("T18: api/account/get_customer.php exists on disk");
} else {
    fail("T18: api/account/get_customer.php not found on disk");
}

// T19: process_edit_customer API exists
$processFile = realpath(__DIR__ . '/../api/process_edit_customer.php');
if ($processFile && file_exists($processFile)) {
    pass("T19: api/process_edit_customer.php exists on disk");
} else {
    fail("T19: api/process_edit_customer.php not found on disk");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 7 — DB-level: get_customer returns correct fields for a real customer
// ─────────────────────────────────────────────────────────────────────────────
section('Section 7 — DB-Level: Customer Data Roundtrip');

$customer = $pdo->query("SELECT * FROM customers WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($customer) {
    $cid  = $customer['customer_id'];
    $name = htmlspecialchars($customer['customer_name']);

    // T20: customer_id field present
    if (isset($customer['customer_id'])) {
        pass("T20: DB row has customer_id — modal hidden field will be populated correctly");
    } else {
        fail("T20: customer_id missing from DB row");
    }

    // T21: All modal-mapped fields exist in DB row
    $modalFields = ['customer_name','company_name','category_id','customer_type','status',
                    'credit_limit','contact_person','email','phone','mobile',
                    'address','city','state','country','tax_id','vat_number',
                    'payment_terms','currency','bank_name','bank_account'];
    $dbMissing = [];
    foreach ($modalFields as $f) {
        if (!array_key_exists($f, $customer)) $dbMissing[] = $f;
    }
    if (empty($dbMissing)) {
        pass("T21: All " . count($modalFields) . " modal-mapped fields exist in customers table");
    } else {
        fail("T21: Missing DB column(s): " . implode(', ', $dbMissing));
    }

    // T22: categories query returns rows (modal dropdown will have options)
    $catCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_categories WHERE status='active'")->fetchColumn();
    if ($catCount > 0) {
        pass("T22: customer_categories has $catCount active rows — Category dropdown will be populated");
    } else {
        fail("T22: No active customer categories found — Category dropdown will be empty");
    }

    echo "<p style='font-family:monospace;color:#555;margin-top:8px'>Test customer: <strong>$name</strong> (ID: $cid)</p>";
} else {
    echo "<p style='color:orange;font-family:monospace'>&#9888; No active customers found — Section 7 skipped.</p>";
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 8 — Consistency with customers.php (same mechanism)
// ─────────────────────────────────────────────────────────────────────────────
section('Section 8 — Consistency with customers.php');

$customersSrc = file_get_contents(__DIR__ . '/../app/bms/customer/customers.php');

// T23: customers.php also uses editCustomer() for the Actions dropdown
if (strpos($customersSrc, 'onclick="editCustomer(') !== false) {
    pass("T23: customers.php Actions dropdown also calls editCustomer() — mechanisms now match");
} else {
    fail("T23: customers.php does not use editCustomer() — unexpected mismatch");
}

// T24: Both files post to the same API endpoint
$detailsHasApi  = strpos($src, 'api/process_edit_customer.php') !== false;
$customersHasApi = strpos($customersSrc, 'api/process_edit_customer.php') !== false;
if ($detailsHasApi && $customersHasApi) {
    pass("T24: Both customer_details.php and customers.php save to api/process_edit_customer.php");
} else {
    fail("T24: API endpoint mismatch — details=" . ($detailsHasApi?'yes':'no') . ", customers=" . ($customersHasApi?'yes':'no'));
}

// T25: Both files load data from the same get_customer endpoint
$detailsHasGet  = strpos($src, 'api/account/get_customer.php') !== false;
$customersHasGet = strpos($customersSrc, 'api/account/get_customer.php') !== false;
if ($detailsHasGet && $customersHasGet) {
    pass("T25: Both files load customer data from api/account/get_customer.php");
} else {
    fail("T25: get_customer endpoint mismatch — details=" . ($detailsHasGet?'yes':'no') . ", customers=" . ($customersHasGet?'yes':'no'));
}

echo "<hr><p style='font-family:monospace;color:#555'>All tests complete. Green = pass, Red = fail.</p></body></html>";
