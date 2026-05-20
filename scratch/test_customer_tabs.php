<?php
/**
 * Test: Customer Details — Section Tabs (Update 31)
 * Covers: tab nav structure, pane wrappers, CSS, DataTable adjust JS,
 *         LPO tab conditional rendering, correct pane IDs.
 *
 * Run at: http://dev.bms.local/scratch/test_customer_tabs.php
 */

$pass = 0;
$fail = 0;
$results = [];

function ok(string $label): void {
    global $pass, $results;
    $pass++;
    $results[] = ['pass', $label];
}

function fail(string $label, string $detail = ''): void {
    global $fail, $results;
    $fail++;
    $results[] = ['fail', $label . ($detail ? " — $detail" : '')];
}

function has(string $label, string $haystack, string $needle): void {
    str_contains($haystack, $needle) ? ok($label) : fail($label, 'Expected: ' . substr($needle, 0, 100));
}

function hasNot(string $label, string $haystack, string $needle): void {
    !str_contains($haystack, $needle) ? ok($label) : fail($label, 'Should NOT contain: ' . substr($needle, 0, 100));
}

function checkSyntax(string $path): bool {
    $code = file_get_contents($path);
    if ($code === false) return false;
    try { token_get_all($code, TOKEN_PARSE); return true; }
    catch (ParseError $e) { return false; }
}

$root = dirname(__DIR__);
$src  = file_get_contents($root . '/app/bms/customer/customer_details.php');

// ── PHASE 1: PHP Syntax ───────────────────────────────────────────────────────
echo "<h3>Phase 1 — PHP Syntax</h3>";
checkSyntax($root . '/app/bms/customer/customer_details.php')
    ? ok('customer_details.php passes PHP syntax check')
    : fail('customer_details.php has syntax errors');

// ── PHASE 2: Tab Nav Structure ────────────────────────────────────────────────
echo "<h3>Phase 2 — Tab Navigation Buttons</h3>";

has('Tab nav ul exists with id="customerDetailTabs"', $src, 'id="customerDetailTabs"');
has('Tab nav uses nav-pills class', $src, 'class="nav nav-pills');
has('Sales Orders tab button exists', $src, 'data-bs-target="#pane-orders"');
has('Invoices tab button exists', $src, 'data-bs-target="#pane-invoices"');
has('LPOs tab button exists', $src, 'data-bs-target="#pane-lpos"');
has('System Info tab button exists', $src, 'data-bs-target="#pane-sysinfo"');
has('First tab (Sales Orders) is active by default', $src, 'data-bs-target="#pane-orders" type="button" role="tab"><i class="bi bi-cart-check');
has('Tabs use data-bs-toggle="pill"', $src, 'data-bs-toggle="pill"');

// LPO tab button is inside PHP conditional
$lpoTabButton = 'data-bs-target="#pane-lpos"';
$lpoCondition = 'if (!empty($customer_lpos) || $can_create_lpos):';
$lpoCondPos   = strpos($src, $lpoCondition);
$lpoTabPos    = strpos($src, $lpoTabButton);
// LPO tab button should appear AFTER the first LPO condition check
($lpoCondPos !== false && $lpoTabPos !== false && $lpoTabPos > $lpoCondPos)
    ? ok('LPO tab button appears inside PHP conditional')
    : fail('LPO tab button not inside PHP conditional');

// ── PHASE 3: Tab Content Panes ────────────────────────────────────────────────
echo "<h3>Phase 3 — Tab Content Panes</h3>";

has('tab-content wrapper exists', $src, 'id="customerDetailTabContent"');
has('Sales Orders pane exists with correct id', $src, 'id="pane-orders"');
has('Sales Orders pane is show active (default visible)', $src, 'tab-pane fade show active" id="pane-orders"');
has('Invoices pane exists', $src, 'id="pane-invoices"');
has('Invoices pane is fade (hidden by default)', $src, 'tab-pane fade" id="pane-invoices"');
has('LPOs pane exists', $src, 'id="pane-lpos"');
has('LPOs pane is fade (hidden by default)', $src, 'tab-pane fade" id="pane-lpos"');
has('System Info pane exists', $src, 'id="pane-sysinfo"');
has('System Info pane is fade (hidden by default)', $src, 'tab-pane fade" id="pane-sysinfo"');

// Verify closing comments exist (structure sanity)
has('pane-orders closing comment present', $src, '<!-- #pane-orders -->');
has('pane-invoices closing comment present', $src, '<!-- #pane-invoices -->');
has('pane-lpos closing comment present', $src, '<!-- #pane-lpos -->');
has('customerDetailTabContent closing comment present', $src, '<!-- #customerDetailTabContent -->');

// ── PHASE 4: Section Content Inside Correct Panes ─────────────────────────────
echo "<h3>Phase 4 — Section Content in Correct Panes</h3>";

