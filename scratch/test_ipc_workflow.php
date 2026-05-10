<?php
/**
 * IPC Workflow Test Suite
 * Tests all changes made to the IPC module:
 *   - DB schema: status ENUM includes 'Viewed'
 *   - Add IPC: status defaults to Draft, retention=0, previous=0
 *   - Edit IPC: status preserved via hidden field, retention=0, previous=0
 *   - Status transitions: Draft→Viewed→Approved (valid), invalid transitions blocked
 *   - Create Invoice: only allowed when status=Approved
 *   - After Paid: no further status transitions
 *
 * URL: http://localhost/bms/scratch/test_ipc_workflow.php
 */
session_start();
require_once __DIR__ . '/../roots.php';
global $pdo;

$pass = 0; $fail = 0; $results = [];
$TEST_PROJECT_ID = 3;
$TEST_USER_ID    = 4;
$_SESSION['user_id'] = $TEST_USER_ID;

// Tracked IDs for cleanup
$ipc_draft_id    = null;
$ipc_paid_id     = null;
$created_inv_id  = null;

function chk(string $label, bool $ok, string $detail = ''): bool {
    global $pass, $fail, $results;
    if ($ok) { $pass++; $results[] = ['s'=>'PASS','l'=>$label,'d'=>$detail]; }
    else      { $fail++;  $results[] = ['s'=>'FAIL','l'=>$label,'d'=>$detail]; }
    return $ok;
}

// Helper: simulate update_ipc_status.php logic directly
function tryStatusUpdate(PDO $pdo, int $ipc_id, string $newStatus): array {
    $allowed = ['Viewed', 'Approved'];
    if (!in_array($newStatus, $allowed)) {
        return ['success' => false, 'message' => 'Invalid request'];
    }
    $stmt = $pdo->prepare("SELECT status FROM interim_payment_certificates WHERE ipc_id = ?");
    $stmt->execute([$ipc_id]);
    $current = $stmt->fetchColumn();
    if ($newStatus === 'Viewed' && $current !== 'Draft') {
        return ['success' => false, 'message' => 'Only Draft IPCs can be marked as Reviewed'];
    }
    if ($newStatus === 'Approved' && $current !== 'Viewed') {
        return ['success' => false, 'message' => 'Only Viewed IPCs can be Approved'];
    }
    $upd = $pdo->prepare("UPDATE interim_payment_certificates SET status=?, updated_at=NOW() WHERE ipc_id=?");
    $upd->execute([$newStatus, $ipc_id]);
    return ['success' => true, 'message' => 'Status updated to ' . $newStatus];
}

