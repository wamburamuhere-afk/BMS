<?php
// File: app/bms/grn/do_create.php
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('do');
includeHeader();

global $pdo;

$dn_id      = isset($_GET['dn_id'])      ? intval($_GET['dn_id'])      : 0;
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

if ($dn_id <= 0 || $project_id <= 0) {
    echo '<div class="alert alert-danger m-4">DN ID and Project ID are required.</div>';
    includeFooter(); exit;
}

// Load DN
$stmt = $pdo->prepare("
    SELECT d.*, s.supplier_name, s.company_name, s.phone as supplier_phone,
           w.warehouse_name, p.project_name, p.contract_number as contract_no
    FROM deliveries d
    LEFT JOIN suppliers s  ON d.supplier_id  = s.supplier_id
    LEFT JOIN warehouses w ON d.warehouse_id = w.warehouse_id
    LEFT JOIN projects p   ON d.project_id   = p.project_id
    WHERE d.delivery_id = ? AND d.project_id = ? AND d.status = 'approved'
");
$stmt->execute([$dn_id, $project_id]);
$dn = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dn) {
    echo '<div class="alert alert-warning m-4"><i class="bi bi-exclamation-triangle me-2"></i>Delivery Note not found or not approved yet. Only approved DNs can have a Delivery Order.</div>';
    includeFooter(); exit;
}

// Check DO doesn't already exist
$check = $pdo->prepare("SELECT do_id, do_number FROM delivery_orders WHERE dn_id = ? LIMIT 1");
$check->execute([$dn_id]);
$existing_do = $check->fetch(PDO::FETCH_ASSOC);
if ($existing_do) {
    echo '<div class="alert alert-info m-4"><i class="bi bi-info-circle me-2"></i>A Delivery Order <strong>' . safe_output($existing_do['do_number']) . '</strong> already exists for this DN. <a href="' . getUrl('do_view') . '?id=' . $existing_do['do_id'] . '" class="alert-link">View it here</a>.</div>';
    includeFooter(); exit;
}

