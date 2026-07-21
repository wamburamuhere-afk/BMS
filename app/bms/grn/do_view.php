<?php
// File: app/bms/grn/do_view.php
// scope-audit: skip — delivery order view page; Phase G-2 will add assertScopeForRecordHtml
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('do');
includeHeader();

global $pdo;

$do_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($do_id <= 0) { echo '<div class="alert alert-danger m-4">Invalid DO ID.</div>'; includeFooter(); exit; }

$stmt = $pdo->prepare("
    SELECT do.*,
           s.supplier_name, s.company_name, s.phone as supplier_phone,
           s.contact_person as supplier_contact,
           w.warehouse_name, w.location as warehouse_location,
           p.project_name, p.contract_number as contract_no,
           u.username as created_by_name
    FROM delivery_orders do
    LEFT JOIN suppliers s    ON do.supplier_id   = s.supplier_id
    LEFT JOIN warehouses w   ON do.warehouse_id  = w.warehouse_id
    LEFT JOIN projects p     ON do.project_id    = p.project_id
    LEFT JOIN users u        ON do.created_by    = u.user_id
    WHERE do.do_id = ?
");
$stmt->execute([$do_id]);
$do = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$do) { echo '<div class="alert alert-danger m-4">Delivery Order not found.</div>'; includeFooter(); exit; }

