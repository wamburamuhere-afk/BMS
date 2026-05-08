<?php
// File: app/bms/grn/dn_view.php
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('dn');
includeHeader();

global $pdo;

$delivery_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($delivery_id <= 0) { echo '<div class="alert alert-danger m-4">Invalid DN ID.</div>'; includeFooter(); exit; }

$stmt = $pdo->prepare("
    SELECT d.*, s.supplier_name, s.company_name, s.phone as supplier_phone,
           s.address as supplier_address, s.contact_person as supplier_contact,
           w.warehouse_name, w.location as warehouse_location,
           p.project_name, p.contract_number as contract_no,
           u.username as created_by_name,
           ab.username as approved_by_name
    FROM deliveries d
    LEFT JOIN suppliers s   ON d.supplier_id  = s.supplier_id
    LEFT JOIN warehouses w  ON d.warehouse_id = w.warehouse_id
    LEFT JOIN projects p    ON d.project_id   = p.project_id
    LEFT JOIN users u       ON d.created_by   = u.user_id
    LEFT JOIN users ab      ON d.approved_by  = ab.user_id
    WHERE d.delivery_id = ?
");
$stmt->execute([$delivery_id]);
$dn = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$dn) { echo '<div class="alert alert-danger m-4">Delivery Note not found.</div>'; includeFooter(); exit; }

