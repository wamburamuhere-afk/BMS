<?php
// File: app/bms/purchase/rfq_view.php
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('rfq');
includeHeader();

global $pdo;
$rfq_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$rfq_id) { header('Location: ' . getUrl('rfq')); exit; }

$stmt = $pdo->prepare("SELECT r.*, s.supplier_name, s.phone as s_phone, s.email as s_email,
    w.warehouse_name, p.project_name
    FROM rfq r
    LEFT JOIN suppliers s ON r.supplier_id = s.supplier_id
    LEFT JOIN warehouses w ON r.warehouse_id = w.warehouse_id
    LEFT JOIN projects p ON r.project_id = p.project_id
    WHERE r.rfq_id = ?");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rfq) { header('Location: ' . getUrl('rfq')); exit; }

$stmt2 = $pdo->prepare("SELECT * FROM rfq_items WHERE rfq_id = ? ORDER BY item_order");
$stmt2->execute([$rfq_id]);
$items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$c_name  = getSetting('company_name', 'BMS');
$c_logo  = getSetting('company_logo', '');
$c_web   = getSetting('company_website', '');
$c_email = getSetting('company_email', '');
$c_tin   = getSetting('company_tin', '');
$c_vrn   = getSetting('company_vrn', '');
?>

<div class="rfq-view-page p-2 p-md-3" style="background:#fff;min-height:100vh;">

    <!-- PRINT HEADER -->
    <div class="d-none d-print-block text-center mb-4">
        <?php if(!empty($c_logo)): ?>
        <div class="mb-2"><img src="<?= htmlspecialchars('../../../'.$c_logo) ?>" alt="Logo" style="max-height:80px;"></div>
        <?php endif; ?>
        <h1 style="color:#0d6efd;font-weight:800;text-transform:uppercase;font-size:22pt;margin:0;"><?= safe_output($c_name) ?></h1>
        <p class="small text-uppercase mb-1"><?php $we=[];if(!empty($c_web))$we[]='Web: '.safe_output($c_web);if(!empty($c_email))$we[]='Email: '.safe_output($c_email);echo implode(' | ',$we); ?></p>
        <p class="small text-uppercase mb-1"><?php $tv=[];if(!empty($c_tin))$tv[]='TIN: '.safe_output($c_tin);if(!empty($c_vrn))$tv[]='VRN: '.safe_output($c_vrn);echo implode(' | ',$tv); ?></p>
        <div class="mt-2">
            <h2 style="color:#495057;font-weight:600;text-transform:uppercase;font-size:14pt;letter-spacing:2px;">REQUEST FOR QUOTATION</h2>
            <p style="color:#6c757d;font-size:9pt;">Generated: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 20px;"></div>
    </div>
    <div class="d-none d-print-block" style="position:fixed;bottom:0;left:0;right:0;border-top:1px solid #dee2e6;padding:5px 0;text-align:center;">
        <small style="color:#666;font-size:8pt;"><?= safe_output($c_name) ?> &mdash; RFQ #<?= safe_output($rfq['rfq_number']) ?> &mdash; Printed: <?= date('d M Y, h:i A') ?></small>
    </div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('rfq') ?>">RFQ</a></li>
            <li class="breadcrumb-item active"><?= safe_output($rfq['rfq_number']) ?></li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4 d-print-none">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-file-earmark-text text-primary me-2"></i>RFQ Details</h2>
            <p class="text-muted mb-0 small">View all details of this request for quotation</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button onclick="window.open('<?= getUrl('print_rfq') ?>?id=<?= $rfq_id ?>', '_blank')" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <a href="<?= getUrl('rfq_create') ?>?edit=<?= $rfq_id ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-pencil me-1"></i> Edit RFQ
            </a>
        </div>
    </div>

    <!-- RFQ Info Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i>RFQ Information</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">RFQ Number</p>
                    <span class="rfq-code"><?= safe_output($rfq['rfq_number']) ?></span>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">RFQ Date</p>
                    <strong><?= safe_output($rfq['rfq_date'] ?? '—') ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Deadline</p>
                    <strong><?= safe_output($rfq['deadline_date'] ?? '—') ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Status</p>
                    <?php
                    $sc=['draft'=>'secondary','sent'=>'warning','received'=>'info','evaluated'=>'primary','awarded'=>'success','cancelled'=>'danger'];
                    $st = $rfq['status'] ?? 'draft';
                    ?>
                    <span class="badge bg-<?= $sc[$st] ?? 'secondary' ?> text-uppercase"><?= ucfirst($st) ?></span>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Supplier</p>
                    <strong><?= safe_output($rfq['supplier_name'] ?? '—') ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Warehouse</p>
                    <strong><?= safe_output($rfq['warehouse_name'] ?? '—') ?></strong>
                </div>
                <?php if (!empty($rfq['project_name'])): ?>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Project</p>
                    <strong><?= safe_output($rfq['project_name']) ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2"></i>RFQ Items</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-uppercase small fw-bold" style="background:#f8fafc;">
                        <tr>
                            <th class="ps-4" style="width:55px;">S/No</th>
                            <th>Description</th>
                            <th style="width:130px;">Unit</th>
                            <th style="width:120px;">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">No items found</td></tr>
                        <?php else: ?>
                        <?php foreach ($items as $i => $item): ?>
                        <tr>
                            <td class="ps-4 fw-bold text-muted"><?= $i+1 ?></td>
                            <td><?= safe_output($item['description']) ?></td>
                            <td><?= safe_output($item['unit'] ?? '—') ?></td>
                            <td><?= safe_output($item['qty']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.rfq-code{color:#0f5132!important;background:#d1e7dd!important;padding:3px 8px;border-radius:5px;font-weight:700;}
.rfq-view-page .table thead th{border-bottom:2px solid #e2e8f0;padding:1rem;color:#475569;}
@media print{
    .d-print-none{display:none!important;}
    table{width:100%!important;border-collapse:collapse!important;}
    th,td{border:1px solid #dee2e6!important;padding:6px!important;font-size:9pt;}
    thead th{background:#f8f9fa!important;-webkit-print-color-adjust:exact;}
}
</style>

<?php includeFooter(); ?>