// Load delivery notes linked to this DO
$dns_stmt = $pdo->prepare("
    SELECT d.delivery_id, d.delivery_number, d.delivery_date, d.status
    FROM deliveries d WHERE d.do_id = ? ORDER BY d.delivery_id
");
$dns_stmt->execute([$do_id]);
$linked_dns = $dns_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load attachments (graceful if the table doesn't exist yet — mirrors the
// delivery_order_items guard below, so a pending migration can't crash the page)
$attachments = [];
try {
    $att_stmt = $pdo->prepare("SELECT do_attachment_id, attachment_name, file_path FROM do_attachments WHERE do_id = ? ORDER BY do_attachment_id");
    $att_stmt->execute([$do_id]);
    $attachments = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Load items (graceful if table doesn't exist yet)
$do_items      = [];
$hasItemsTable = false;
try { $pdo->query("SELECT 1 FROM delivery_order_items LIMIT 1"); $hasItemsTable = true; } catch (Exception $e) {}
if ($hasItemsTable) {
    $items_stmt = $pdo->prepare("SELECT item_id, product_name, available_qty, qty_to_issue, unit FROM delivery_order_items WHERE do_id = ? ORDER BY item_id");
    $items_stmt->execute([$do_id]);
    $do_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$project_id   = $do['project_id'];
$return_url   = getUrl('project_view') . '?id=' . $project_id . '&tab=procurement';

$status_colors = ['draft'=>'secondary','pending'=>'warning','approved'=>'success'];
$status_color  = $status_colors[$do['status']] ?? 'secondary';
?>

<div class="container-fluid mt-3">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $return_url ?>">Procurement</a></li>
            <li class="breadcrumb-item active">DO — <?= safe_output($do['do_number']) ?></li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4 d-print-none">
        <div>
            <h4 class="fw-bold mb-1">
                <i class="bi bi-file-earmark-check text-primary me-2"></i>
                Delivery Order — <span class="text-primary"><?= safe_output($do['do_number']) ?></span>
                <span class="badge bg-<?= $status_color ?> ms-2" style="font-size:.7rem;"><?= strtoupper(str_replace('_',' ',$do['status'])) ?></span>
            </h4>
            <p class="text-muted small mb-0">Project: <strong><?= safe_output($do['project_name']) ?></strong></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($do['status'] === 'draft'): ?>
            <button class="btn btn-warning btn-sm shadow-sm" onclick="changeDOStatus(<?= $do_id ?>, 'pending')">
                <i class="bi bi-arrow-right-circle me-1"></i> Move to Pending
            </button>
            <?php elseif ($do['status'] === 'pending'): ?>
            <button class="btn btn-success btn-sm shadow-sm" onclick="changeDOStatus(<?= $do_id ?>, 'approved')">
                <i class="bi bi-check2-all me-1"></i> Approve
            </button>
            <?php endif; ?>
            <div class="btn-group btn-group-sm shadow-sm">
                <button onclick="printDO(<?= $do_id ?>)" class="btn btn-outline-secondary">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
                <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">Choose print template</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">Print Template</h6></li>
                    <li><a class="dropdown-item" href="#" onclick="printDO(<?= $do_id ?>, 'standard'); return false;"><i class="bi bi-check2 me-2"></i>Standard (default)</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printDO(<?= $do_id ?>, 'manifest'); return false;">Manifest</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printDO(<?= $do_id ?>, 'convoy'); return false;">Convoy</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printDO(<?= $do_id ?>, 'waybill'); return false;">Waybill</a></li>
                </ul>
            </div>
            <a href="<?= $return_url ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- DO Info -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle text-primary me-2"></i>Delivery Order Details</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase mb-1">DO Number</div>
                                <div class="fw-bold text-primary"><?= safe_output($do['do_number']) ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase mb-1">Project</div>
                                <div class="fw-bold"><?= safe_output($do['project_name']) ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase mb-1">DO Date</div>
                                <div class="fw-bold"><?= format_date($do['do_date']) ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase mb-1">Expected Date</div>
                                <div class="fw-bold"><?= $do['expected_date'] ? format_date($do['expected_date']) : '<span class="text-muted">N/A</span>' ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase mb-1">Warehouse</div>
                                <div class="fw-bold"><i class="bi bi-building text-primary me-1"></i><?= safe_output($do['warehouse_name']) ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase mb-1">Supplier</div>
                                <div class="fw-bold"><?= safe_output($do['supplier_name']) ?></div>
                                <?php if (!empty($do['company_name'])): ?>
                                <small class="text-muted"><?= safe_output($do['company_name']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($do['driver_name'])): ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase mb-1">Driver</div>
                                <div class="fw-bold"><i class="bi bi-person me-1"></i><?= safe_output($do['driver_name']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($do['vehicle_number'])): ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase mb-1">Vehicle</div>
                                <div class="fw-bold"><i class="bi bi-truck me-1"></i><?= safe_output($do['vehicle_number']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($do['contact_person'])): ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase mb-1">Contact</div>
                                <div class="fw-bold"><?= safe_output($do['contact_person']) ?></div>
                                <?php if (!empty($do['contact_phone'])): ?>
                                <small class="text-muted"><?= safe_output($do['contact_phone']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($do['notes'])): ?>
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <div class="text-muted small fw-bold text-uppercase mb-1">Notes</div>
                                <div><?= safe_output($do['notes']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Items to Deliver -->
            <?php if (!empty($do_items)): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-list-check text-primary me-2"></i>Items to Deliver</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3" style="width:50px;">S/NO</th>
                                    <th>Product</th>
                                    <th class="text-center" style="width:120px;">Qty to Issue</th>
                                    <th class="text-center" style="width:90px;">Unit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($do_items as $i => $item): ?>
                                <tr>
                                    <td class="ps-3 text-muted fw-bold"><?= $i + 1 ?></td>
                                    <td class="fw-bold"><?= safe_output($item['product_name']) ?></td>
                                    <td class="text-center fw-bold text-primary"><?= number_format($item['qty_to_issue'], 3) ?></td>
                                    <td class="text-center"><?= safe_output($item['unit']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Linked Delivery Notes -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-truck-flatbed text-primary me-2"></i>Delivery Notes Issued Against This DO</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($linked_dns)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3">DN Number</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th class="d-print-none">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linked_dns as $dn): ?>
                                <?php $dnsc = ['draft'=>'secondary','review'=>'warning','approved'=>'success'][$dn['status']] ?? 'secondary'; ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-primary"><?= safe_output($dn['delivery_number']) ?></td>
                                    <td><small><?= format_date($dn['delivery_date']) ?></small></td>
                                    <td><span class="badge bg-<?= $dnsc ?>"><?= strtoupper($dn['status']) ?></span></td>
                                    <td class="d-print-none"><a href="<?= getUrl('dn_view') ?>?id=<?= $dn['delivery_id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2">View</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-3 text-muted small">No Delivery Notes have been issued against this DO yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Attachments -->
            <?php if (!empty($attachments)): ?>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip text-primary me-2"></i>Attachments</h6>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <?php foreach ($attachments as $att): ?>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center gap-2 p-2 border rounded bg-light">
                                <i class="bi bi-file-earmark fs-5 text-primary"></i>
                                <div>
                                    <div class="small text-muted"><?= safe_output($att['attachment_name'] ?: 'Attachment') ?></div>
                                    <a href="<?= getUrl($att['file_path']) ?>" target="_blank" class="btn btn-sm btn-primary py-0 px-2 mt-1">View Attachment</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Sidebar -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-activity me-2"></i>Status & Info</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Current Status</div>
                        <span class="badge bg-<?= $status_color ?> px-3 py-2 fs-6"><?= strtoupper(str_replace('_',' ',$do['status'])) ?></span>
                    </div>
                    <hr>
                    <div class="mb-2">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Created By</div>
                        <div class="fw-bold"><?= safe_output($do['created_by_name'] ?? 'N/A') ?></div>
                        <small class="text-muted"><?= format_date($do['created_at']) ?></small>
                    </div>
                    <hr>
                    <div class="mb-2">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Linked DNs</div>
                        <div class="fw-bold fs-5 text-primary"><?= count($linked_dns) ?></div>
                    </div>
                    <div>
                        <div class="text-muted small fw-bold text-uppercase mb-1">Attachments</div>
                        <div class="fw-bold fs-5 text-info"><?= count($attachments) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($do['status'] !== 'approved'): ?>
            <div class="card shadow-sm border-0 mt-4">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-arrow-repeat text-primary me-2"></i>Workflow Actions</h6>
                </div>
                <div class="card-body p-4">
                    <div class="d-grid gap-2">
                        <?php if ($do['status'] === 'draft'): ?>
                        <button class="btn btn-warning" onclick="changeDOStatus(<?= $do_id ?>, 'pending')">
                            <i class="bi bi-arrow-right-circle me-2"></i> Move to Pending
                        </button>
                        <?php elseif ($do['status'] === 'pending'): ?>
                        <button class="btn btn-success" onclick="changeDOStatus(<?= $do_id ?>, 'approved')">
                            <i class="bi bi-check2-all me-2"></i> Approve DO
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function changeDOStatus(doId, newStatus) {
    const cfg = {
        'pending':  { title: 'Move to Pending?',    text: 'This DO will be moved to Pending status.',    color: '#ffc107', btn: 'Yes, Move' },
        'approved': { title: 'Approve Delivery Order?', text: 'Once approved, this DO cannot be changed.', color: '#198754', btn: 'Yes, Approve' }
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
            $.post('<?= getUrl("api/operations/change_do_status") ?>', { do_id: doId, status: newStatus }, function(res) {
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

// No DataTable needed on this page

const DO_PRINT_TEMPLATES = {
    standard: '<?= getUrl('print_delivery_order') ?>',
    manifest: '<?= getUrl('print_delivery_order_manifest') ?>',
    convoy:   '<?= getUrl('print_delivery_order_convoy') ?>',
    waybill:  '<?= getUrl('print_delivery_order_waybill') ?>'
};
function printDO(id, template) {
    const base = DO_PRINT_TEMPLATES[template] || DO_PRINT_TEMPLATES.standard;
    window.open(base + '?id=' + id, '_blank');
}
</script>
<?php includeFooter(); ?>