$items_stmt = $pdo->prepare("
    SELECT di.*, p.product_name, p.sku, p.unit
    FROM delivery_items di
    LEFT JOIN products p ON di.product_id = p.product_id
    WHERE di.delivery_id = ? ORDER BY di.delivery_item_id
");
$items_stmt->execute([$delivery_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load referenced DO if this DN has a do_id
$referenced_do = null;
if (!empty($dn['do_id'])) {
    $do_stmt = $pdo->prepare("SELECT do_id, do_number, status FROM delivery_orders WHERE do_id = ?");
    $do_stmt->execute([$dn['do_id']]);
    $referenced_do = $do_stmt->fetch(PDO::FETCH_ASSOC);
}

// Load Attachments
try {
    $att_stmt = $pdo->prepare("SELECT * FROM delivery_attachments WHERE delivery_id = ? ORDER BY uploaded_at ASC");
    $att_stmt->execute([$delivery_id]);
    $attachments = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If table is missing, create it automatically
    if ($e->getCode() == '42S02') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_attachments (
            attachment_id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(100),
            file_size INT,
            uploaded_by INT,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $attachments = [];
    } else {
        throw $e;
    }
}

$company_name = getSetting('company_name', 'BMS');
$company_logo = getSetting('company_logo', '');
$print_user   = ucwords(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
$print_role   = ucwords($_SESSION['user_role'] ?? 'Staff');
$print_date   = date('d M, Y \a\t h:i A');
$project_id   = $dn['project_id'];
$return_url   = getUrl('delivery_notes');

$status_colors = ['draft'=>'secondary','review'=>'warning','approved'=>'success','completed'=>'success'];
$status_color  = $status_colors[$dn['status']] ?? 'secondary';

$total_qty = array_sum(array_column($items, 'quantity_delivered'));
?>

<!-- PRINT HEADER -->
<div class="d-none d-print-block text-center mb-4">
    <?php if (!empty($company_logo)): ?>
    <div class="mb-2"><img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height:70px;width:auto;"></div>
    <?php endif; ?>
    <h1 style="color:#0d6efd;font-weight:800;text-transform:uppercase;font-size:18pt;margin:0;"><?= safe_output($company_name) ?></h1>
    <h2 style="font-weight:700;text-transform:uppercase;font-size:13pt;margin:4px 0;">DELIVERY NOTE</h2>
    <p style="margin:0;font-size:8pt;color:#555;">DN# <?= safe_output($dn['delivery_number']) ?> — <?= safe_output($dn['project_name']) ?></p>
    <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
</div>
<div class="print-footer d-none d-print-block">
    <p class="mb-1" style="font-size:7pt;">Printed by <strong><?= safe_output($print_user) ?> — <?= safe_output($print_role) ?></strong> on <strong><?= $print_date ?></strong></p>
    <p class="mb-0 fw-bold text-primary" style="font-size:8pt;">Powered By BJP Technologies &copy; 2026</p>
</div>

<div class="container-fluid mt-3">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $return_url ?>">Procurement</a></li>
            <li class="breadcrumb-item active">View DN</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4 d-print-none">
        <div>
            <h4 class="fw-bold mb-1">
                <i class="bi bi-truck-flatbed text-primary me-2"></i>
                Delivery Note — <span class="text-primary"><?= safe_output($dn['delivery_number']) ?></span>
                <span class="badge bg-<?= $status_color ?> ms-2" style="font-size:.7rem;"><?= strtoupper($dn['status']) ?></span>
            </h4>
            <p class="text-muted small mb-0">Project: <strong><?= safe_output($dn['project_name']) ?></strong>
                <?php if (!empty($dn['contract_no'])): ?> — Contract: <strong><?= safe_output($dn['contract_no']) ?></strong><?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (in_array($dn['status'], ['draft','review'])): ?>
            <a href="<?= getUrl('dn_create') ?>?project_id=<?= $project_id ?>&edit=<?= $delivery_id ?>" class="btn btn-warning btn-sm shadow-sm">
                <i class="bi bi-pencil me-1"></i> Edit
            </a>
            <?php endif; ?>
            <?php if ($dn['status'] === 'draft'): ?>
            <button class="btn btn-primary btn-sm shadow-sm" onclick="changeDNStatus(<?= $delivery_id ?>, 'review')">
                <i class="bi bi-send me-1"></i> Submit for Review
            </button>
            <button class="btn btn-success btn-sm shadow-sm" onclick="changeDNStatus(<?= $delivery_id ?>, 'approved')">
                <i class="bi bi-check2-all me-1"></i> Approve DN
            </button>
            <?php elseif ($dn['status'] === 'review'): ?>
            <button class="btn btn-success btn-sm shadow-sm" onclick="changeDNStatus(<?= $delivery_id ?>, 'approved')">
                <i class="bi bi-check2-all me-1"></i> Approve DN
            </button>
            <?php endif; ?>
            <?php if (in_array($dn['status'], ['draft','review'])): ?>
            <button class="btn btn-outline-danger btn-sm" onclick="deleteDN(<?= $delivery_id ?>)">
                <i class="bi bi-trash me-1"></i> Delete
            </button>
            <?php endif; ?>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.open('<?= getUrl('print_delivery_note') ?>?id=<?= $delivery_id ?>', '_blank')">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <a href="<?= $return_url ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Info Cards -->
        <div class="col-lg-8">
            <!-- DN Info -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle text-primary me-2"></i>Delivery Note Information</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">DN Number</div>
                                <div class="fw-bold text-primary"><?= safe_output($dn['delivery_number']) ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">DN Date</div>
                                <div class="fw-bold"><?= format_date($dn['delivery_date']) ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Warehouse</div>
                                <div class="fw-bold"><i class="bi bi-building text-primary me-1"></i><?= safe_output($dn['warehouse_name']) ?></div>
                                <?php if (!empty($dn['warehouse_location'])): ?>
                                <small class="text-muted"><?= safe_output($dn['warehouse_location']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Supplier</div>
                                <div class="fw-bold"><?= safe_output($dn['supplier_name']) ?></div>
                                <?php if (!empty($dn['company_name'])): ?>
                                <small class="text-muted"><?= safe_output($dn['company_name']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($dn['contact_person'])): ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Contact Person</div>
                                <div class="fw-bold"><?= safe_output($dn['contact_person']) ?></div>
                                <?php if (!empty($dn['contact_phone'])): ?>
                                <small class="text-muted"><i class="bi bi-telephone me-1"></i><?= safe_output($dn['contact_phone']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($dn['delivery_address'])): ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Delivery Address</div>
                                <div class="fw-bold"><?= safe_output($dn['delivery_address']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($dn['notes'])): ?>
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Notes</div>
                                <div><?= safe_output($dn['notes']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-list-task text-primary me-2"></i>Materials to be Delivered</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="dnItemsViewTable">
                            <thead class="bg-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3" style="width:50px;">S/NO</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th class="text-center">Quantity</th>
                                    <th>Unit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $idx => $item): ?>
                                <tr>
                                    <td class="ps-3 text-muted fw-bold"><?= $idx + 1 ?></td>
                                    <td><div class="fw-bold"><?= safe_output($item['product_name']) ?></div></td>
                                    <td><code><?= safe_output($item['sku'] ?? 'N/A') ?></code></td>
                                    <td class="text-center fw-bold text-primary fs-6"><?= number_format($item['quantity_delivered'], 3) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= safe_output($item['unit'] ?? 'pcs') ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end ps-3">Total</td>
                                    <td class="text-center text-primary fs-6"><?= number_format($total_qty, 3) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Sidebar -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-activity me-2"></i>Status & Audit</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Current Status</div>
                        <span class="badge bg-<?= $status_color ?> px-3 py-2 fs-6"><?= strtoupper($dn['status']) ?></span>
                    </div>
                    <hr>
                    <div class="mb-2">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Created By</div>
                        <div class="fw-bold"><?= safe_output($dn['created_by_name'] ?? 'N/A') ?></div>
                        <small class="text-muted"><?= format_date($dn['created_at']) ?></small>
                    </div>
                    <?php if ($dn['approved_by']): ?>
                    <div class="mb-2">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Approved By</div>
                        <div class="fw-bold text-success"><?= safe_output($dn['approved_by_name'] ?? 'N/A') ?></div>
                        <small class="text-muted"><?= format_date($dn['approved_at']) ?></small>
                    </div>
                    <?php endif; ?>
                    <hr>
                    <div class="mb-2">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Total Items</div>
                        <div class="fw-bold fs-5 text-primary"><?= count($items) ?></div>
                    </div>
                    <div>
                        <div class="text-muted small fw-bold text-uppercase mb-1">Total Quantity</div>
                        <div class="fw-bold fs-5 text-success"><?= number_format($total_qty, 3) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($referenced_do): ?>
            <div class="card shadow-sm border-0 border-primary mt-4">
                <div class="card-header bg-primary text-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-check me-2"></i>Referenced DO</h6>
                </div>
                <div class="card-body p-4 text-center">
                    <div class="fw-bold text-primary mb-2"><?= safe_output($referenced_do['do_number']) ?></div>
                    <span class="badge bg-<?= $status_colors[$referenced_do['status']] ?? 'secondary' ?> mb-3"><?= strtoupper($referenced_do['status']) ?></span>
                    <div class="d-grid">
                        <a href="<?= getUrl('do_view') ?>?id=<?= $referenced_do['do_id'] ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-eye me-1"></i> View Delivery Order
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Attachments -->
            <div class="card shadow-sm border-0 mt-4">
                <div class="card-header bg-dark text-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2"></i>Documents & Attachments</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($attachments)): ?>
                    <div class="p-4 text-center text-muted small">
                        <i class="bi bi-folder2-open fs-2 d-block mb-2"></i>
                        No documents attached to this DN.
                    </div>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($attachments as $att): ?>
                        <div class="list-group-item p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="me-2 text-truncate" style="max-width: 200px;">
                                    <div class="fw-bold text-dark small text-truncate" title="<?= safe_output($att['file_name']) ?>">
                                        <?= safe_output($att['file_name']) ?>
                                    </div>
                                    <small class="text-muted smallest"><?= format_bytes($att['file_size']) ?></small>
                                </div>
                                <a href="<?= getUrl($att['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-download"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function changeDNStatus(id, newStatus) {
    const cfg = {
        'review':   { title: 'Submit for Review?',  text: 'This DN will be sent for review.',            color: '#ffc107', btn: 'Yes, Submit' },
        'approved': { title: 'Approve Delivery Note?', text: 'Once approved, this DN cannot be changed.', color: '#198754', btn: 'Yes, Approve' }
    };
    const m = cfg[newStatus];
    Swal.fire({
        title: m.title, text: m.text, icon: 'question',
        showCancelButton: true,
        confirmButtonColor: m.color,
        confirmButtonText: m.btn,
        cancelButtonText: 'Cancel'
    }).then(r => {
        if (r.isConfirmed) {
            Swal.fire({ title: 'Updating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.post('<?= getUrl("api/operations/change_dn_status") ?>', { delivery_id: id, status: newStatus }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Updated!', text: res.message, confirmButtonColor: '#198754' })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            }, 'json');
        }
    });
}

function deleteDN(id) {
    Swal.fire({
        title: 'Delete Delivery Note?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then(r => {
        if (r.isConfirmed) {
            Swal.fire({ title: 'Deleting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.post('<?= getUrl("api/delete_dn") ?>', { delivery_id: id }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, confirmButtonColor: '#198754' })
                        .then(() => { window.location.href = '<?= $return_url ?>'; });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            }, 'json');
        }
    });
}

$(document).ready(function() {
    $('#dnItemsViewTable').DataTable({
        responsive: true, pageLength: 25,
        dom: '<"top d-print-none"f>rt<"clear">',
        columnDefs: [
            { responsivePriority: 1, targets: 0 },
            { responsivePriority: 1, targets: 1 },
            { responsivePriority: 3, targets: 2 },
            { responsivePriority: 1, targets: 3 },
            { responsivePriority: 2, targets: 4 }
        ]
    });
});
</script>

<style>
@page { size: A4; margin: 0.5in 0.5in 1.8cm 0.5in; }
body { padding-bottom: 2cm !important; }
.print-footer { position:fixed !important; bottom:0 !important; left:0; right:0; height:1.4cm; display:flex !important; flex-direction:column; justify-content:center; text-align:center; background:#fff !important; border-top:2px solid #0d6efd !important; font-size:7.5px; z-index:999999; -webkit-print-color-adjust:exact; }
@media print {
    .d-print-none { display:none !important; }
    .card { border:1px solid #dee2e6 !important; box-shadow:none !important; }
}
</style>
<?php includeFooter(); ?>