// Load DN items
$items_stmt = $pdo->prepare("
    SELECT di.*, p.product_name, p.sku, p.unit
    FROM delivery_items di
    LEFT JOIN products p ON di.product_id = p.product_id
    WHERE di.delivery_id = ?
");
$items_stmt->execute([$dn_id]);
$dn_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

$company_name = getSetting('company_name', 'BMS');
$company_logo = getSetting('company_logo', '');
$return_url   = getUrl('project_view') . '?id=' . $project_id . '&tab=procurement';
$total_qty    = array_sum(array_column($dn_items, 'quantity_delivered'));
?>

<div class="container-fluid mt-3">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $return_url ?>">Procurement</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('dn_view') ?>?id=<?= $dn_id ?>">DN — <?= safe_output($dn['delivery_number']) ?></a></li>
            <li class="breadcrumb-item active">Create Delivery Order</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-file-earmark-check text-primary me-2"></i>Create Delivery Order</h4>
            <p class="text-muted small mb-0">
                Based on DN: <strong class="text-primary"><?= safe_output($dn['delivery_number']) ?></strong>
                — Project: <strong><?= safe_output($dn['project_name']) ?></strong>
            </p>
        </div>
        <a href="<?= getUrl('dn_view') ?>?id=<?= $dn_id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to DN
        </a>
    </div>

    <form id="doForm">
        <input type="hidden" name="dn_id"       value="<?= $dn_id ?>">
        <input type="hidden" name="project_id"  value="<?= $project_id ?>">
        <input type="hidden" name="warehouse_id" value="<?= $dn['warehouse_id'] ?>">
        <input type="hidden" name="supplier_id"  value="<?= $dn['supplier_id'] ?>">

        <div class="row g-4">
            <!-- LEFT -->
            <div class="col-lg-8">
                <!-- DN Reference Card -->
                <div class="card shadow-sm border-primary border-2 mb-4">
                    <div class="card-header bg-primary bg-opacity-10 py-2">
                        <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-link-45deg me-2"></i>Referenced Delivery Note</h6>
                    </div>
                    <div class="card-body p-3">
                        <div class="row g-2 small">
                            <div class="col-sm-4"><span class="text-muted">DN Number:</span> <strong><?= safe_output($dn['delivery_number']) ?></strong></div>
                            <div class="col-sm-4"><span class="text-muted">Warehouse:</span> <strong><?= safe_output($dn['warehouse_name']) ?></strong></div>
                            <div class="col-sm-4"><span class="text-muted">Supplier:</span> <strong><?= safe_output($dn['supplier_name']) ?></strong></div>
                            <div class="col-sm-4"><span class="text-muted">DN Date:</span> <strong><?= format_date($dn['delivery_date']) ?></strong></div>
                            <div class="col-sm-4"><span class="text-muted">Total Items:</span> <strong><?= count($dn_items) ?></strong></div>
                            <div class="col-sm-4"><span class="text-muted">Total Qty:</span> <strong class="text-primary"><?= number_format($total_qty, 3) ?></strong></div>
                        </div>
                    </div>
                </div>

                <!-- DO Details -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-light py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-truck me-2 text-primary"></i>Delivery Order Details</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">DO Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="do_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Expected Delivery Date</label>
                                <input type="date" class="form-control" name="expected_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Driver Name</label>
                                <input type="text" class="form-control" name="driver_name" placeholder="Driver full name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Vehicle Number</label>
                                <input type="text" class="form-control" name="vehicle_number" placeholder="e.g., T 123 ABC">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contact Person</label>
                                <input type="text" class="form-control" name="contact_person"
                                    value="<?= safe_output($dn['contact_person'] ?? '') ?>"
                                    placeholder="Person at delivery site">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contact Phone</label>
                                <input type="text" class="form-control" name="contact_phone"
                                    value="<?= safe_output($dn['contact_phone'] ?? '') ?>"
                                    placeholder="+255...">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Notes / Instructions</label>
                                <textarea class="form-control" name="notes" rows="2"
                                    placeholder="Delivery instructions or special notes..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items from DN (read only) -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-list-task text-primary me-2"></i>Materials to be Delivered</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
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
                                    <?php foreach ($dn_items as $idx => $item): ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-bold"><?= $idx + 1 ?></td>
                                        <td><div class="fw-bold"><?= safe_output($item['product_name']) ?></div></td>
                                        <td><code><?= safe_output($item['sku'] ?? 'N/A') ?></code></td>
                                        <td class="text-center fw-bold text-primary"><?= number_format($item['quantity_delivered'], 3) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= safe_output($item['unit'] ?? 'pcs') ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-light fw-bold">
                                    <tr>
                                        <td colspan="3" class="text-end ps-3">Total</td>
                                        <td class="text-center text-primary"><?= number_format($total_qty, 3) ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 border-primary">
                    <div class="card-header bg-primary text-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-send-check me-2"></i>Create Delivery Order</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="alert alert-info small py-2 mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            This will create a Delivery Order referencing DN <strong><?= safe_output($dn['delivery_number']) ?></strong>.
                            Stock will be deducted when DO status is marked as <strong>Delivered</strong>.
                        </div>
                        <div class="mb-3 p-3 bg-light rounded border">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Warehouse:</span>
                                <span class="fw-bold"><?= safe_output($dn['warehouse_name']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Supplier:</span>
                                <span class="fw-bold"><?= safe_output($dn['supplier_name']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between small">
                                <span class="text-muted">Total Qty:</span>
                                <span class="fw-bold text-primary"><?= number_format($total_qty, 3) ?></span>
                            </div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary btn-lg shadow-sm" onclick="submitDO()">
                                <i class="bi bi-send me-2"></i> Create Delivery Order
                            </button>
                            <a href="<?= getUrl('dn_view') ?>?id=<?= $dn_id ?>" class="btn btn-link text-muted text-decoration-none">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function submitDO() {
    const doDate = $('[name="do_date"]').val();
    if (!doDate) {
        Swal.fire({ icon: 'warning', title: 'Missing Date', text: 'Please enter DO date.', confirmButtonColor: '#0d6efd' });
        return;
    }
    Swal.fire({ title: 'Creating Delivery Order...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.post('<?= getUrl("api/create_do") ?>', {
        dn_id:          <?= $dn_id ?>,
        project_id:     <?= $project_id ?>,
        warehouse_id:   <?= $dn['warehouse_id'] ?>,
        supplier_id:    <?= $dn['supplier_id'] ?>,
        do_date:        doDate,
        expected_date:  $('[name="expected_date"]').val(),
        driver_name:    $('[name="driver_name"]').val(),
        vehicle_number: $('[name="vehicle_number"]').val(),
        contact_person: $('[name="contact_person"]').val(),
        contact_phone:  $('[name="contact_phone"]').val(),
        notes:          $('[name="notes"]').val()
    }, function(res) {
        if (res.success) {
            Swal.fire({ icon: 'success', title: 'DO Created!', text: res.message, confirmButtonColor: '#198754' })
                .then(() => { window.location.href = '<?= getUrl("do_view") ?>?id=' + res.do_id; });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    }, 'json').fail(function() {
        Swal.fire({ icon: 'error', title: 'Server Error', text: 'Please try again.' });
    });
}
</script>
<?php includeFooter(); ?>
