<?php
/**
 * Test Suite: Sub-Contractors Module
 * Run this in browser to verify everything is working.
 * URL: http://localhost/bms/scratch/test_sub_contractors.php
 */
require_once __DIR__ . '/../roots.php';

$pass = 0;
$fail = 0;
$results = [];

function ok($label, $condition, $detail = '') {
    global $pass, $fail, $results;
    if ($condition) {
        $pass++;
        $results[] = ['status' => 'PASS', 'label' => $label, 'detail' => $detail];
    } else {
        $fail++;
        $results[] = ['status' => 'FAIL', 'label' => $label, 'detail' => $detail];
    }
}

// -----------------------------------------------------------------------
// 1. TABLE EXISTS
// -----------------------------------------------------------------------
try {
    $check = $pdo->query("SHOW TABLES LIKE 'sub_contractors'")->fetchColumn();
    ok('Table: sub_contractors exists', $check !== false);
} catch (Exception $e) {
    ok('Table: sub_contractors exists', false, $e->getMessage());
}

// -----------------------------------------------------------------------
// 2. REQUIRED COLUMNS
// -----------------------------------------------------------------------
$required_columns = [
    'supplier_id', 'supplier_name', 'supplier_code', 'company_name', 'acronym',
    'supplier_type', 'year', 'contact_person', 'contact_title',
    'email', 'company_email', 'phone', 'mobile', 'fax', 'website',
    'address', 'postal_address', 'council', 'ward', 'city', 'state', 'country', 'postal_code',
    'tax_id', 'vat_number', 'payment_terms', 'currency',
    'bank_name', 'bank_account', 'bank_address',
    'category_id', 'project_id', 'credit_limit', 'description',
    'status', 'logo_path', 'created_by', 'updated_by', 'created_at', 'updated_at'
];

try {
    $cols_result = $pdo->query("SHOW COLUMNS FROM sub_contractors")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($required_columns as $col) {
        ok("Column: $col", in_array($col, $cols_result));
    }
} catch (Exception $e) {
    ok('Columns check', false, $e->getMessage());
}

// -----------------------------------------------------------------------
// 3. API FILES EXIST
// -----------------------------------------------------------------------
$api_files = [
    'api/add_sub_contractor.php',
    'api/get_sub_contractor.php',
    'api/update_sub_contractor.php',
    'api/delete_sub_contractor.php',
    'api/update_sub_contractor_status.php',
];
foreach ($api_files as $f) {
    $path = __DIR__ . '/../' . $f;
    ok("File exists: $f", file_exists($path));
}

// -----------------------------------------------------------------------
// 4. VIEW FILES EXIST
// -----------------------------------------------------------------------
$view_files = [
    'app/bms/operations/sub_contractors.php',
    'app/bms/operations/sub_contractor_details.php',
];
foreach ($view_files as $f) {
    $path = __DIR__ . '/../' . $f;
    ok("File exists: $f", file_exists($path));
}

// -----------------------------------------------------------------------
// 5. ROUTES REGISTERED IN roots.php
// -----------------------------------------------------------------------
global $routes;
$required_routes = [
    'sub_contractors',
    'sub_contractors/view',
    'sub_contractors/details',
];
foreach ($required_routes as $route) {
    ok("Route registered: $route", isset($routes[$route]));
}

// -----------------------------------------------------------------------
// 6. MENU LINK IN header.php
// -----------------------------------------------------------------------
$header_content = file_get_contents(__DIR__ . '/../header.php');
ok('Menu: Sub-Contractors link in header.php', strpos($header_content, "getUrl('sub_contractors')") !== false);

// -----------------------------------------------------------------------
// 7. CRUD TEST (non-destructive read)
// -----------------------------------------------------------------------
try {
    $count = $pdo->query("SELECT COUNT(*) FROM sub_contractors WHERE status != 'deleted'")->fetchColumn();
    ok('DB: sub_contractors table is queryable', true, "Records found: $count");
} catch (Exception $e) {
    ok('DB: sub_contractors table is queryable', false, $e->getMessage());
}

// Try fetching one record and check key fields
try {
    $sample = $pdo->query("SELECT supplier_id, supplier_name, supplier_code, status FROM sub_contractors WHERE status != 'deleted' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($sample) {
        ok('DB: Sample record has supplier_name', !empty($sample['supplier_name']), $sample['supplier_name']);
        ok('DB: Sample record has supplier_code', !empty($sample['supplier_code']), $sample['supplier_code']);
        ok('DB: Sample record has valid status', in_array($sample['status'], ['active','inactive','suspended','blacklisted']), $sample['status']);
    } else {
        ok('DB: Sample record', true, 'No records yet (table empty — OK for fresh install)');
    }
} catch (Exception $e) {
    ok('DB: Sample record', false, $e->getMessage());
}

// -----------------------------------------------------------------------
// OUTPUT
// -----------------------------------------------------------------------
$total = $pass + $fail;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sub-Contractors Test Suite</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4 bg-light">
<div class="container">
    <h3 class="mb-1">Sub-Contractors Module — Test Suite</h3>
    <p class="text-muted mb-3">Run date: <?= date('Y-m-d H:i:s') ?></p>

    <div class="row mb-4">
        <div class="col-auto">
            <div class="card text-white bg-success px-4 py-3 text-center">
                <h2 class="mb-0"><?= $pass ?></h2><small>PASSED</small>
            </div>
        </div>
        <div class="col-auto">
            <div class="card text-white bg-<?= $fail > 0 ? 'danger' : 'secondary' ?> px-4 py-3 text-center">
                <h2 class="mb-0"><?= $fail ?></h2><small>FAILED</small>
            </div>
        </div>
        <div class="col-auto">
            <div class="card bg-white px-4 py-3 text-center">
                <h2 class="mb-0"><?= $total ?></h2><small>TOTAL</small>
            </div>
        </div>
    </div>

    <table class="table table-bordered table-sm bg-white">
        <thead class="table-dark">
            <tr><th width="80">Status</th><th>Test</th><th>Detail</th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr class="<?= $r['status'] === 'PASS' ? 'table-success' : 'table-danger' ?>">
                <td><strong><?= $r['status'] ?></strong></td>
                <td><?= htmlspecialchars($r['label']) ?></td>
                <td class="text-muted small"><?= htmlspecialchars($r['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($fail === 0): ?>
    <div class="alert alert-success">All tests passed.</div>
    <?php else: ?>
    <div class="alert alert-danger"><?= $fail ?> test(s) failed. Fix the issues above before deploying.</div>
    <?php endif; ?>
</div>
</body>
</html>
