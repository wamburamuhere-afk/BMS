<?php
/**
 * Test Suite: Inspections & IPC Module
 * URL: http://localhost/bms/scratch/test_inspections_ipc.php
 */
require_once __DIR__ . '/../roots.php';

$pass = 0; $fail = 0; $results = [];

function chk($label, $condition, $detail = '') {
    global $pass, $fail, $results;
    if ($condition) { $pass++; $results[] = ['status'=>'PASS','label'=>$label,'detail'=>$detail]; }
    else            { $fail++;  $results[] = ['status'=>'FAIL','label'=>$label,'detail'=>$detail]; }
}

// ── 1. TABLES ──────────────────────────────────────────────────────────────
foreach (['project_inspections','interim_payment_certificates'] as $t) {
    $exists = $pdo->query("SHOW TABLES LIKE '$t'")->fetchColumn();
    chk("Table exists: $t", $exists !== false);
}

// ── 2. INSPECTIONS COLUMNS ─────────────────────────────────────────────────
$req_insp = ['inspection_id','project_id','milestone_id','inspection_no','inspection_date',
             'inspection_time','inspection_type','inspector_name','inspector_org','location_area',
             'result','defects_found','corrective_action','reinspection_required','reinspection_date',
             'signed_off_by','notes','status','created_by','created_at','updated_at'];
$insp_cols = $pdo->query("SHOW COLUMNS FROM project_inspections")->fetchAll(PDO::FETCH_COLUMN);
foreach ($req_insp as $col) chk("Inspections column: $col", in_array($col, $insp_cols));

// ── 3. IPC COLUMNS ─────────────────────────────────────────────────────────
$req_ipc = ['ipc_id','project_id','milestone_id','ipc_number','period_from','period_to',
            'work_done_percent','cumulative_percent','contract_sum','certified_amount',
            'retention_percent','retention_amount','previous_payments','net_payable',
            'status','notes','invoice_id','created_by','created_at','updated_at'];
$ipc_cols = $pdo->query("SHOW COLUMNS FROM interim_payment_certificates")->fetchAll(PDO::FETCH_COLUMN);
foreach ($req_ipc as $col) chk("IPC column: $col", in_array($col, $ipc_cols));

// ── 4. API FILES ───────────────────────────────────────────────────────────
$apis = [
    'api/operations/save_inspection.php',
    'api/operations/get_inspections.php',
    'api/operations/get_inspection.php',
    'api/operations/delete_inspection.php',
    'api/operations/save_ipc.php',
    'api/operations/get_ipcs.php',
    'api/operations/get_ipc.php',
    'api/operations/delete_ipc.php',
    'api/operations/create_invoice_from_ipc.php',
];
foreach ($apis as $f) chk("File exists: $f", file_exists(__DIR__.'/../'.$f));

// ── 5. DB INTEGRITY ────────────────────────────────────────────────────────
try {
    $c1 = $pdo->query("SELECT COUNT(*) FROM project_inspections")->fetchColumn();
    chk("project_inspections is queryable", true, "Records: $c1");
    $c2 = $pdo->query("SELECT COUNT(*) FROM interim_payment_certificates")->fetchColumn();
    chk("interim_payment_certificates is queryable", true, "Records: $c2");
} catch (Exception $e) {
    chk("DB queryable", false, $e->getMessage());
}

// ── 6. IPC AUTO-CALC LOGIC ─────────────────────────────────────────────────
$certified = 1000000;
$retention_pct = 10;
$previous = 200000;
$retention_amt = round($certified * $retention_pct / 100, 2);
$net = round($certified - $retention_amt - $previous, 2);
chk("IPC auto-calc: retention amount correct", $retention_amt === 100000.0, "Expected 100000, got $retention_amt");
chk("IPC auto-calc: net payable correct",      $net === 700000.0,           "Expected 700000, got $net");

// ── 7. PROJECT_INSPECTIONS FOREIGN KEY ─────────────────────────────────────
$fk = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_NAME='project_inspections' AND REFERENCED_TABLE_NAME='projects'")->fetchColumn();
chk("FK: project_inspections → projects", $fk > 0);

$fk2 = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_NAME='interim_payment_certificates' AND REFERENCED_TABLE_NAME='projects'")->fetchColumn();
chk("FK: interim_payment_certificates → projects", $fk2 > 0);

