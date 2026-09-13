<?php
// File: app/bms/purchase/rfq_view.php
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';
autoEnforcePermission('rfq');
logActivity($pdo, $_SESSION['user_id'], 'VIEW', '[RFQ View] Page viewed');
includeHeader();

global $pdo;
$rfq_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$rfq_id) { header('Location: ' . getUrl('rfq')); exit; }
assertScopeForRecordHtml('rfq', 'rfq_id', $rfq_id);

// Context-aware back navigation — short ?back=<tab> keeps URLs clean
$back_tab    = $_GET['back'] ?? '';
$from_project = !empty($back_tab);
$back_url    = getUrl('rfq'); // updated below once rfq record is loaded

$stmt = $pdo->prepare("
    SELECT r.*,
        s.supplier_name, s.phone as s_phone, s.email as s_email,
        w.warehouse_name,
        p.project_name
    FROM rfq r
    LEFT JOIN suppliers s ON r.supplier_id = s.supplier_id
    LEFT JOIN warehouses w ON r.warehouse_id = w.warehouse_id
    LEFT JOIN projects p  ON r.project_id   = p.project_id
    WHERE r.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rfq) { header('Location: ' . getUrl('rfq')); exit; }

// Phase 6 (pos_upgrade_plan.md): gate directly on warehouse scope, not just
// project — a user granted only some of a project's warehouses shouldn't be
// able to open the detail page of a record drawn from a different one.
if (!empty($rfq['warehouse_id']) && !userCan('warehouse', (int)$rfq['warehouse_id'])) {
    if (!headers_sent()) http_response_code(403);
    die('Access denied: this warehouse is not in your assigned scope.');
}

// Compute back URL now that we have the rfq's project_id
if ($from_project && !empty($rfq['project_id'])) {
    $back_url = getUrl('project_view') . '?id=' . (int)$rfq['project_id'] . '&tab=' . $back_tab;
}

$stmt2 = $pdo->prepare("SELECT * FROM rfq_items WHERE rfq_id = ? ORDER BY item_order");
$stmt2->execute([$rfq_id]);
$items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$stmt3 = $pdo->prepare("SELECT * FROM rfq_attachments WHERE rfq_id = ? ORDER BY uploaded_at");
$stmt3->execute([$rfq_id]);
$attachments = $stmt3->fetchAll(PDO::FETCH_ASSOC);

$c_name  = getSetting('company_name', 'BMS');
$c_logo  = getSetting('company_logo', '');
$c_web   = getSetting('company_website', '');
$c_email = getSetting('company_email', '');
$c_tin   = getSetting('company_tin', '');
$c_vrn   = getSetting('company_vrn', '');

$status     = $rfq['status'] ?? 'draft';
$can_review  = canReview('rfq');
$can_approve = canApprove('rfq');

