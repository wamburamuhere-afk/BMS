<?php
/**
 * Test: LPO UI Fixes — update 30
 * Covers: Document column removal, safeOutput fix, color changes,
 *         Edit modal = Add modal layout, mobile card View button,
 *         View Details modal document link, all API syntax.
 *
 * Run at: http://dev.bms.local/scratch/test_lpo_ui_fixes.php
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

function assertContains(string $label, string $haystack, string $needle): void {
    str_contains($haystack, $needle) ? ok($label) : fail($label, "Expected: " . substr($needle, 0, 80));
}

function assertNotContains(string $label, string $haystack, string $needle): void {
    !str_contains($haystack, $needle) ? ok($label) : fail($label, "Should NOT contain: " . substr($needle, 0, 80));
}

function checkSyntax(string $path): bool {
    $code = file_get_contents($path);
    if ($code === false) return false;
    try {
        token_get_all($code, TOKEN_PARSE);
        return true;
    } catch (ParseError $e) {
        return false;
    }
}

$root = dirname(__DIR__);
$details = file_get_contents($root . '/app/bms/customer/customer_details.php');

// ── PHASE 1: PHP syntax ───────────────────────────────────────────────────────
echo "<h3>Phase 1 — PHP Syntax Checks</h3>";

$files = [
    'app/bms/customer/customer_details.php',
    'api/customer/add_lpo.php',
    'api/customer/update_lpo.php',
    'api/customer/delete_lpo.php',
    'api/customer/get_lpo.php',
    'api/customer/get_lpos_list.php',
    'api/customer/change_lpo_status.php',
    'migrations/2026_05_20_create_customer_lpos.php',
    'migrations/2026_05_20_lpo_status_workflow.php',
];
foreach ($files as $f) {
    $abs = $root . '/' . $f;
    if (!file_exists($abs)) {
        fail("File exists: $f", "NOT FOUND");
    } elseif (!checkSyntax($abs)) {
        fail("Syntax OK: $f", "parse error");
    } else {
        ok("Syntax OK: $f");
    }
}

// ── PHASE 2: Document column removed from desktop table ───────────────────────
echo "<h3>Phase 2 — Document Column Removed from Desktop Table</h3>";

assertNotContains(
    'No Document <th> in LPO table header',
    $details,
    '<th style="color:#212529;">Document</th>'
);
assertNotContains(
    'No document download <td> in table rows',
    $details,
    'bi-file-earmark-arrow-down"></i></a>' . PHP_EOL . '                                        <?php else: ?>'
);
// The document td block used to have bi-file-earmark-arrow-down inside the table body — ensure it's gone there
// (it still exists inside viewLpo JS which is correct — the View Details modal)
$tableSection = substr($details, strpos($details, 'id="customerLposTable"'), 3000);
assertNotContains(
    'No document download link in table <tbody>',
    $tableSection,
    'getUrl($lpo[\'document_path\'])'
);

// ── PHASE 3: safeOutput removed from viewLpo() ────────────────────────────────
echo "<h3>Phase 3 — safeOutput Bug Fixed in viewLpo()</h3>";

$viewLpoStart = strpos($details, 'function viewLpo(lpoId)');
$viewLpoEnd   = strpos($details, 'function changeLpoStatus');
$viewLpoBlock = substr($details, $viewLpoStart, $viewLpoEnd - $viewLpoStart);

assertNotContains(
    'No safeOutput() call inside viewLpo()',
    $viewLpoBlock,
    'safeOutput('
);
assertContains(
    'Local esc() helper defined inside viewLpo()',
    $viewLpoBlock,
    'function esc(s)'
);
assertContains(
    'esc() used for lpo_number in viewLpo()',
    $viewLpoBlock,
    'esc(d.lpo_number)'
);
assertContains(
    'esc() used for currency in viewLpo()',
    $viewLpoBlock,
    'esc(d.currency)'
);
assertContains(
    'esc() used for document_url (href safe)',
    $viewLpoBlock,
    'esc(d.document_url)'
);

// ── PHASE 4: Status color changes ─────────────────────────────────────────────
echo "<h3>Phase 4 — Color Changes</h3>";

// JS statusColors
assertContains(
    'statusColors.pending is "primary" (not warning)',
    $viewLpoBlock,
    "pending:'primary'"
);
assertNotContains(
    'statusColors.pending is NOT "warning text-dark"',
    $viewLpoBlock,
    "pending:'warning text-dark'"
);

// PHP $lpo_badges — pending
assertNotContains(
    'PHP badge: pending NOT bg-warning',
    $details,
    "'pending'=>'bg-warning text-dark'"
);
assertContains(
    'PHP badge: pending is bg-primary',
    $details,
    "'pending'=>'bg-primary'"
);

// ── PHASE 5: View LPO modal header and buttons ────────────────────────────────
echo "<h3>Phase 5 — View LPO Modal Header & Buttons</h3>";

$viewModalStart = strpos($details, 'id="viewLpoModal"');
$viewModalEnd   = strpos($details, 'id="addLpoModal"');
$viewModalBlock = substr($details, $viewModalStart, $viewModalEnd - $viewModalStart);

assertContains(
    'View modal header is bg-white',
    $viewModalBlock,
    'modal-header bg-white border-bottom'
);
assertNotContains(
    'View modal header is NOT bg-info',
    $viewModalBlock,
    'modal-header bg-info'
);
assertContains(
    'View modal Edit button is btn-primary',
    $viewModalBlock,
    'btn btn-primary d-none" id="viewLpoEditBtn"'
);
assertContains(
    'View modal Review button is btn-primary',
    $viewModalBlock,
    'btn btn-primary d-none" id="viewLpoReviewBtn"'
);
assertNotContains(
    'View modal Edit button is NOT btn-warning',
    $viewModalBlock,
    'btn-warning d-none" id="viewLpoEditBtn"'
);
assertNotContains(
    'View modal Review button is NOT btn-info',
    $viewModalBlock,
    'btn-info text-dark d-none" id="viewLpoReviewBtn"'
);

// Document shown inside View Details modal
assertContains(
    'View Details modal shows document link',
    $viewLpoBlock,
    'View Document'
);

// ── PHASE 6: Edit LPO modal = Add LPO modal layout ────────────────────────────
echo "<h3>Phase 6 — Edit LPO Modal Matches Add LPO Layout</h3>";

$editModalStart = strpos($details, 'id="editLpoModal"');
$editModalEnd   = strpos($details, '<?php endif; ?>', $editModalStart);
$editModalBlock = substr($details, $editModalStart, $editModalEnd - $editModalStart);

assertContains(
    'Edit modal header is bg-primary text-white',
    $editModalBlock,
    'modal-header bg-primary text-white'
);
assertNotContains(
    'Edit modal header is NOT bg-warning',
    $editModalBlock,
    'modal-header bg-warning'
);
assertContains(
    'Edit modal close button has btn-close-white',
    $editModalBlock,
    'btn-close btn-close-white'
);
assertContains(
    'edit_lpo_number is hidden input',
    $editModalBlock,
    'type="hidden" name="lpo_number" id="edit_lpo_number"'
);
assertNotContains(
    'edit_lpo_number is NOT visible text input',
    $editModalBlock,
    'type="text" class="form-control" name="lpo_number"'
);
assertContains(
    'edit_lpo_status is hidden input',
    $editModalBlock,
    'type="hidden" name="status" id="edit_lpo_status"'
);
assertNotContains(
    'edit_lpo_status is NOT a <select>',
    $editModalBlock,
    '<select class="form-select" name="status" id="edit_lpo_status"'
);
assertContains(
    'Edit modal has info banner (matches Add modal)',
    $editModalBlock,
    'LPO Number is auto-generated and cannot be changed'
);
assertContains(
    'Edit modal Update button is btn-primary',
    $editModalBlock,
    'btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update LPO'
);
assertNotContains(
    'Edit modal Update button is NOT btn-warning',
    $editModalBlock,
    'btn btn-warning"><i class="bi bi-check-circle'
);

// ── PHASE 7: Mobile cards always show footer with View button ─────────────────
echo "<h3>Phase 7 — Mobile Cards — View Button Always Shown</h3>";

// Use endforeach as the end delimiter — much safer than the first endif
$mobileStart = strpos($details, 'id="lposCardView"');
$mobileEnd   = strpos($details, '<?php endforeach; ?>', $mobileStart);
$mobileBlock = substr($details, $mobileStart, $mobileEnd - $mobileStart);

assertNotContains(
    'Mobile footer NOT gated by document_path condition',
    $mobileBlock,
    "\$lpo['document_path']): ?>"
);
assertContains(
    'Mobile card View Details button exists',
    $mobileBlock,
    "onclick=\"viewLpo("
);
assertContains(
    'Mobile View button has eye icon',
    $mobileBlock,
    'bi-eye'
);
assertNotContains(
    'Mobile card document download link removed',
    $mobileBlock,
    'bi-file-earmark-arrow-down'
);

// ── PHASE 8: DataTable columnDefs ─────────────────────────────────────────────
echo "<h3>Phase 8 — DataTable columnDefs</h3>";

assertContains(
    'DataTable columnDefs targets [0, -1]',
    $details,
    'targets: [0, -1]'
);
assertNotContains(
    'DataTable columnDefs NOT [0, 6, 7] (old Document column targets)',
    $details,
    'targets: [0, 6, 7]'
);

// ── PHASE 9: change_lpo_status.php logic ──────────────────────────────────────
echo "<h3>Phase 9 — change_lpo_status.php Workflow Enforcement</h3>";

$statusApi = file_get_contents($root . '/api/customer/change_lpo_status.php');
assertContains('change_lpo_status.php: isAuthenticated check', $statusApi, 'isAuthenticated()');
assertContains('change_lpo_status.php: csrf_check', $statusApi, 'csrf_check()');
assertContains('change_lpo_status.php: pending→reviewed transition', $statusApi, "'pending'");
assertContains('change_lpo_status.php: reviewed→approved value', $statusApi, "'reviewed'");
assertContains('change_lpo_status.php: approved as target value', $statusApi, "'approved'");
assertContains('change_lpo_status.php: logActivity call', $statusApi, 'logActivity(');
assertContains('change_lpo_status.php: blocks invalid transition', $statusApi, "Cannot change status from");

// ── SUMMARY ───────────────────────────────────────────────────────────────────
$total = $pass + $fail;
?>
<!DOCTYPE html>
<html>
<head>
    <title>LPO UI Fixes — Test Results</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<h2>LPO UI Fixes — Test Results (Update 30)</h2>
<div class="alert alert-<?= $fail === 0 ? 'success' : 'danger' ?> fw-bold">
    <?= $pass ?> / <?= $total ?> passed
    <?= $fail > 0 ? " — $fail FAILED" : ' — All tests passed!' ?>
</div>
<table class="table table-sm table-bordered">
    <thead class="table-dark"><tr><th>Result</th><th>Test</th></tr></thead>
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