// Extract each pane block and verify it contains the right section heading
function extractPane(string $src, string $paneId): string {
    $start = strpos($src, 'id="' . $paneId . '"');
    if ($start === false) return '';
    // Find closing comment as end marker
    $end = strpos($src, '<!-- #' . $paneId . ' -->', $start);
    if ($end === false) return substr($src, $start, 4000);
    return substr($src, $start, $end - $start);
}

$ordersPane   = extractPane($src, 'pane-orders');
$invoicesPane = extractPane($src, 'pane-invoices');
$lposPane     = extractPane($src, 'pane-lpos');
$sysinfoPane  = extractPane($src, 'pane-sysinfo');

has('Sales Order History section is inside pane-orders', $ordersPane, 'Sales Order History');
has('customerOrdersTable is inside pane-orders', $ordersPane, 'id="customerOrdersTable"');

has('Invoice & Payment History section is inside pane-invoices', $invoicesPane, 'Invoice & Payment History');
has('customerInvoicesTable is inside pane-invoices', $invoicesPane, 'id="customerInvoicesTable"');

has('Purchase Orders (LPO) section is inside pane-lpos', $lposPane, 'Purchase Orders (LPO)');
has('customerLposTable is inside pane-lpos', $lposPane, 'id="customerLposTable"');

has('System Information section is inside pane-sysinfo', $sysinfoPane, 'System Information');
has('Date Created label is inside pane-sysinfo', $sysinfoPane, 'Date Created');

// ── PHASE 5: LPO Pane Is Inside PHP Conditional ───────────────────────────────
echo "<h3>Phase 5 — LPO Pane PHP Conditional</h3>";

// Verify the pane-lpos div appears after the LPO PHP condition opens and before endif
// (use position-based check — avoids newline encoding issues on Windows)
$condOpen  = strpos($src, "if (!empty(\$customer_lpos) || \$can_create_lpos):");
$paneOpen  = strpos($src, 'id="pane-lpos"');
$paneClose = strpos($src, '<!-- #pane-lpos -->');
$condEnd   = strpos($src, '<?php endif; ?>', $paneClose ?: 0);
($condOpen !== false && $paneOpen !== false && $paneClose !== false && $condEnd !== false
    && $condOpen < $paneOpen && $paneOpen < $paneClose && $paneClose < $condEnd)
    ? ok('pane-lpos is wrapped inside PHP conditional block')
    : fail('pane-lpos is NOT correctly wrapped in PHP conditional');

// ── PHASE 6: CSS for Tab Pills ────────────────────────────────────────────────
echo "<h3>Phase 6 — CSS for Tab Button Colors</h3>";

has('CSS: #customerDetailTabs .nav-link rule exists', $src, '#customerDetailTabs .nav-link {');
has('CSS: active tab uses blue background (#0d6efd)', $src, 'background-color: #0d6efd');
has('CSS: active tab uses white text', $src, 'color: #fff');
has('CSS: hover also shows blue', $src, '#customerDetailTabs .nav-link:hover');
has('CSS: mobile font-size adjustment exists', $src, '@media (max-width: 576px)');

// ── PHASE 7: DataTable Column Adjust on Tab Switch ────────────────────────────
echo "<h3>Phase 7 — DataTable Column Adjust on Tab Switch</h3>";

has('shown.bs.tab event listener exists', $src, 'shown.bs.tab');
has('customerOrdersTable adjusted on tab switch', $src, "'#customerOrdersTable'");
has('customerInvoicesTable adjusted on tab switch', $src, "'#customerInvoicesTable'");
has('customerLposTable adjusted on tab switch', $src, "'#customerLposTable'");
has('isDataTable guard before adjust', $src, 'isDataTable(sel)');

// ── PHASE 8: No Broken References ─────────────────────────────────────────────
echo "<h3>Phase 8 — No Broken Tab References</h3>";

// Every pane ID referenced in the nav should have a matching pane div
$paneIds = ['pane-orders', 'pane-invoices', 'pane-lpos', 'pane-sysinfo'];
foreach ($paneIds as $pid) {
    has("pane id=\"$pid\" present in document", $src, "id=\"$pid\"");
    has("data-bs-target=\"#$pid\" button present", $src, "data-bs-target=\"#$pid\"");
}

// ── SUMMARY ───────────────────────────────────────────────────────────────────
$total = $pass + $fail;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Customer Tabs — Test Results</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<h2>Customer Details — Section Tabs Test (Update 31)</h2>
<div class="alert alert-<?= $fail === 0 ? 'success' : 'danger' ?> fw-bold">
    <?= $pass ?> / <?= $total ?> passed
    <?= $fail > 0 ? " — $fail FAILED" : ' — All tests passed!' ?>
</div>
<table class="table table-sm table-bordered">
    <thead class="table-dark"><tr><th width="80">Result</th><th>Test</th></tr></thead>
    <tbody>
    <?php foreach ($results as [$status, $label]): ?>
        <tr class="<?= $status === 'pass' ? 'table-success' : 'table-danger' ?>">
            <td class="fw-bold"><?= $status === 'pass' ? '✓ PASS' : '✗ FAIL' ?></td>
            <td><?= htmlspecialchars($label) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