// Status badge colour map
$statusMap = [
    'draft'     => ['class' => 'secondary', 'label' => t('Draft')],
    'review'    => ['class' => 'primary',   'label' => t('In Review')],
    'approved'  => ['class' => 'success',   'label' => t('Approved')],
    'sent'      => ['class' => 'info',      'label' => t('Sent')],
    'received'  => ['class' => 'info',      'label' => t('Quote Received')],
    'evaluated' => ['class' => 'primary',   'label' => t('Evaluated')],
    'awarded'   => ['class' => 'success',   'label' => t('Awarded')],
    'cancelled' => ['class' => 'danger',    'label' => t('Cancelled')],
];
$badge = $statusMap[$status] ?? ['class' => 'secondary', 'label' => ucfirst($status)];
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
            <h2 style="color:#495057;font-weight:600;text-transform:uppercase;font-size:14pt;letter-spacing:2px;"><?= t('REQUEST FOR QUOTATION') ?></h2>
            <p style="color:#6c757d;font-size:9pt;"><?= t('Generated:') ?> <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 20px;"></div>
    </div>
    <div class="d-none d-print-block" style="position:fixed;bottom:0;left:0;right:0;border-top:1px solid #dee2e6;padding:5px 0;text-align:center;">
        <small style="color:#666;font-size:8pt;"><?= safe_output($c_name) ?> &mdash; RFQ #<?= safe_output($rfq['rfq_number']) ?> &mdash; <?= t('Printed:') ?> <?= date('d M Y, h:i A') ?></small>
    </div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>"><?= t('Dashboard') ?></a></li>
            <?php if ($from_project): ?>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($back_url) ?>"><?= t('Project RFQs') ?></a></li>
            <?php else: ?>
            <li class="breadcrumb-item"><a href="<?= getUrl('rfq') ?>"><?= t('RFQ') ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active"><?= safe_output($rfq['rfq_number']) ?></li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4 d-print-none">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-file-earmark-text text-primary me-2"></i><?= t('RFQ Details') ?></h2>
            <p class="text-muted mb-0 small"><?= t('View all details of this request for quotation') ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <!-- Status Badge -->
            <span class="badge bg-<?= $badge['class'] ?> fs-6 px-3 py-2"><?= $badge['label'] ?></span>

            <!-- Back Button -->
            <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-blue-touch btn-sm px-3 shadow-sm">
                <i class="bi bi-arrow-left me-1"></i> <?= $from_project ? t('Back to Project') : t('Back') ?>
            </a>

            <!-- ── WORKFLOW ACTION BUTTONS ── -->
            <?php if ($status === 'draft' && $can_review): ?>
            <button id="btnReview" class="btn btn-blue-touch btn-sm px-3 shadow-sm" onclick="submitForReview()">
                <i class="bi bi-eye-fill me-1"></i> <?= t('Review') ?>
            </button>
            <?php endif; ?>

            <?php if ($status === 'review' && $can_approve): ?>
            <button id="btnApprove" class="btn btn-success btn-sm px-3 shadow-sm" onclick="approveRFQ()">
                <i class="bi bi-check-circle-fill me-1"></i> <?= t('Approve') ?>
            </button>
            <?php endif; ?>
            <!-- ── END WORKFLOW ── -->

            <?php if ($status === 'approved' && !empty($rfq['supplier_id'])): ?>
            <?php
                $po_create_url = getUrl('purchase_order_create')
                    . '?supplier=' . (int)$rfq['supplier_id']
                    . '&rfq_ref='  . $rfq_id
                    . (!empty($rfq['project_id']) ? '&project=' . (int)$rfq['project_id'] : '')
                    . ($from_project ? '&back=procurement' : '');
            ?>
            <a href="<?= htmlspecialchars($po_create_url) ?>"
               class="btn btn-outline-primary btn-sm px-3">
                <i class="bi bi-cart-plus me-1"></i> <?= t('Create PO') ?>
            </a>
            <?php endif; ?>

            <div class="btn-group shadow-sm">
                <button onclick="printRfqDoc()" class="btn btn-blue-touch btn-sm px-3">
                    <i class="bi bi-printer me-1"></i> <?= t('Print') ?>
                </button>
                <button type="button" class="btn btn-blue-touch btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden"><?= t('Choose print template') ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header"><?= t('Print Template') ?></h6></li>
                    <li><a class="dropdown-item" href="#" onclick="printRfqDoc('standard'); return false;"><i class="bi bi-check2 me-2"></i><?= t('Standard (default)') ?></a></li>
                    <li><a class="dropdown-item" href="#" onclick="printRfqDoc('navy'); return false;"><?= t('Striped') ?></a></li>
                    <li><a class="dropdown-item" href="#" onclick="printRfqDoc('corporate'); return false;"><?= t('Minimal') ?></a></li>
                    <li><a class="dropdown-item" href="#" onclick="printRfqDoc('banded'); return false;"><?= t('Radiant') ?></a></li>
                </ul>
            </div>
            <?php if ($status === 'draft'): ?>
            <a href="<?= getUrl('rfq_create') ?>?edit=<?= $rfq_id ?><?= $return_url ? '&return_url=' . urlencode($back_url) : '' ?>" class="btn btn-outline-info btn-sm">
                <i class="bi bi-pencil me-1"></i> <?= t('Edit') ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- RFQ Info Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i><?= t('RFQ Information') ?></h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= t('RFQ Number') ?></p>
                    <span class="rfq-code"><?= safe_output($rfq['rfq_number']) ?></span>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= t('RFQ Date') ?></p>
                    <strong><?= safe_output($rfq['rfq_date'] ?? '—') ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= t('Deadline') ?></p>
                    <strong><?= safe_output($rfq['deadline_date'] ?? '—') ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= t('Status') ?></p>
                    <span class="badge bg-<?= $badge['class'] ?> text-uppercase"><?= $badge['label'] ?></span>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= t('Supplier') ?></p>
                    <strong><?= safe_output($rfq['supplier_name'] ?? '—') ?></strong>
                </div>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= t('Warehouse') ?></p>
                    <strong><?= safe_output($rfq['warehouse_name'] ?? '—') ?></strong>
                </div>
                <?php if (!empty($rfq['project_name'])): ?>
                <div class="col-6 col-md-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= t('Project') ?></p>
                    <strong><?= safe_output($rfq['project_name']) ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2"></i><?= t('RFQ Items') ?></h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-uppercase small fw-bold" style="background:#f8fafc;">
                        <tr>
                            <th class="ps-4" style="width:55px;"><?= t('S/No') ?></th>
                            <th><?= t('Description') ?></th>
                            <th style="width:130px;"><?= t('Unit') ?></th>
                            <th style="width:120px;"><?= t('Qty') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted"><?= t('No items found') ?></td></tr>
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

    <!-- ══════════════════════════════════════════
         AUTHORIZATION PANEL — 3-part signature block
         Visible only after at least one action
         ══════════════════════════════════════════ -->
    <?php
    $has_prepared = !empty($rfq['prepared_by_name']);
    $has_reviewed = !empty($rfq['reviewed_by_name']);
    $has_approved = !empty($rfq['approved_by_name']);

    if ($has_prepared || $has_reviewed || $has_approved):
    ?>
    <div class="card border-0 shadow-sm mb-4 auth-panel">
        <div class="card-header py-3" style="background:linear-gradient(135deg,#0d6efd15,#19875415);">
            <h6 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2 text-success"></i><?= t('Authorization Trail') ?></h6>
        </div>
        <div class="card-body">
            <div class="row g-3">

                <!-- Prepared By -->
                <div class="col-12 col-md-4">
                    <div class="auth-box auth-prepared p-3 rounded-3 h-100">
                        <div class="auth-label mb-2">
                            <i class="bi bi-pencil-square me-1"></i>
                            <span class="text-uppercase fw-bold small"><?= t('Prepared By') ?></span>
                        </div>
                        <?php if ($has_prepared): ?>
                        <div class="auth-name fw-bold"><?= safe_output($rfq['prepared_by_name']) ?></div>
                        <div class="auth-role text-muted small"><?= safe_output($rfq['prepared_by_role'] ?? '') ?></div>
                        <?php else: ?>
                        <div class="text-muted small fst-italic"><?= t('Not yet recorded') ?></div>
                        <?php endif; ?>
                        <div class="auth-line mt-3"></div>
                        <div class="auth-line-label small text-muted"><?= t('Signature') ?></div>
                    </div>
                </div>

                <!-- Reviewed By -->
                <div class="col-12 col-md-4">
                    <div class="auth-box auth-reviewed p-3 rounded-3 h-100">
                        <div class="auth-label mb-2">
                            <i class="bi bi-eye-fill me-1"></i>
                            <span class="text-uppercase fw-bold small"><?= t('Reviewed By') ?></span>
                        </div>
                        <?php if ($has_reviewed): ?>
                        <div class="auth-name fw-bold"><?= safe_output($rfq['reviewed_by_name']) ?></div>
                        <div class="auth-role text-muted small"><?= safe_output($rfq['reviewed_by_role'] ?? '') ?></div>
                        <div class="auth-date text-muted" style="font-size:.75rem;">
                            <?php if (!empty($rfq['reviewed_at'])): ?>
                                <?= date('d M Y, h:i A', strtotime($rfq['reviewed_at'])) ?>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-muted small fst-italic"><?= t('Pending review') ?></div>
                        <?php endif; ?>
                        <div class="auth-line mt-3"></div>
                        <div class="auth-line-label small text-muted"><?= t('Signature') ?></div>
                    </div>
                </div>

                <!-- Approved By -->
                <div class="col-12 col-md-4">
                    <div class="auth-box auth-approved p-3 rounded-3 h-100">
                        <div class="auth-label mb-2">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <span class="text-uppercase fw-bold small"><?= t('Approved By') ?></span>
                        </div>
                        <?php if ($has_approved): ?>
                        <div class="auth-name fw-bold"><?= safe_output($rfq['approved_by_name']) ?></div>
                        <div class="auth-role text-muted small"><?= safe_output($rfq['approved_by_role'] ?? '') ?></div>
                        <div class="auth-date text-muted" style="font-size:.75rem;">
                            <?php if (!empty($rfq['approved_at'])): ?>
                                <?= date('d M Y, h:i A', strtotime($rfq['approved_at'])) ?>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-muted small fst-italic"><?= t('Pending approval') ?></div>
                        <?php endif; ?>
                        <div class="auth-line mt-3"></div>
                        <div class="auth-line-label small text-muted"><?= t('Signature') ?></div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Attachments Card -->
    <?php if (!empty($attachments)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2"></i><?= t('Attachments') ?>
                <span class="badge bg-secondary ms-1"><?= count($attachments) ?></span>
            </h6>
        </div>
        <div class="card-body p-0">
            <ul class="list-group list-group-flush">
                <?php foreach ($attachments as $att): ?>
                <li class="list-group-item d-flex align-items-center gap-3 py-2 px-3">
                    <i class="bi bi-file-earmark text-primary fs-5"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= safe_output($att['attachment_name'] ?: $att['original_name']) ?></div>
                        <?php if ($att['attachment_name'] && $att['original_name'] && $att['attachment_name'] !== $att['original_name']): ?>
                        <div class="text-muted small"><?= safe_output($att['original_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <a href="<?= getUrl($att['file_path']) ?>" target="_blank"
                       class="btn btn-sm btn-outline-primary py-1 d-print-none">
                        <i class="bi bi-file-earmark-arrow-down me-1"></i><?= t('Download') ?>
                    </a>
                    <span class="d-none d-print-inline small text-muted"><?= safe_output($att['file_path']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /rfq-view-page -->

<style>
.rfq-code{color:#0f5132!important;background:#d1e7dd!important;padding:3px 8px;border-radius:5px;font-weight:700;}
.rfq-view-page .table thead th{border-bottom:2px solid #e2e8f0;padding:1rem;color:#475569;}

/* Blue on touch styling */
.btn-blue-touch {
    background-color: #0d6efd !important;
    border-color: #0d6efd !important;
    color: #fff !important;
    transition: all 0.2s ease;
}
.btn-blue-touch:hover, .btn-blue-touch:active, .btn-blue-touch:focus {
    background-color: #0b5ed7 !important;
    border-color: #0a58ca !important;
    color: #fff !important;
    box-shadow: 0 0 0 0.25rem rgba(49, 132, 253, 0.5) !important;
}

/* Auth panel styles */
.auth-box{border:1px solid #e9ecef;background:#fdfdfd;transition:box-shadow .2s;}
.auth-box:hover{box-shadow:0 2px 12px rgba(0,0,0,.08);}
.auth-prepared{border-left:4px solid #6c757d!important;}
.auth-reviewed{border-left:4px solid #0d6efd!important;}
.auth-approved{border-left:4px solid #198754!important;}
.auth-label{color:#6c757d;}
.auth-reviewed .auth-label{color:#0d6efd;}
.auth-approved .auth-label{color:#198754;}
.auth-name{font-size:1rem;color:#212529;}
.auth-line{border-bottom:1.5px solid #adb5bd;margin-top:1.5rem;}
.auth-line-label{margin-top:3px;letter-spacing:.5px;}

/* Print */
@media print{
    .d-print-none{display:none!important;}
    table{width:100%!important;border-collapse:collapse!important;}
    th,td{border:1px solid #dee2e6!important;padding:6px!important;font-size:9pt;}
    thead th{background:#f8f9fa!important;-webkit-print-color-adjust:exact;}
    .auth-panel{page-break-inside:avoid;}
    .auth-box{border:1px solid #dee2e6!important;padding:12px!important;}
    .auth-line{border-bottom:1px solid #333!important;}
}
</style>

<script>
const rfqId     = <?= $rfq_id ?>;
const reviewUrl = '<?= getUrl('api/review_rfq') ?>';
const approveUrl= '<?= getUrl('api/approve_rfq') ?>';

const RFQ_PRINT_TEMPLATES = {
    standard:  '<?= getUrl('print_rfq') ?>',
    navy:      '<?= getUrl('print_rfq_navy') ?>',
    corporate: '<?= getUrl('print_rfq_corporate') ?>',
    banded:    '<?= getUrl('print_rfq_banded') ?>'
};
function printRfqDoc(template) {
    const base = RFQ_PRINT_TEMPLATES[template] || RFQ_PRINT_TEMPLATES.standard;
    window.open(base + '?id=' + rfqId, '_blank');
}

function submitForReview() {
    Swal.fire({
        title: <?= json_encode(t('Submit for Review?')) ?>,
        text: <?= json_encode(sprintf(t('RFQ #%s will be sent for review. You will no longer be able to edit it.'), safe_output($rfq['rfq_number']))) ?>,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        confirmButtonText: <?= json_encode(t('Yes, Submit')) ?>,
        cancelButtonText: <?= json_encode(t('Cancel')) ?>
    }).then(result => {
        if (!result.isConfirmed) return;
        $('#btnReview').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> <?= t('Submitting...') ?>');
        $.post(reviewUrl, { rfq_id: rfqId }, function(res) {
            if (res.success) {
                Swal.fire({
                    icon: 'success', title: <?= json_encode(t('Submitted for Review!')) ?>,
                    text: res.message, confirmButtonColor: '#0d6efd',
                    confirmButtonText: <?= json_encode(t('OK')) ?>
                }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: res.message || <?= json_encode(t('Could not submit for review.')) ?> });
                $('#btnReview').prop('disabled', false).html('<i class="bi bi-eye-fill me-1"></i> <?= t('Review') ?>');
            }
        }, 'json').fail(() => {
            Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: <?= json_encode(t('Server error. Please try again.')) ?> });
            $('#btnReview').prop('disabled', false).html('<i class="bi bi-eye-fill me-1"></i> <?= t('Review') ?>');
        });
    });
}

function approveRFQ() {
    Swal.fire({
        title: <?= json_encode(t('Approve this RFQ?')) ?>,
        text: <?= json_encode(sprintf(t('RFQ #%s will be marked as approved.'), safe_output($rfq['rfq_number']))) ?>,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: <?= json_encode(t('Yes, Approve')) ?>,
        cancelButtonText: <?= json_encode(t('Cancel')) ?>
    }).then(result => {
        if (!result.isConfirmed) return;
        $('#btnApprove').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> <?= t('Approving...') ?>');
        $.post(approveUrl, { rfq_id: rfqId }, function(res) {
            if (res.success) {
                Swal.fire({
                    icon: 'success', title: <?= json_encode(t('RFQ Approved!')) ?>,
                    text: res.message, confirmButtonColor: '#198754',
                    confirmButtonText: <?= json_encode(t('OK')) ?>
                }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: res.message || <?= json_encode(t('Could not approve RFQ.')) ?> });
                $('#btnApprove').prop('disabled', false).html('<i class="bi bi-check-circle-fill me-1"></i> <?= t('Approve') ?>');
            }
        }, 'json').fail(() => {
            Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: <?= json_encode(t('Server error. Please try again.')) ?> });
            $('#btnApprove').prop('disabled', false).html('<i class="bi bi-check-circle-fill me-1"></i> <?= t('Approve') ?>');
        });
    });
}
</script>

<?php includeFooter(); ?>