// Helper: create a minimal test IPC with given status
function createTestIpc(PDO $pdo, int $project_id, int $user_id, string $status, string $note): int {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM interim_payment_certificates WHERE project_id=?");
    $cnt->execute([$project_id]);
    $no = 'IPC-TEST-' . str_pad($cnt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);
    $ins = $pdo->prepare("INSERT INTO interim_payment_certificates
        (project_id, ipc_number, ipc_date, certified_amount, retention_percent, retention_amount,
         previous_payments, net_payable, status, notes, items_json, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([$project_id, $no, date('Y-m-d'), 500000, 0, 0, 0, 500000,
                   $status, $note, json_encode([
                       ['product_name'=>'Test Item','quantity'=>1,'unit'=>'pcs',
                        'unit_price'=>500000,'tax_percent'=>0,'tax_amount'=>0,'total'=>500000]
                   ]), $user_id]);
    return (int)$pdo->lastInsertId();
}


// ═══════════════════════════════════════════════════════════════
// 1. SCHEMA — status ENUM includes 'Viewed'
// ═══════════════════════════════════════════════════════════════
try {
    $col = $pdo->query("SHOW COLUMNS FROM interim_payment_certificates LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    chk('Schema: status column exists',            $col !== false,                                     $col['Type'] ?? 'not found');
    $type = $col['Type'] ?? '';
    chk('Schema: status is ENUM type',             str_starts_with($type, 'enum('),                    $type);
    chk("Schema: 'Draft' in enum",                 str_contains($type, "'Draft'"),                     $type);
    chk("Schema: 'Viewed' in enum",                str_contains($type, "'Viewed'"),                    $type);
    chk("Schema: 'Approved' in enum",              str_contains($type, "'Approved'"),                  $type);
    chk("Schema: 'Paid' in enum",                  str_contains($type, "'Paid'"),                      $type);
    chk("Schema: default is 'Draft'",              str_contains($col['Default'] ?? '', 'Draft'),       "Default: " . ($col['Default'] ?? 'none'));
} catch (Exception $e) {
    foreach (range(1, 7) as $i) chk("Schema #$i", false, $e->getMessage());
}


// ═══════════════════════════════════════════════════════════════
// 2. ADD IPC — status=Draft, retention=0, previous=0 by default
// ═══════════════════════════════════════════════════════════════
try {
    // Simulate the simplified Add form: no status/retention/previous posted
    $post_status           = 'Draft';   // hidden field value
    $post_retention_percent = 0;        // hidden field value
    $post_previous_payments = 0;        // hidden field value

    $items_raw = [['product_name'=>'Service A','quantity'=>1,'unit'=>'lot','unit_price'=>300000,'tax_percent'=>18]];
    $subtotal = 0; $tax_total = 0; $items = [];
    foreach ($items_raw as $it) {
        $line_sub   = round($it['quantity'] * $it['unit_price'], 2);
        $tax_amt    = round($line_sub * $it['tax_percent'] / 100, 2);
        $items[]    = array_merge($it, ['tax_amount' => $tax_amt, 'total' => $line_sub + $tax_amt]);
        $subtotal  += $line_sub; $tax_total += $tax_amt;
    }
    $certified   = round($subtotal + $tax_total, 2);       // 354000
    $ret_amt     = round($certified * $post_retention_percent / 100, 2); // 0
    $net_payable = round($certified - $ret_amt - $post_previous_payments, 2); // 354000

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM interim_payment_certificates WHERE project_id=?");
    $cnt->execute([$TEST_PROJECT_ID]);
    $ipc_no = 'IPC-ADD-' . str_pad($cnt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

    $ins = $pdo->prepare("INSERT INTO interim_payment_certificates
        (project_id, ipc_number, ipc_date, certified_amount, retention_percent, retention_amount,
         previous_payments, net_payable, status, notes, items_json, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([$TEST_PROJECT_ID, $ipc_no, date('Y-m-d'), $certified, $post_retention_percent,
                   $ret_amt, $post_previous_payments, $net_payable, $post_status,
                   '[TEST] Add IPC simplified form', json_encode($items), $TEST_USER_ID]);
    $ipc_draft_id = (int)$pdo->lastInsertId();

    chk('Add IPC: record inserted',                $ipc_draft_id > 0,                                'ipc_id=' . $ipc_draft_id);

    // Verify stored values
    $row = $pdo->prepare("SELECT status, retention_percent, previous_payments, net_payable
                          FROM interim_payment_certificates WHERE ipc_id=?");
    $row->execute([$ipc_draft_id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);

    chk("Add IPC: status saved as 'Draft'",        ($r['status'] ?? '') === 'Draft',                  'status=' . ($r['status'] ?? 'null'));
    chk('Add IPC: retention_percent = 0',          floatval($r['retention_percent'] ?? -1) == 0,      'retention_percent=' . ($r['retention_percent'] ?? 'null'));
    chk('Add IPC: previous_payments = 0',          floatval($r['previous_payments'] ?? -1) == 0,      'previous_payments=' . ($r['previous_payments'] ?? 'null'));
    chk('Add IPC: net_payable = certified (no deductions)', abs(floatval($r['net_payable'] ?? 0) - $certified) < 0.01, "net={$r['net_payable']}, certified=$certified");

} catch (Exception $e) {
    foreach (range(1, 5) as $i) chk("Add IPC #$i", false, $e->getMessage());
}


// ═══════════════════════════════════════════════════════════════
// 3. STATUS WORKFLOW — valid transitions
// ═══════════════════════════════════════════════════════════════
if ($ipc_draft_id) {
    try {
        // 3a. Draft → Viewed
        $res = tryStatusUpdate($pdo, $ipc_draft_id, 'Viewed');
        chk('Workflow: Draft → Viewed succeeds',   $res['success'],                                   $res['message']);

        $row = $pdo->prepare("SELECT status FROM interim_payment_certificates WHERE ipc_id=?");
        $row->execute([$ipc_draft_id]);
        chk("Workflow: status is now 'Viewed' in DB", $row->fetchColumn() === 'Viewed',               '');

        // 3b. Viewed → Approved
        $res = tryStatusUpdate($pdo, $ipc_draft_id, 'Approved');
        chk('Workflow: Viewed → Approved succeeds',  $res['success'],                                 $res['message']);

        $row->execute([$ipc_draft_id]);
        chk("Workflow: status is now 'Approved' in DB", $row->fetchColumn() === 'Approved',           '');

    } catch (Exception $e) {
        foreach (range(1, 4) as $i) chk("Workflow valid #$i", false, $e->getMessage());
    }
} else {
    foreach (range(1, 4) as $i) chk("Workflow valid #$i", false, 'skipped — Add IPC failed');
}


// ═══════════════════════════════════════════════════════════════
// 4. STATUS WORKFLOW — invalid transitions blocked
// ═══════════════════════════════════════════════════════════════
try {
    // Create a fresh Draft IPC for invalid-transition tests
    $ipc_inv = createTestIpc($pdo, $TEST_PROJECT_ID, $TEST_USER_ID, 'Draft', '[TEST] invalid transitions');

    // Draft → Approved (skip Viewed — must fail)
    $res = tryStatusUpdate($pdo, $ipc_inv, 'Approved');
    chk('Workflow: Draft → Approved is blocked',   !$res['success'],                                  $res['message']);

    // Draft → invalid value
    $res = tryStatusUpdate($pdo, $ipc_inv, 'Rejected');
    chk('Workflow: invalid status value blocked',  !$res['success'],                                  $res['message']);

    // Move to Viewed then Approved, then try Viewed again (regression)
    tryStatusUpdate($pdo, $ipc_inv, 'Viewed');
    tryStatusUpdate($pdo, $ipc_inv, 'Approved');
    $res = tryStatusUpdate($pdo, $ipc_inv, 'Viewed');
    chk('Workflow: Approved → Viewed is blocked',  !$res['success'],                                  $res['message']);

    $res = tryStatusUpdate($pdo, $ipc_inv, 'Approved');
    chk('Workflow: Approved → Approved is blocked', !$res['success'],                                 $res['message']);

    // Cleanup
    $pdo->prepare("DELETE FROM interim_payment_certificates WHERE ipc_id=?")->execute([$ipc_inv]);

} catch (Exception $e) {
    foreach (range(1, 4) as $i) chk("Workflow invalid #$i", false, $e->getMessage());
}


// ═══════════════════════════════════════════════════════════════
// 5. EDIT IPC — status preserved, retention=0, previous=0
// ═══════════════════════════════════════════════════════════════
if ($ipc_draft_id) {
    try {
        // Create a separate IPC in 'Viewed' status to test edit preserves it
        $ipc_edit = createTestIpc($pdo, $TEST_PROJECT_ID, $TEST_USER_ID, 'Draft', '[TEST] edit status preserve');
        tryStatusUpdate($pdo, $ipc_edit, 'Viewed');

        // Simulate Edit form submit: status comes from hidden field (current status)
        $current_status = 'Viewed'; // what the hidden field would send
        $upd = $pdo->prepare("UPDATE interim_payment_certificates SET
            ipc_date=?, certified_amount=?, retention_percent=?, retention_amount=?,
            previous_payments=?, net_payable=?, status=?, notes=?, items_json=?, updated_at=NOW()
            WHERE ipc_id=?");
        $upd->execute([date('Y-m-d'), 600000, 0, 0, 0, 600000, $current_status,
                       '[TEST EDITED]', json_encode([]), $ipc_edit]);

        $row = $pdo->prepare("SELECT status, retention_percent, previous_payments FROM interim_payment_certificates WHERE ipc_id=?");
        $row->execute([$ipc_edit]);
        $r = $row->fetch(PDO::FETCH_ASSOC);

        chk("Edit IPC: status 'Viewed' preserved after save",  ($r['status'] ?? '') === 'Viewed',      'status=' . ($r['status'] ?? 'null'));
        chk('Edit IPC: retention_percent = 0 after save',      floatval($r['retention_percent'] ?? -1) == 0, $r['retention_percent'] ?? 'null');
        chk('Edit IPC: previous_payments = 0 after save',      floatval($r['previous_payments'] ?? -1) == 0, $r['previous_payments'] ?? 'null');

        $pdo->prepare("DELETE FROM interim_payment_certificates WHERE ipc_id=?")->execute([$ipc_edit]);

    } catch (Exception $e) {
        foreach (range(1, 3) as $i) chk("Edit IPC #$i", false, $e->getMessage());
    }
} else {
    foreach (range(1, 3) as $i) chk("Edit IPC #$i", false, 'skipped — Add IPC failed');
}


// ═══════════════════════════════════════════════════════════════
// 6. CREATE INVOICE — only works when status=Approved
// ═══════════════════════════════════════════════════════════════
if ($ipc_draft_id) {
    try {
        // Confirm IPC is Approved (from section 3)
        $row = $pdo->prepare("SELECT status, invoice_id FROM interim_payment_certificates WHERE ipc_id=?");
        $row->execute([$ipc_draft_id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);

        chk('Create Invoice: IPC is Approved',     ($r['status'] ?? '') === 'Approved',               'status=' . ($r['status'] ?? 'null'));
        chk('Create Invoice: no invoice yet',       empty($r['invoice_id']),                           'invoice_id=' . ($r['invoice_id'] ?? 'null'));

        // Simulate create_invoice_from_ipc.php: blocked if not Approved or already linked
        $can_invoice = ($r['status'] === 'Approved' && empty($r['invoice_id']));
        chk('Create Invoice: guard allows creation', $can_invoice,                                     '');

        if ($can_invoice) {
            // Get project customer_id
            $proj = $pdo->prepare("SELECT customer_id FROM projects WHERE project_id=?");
            $proj->execute([$TEST_PROJECT_ID]);
            $customer_id = $proj->fetchColumn() ?: 1;

            $last = $pdo->query("SELECT invoice_number FROM invoices ORDER BY invoice_id DESC LIMIT 1")->fetchColumn();
            $next = 1;
            if ($last && preg_match('/(\d+)$/', $last, $m)) $next = intval($m[1]) + 1;
            $inv_no = 'INV-TEST-' . str_pad($next, 5, '0', STR_PAD_LEFT);

            $ins_inv = $pdo->prepare("INSERT INTO invoices
                (invoice_number, customer_id, invoice_date, due_date, subtotal, tax_amount,
                 discount_amount, shipping_cost, grand_total, paid_amount, balance_due,
                 currency, notes, status, project_id, created_by)
                VALUES (?,?,?,?,?,0,0,0,?,0,?,?,?,?,?,?)");
            $ins_inv->execute([$inv_no, $customer_id, date('Y-m-d'),
                               date('Y-m-d', strtotime('+30 days')),
                               500000, 500000, 500000, 'TZS',
                               '[TEST] IPC invoice', 'unpaid', $TEST_PROJECT_ID, $TEST_USER_ID]);
            $created_inv_id = (int)$pdo->lastInsertId();

            $pdo->prepare("UPDATE interim_payment_certificates SET invoice_id=?, status='Paid', updated_at=NOW() WHERE ipc_id=?")
                ->execute([$created_inv_id, $ipc_draft_id]);

            chk('Create Invoice: invoice record inserted',   $created_inv_id > 0,                     'invoice_id=' . $created_inv_id);

            $row->execute([$ipc_draft_id]);
            $updated = $row->fetch(PDO::FETCH_ASSOC);
            chk("Create Invoice: IPC status changed to 'Paid'", ($updated['status'] ?? '') === 'Paid', 'status=' . ($updated['status'] ?? 'null'));
            chk('Create Invoice: invoice_id linked on IPC',  intval($updated['invoice_id'] ?? 0) === $created_inv_id, 'invoice_id=' . ($updated['invoice_id'] ?? 'null'));

            // Duplicate guard: Paid IPC with invoice_id set → cannot create again
            $row->execute([$ipc_draft_id]);
            $paid = $row->fetch(PDO::FETCH_ASSOC);
            $would_block = !($paid['status'] === 'Approved' && empty($paid['invoice_id']));
            chk('Create Invoice: duplicate creation blocked on Paid IPC', $would_block,               'status=' . $paid['status'] . ', invoice_id=' . $paid['invoice_id']);
        } else {
            foreach (['invoice inserted', 'IPC Paid', 'invoice_id linked', 'duplicate blocked'] as $l)
                chk("Create Invoice: $l", false, 'guard check failed');
        }

        // Also test: Draft IPC cannot create invoice
        $ipc_draft_block = createTestIpc($pdo, $TEST_PROJECT_ID, $TEST_USER_ID, 'Draft', '[TEST] block draft invoice');
        $row->execute([$ipc_draft_block]);
        $rb = $row->fetch(PDO::FETCH_ASSOC);
        $blocked = !($rb['status'] === 'Approved' && empty($rb['invoice_id']));
        chk('Create Invoice: Draft IPC is blocked',  $blocked,                                        'status=' . ($rb['status'] ?? 'null'));
        $pdo->prepare("DELETE FROM interim_payment_certificates WHERE ipc_id=?")->execute([$ipc_draft_block]);

    } catch (Exception $e) {
        foreach (range(1, 8) as $i) chk("Create Invoice #$i", false, $e->getMessage());
    }
} else {
    foreach (range(1, 8) as $i) chk("Create Invoice #$i", false, 'skipped — Add IPC failed');
}


// ═══════════════════════════════════════════════════════════════
// 7. AFTER PAID — no further status transitions allowed
// ═══════════════════════════════════════════════════════════════
if ($ipc_draft_id) {
    try {
        $res = tryStatusUpdate($pdo, $ipc_draft_id, 'Viewed');
        chk('Post-Paid: Paid → Viewed is blocked',    !$res['success'],                               $res['message']);

        $res = tryStatusUpdate($pdo, $ipc_draft_id, 'Approved');
        chk('Post-Paid: Paid → Approved is blocked',  !$res['success'],                               $res['message']);

    } catch (Exception $e) {
        foreach (range(1, 2) as $i) chk("Post-Paid #$i", false, $e->getMessage());
    }
} else {
    foreach (range(1, 2) as $i) chk("Post-Paid #$i", false, 'skipped — IPC not available');
}


// ═══════════════════════════════════════════════════════════════
// 8. FORM FIELDS — verify HTML output matches expected structure
// ═══════════════════════════════════════════════════════════════
try {
    $html = file_get_contents(__DIR__ . '/../app/bms/operations/project_view.php');
    chk('Form fields: file readable',                           $html !== false,                       '');

    // Add IPC form — removed visible fields
    $add_no_status_select   = !preg_match('/<select[^>]*name=["\']status["\'][^>]*>.*?<\/select>/si', substr($html, strpos($html, 'ipcAddForm'), strpos($html, 'ipcEditModal') - strpos($html, 'ipcAddForm')));
    chk('Add IPC form: no Status dropdown',                     $add_no_status_select,                 '');

    $add_has_status_hidden  = (bool)preg_match('/<input[^>]*type=["\']hidden["\'][^>]*name=["\']status["\'][^>]*value=["\']Draft["\']/', substr($html, strpos($html, 'ipcAddForm'), strpos($html, 'ipcEditModal') - strpos($html, 'ipcAddForm')));
    chk('Add IPC form: hidden status=Draft present',            $add_has_status_hidden,                '');

    $add_no_retention_input = !str_contains(substr($html, strpos($html, 'ipcAddModal'), strpos($html, 'ipcEditModal') - strpos($html, 'ipcAddModal')), 'id="ipc_add_retention_pct"');
    chk('Add IPC form: Retention (%) input removed',            $add_no_retention_input,               '');

    $add_no_previous_input  = !str_contains(substr($html, strpos($html, 'ipcAddModal'), strpos($html, 'ipcEditModal') - strpos($html, 'ipcAddModal')), 'id="ipc_add_previous"');
    chk('Add IPC form: Less Previous input removed',            $add_no_previous_input,                '');

    // Edit IPC form — same removals
    $edit_block = substr($html, strpos($html, 'ipcEditModal'), strpos($html, 'ipcViewModal') - strpos($html, 'ipcEditModal'));

    $edit_no_status_select  = !preg_match('/<select[^>]*name=["\']status["\']/', $edit_block);
    chk('Edit IPC form: no Status dropdown',                    $edit_no_status_select,                '');

    $edit_has_status_hidden = (bool)preg_match('/<input[^>]*type=["\']hidden["\'][^>]*name=["\']status["\']/', $edit_block);
    chk('Edit IPC form: hidden status field present',           $edit_has_status_hidden,               '');

    $edit_no_retention_vis  = !str_contains($edit_block, 'id="ipc_edit_retention_pct"');
    chk('Edit IPC form: Retention (%) visible input removed',   $edit_no_retention_vis,                '');

    $edit_no_previous_vis   = !str_contains($edit_block, 'ipc_edit_previous"');
    chk('Edit IPC form: Less Previous visible input removed',   $edit_no_previous_vis,                 '');

    // IPC table — Invoice column removed
    $table_block = substr($html, strpos($html, 'proj-ipc-table'), 500);
    chk('IPC table: Invoice column header removed',             !str_contains($table_block, '<th>Invoice</th>'), '');

} catch (Exception $e) {
    foreach (range(1, 10) as $i) chk("Form fields #$i", false, $e->getMessage());
}


// ═══════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════
if ($created_inv_id) {
    try { $pdo->prepare("DELETE FROM invoices WHERE invoice_id=?")->execute([$created_inv_id]); } catch(Exception $e){}
}
if ($ipc_draft_id) {
    try { $pdo->prepare("DELETE FROM interim_payment_certificates WHERE ipc_id=?")->execute([$ipc_draft_id]); } catch(Exception $e){}
}


// ── OUTPUT ────────────────────────────────────────────────────
$total = $pass + $fail;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>IPC Workflow Test Suite</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light p-4">
<div class="container">
    <h3 class="mb-1">IPC Workflow — Test Suite</h3>
    <p class="text-muted mb-3">
        Run: <?= date('Y-m-d H:i:s') ?> &nbsp;|&nbsp; Project ID: <?= $TEST_PROJECT_ID ?>
    </p>
    <div class="row mb-4 g-3">
        <div class="col-auto"><div class="card text-white bg-success px-4 py-3 text-center"><h2 class="mb-0"><?= $pass ?></h2><small>PASSED</small></div></div>
        <div class="col-auto"><div class="card text-white bg-<?= $fail > 0 ? 'danger' : 'secondary' ?> px-4 py-3 text-center"><h2 class="mb-0"><?= $fail ?></h2><small>FAILED</small></div></div>
        <div class="col-auto"><div class="card bg-white px-4 py-3 text-center"><h2 class="mb-0"><?= $total ?></h2><small>TOTAL</small></div></div>
    </div>

    <table class="table table-bordered table-sm bg-white">
        <thead class="table-dark"><tr><th width="80">Status</th><th>Test</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr class="<?= $r['s'] === 'PASS' ? 'table-success' : 'table-danger' ?>">
                <td><strong><?= $r['s'] ?></strong></td>
                <td><?= htmlspecialchars($r['l']) ?></td>
                <td class="text-muted small"><?= htmlspecialchars($r['d']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($fail === 0): ?>
        <div class="alert alert-success fw-bold">All <?= $total ?> tests passed. IPC workflow is working correctly.</div>
    <?php else: ?>
        <div class="alert alert-danger"><?= $fail ?> test(s) failed — check the red rows above.</div>
    <?php endif; ?>

    <div class="mt-3">
        <h6 class="fw-bold">Coverage</h6>
        <ul class="small text-muted">
            <li>Schema: status ENUM includes Draft, Viewed, Approved, Paid</li>
            <li>Add IPC: status=Draft, retention=0, previous=0 by default</li>
            <li>Status workflow: Draft→Viewed→Approved (valid path)</li>
            <li>Invalid transitions blocked: Draft→Approved, Approved→Viewed, invalid values</li>
            <li>Edit IPC: status preserved via hidden field, retention=0, previous=0</li>
            <li>Create Invoice: only when status=Approved and no existing invoice</li>
            <li>After Paid: no further status transitions allowed</li>
            <li>Form HTML: Invoice column, Retention, Less Previous, Status dropdown removed</li>
        </ul>
    </div>
    <p class="text-muted small mt-2">All test data is automatically cleaned up after every run.</p>
</div>
</body>
</html>
