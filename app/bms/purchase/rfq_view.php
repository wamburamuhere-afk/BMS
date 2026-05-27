<?php
// File: app/bms/purchase/rfq_view.php
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
autoEnforcePermission('rfq');
logActivity($pdo, $_SESSION['user_id'], 'VIEW', '[RFQ View] Page viewed');
includeHeader();

global $pdo;
$rfq_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$rfq_id) { header('Location: ' . getUrl('rfq')); exit; }
assertScopeForRecordHtml('rfq', 'rfq_id', $rfq_id);

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
    'draft'     => ['class' => 'secondary', 'label' => 'Draft'],
    'review'    => ['class' => 'primary',   'label' => 'In Review'],
    'approved'  => ['class' => 'success',   'label' => 'Approved'],
    'sent'      => ['class' => 'info',      'label' => 'Sent'],
    'received'  => ['class' => 'info',      'label' => 'Quote Received'],
    'evaluated' => ['class' => 'primary',   'label' => 'Evaluated'],
    'awarded'   => ['class' => 'success',   'label' => 'Awarded'],
    'cancelled' => ['class' => 'danger',    'label' => 'Cancelled'],
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
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <!-- Status Badge -->
            <span class="badge bg-<?= $badge['class'] ?> fs-6 px-3 py-2"><?= $badge['label'] ?></span>

            <!-- Back Button -->
            <a href="<?= getUrl('rfq') ?>" class="btn btn-blue-touch btn-sm px-3 shadow-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>

            <!-- ── WORKFLOW ACTION BUTTONS ── -->
            <?php if ($status === 'draft' && $can_review): ?>
            <button id="btnReview" class="btn btn-blue-touch btn-sm px-3 shadow-sm" onclick="submitForReview()">
                <i class="bi bi-eye-fill me-1"></i> Review
            </button>
            <?php endif; ?>

            <?php if ($status === 'review' && $can_approve): ?>
            <button id="btnApprove" class="btn btn-success btn-sm px-3 shadow-sm" onclick="approveRFQ()">
                <i class="bi bi-check-circle-fill me-1"></i> Approve
            </button>
            <?php endif; ?>
            <!-- ── END WORKFLOW ── -->

            <?php if ($status === 'approved' && !empty($rfq['supplier_id'])): ?>
            <a href="<?= getUrl('purchase_order_create') ?>?supplier=<?= $rfq['supplier_id'] ?>&rfq_ref=<?= $rfq_id ?>"
               class="btn btn-outline-primary btn-sm px-3">
                <i class="bi bi-cart-plus me-1"></i> Create PO
            </a>
            <?php endif; ?>

            <button onclick="window.open('<?= getUrl('print_rfq') ?>?id=<?= $rfq_id ?>', '_blank')" class="btn btn-blue-touch btn-sm px-3 shadow-sm">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <?php if ($status === 'draft'): ?>
            <a href="<?= getUrl('rfq_create') ?>?edit=<?= $rfq_id ?>" class="btn btn-outline-info btn-sm">
                <i class="bi bi-pencil me-1"></i> Edit
            </a>
            <?php endif; ?>
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
                    <span class="badge bg-<?= $badge['class'] ?> text-uppercase"><?= $badge['label'] ?></span>
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
    <div class="card border-0 shadow-sm mb-4">
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
            <h6 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2 text-success"></i>Authorization Trail</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">

                <!-- Prepared By -->
                <div class="col-12 col-md-4">
                    <div class="auth-box auth-prepared p-3 rounded-3 h-100">
                        <div class="auth-label mb-2">
                            <i class="bi bi-pencil-square me-1"></i>
                            <span class="text-uppercase fw-bold small">Prepared By</span>
                        </div>
                        <?php if ($has_prepared): ?>
                        <div class="auth-name fw-bold"><?= safe_output($rfq['prepared_by_name']) ?></div>
                        <div class="auth-role text-muted small"><?= safe_output($rfq['prepared_by_role'] ?? '') ?></div>
                        <?php else: ?>
                        <div class="text-muted small fst-italic">Not yet recorded</div>
                        <?php endif; ?>
                        <div class="auth-line mt-3"></div>
                        <div class="auth-line-label small text-muted">Signature</div>
                    </div>
                </div>

                <!-- Reviewed By -->
                <div class="col-12 col-md-4">
                    <div class="auth-box auth-reviewed p-3 rounded-3 h-100">
                        <div class="auth-label mb-2">
                            <i class="bi bi-eye-fill me-1"></i>
                            <span class="text-uppercase fw-bold small">Reviewed By</span>
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
                        <div class="text-muted small fst-italic">Pending review</div>
                        <?php endif; ?>
                        <div class="auth-line mt-3"></div>
                        <div class="auth-line-label small text-muted">Signature</div>
                    </div>
                </div>

                <!-- Approved By -->
                <div class="col-12 col-md-4">
                    <div class="auth-box auth-approved p-3 rounded-3 h-100">
                        <div class="auth-label mb-2">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <span class="text-uppercase fw-bold small">Approved By</span>
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
                        <div class="text-muted small fst-italic">Pending approval</div>
                        <?php endif; ?>
                        <div class="auth-line mt-3"></div>
                        <div class="auth-line-label small text-muted">Signature</div>
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
            <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2"></i>Attachments
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
                        <i class="bi bi-file-earmark-arrow-down me-1"></i>Download
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

function submitForReview() {
    Swal.fire({
        title: 'Submit for Review?',
        text: 'RFQ #<?= safe_output($rfq['rfq_number']) ?> will be sent for review. You will no longer be able to edit it.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        confirmButtonText: 'Yes, Submit',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        $('#btnReview').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Submitting...');
        $.post(reviewUrl, { rfq_id: rfqId }, function(res) {
            if (res.success) {
                Swal.fire({
                    icon: 'success', title: 'Submitted for Review!',
                    text: res.message, confirmButtonColor: '#0d6efd',
                    confirmButtonText: 'OK'
                }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not submit for review.' });
                $('#btnReview').prop('disabled', false).html('<i class="bi bi-eye-fill me-1"></i> Review');
            }
        }, 'json').fail(() => {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' });
            $('#btnReview').prop('disabled', false).html('<i class="bi bi-eye-fill me-1"></i> Review');
        });
    });
}

function approveRFQ() {
    Swal.fire({
        title: 'Approve this RFQ?',
        text: 'RFQ #<?= safe_output($rfq['rfq_number']) ?> will be marked as approved.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        $('#btnApprove').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Approving...');
        $.post(approveUrl, { rfq_id: rfqId }, function(res) {
            if (res.success) {
                Swal.fire({
                    icon: 'success', title: 'RFQ Approved!',
                    text: res.message, confirmButtonColor: '#198754',
                    confirmButtonText: 'OK'
                }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not approve RFQ.' });
                $('#btnApprove').prop('disabled', false).html('<i class="bi bi-check-circle-fill me-1"></i> Approve');
            }
        }, 'json').fail(() => {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' });
            $('#btnApprove').prop('disabled', false).html('<i class="bi bi-check-circle-fill me-1"></i> Approve');
        });
    });
}
</script>

<?php includeFooter(); ?>