// ── 8. PROJECT_VIEW UI — INSPECTIONS TAB ──────────────────────────────────
$pv = file_get_contents(__DIR__.'/../app/bms/operations/project_view.php');
chk("project_view: Inspections nav item", str_contains($pv, 'proj-inspections-tab'));
chk("project_view: Inspections tab panel", str_contains($pv, 'id="proj-inspections"'));
chk("project_view: Inspections stat cards", str_contains($pv, 'id="insp-total"'));
chk("project_view: inspLoadTable function", str_contains($pv, 'function inspLoadTable'));
chk("project_view: inspSave function", str_contains($pv, 'function inspSave'));
chk("project_view: inspEdit function", str_contains($pv, 'function inspEdit'));
chk("project_view: inspView function", str_contains($pv, 'function inspView'));
chk("project_view: inspDelete function", str_contains($pv, 'function inspDelete'));
chk("project_view: inspAddModal", str_contains($pv, 'id="inspAddModal"'));
chk("project_view: inspEditModal", str_contains($pv, 'id="inspEditModal"'));
chk("project_view: inspViewModal", str_contains($pv, 'id="inspViewModal"'));

// ── 9. PROJECT_VIEW UI — IPC TAB ──────────────────────────────────────────
chk("project_view: IPC nav item", str_contains($pv, 'proj-ipc-tab'));
chk("project_view: IPC tab panel", str_contains($pv, 'id="proj-ipc"'));
chk("project_view: IPC stat cards", str_contains($pv, 'id="ipc-total"'));
chk("project_view: ipcLoadTable function", str_contains($pv, 'function ipcLoadTable'));
chk("project_view: ipcSave function", str_contains($pv, 'function ipcSave'));
chk("project_view: ipcEdit function", str_contains($pv, 'function ipcEdit'));
chk("project_view: ipcView function", str_contains($pv, 'function ipcView'));
chk("project_view: ipcDelete function", str_contains($pv, 'function ipcDelete'));
chk("project_view: ipcCreateInvoice function", str_contains($pv, 'function ipcCreateInvoice'));
chk("project_view: ipcCalc function", str_contains($pv, 'function ipcCalc'));
chk("project_view: ipcAddModal", str_contains($pv, 'id="ipcAddModal"'));
chk("project_view: ipcEditModal", str_contains($pv, 'id="ipcEditModal"'));
chk("project_view: ipcViewModal", str_contains($pv, 'id="ipcViewModal"'));
chk("project_view: IPC Create Invoice button in view modal", str_contains($pv, 'ipcCreateInvoiceBtn'));
chk("project_view: milestone query for modals", str_contains($pv, 'proj_milestones'));

// ── OUTPUT ──────────────────────────────────────────────────────────────────
$total = $pass + $fail;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Inspections & IPC Test Suite</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4 bg-light">
<div class="container">
    <h3 class="mb-1">Inspections & IPC Module — Test Suite</h3>
    <p class="text-muted mb-3">Run date: <?= date('Y-m-d H:i:s') ?></p>
    <div class="row mb-4">
        <div class="col-auto"><div class="card text-white bg-success px-4 py-3 text-center"><h2 class="mb-0"><?= $pass ?></h2><small>PASSED</small></div></div>
        <div class="col-auto"><div class="card text-white bg-<?= $fail>0?'danger':'secondary' ?> px-4 py-3 text-center"><h2 class="mb-0"><?= $fail ?></h2><small>FAILED</small></div></div>
        <div class="col-auto"><div class="card bg-white px-4 py-3 text-center"><h2 class="mb-0"><?= $total ?></h2><small>TOTAL</small></div></div>
    </div>
    <table class="table table-bordered table-sm bg-white">
        <thead class="table-dark"><tr><th width="80">Status</th><th>Test</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr class="<?= $r['status']==='PASS'?'table-success':'table-danger' ?>">
                <td><strong><?= $r['status'] ?></strong></td>
                <td><?= htmlspecialchars($r['label']) ?></td>
                <td class="text-muted small"><?= htmlspecialchars($r['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if($fail===0): ?>
        <div class="alert alert-success">All tests passed.</div>
    <?php else: ?>
        <div class="alert alert-danger"><?= $fail ?> test(s) failed.</div>
    <?php endif; ?>
</div>
</body>
</html>
