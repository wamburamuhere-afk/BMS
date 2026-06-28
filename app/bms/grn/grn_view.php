<?php
// File: app/bms/grn/grn_view.php
require_once __DIR__ . '/../../../roots.php';

// Phase 5a — enforce view permission on GRN detail
autoEnforcePermission('grn');

require_once __DIR__ . '/../../../core/workflow.php';
// Include the header
includeHeader();

// Check permissions
if (!isAuthenticated()) {
    header("Location: login.php");
    exit();
}

// Three-approval workflow capabilities
$grn_can_review  = canReview('grn');
$grn_can_approve = canApprove('grn');
$grn_is_admin    = isAdmin();

// Get GRN ID
$receipt_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
// Origin context (URL only): set when arriving from inside a project, so the Back button
// and the Edit link return to the project instead of the general list.
$origin_project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$origin_qs = $origin_project_id > 0 ? ('&project_id=' . $origin_project_id) : '';

if ($receipt_id <= 0) {
    header("Location: grn.php?error=Invalid GRN ID");
    exit();
}

// Phase C — block viewing GRNs on projects not in user scope (HTML-safe)
assertScopeForRecordHtml('purchase_receipts', 'receipt_id', $receipt_id);

// Fetch GRN Details
global $pdo;
$stmt = $pdo->prepare("
    SELECT 
        pr.*,
        s.supplier_name,
        s.company_name,
        s.email as supplier_email,
        s.phone as supplier_phone,
        s.address as supplier_address,
        w.warehouse_name,
        u.username as created_by_name,
        u2.username as received_by_name,
        po.order_number
    FROM purchase_receipts pr
    LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
    LEFT JOIN warehouses w ON pr.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    LEFT JOIN users u2 ON pr.received_by = u2.user_id
    LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
    WHERE pr.receipt_id = ?
");
$stmt->execute([$receipt_id]);
$grn = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$grn) {
    header("Location: grn.php?error=GRN Not Found");
    exit();
}

// Fetch GRN Items
$stmtItems = $pdo->prepare("
    SELECT 
        ri.*,
        p.product_name,
        p.sku
    FROM receipt_items ri
    LEFT JOIN products p ON ri.product_id = p.product_id
    WHERE ri.receipt_id = ?
");
$stmtItems->execute([$receipt_id]);
$grnItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// DN Mode detection
$is_dn = isset($_GET['type']) && $_GET['type'] === 'delivery_note';
$doc_label = $is_dn ? 'Received Note' : 'Goods Received Note';
$doc_short = $is_dn ? 'DN' : 'GRN';

// Page Title
$page_title = $doc_short . " #" . $grn['receipt_number'];

// Fetch GRN Attachments
$stmtAtt = $pdo->prepare("SELECT * FROM purchase_receipt_attachments WHERE receipt_id = ? ORDER BY uploaded_at DESC");
$stmtAtt->execute([$receipt_id]);
$attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

// Log Audit
logAudit($pdo, $_SESSION['user_id'], "view", [
    'activity_type' => 'view',
    'entity_type' => 'grn',
    'entity_id' => $receipt_id,
    'description' => "Viewed Goods Received Note #" . $grn['receipt_number']
]);
?>

<style>
    @media print {
        .navbar, .btn, .d-print-none, #sidebar-column, .main-header {
            display: none !important;
        }
        body {
            background-color: #fff !important;
            margin: 5mm 15mm 15mm 15mm !important; /* Top margin reduced to 5mm */
            max-width: none !important;
            padding: 0 !important;
        }
        
        .container-fluid {
            margin-top: 0 !important;
            padding-top: 0 !important;
        }
        
        /* Card styling for print */
        .card {
            border: none !important;
            box-shadow: none !important;
            margin-bottom: 20px !important;
        }
        
        .card-header {
            background-color: #f8f9fa !important;
            border-bottom: 2px solid #000 !important;
            padding: 10px !important;
        }
        
        .card-body {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }
        
        /* Table styling */
        .table {
            border-color: #000 !important;
        }
        
        .table th {
            background-color: #f8f9fa !important;
            color: #000 !important;
            border-bottom: 2px solid #000 !important;
            padding: 8px !important;
        }
        
        .table td {
            border-bottom: 1px solid #ddd !important;
        }
        
        /* Badge styling */
        .badge {
            background-color: transparent !important;
            color: #000 !important;
            border: 1px solid #000 !important;
        }
        
        /* Text colors */
        .text-primary, .text-success, .text-danger, .text-warning, .text-info, .text-muted {
            color: #000 !important;
        }
        
        /* Layout adjustments */
        .col-lg-8 {
            width: 100% !important;
            flex: 0 0 100% !important;
            max-width: 100% !important;
        }

        #print-info-block {
            display: block !important;
        }
    }
</style>

