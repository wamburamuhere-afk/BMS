<?php
/**
 * Test: customer_details.php — "Create New Order" and "New Invoice" button routes
 *
 * Verifies:
 *   - The broken routes ('sales/create_order', 'invoice/create_invoice') are gone
 *   - The correct routes ('sales_order_create', 'invoice_create') are in place
 *   - Both routes are registered in roots.php
 *   - Both target files exist on disk
 *   - Both target files read $_GET['customer'] to pre-fill the customer
 *
 * Run: http://localhost/bms/scratch/test_customer_view_buttons_2026_05.php
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

echo "<!DOCTYPE html><html><head><title>Customer View Buttons Test</title></head><body>";
echo "<h2 style='font-family:monospace'>Customer View Buttons — Test Suite</h2>";
echo "<p style='font-family:monospace;color:#555'>customer_details.php: Create New Order + New Invoice button route fix</p>";

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 1 — Route registry: correct slugs are registered, broken ones are not
// ─────────────────────────────────────────────────────────────────────────────
section('Section 1 — Route Registry (roots.php)');

global $routes;

// T1: correct slug for New Order IS registered
if (isset($routes['sales_order_create'])) {
    pass("T1: Route 'sales_order_create' is registered in roots.php");
} else {
    fail("T1: Route 'sales_order_create' is NOT registered in roots.php");
}

// T2: correct slug for New Invoice IS registered
if (isset($routes['invoice_create'])) {
    pass("T2: Route 'invoice_create' is registered in roots.php");
} else {
    fail("T2: Route 'invoice_create' is NOT registered in roots.php");
}

// T3: old broken slug for New Order is NOT registered
if (!isset($routes['sales/create_order'])) {
    pass("T3: Broken route 'sales/create_order' is NOT in routes (correct — would 404)");
} else {
    fail("T3: Broken route 'sales/create_order' unexpectedly exists — it should not be used");
}

// T4: old broken slug for New Invoice is NOT registered
if (!isset($routes['invoice/create_invoice'])) {
    pass("T4: Broken route 'invoice/create_invoice' is NOT in routes (correct — would 404)");
} else {
    fail("T4: Broken route 'invoice/create_invoice' unexpectedly exists — it should not be used");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 2 — customer_details.php source: correct routes in HTML, broken gone
// ─────────────────────────────────────────────────────────────────────────────
section('Section 2 — customer_details.php Source Code');

$detailsFile = __DIR__ . '/../app/bms/customer/customer_details.php';
$src = file_get_contents($detailsFile);

// T5: correct 'sales_order_create' slug present
if (strpos($src, "getUrl('sales_order_create')") !== false) {
    pass("T5: 'getUrl(\'sales_order_create\')' found in customer_details.php");
} else {
    fail("T5: 'getUrl(\'sales_order_create\')' NOT found — Create New Order button still broken");
}

// T6: correct 'invoice_create' slug present
if (strpos($src, "getUrl('invoice_create')") !== false) {
    pass("T6: 'getUrl(\'invoice_create\')' found in customer_details.php");
} else {
    fail("T6: 'getUrl(\'invoice_create\')' NOT found — New Invoice button still broken");
}

// T7: broken 'sales/create_order' slug is gone
if (strpos($src, "getUrl('sales/create_order')") === false) {
    pass("T7: Broken slug 'sales/create_order' is gone from customer_details.php");
} else {
    fail("T7: Broken slug 'sales/create_order' still present in customer_details.php — fix not applied");
}

// T8: broken 'invoice/create_invoice' slug is gone
if (strpos($src, "getUrl('invoice/create_invoice')") === false) {
    pass("T8: Broken slug 'invoice/create_invoice' is gone from customer_details.php");
} else {
    fail("T8: Broken slug 'invoice/create_invoice' still present in customer_details.php — fix not applied");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 3 — Target files exist on disk
// ─────────────────────────────────────────────────────────────────────────────
section('Section 3 — Target Files Exist');

$orderCreateFile   = realpath($routes['sales_order_create'] ?? '');
$invoiceCreateFile = realpath($routes['invoice_create'] ?? '');

// T9: sales_order_create.php exists
if ($orderCreateFile && file_exists($orderCreateFile)) {
    pass("T9: sales_order_create.php exists at " . basename(dirname($orderCreateFile)) . '/' . basename($orderCreateFile));
} else {
    fail("T9: sales_order_create.php not found on disk");
}

// T10: invoice_create.php exists
if ($invoiceCreateFile && file_exists($invoiceCreateFile)) {
    pass("T10: invoice_create.php exists at " . basename(dirname($invoiceCreateFile)) . '/' . basename($invoiceCreateFile));
} else {
    fail("T10: invoice_create.php not found on disk");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 4 — Target files read $_GET['customer'] to pre-fill customer
// ─────────────────────────────────────────────────────────────────────────────
section('Section 4 — Target Files Accept ?customer= Parameter');

if ($orderCreateFile) {
    $orderSrc = file_get_contents($orderCreateFile);
    // T11: sales_order_create reads customer from GET
    if (strpos($orderSrc, "\$_GET['customer']") !== false) {
        pass("T11: sales_order_create.php reads \$_GET['customer'] — customer will be pre-selected");
    } else {
        fail("T11: sales_order_create.php does not read \$_GET['customer'] — customer won't pre-fill");
    }
} else {
    fail("T11: Cannot check — sales_order_create.php not found");
}

if ($invoiceCreateFile) {
    $invoiceSrc = file_get_contents($invoiceCreateFile);
    // T12: invoice_create reads customer from GET
    if (strpos($invoiceSrc, "\$_GET['customer']") !== false) {
        pass("T12: invoice_create.php reads \$_GET['customer'] — customer will be pre-selected");
    } else {
        fail("T12: invoice_create.php does not read \$_GET['customer'] — customer won't pre-fill");
    }
} else {
    fail("T12: Cannot check — invoice_create.php not found");
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 5 — DB-level: generated URLs are valid for a real customer
// ─────────────────────────────────────────────────────────────────────────────
section('Section 5 — Generated URLs with a Real Customer ID');

$customer = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($customer) {
    $cid = $customer['customer_id'];
    $name = htmlspecialchars($customer['customer_name']);

    $orderUrl   = getUrl('sales_order_create') . '?customer=' . $cid;
    $invoiceUrl = getUrl('invoice_create')      . '?customer=' . $cid;

    // T13: Order URL contains correct slug and customer id
    if (strpos($orderUrl, 'sales_order_create') !== false && strpos($orderUrl, "customer=$cid") !== false) {
        pass("T13: Create New Order URL correct → $orderUrl");
    } else {
        fail("T13: Create New Order URL malformed → $orderUrl");
    }

    // T14: Invoice URL contains correct slug and customer id
    if (strpos($invoiceUrl, 'invoice_create') !== false && strpos($invoiceUrl, "customer=$cid") !== false) {
        pass("T14: New Invoice URL correct → $invoiceUrl");
    } else {
        fail("T14: New Invoice URL malformed → $invoiceUrl");
    }

    echo "<p style='font-family:monospace;color:#555;margin-top:8px'>Test customer: <strong>$name</strong> (ID: $cid)</p>";
} else {
    echo "<p style='color:orange;font-family:monospace'>&#9888; No active customers found — Section 5 skipped.</p>";
}

// ─────────────────────────────────────────────────────────────────────────────
// SECTION 6 — customers.php consistency: same slug used there too
// ─────────────────────────────────────────────────────────────────────────────
section('Section 6 — Consistency with customers.php Actions Dropdown');

$customersSrc = file_get_contents(__DIR__ . '/../app/bms/customer/customers.php');

// T15: customers.php also uses sales_order_create (same as the fixed button)
if (strpos($customersSrc, 'sales_order_create') !== false) {
    pass("T15: customers.php Actions dropdown uses 'sales_order_create' — matches fixed customer_details.php button");
} else {
    fail("T15: customers.php does not use 'sales_order_create' — mismatch detected");
}

// T16: customers/view route maps to customer_details.php (verify same file)
if (isset($routes['customers/view']) && strpos($routes['customers/view'], 'customer_details.php') !== false) {
    pass("T16: Route 'customers/view' correctly maps to customer_details.php");
} else {
    fail("T16: Route 'customers/view' does not map to customer_details.php");
}

echo "<hr><p style='font-family:monospace;color:#555'>All tests complete. Green = pass, Red = fail.</p></body></html>";