<div class="container-fluid mt-4 mb-5">
    <!-- Page Header & Actions (Screen Only) -->
    <div class="row mb-4 d-print-none align-items-center">
        <div class="col-md-7">
            <h3 class="fw-bold text-dark mb-1">
                <i class="bi bi-file-earmark-check text-primary me-2"></i><?= $doc_label ?> Details
            </h3>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= getUrl('grn') ?>" class="text-decoration-none">Procurement</a></li>
                    <li class="breadcrumb-item active" aria-current="page">View <?= $doc_short ?></li>
                </ol>
            </nav>
        </div>
        <div class="col-md-5 text-md-end mt-3 mt-md-0">
            <div class="d-flex flex-wrap justify-content-md-end gap-2">
                <?php if ($origin_project_id > 0): ?>
                <a href="<?= getUrl('project_view') ?>?id=<?= $origin_project_id ?>&tab=procurement" class="btn btn-outline-primary px-3 shadow-sm">
                    <i class="bi bi-kanban me-1"></i> Back to Project
                </a>
                <?php endif; ?>
                <a href="<?= getUrl($is_dn ? 'delivery_notes' : 'grn') ?>" class="btn btn-outline-secondary px-3 shadow-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to List
                </a>
                <a href="<?= getUrl('grn_print') ?>?id=<?= $receipt_id ?>" target="_blank" class="btn btn-primary px-3 shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print <?= $doc_short ?>
                </a>
                <?php
                $grn_can_edit_now = canEdit('grn') && canEditDocument($grn['status'], $grn_is_admin);
                if ($grn_can_edit_now):
                ?>
                <a href="<?= getUrl('grn_edit') ?>?id=<?= $receipt_id ?><?= $origin_qs ?>" class="btn btn-outline-primary px-3 shadow-sm">
                    <i class="bi bi-pencil me-1"></i> Edit
                </a>
                <?php endif; ?>
                <?php
                // Three-approval sequential buttons — parallel pattern
                $grn_in_workflow = in_array($grn['status'], ['pending','reviewed'], true);
                if ($grn_in_workflow && $grn_can_review):
                    if ($grn['status'] === 'pending'):
                ?>
                <button type="button" class="btn btn-primary fw-bold px-3 shadow-sm" onclick="markReviewedFromView()">
                    <i class="bi bi-check2 me-1"></i> Mark Reviewed
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-outline-secondary px-3" disabled title="Already reviewed">
                    <i class="bi bi-check2 me-1"></i> Mark Reviewed
                </button>
                <?php
                    endif;
                endif;
                if ($grn_in_workflow && $grn_can_approve):
                    if ($grn['status'] === 'reviewed'):
                ?>
                <button type="button" class="btn btn-success fw-bold px-3 shadow-sm" onclick="approveGRNFromView()">
                    <i class="bi bi-check-circle me-1"></i> Approve GRN
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-outline-secondary px-3" disabled title="Must be reviewed before approval">
                    <i class="bi bi-check-circle me-1"></i> Approve GRN
                </button>
                <?php
                    endif;
                endif;
                ?>
            </div>
        </div>
    </div>

    <!-- Three-approval audit panel -->
    <?php
    $wf = [
        'created_by_name'  => $grn['created_by_name'] ?? '',
        'created_by_role'  => '',
        'created_at'       => $grn['created_at'] ?? '',
        'reviewed_by_name' => $grn['reviewed_by_name'] ?? '',
        'reviewed_by_role' => $grn['reviewed_by_role'] ?? '',
        'reviewed_at'      => $grn['reviewed_at']      ?? '',
        'approved_by_name' => $grn['approved_by_name'] ?? '',
        'approved_by_role' => $grn['approved_by_role'] ?? '',
        'approved_at'      => $grn['approved_at']      ?? '',
    ];
    require ROOT_DIR . '/includes/workflow_audit_panel.php';
    ?>

    <script>
    const GRN_ID = <?= (int)$receipt_id ?>;
    function markReviewedFromView() {
        Swal.fire({ title: 'Mark as Reviewed?', text: 'GRN will move to Reviewed and become approvable.', icon: 'question', showCancelButton: true, confirmButtonColor: '#0d6efd', confirmButtonText: 'Yes, mark reviewed' })
            .then(r => {
                if (!r.isConfirmed) return;
                $.post('<?= buildUrl('api/review_grn.php') ?>', { receipt_id: GRN_ID }, function(res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Reviewed!', text: res.message, timer: 1800, showConfirmButton: false }).then(() => location.reload());
                    } else { Swal.fire('Error', res.message, 'error'); }
                }, 'json');
            });
    }
    function approveGRNFromView() {
        Swal.fire({ title: 'Approve GRN?', text: 'Stock will be updated on approval.', icon: 'question', showCancelButton: true, confirmButtonColor: '#198754', confirmButtonText: 'Yes, approve' })
            .then(r => {
                if (!r.isConfirmed) return;
                $.post('<?= buildUrl('api/approve_grn.php') ?>', { receipt_id: GRN_ID }, function(res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Approved!', text: res.message, timer: 2000, showConfirmButton: false }).then(() => location.reload());
                    } else { Swal.fire('Error', res.message, 'error'); }
                }, 'json');
            });
    }
    </script>

    <!-- Print Header (Visible only when printing) -->
    <div class="d-none d-print-block text-center mb-4">
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
        ?>
       
        
        <div class="mt-4 text-center">
            <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 18pt; letter-spacing: 2px;"><?= $doc_label ?></h2>
            <h4 style="color: #000; margin: 0; font-size: 14pt;"><?= $doc_short ?> #<?= safe_output($grn['receipt_number']) ?></h4>
            <p style="color: #000; margin: 0; font-size: 11pt;">Date: <?= date('d M Y', strtotime($grn['receipt_date'])) ?></p>
        </div>
        <div style="border-bottom: 3px solid #000; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Status Alert -->
    <div class="alert alert-<?= get_status_badge_class($grn['status']) ?> d-flex align-items-center" role="alert">
        <i class="bi bi-info-circle-fill me-2"></i>
        <div>
            <strong>Current Status:</strong> <?= ucfirst(str_replace('_', ' ', $grn['status'])) ?>
        </div>
    </div>

    <div class="row">
        
        <!-- Print-only Info Block (Visible in print only, full width) -->
        <div id="print-info-block" class="col-12 d-none d-print-block mb-4">
            <div class="row">
                <div class="col-6">
                    <h5 class="fw-bold mb-2 text-decoration-underline">Supplier</h5>
                    <div class="fw-bold"><?= safe_output($grn['supplier_name']) ?></div>
                    <?php if (!empty($grn['company_name'])): ?>
                        <div><?= safe_output($grn['company_name']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($grn['supplier_phone'])): ?>
                        <div>Tel: <?= safe_output($grn['supplier_phone']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-6 text-end">
                    <h5 class="fw-bold mb-2 text-decoration-underline">Details</h5>
                    <div><strong>Warehouse:</strong> <?= safe_output($grn['warehouse_name']) ?></div>
                    <?php if (!empty($grn['order_number'])): ?>
                        <div><strong>PO Ref:</strong> <?= safe_output($grn['order_number']) ?></div>
                    <?php endif; ?>
                    <div><strong>Received By:</strong> <?= safe_output($grn['received_by_name'] ?? 'N/A') ?></div>
                </div>
            </div>
        </div>

        <!-- Main GRN Info -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-primary">Received Items</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4" style="width: 80px;">S/NO</th>
                                    <th>Product / Item</th>
                                    <th class="text-center" style="width: 150px;">Quantity</th>
                                    <?php if (!$is_dn): ?>
                                    <th class="text-end" style="width: 150px;">Unit Price (TZS)</th>
                                    <th class="text-end pe-4" style="width: 150px;">Total (TZS)</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_cost = 0;
                                foreach ($grnItems as $index => $item): 
                                    $item_total = $item['quantity_received'] * $item['unit_price'];
                                    $total_cost += $item_total;
                                ?>
                                <tr>
                                    <td class="ps-4 text-muted fw-bold"><?= $index + 1 ?></td>
                                    <td>
                                        <div class="fw-bold"><?= safe_output($item['product_name']) ?></div>
                                        <?php if($item['sku']): ?>
                                            <small class="text-muted">SKU: <?= safe_output($item['sku']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border">
                                            <?= $item['quantity_received'] ?> <?= safe_output($item['unit'] ?? '') ?>
                                        </span>
                                    </td>
                                    <?php if (!$is_dn): ?>
                                    <td class="text-end font-monospace"><?= number_format($item['unit_price'], 2) ?></td>
                                    <td class="text-end pe-4 fw-bold font-monospace"><?= number_format($item_total, 2) ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if (!$is_dn): ?>
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="4" class="text-end fw-bold fs-5">Total Value:</td>
                                    <td class="text-end pe-4 fw-bold fs-5 text-primary font-monospace">
                                        <?= number_format($total_cost, 2) ?>
                                    </td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Attachments Section -->
            <?php if (!empty($attachments)): ?>
            <div class="card shadow-sm mb-4 d-print-none">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-paperclip me-2"></i> Attachments & Documents</h6>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php foreach ($attachments as $att): 
                            $ext = strtolower(pathinfo($att['file_path'], PATHINFO_EXTENSION));
                            $icon = 'bi-file-earmark-text';
                            $icon_color = 'text-primary';
                            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                $icon = 'bi-file-earmark-image';
                                $icon_color = 'text-success';
                            }
                            if ($ext === 'pdf') {
                                $icon = 'bi-file-earmark-pdf';
                                $icon_color = 'text-danger';
                            }
                            
                            $file_url = '../../../' . $att['file_path'];
                        ?>
                        <div class="list-group-item d-flex align-items-center justify-content-between py-3 border-bottom">
                            <div class="d-flex align-items-center flex-grow-1 overflow-hidden">
                                <i class="bi <?= $icon ?> fs-4 <?= $icon_color ?> me-3"></i>
                                <div class="text-truncate me-3">
                                    <span class="fw-bold text-dark"><?= safe_output($att['file_name']) ?></span>
                                    <span class="text-muted small ms-2">(<?= strtoupper($ext) ?>)</span>
                                </div>
                            </div>
                            <a href="<?= htmlspecialchars($file_url) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 flex-shrink-0" target="_blank">
                                <i class="bi bi-eye me-1"></i> View
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Notes Section -->
            <?php if (!empty($grn['notes'])): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">GRN Notes</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted"><?= nl2br(safe_output($grn['notes'])) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Info -->
        <div id="sidebar-column" class="col-lg-4 d-print-none">
            <!-- Printing Sidebar Info manually in main column for better layout or keeping it stacked? 
                 User wants professional layout. Often sidebars at bottom in print is bad.
                 Let's keep it stacked but styling handles width. 
                 Actually, let's create a specific print layout section for Supplier/GRN info at the TOP.
            -->
            <!-- Supplier Info -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Supplier Information</h6>
                </div>
                <div class="card-body">
                    <h5 class="fw-bold mb-1"><?= safe_output($grn['supplier_name']) ?></h5>
                    <?php if (!empty($grn['company_name'])): ?>
                        <div class="text-muted mb-2"><?= safe_output($grn['company_name']) ?></div>
                    <?php endif; ?>
                    
                    <hr class="my-3">
                    
                    <?php if (!empty($grn['supplier_email'])): ?>
                        <div class="d-flex mb-2">
                            <i class="bi bi-envelope text-muted me-2"></i>
                            <span><?= safe_output($grn['supplier_email']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($grn['supplier_phone'])): ?>
                        <div class="d-flex mb-2">
                            <i class="bi bi-telephone text-muted me-2"></i>
                            <span><?= safe_output($grn['supplier_phone']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($grn['supplier_address'])): ?>
                        <div class="d-flex mb-2">
                            <i class="bi bi-geo-alt text-muted me-2"></i>
                            <span><?= safe_output($grn['supplier_address']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- GRN Info -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">GRN Information</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Receipt Date:</span>
                        <span class="fw-medium"><?= date('M d, Y', strtotime($grn['receipt_date'])) ?></span>
                    </div>
                    <?php if (!empty($grn['order_number'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Purchase Order:</span>
                        <span class="fw-medium">
                            <a href="purchase_order_view.php?id=<?= $grn['purchase_order_id'] ?>" class="text-decoration-none">
                                <?= safe_output($grn['order_number']) ?>
                            </a>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Warehouse:</span>
                        <span class="fw-medium"><?= safe_output($grn['warehouse_name']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Received By:</span>
                        <span class="fw-medium"><?= safe_output($grn['received_by_name'] ?? 'N/A') ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Created By:</span>
                        <span class="fw-medium"><?= safe_output($grn['created_by_name'] ?? 'N/A') ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Print-only Info Block (Top of page usually) -->
        <div class="d-none d-print-block w-100 mb-4">
            <div class="row">
                <div class="col-6">
                    <h5 class="fw-bold mb-2 text-decoration-underline">Supplier</h5>
                    <div class="fw-bold"><?= safe_output($grn['supplier_name']) ?></div>
                    <?php if (!empty($grn['company_name'])): ?>
                        <div><?= safe_output($grn['company_name']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($grn['supplier_phone'])): ?>
                        <div>Tel: <?= safe_output($grn['supplier_phone']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-6 text-end">
                    <h5 class="fw-bold mb-2 text-decoration-underline">Details</h5>
                    <div><strong>Warehouse:</strong> <?= safe_output($grn['warehouse_name']) ?></div>
                    <?php if (!empty($grn['order_number'])): ?>
                        <div><strong>PO Ref:</strong> <?= safe_output($grn['order_number']) ?></div>
                    <?php endif; ?>
                    <div><strong>Received By:</strong> <?= safe_output($grn['received_by_name'] ?? 'N/A') ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
function get_status_badge_class($status) {
    switch ($status) {
        case 'completed': return 'success';
        case 'pending': return 'warning';
        case 'cancelled': return 'danger';
        case 'draft': return 'secondary';
        default: return 'primary';
    }
}
includeFooter(); 
?>
<script>
    // Auto-print if requested via URL parameter
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === 'true') {
            window.print();
        }
    });
</script>
