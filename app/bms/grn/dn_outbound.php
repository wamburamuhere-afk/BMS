<?php
// File: app/bms/grn/dn_outbound.php
// scope-audit: skip — outbound DN create form; no prior record to scope; Phase G-2
// CREATE a Delivery Note — OUTBOUND: goods sent TO a Supplier / Sub-Contractor
// ("DN ya kupeleka nje"). The DN number is generated automatically; no attachment.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('dn');

global $pdo;

$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
// An inbound (Record) DN must be edited on the Record form — redirect before output.
if ($edit_id > 0) {
    $peek = $pdo->prepare("SELECT dn_type FROM deliveries WHERE delivery_id = ?");
    $peek->execute([$edit_id]);
    if ($peek->fetchColumn() === 'inbound') {
        header('Location: ' . getUrl('dn_create') . '?edit=' . $edit_id);
        exit;
    }
}

includeHeader();

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$is_edit    = $edit_id > 0;

// ── LOAD DN (edit mode) ──────────────────────────────────────
$dn = null; $dn_items = [];
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
    $stmt->execute([$edit_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dn) {
        $project_id = intval($dn['project_id'] ?? 0);
        $stmt2 = $pdo->prepare("SELECT di.*, p.product_name, p.sku, p.unit FROM delivery_items di LEFT JOIN products p ON di.product_id = p.product_id WHERE di.delivery_id = ? ORDER BY di.delivery_item_id");
        $stmt2->execute([$edit_id]);
        $dn_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── LPO LINK (create mode only) — customer-party outbound DN, prefilled with
// remaining-to-deliver quantities from the LPO's items ─────────────────────
$lpo = null; $lpo_items = []; $lpo_customer = null;
$lpo_id = (!$is_edit && isset($_GET['lpo_id'])) ? intval($_GET['lpo_id']) : 0;
if ($lpo_id > 0) {
    $lstmt = $pdo->prepare("SELECT * FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
    $lstmt->execute([$lpo_id]);
    $lpo = $lstmt->fetch(PDO::FETCH_ASSOC);
    if ($lpo && in_array($lpo['status'], ['approved', 'partially_fulfilled'], true)) {
        $cstmt = $pdo->prepare("SELECT customer_id, customer_name, company_name, customer_type FROM customers WHERE customer_id = ? AND status = 'active'");
        $cstmt->execute([$lpo['customer_id']]);
        $lpo_customer = $cstmt->fetch(PDO::FETCH_ASSOC);

        if ($lpo_customer) {
            $project_id = intval($lpo['project_id'] ?? 0);
            // Ordered qty per product on the LPO, minus already-delivered qty on
            // prior non-cancelled outbound DNs linked to this LPO (remaining-to-deliver).
            $iistmt = $pdo->prepare("
                SELECT loi.product_id, loi.product_name, loi.quantity, p.unit
                FROM customer_lpo_items loi
                LEFT JOIN products p ON loi.product_id = p.product_id
                WHERE loi.lpo_id = ? AND loi.product_id IS NOT NULL
            ");
            $iistmt->execute([$lpo_id]);
            $ordered = $iistmt->fetchAll(PDO::FETCH_ASSOC);

            $dstmt = $pdo->prepare("
                SELECT di.product_id, SUM(di.quantity_delivered) AS delivered
                FROM delivery_items di
                JOIN deliveries d ON di.delivery_id = d.delivery_id
                WHERE d.customer_lpo_id = ? AND d.status != 'cancelled'
                GROUP BY di.product_id
            ");
            $dstmt->execute([$lpo_id]);
            $delivered_by_product = [];
            foreach ($dstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $delivered_by_product[$r['product_id']] = (float)$r['delivered'];
            }

            foreach ($ordered as $o) {
                $remaining = (float)$o['quantity'] - ($delivered_by_product[$o['product_id']] ?? 0);
                if ($remaining > 0.0001) {
                    $lpo_items[] = ['product_id' => $o['product_id'], 'product_name' => $o['product_name'], 'remaining' => $remaining, 'unit' => $o['unit'] ?: 'pcs'];
                }
            }
        } else {
            $lpo = null; // customer inactive/missing — treat as no LPO link
        }
    } else {
        $lpo = null; // not in an eligible status
    }
}

$has_project = $project_id > 0;

// ── LISTS — scoped by project for non-admins ─────────────────
$_dno_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));

if (isAdmin()) {
    $all_projects   = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
    $all_warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, location, IFNULL(project_id,0) AS project_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
    $all_suppliers  = $pdo->query("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_dno_assigned)) {
    $_dno_ph = implode(',', array_fill(0, count($_dno_assigned), '?'));
    $_dno_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($_dno_ph) ORDER BY project_name");
    $_dno_pstmt->execute($_dno_assigned);
    $all_projects = $_dno_pstmt->fetchAll(PDO::FETCH_ASSOC);
    $_dno_wstmt = $pdo->prepare("SELECT warehouse_id, warehouse_name, location, IFNULL(project_id,0) AS project_id FROM warehouses WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_dno_ph)) ORDER BY warehouse_name");
    $_dno_wstmt->execute($_dno_assigned);
    $all_warehouses = $_dno_wstmt->fetchAll(PDO::FETCH_ASSOC);
    $_dno_sstmt = $pdo->prepare("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_dno_ph)) ORDER BY supplier_name");
    $_dno_sstmt->execute($_dno_assigned);
    $all_suppliers = $_dno_sstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $all_projects   = [];
    $all_warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, location, IFNULL(project_id,0) AS project_id FROM warehouses WHERE status = 'active' AND project_id IS NULL ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
    $all_suppliers  = $pdo->query("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' AND project_id IS NULL ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
}
$all_subs = $pdo->query("SELECT supplier_id, supplier_name, company_name FROM sub_contractors WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

// Currently-selected party (edit mode)
$cur_party_type = 'supplier';
$cur_party_id   = 0;
if ($dn) {
    $cur_party_type = in_array($dn['party_type'], ['subcontractor', 'customer'], true) ? $dn['party_type'] : 'supplier';
    if ($cur_party_type === 'subcontractor')  $cur_party_id = intval($dn['subcontractor_id'] ?? 0);
    elseif ($cur_party_type === 'customer')   $cur_party_id = intval($dn['customer_id'] ?? 0);
    else                                       $cur_party_id = intval($dn['supplier_id'] ?? 0);
} elseif ($lpo && $lpo_customer) {
    $cur_party_type = 'customer';
    $cur_party_id   = (int)$lpo_customer['customer_id'];
}

// Locked customer display name — either the LPO's customer (create flow) or
// the existing DN's linked customer (edit flow, party_type='customer').
$locked_customer_name = null;
if ($cur_party_type === 'customer') {
    if ($lpo_customer) {
        $locked_customer_name = ($lpo_customer['customer_type'] === 'business' && !empty($lpo_customer['company_name']))
            ? $lpo_customer['company_name'] : $lpo_customer['customer_name'];
    } elseif ($cur_party_id > 0) {
        $ccstmt = $pdo->prepare("SELECT customer_name, company_name, customer_type FROM customers WHERE customer_id = ?");
        $ccstmt->execute([$cur_party_id]);
        $cc = $ccstmt->fetch(PDO::FETCH_ASSOC);
        if ($cc) {
            $locked_customer_name = ($cc['customer_type'] === 'business' && !empty($cc['company_name'])) ? $cc['company_name'] : $cc['customer_name'];
        }
    }
}

$return_url = getUrl('delivery_notes');
?>

<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>

<div class="container-fluid mt-3">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none dn-sticky-nav">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $return_url ?>">Delivery Notes</a></li>
            <li class="breadcrumb-item active"><?= $is_edit ? 'Edit Outbound DN' : 'Create Delivery Note' ?></li>
        </ol>
    </nav>

    <div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-start gap-3 mb-4 d-print-none">
        <div>
            <h4 class="fw-bold mb-1">
                <i class="bi bi-box-arrow-up-right text-primary me-2"></i>
                <?= $is_edit ? 'Edit Outbound Delivery Note' : 'Create Delivery Note' ?>
                <span class="badge bg-primary-subtle text-primary border border-primary ms-1" style="font-size:.65rem;">OUTBOUND</span>
            </h4>
            <p class="text-muted small mb-0">Goods <strong>sent to</strong> a supplier, sub-contractor, or customer (LPO fulfillment) — the DN number is generated automatically.</p>
        </div>
        <a href="<?= $return_url ?>" class="btn btn-outline-secondary btn-sm flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>

    <?php if ($lpo && $lpo_customer): ?>
    <div class="alert alert-info d-flex align-items-center gap-2 d-print-none">
        <i class="bi bi-info-circle fs-5"></i>
        <div>
            Pre-filling from Customer LPO <strong><?= safe_output($lpo['lpo_number']) ?></strong>
            — <strong><?= safe_output($locked_customer_name) ?></strong>
            — <?= count($lpo_items) ?> item(s) with remaining quantity loaded.
            <a href="<?= getUrl('lpo_view') ?>?id=<?= $lpo_id ?>" class="ms-2">Back to LPO</a>
        </div>
    </div>
    <?php endif; ?>

    <form id="dnForm">
        <?php if ($is_edit): ?><input type="hidden" name="delivery_id" value="<?= $edit_id ?>"><?php endif; ?>
        <?php if ($lpo): ?><input type="hidden" name="customer_lpo_id" value="<?= $lpo_id ?>"><?php elseif ($is_edit && !empty($dn['customer_lpo_id'])): ?><input type="hidden" name="customer_lpo_id" value="<?= (int)$dn['customer_lpo_id'] ?>"><?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i>Delivery Note Details</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <!-- DN NUMBER — auto -->
                            <div class="col-12">
                                <label class="form-label fw-semibold">DN Number</label>
                                <div class="form-control bg-light fw-bold text-primary">
                                    <i class="bi bi-magic me-1"></i>
                                    <?= $is_edit && $dn ? safe_output($dn['delivery_number']) : 'Generated automatically on save' ?>
                                </div>
                                <small class="text-muted">Outbound delivery notes are numbered automatically by the system.</small>
                            </div>

                            <hr class="my-1">

                            <?php if ($cur_party_type === 'customer'): ?>
                            <!-- LOCKED CUSTOMER PARTY (from Customer LPO) -->
                            <input type="hidden" name="party_type" id="dn_party_type" value="customer">
                            <input type="hidden" name="party_id" id="dn_party_id" value="<?= $cur_party_id ?>">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Send To</label>
                                <div class="form-control bg-light fw-bold"><i class="bi bi-person-check me-1"></i> <?= safe_output($locked_customer_name) ?></div>
                                <small class="text-muted">Customer — locked from the linked LPO.</small>
                            </div>
                            <?php else: ?>
                            <!-- PARTY TYPE (dropdown) -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Send To <span class="text-danger">*</span></label>
                                <select class="form-select" name="party_type" id="dn_party_type">
                                    <option value="supplier" <?= $cur_party_type === 'supplier' ? 'selected' : '' ?>>Supplier</option>
                                    <option value="subcontractor" <?= $cur_party_type === 'subcontractor' ? 'selected' : '' ?>>Sub-Contractor</option>
                                </select>
                            </div>

                            <!-- SPECIFIC PARTY -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" id="partyLabel">Select Supplier <span class="text-danger">*</span></label>
                                <select class="form-select" name="party_id" id="dn_party_id" required></select>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Project <span class="text-muted small">(Optional)</span></label>
                                <select class="form-select select2-static" name="project_id" id="dn_project_id">
                                    <option value="0">-- No Project (General) --</option>
                                    <?php foreach ($all_projects as $p): ?>
                                    <option value="<?= $p['project_id'] ?>" <?= ($project_id == $p['project_id']) ? 'selected' : '' ?>><?= safe_output($p['project_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Warehouse (Source) <span class="text-danger">*</span></label>
                                <select class="form-select" name="warehouse_id" id="dn_warehouse_id" required>
                                    <option value="">-- Select Warehouse --</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">DN Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="delivery_date" value="<?= $dn ? $dn['delivery_date'] : date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contact Person</label>
                                <input type="text" class="form-control" name="contact_person" value="<?= $dn ? safe_output($dn['contact_person']) : '' ?>" placeholder="Person receiving the goods">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contact Phone</label>
                                <input type="text" class="form-control" name="contact_phone" value="<?= $dn ? safe_output($dn['contact_phone']) : '' ?>" placeholder="+255...">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Delivery Address</label>
                                <input type="text" class="form-control" name="delivery_address" value="<?= $dn ? safe_output($dn['delivery_address']) : '' ?>" placeholder="Destination address">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Notes</label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes..."><?= $dn ? safe_output($dn['notes']) : '' ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i>Materials to Send</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="dnItemsTable" style="min-width:560px;">
                                <thead class="bg-light text-uppercase small fw-bold">
                                    <tr>
                                        <th class="ps-3" style="width:50px;">S/NO</th>
                                        <th>Product</th>
                                        <th style="width:120px;">Available</th>
                                        <th style="width:130px;">Qty to Send</th>
                                        <th style="width:80px;">Unit</th>
                                        <th style="width:55px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="dnItemsBody"></tbody>
                            </table>
                        </div>
                        <div id="dnItemsEmpty" class="text-center py-4 text-muted">
                            <i class="bi bi-box-seam fs-3 d-block mb-2 opacity-25"></i>
                            <p class="small">Select a warehouse first, then click "Add Item"</p>
                        </div>
                        <div class="p-3 border-top">
                            <button type="button" class="btn btn-primary btn-sm" onclick="addDNItem()">
                                <i class="bi bi-plus-circle me-1"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm border-0 border-primary mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-clipboard-check me-2"></i>DN Summary</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Items:</span>
                            <span class="fw-bold" id="dnTotalItems">0</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Qty:</span>
                            <span class="fw-bold text-primary" id="dnTotalQty">0</span>
                        </div>
                        <hr>
                        <div class="alert alert-primary small py-2 mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            This creates an outbound delivery note for goods <strong>sent to</strong> the selected party. No attachment is required.
                        </div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary shadow-sm px-4" onclick="submitDN('draft')">
                                <i class="bi bi-save me-2"></i> <?= $is_edit ? 'Update DN' : 'Create Delivery Note' ?>
                            </button>
                            <a href="<?= $return_url ?>" class="btn btn-link text-muted text-decoration-none small">Cancel</a>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0" id="warehouseStockCard" style="display:none;">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0 fw-bold small"><i class="bi bi-building me-2"></i>Available Stock</h6>
                    </div>
                    <div class="card-body p-2" id="warehouseStockList">
                        <div class="text-center text-muted py-3 small">Loading...</div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let PROJECT_ID = <?= $project_id ?>;
let warehouseStock = [];
let isInitialLoad = true;

const ALL_WAREHOUSES = <?= json_encode(array_values(array_map(fn($w) => [
    'id' => (int)$w['warehouse_id'],
    'text' => $w['warehouse_name'] . (!empty($w['location']) ? ' — ' . $w['location'] : ''),
    'project_id' => (int)$w['project_id'],
], $all_warehouses))) ?>;
const ALL_SUPPLIERS = <?= json_encode(array_values(array_map(fn($s) => [
    'id' => (int)$s['supplier_id'],
    'text' => $s['supplier_name'] . (!empty($s['company_name']) ? ' (' . $s['company_name'] . ')' : ''),
], $all_suppliers))) ?>;
const ALL_SUBCONTRACTORS = <?= json_encode(array_values(array_map(fn($s) => [
    'id' => (int)$s['supplier_id'],
    'text' => $s['supplier_name'] . (!empty($s['company_name']) ? ' (' . $s['company_name'] . ')' : ''),
], $all_subs))) ?>;

const CUR_PARTY_TYPE = '<?= $cur_party_type ?>';
const CUR_PARTY_ID   = <?= (int)$cur_party_id ?>;
const IS_EDIT        = <?= $is_edit ? 'true' : 'false' ?>;
const PRESET_WH      = <?= (int)($dn['warehouse_id'] ?? 0) ?>;
const LPO_ITEMS      = <?= json_encode(array_values($lpo_items)) ?>;

function initS2($el, placeholder) {
    if ($el.data('select2')) $el.select2('destroy');
    $el.select2({ theme: 'bootstrap-5', placeholder: placeholder, allowClear: true, width: '100%' });
}

function rebuildWarehouses() {
    const $sel = $('#dn_warehouse_id');
    const current = $sel.val() || PRESET_WH;
    initS2($sel, '-- Select Warehouse --');
    $sel.empty().append($('<option>').val('').text('-- Select Warehouse --'));
    // Warehouse depends on Project: no project -> only warehouses not assigned
    // to any project; project selected -> only that project's warehouses.
    ALL_WAREHOUSES.filter(w => PROJECT_ID === 0 ? w.project_id === 0 : w.project_id === PROJECT_ID)
        .forEach(w => $sel.append($('<option>').val(w.id).text(w.text).prop('selected', w.id == current)));
    $sel.trigger('change.select2');
}

function rebuildParty(preserve) {
    const type = $('#dn_party_type').val();
    if (type === 'customer') return; // locked customer field — no dropdown to rebuild
    const list = type === 'subcontractor' ? ALL_SUBCONTRACTORS : ALL_SUPPLIERS;
    $('#partyLabel').html((type === 'subcontractor' ? 'Select Sub-Contractor' : 'Select Supplier') + ' <span class="text-danger">*</span>');
    const $sel = $('#dn_party_id');
    const current = preserve ? $sel.val() : null;
    initS2($sel, type === 'subcontractor' ? '-- Select Sub-Contractor --' : '-- Select Supplier --');
    $sel.empty().append($('<option>').val('').text(type === 'subcontractor' ? '-- Select Sub-Contractor --' : '-- Select Supplier --'));
    list.forEach(s => $sel.append($('<option>').val(s.id).text(s.text).prop('selected', s.id == current)));
    $sel.trigger('change.select2');
}

$(document).ready(function () {
    $('#dn_project_id').select2({ theme: 'bootstrap-5', placeholder: '-- No Project (General) --', allowClear: true, width: '100%' });

    rebuildWarehouses();
    $('#dn_party_type').val(CUR_PARTY_TYPE);
    rebuildParty(false);
    if (CUR_PARTY_ID > 0) $('#dn_party_id').val(CUR_PARTY_ID).trigger('change.select2');

    $('#dn_project_id').on('change', function () { PROJECT_ID = parseInt($(this).val()) || 0; rebuildWarehouses(); });
    $('#dn_warehouse_id').on('change', function () { if (!isInitialLoad) loadWarehouseStock(); });
    $('#dn_party_type').on('change', function () { rebuildParty(false); });

    if (PRESET_WH > 0) { $('#dn_warehouse_id').val(PRESET_WH).trigger('change.select2'); loadWarehouseStock(); }

    if (IS_EDIT) {
        setTimeout(function () {
            <?php foreach ($dn_items as $item): ?>
            addDNItem('<?= $item['product_id'] ?>', '<?= addslashes($item['product_name']) ?>', '<?= $item['quantity_delivered'] ?>', '<?= $item['unit'] ?>', 0);
            <?php endforeach; ?>
        }, 800);
    } else if (LPO_ITEMS.length > 0) {
        setTimeout(function () {
            LPO_ITEMS.forEach(function (it) {
                addDNItem(it.product_id, it.product_name, it.remaining, it.unit, 0);
            });
        }, 800);
    } else {
        addDNItem();
    }

    setTimeout(() => { isInitialLoad = false; }, 1000);
});

function loadWarehouseStock(callback) {
    const warehouseId = document.getElementById('dn_warehouse_id').value;
    if (!warehouseId) {
        document.getElementById('warehouseStockCard').style.display = 'none';
        warehouseStock = [];
        return;
    }
    document.getElementById('warehouseStockCard').style.display = '';
    document.getElementById('warehouseStockList').innerHTML = '<div class="text-center text-muted py-2 small"><i class="bi bi-hourglass-split me-1"></i>Loading stock...</div>';
    $.getJSON(APP_URL + '/api/get_project_warehouse_stock', { warehouse_id: warehouseId, project_id: PROJECT_ID }, function (res) {
        if (res.success && res.data && res.data.length > 0) {
            warehouseStock = res.data;
            let html = '';
            res.data.forEach(p => {
                if (p.is_service) return;
                html += `<div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                    <div><div class="fw-bold">${p.product_name}</div><small class="text-muted">${p.sku || ''}</small></div>
                    <span class="badge bg-${p.available_quantity > 0 ? 'success' : 'danger'} bg-opacity-10 text-${p.available_quantity > 0 ? 'success' : 'danger'} border">${p.available_quantity} ${p.unit}</span>
                </div>`;
            });
            document.getElementById('warehouseStockList').innerHTML = html;
        } else {
            warehouseStock = [];
            document.getElementById('warehouseStockList').innerHTML = '<div class="text-center text-muted py-2 small">No stock in this warehouse.</div>';
        }
        if (typeof callback === 'function') callback();
    }).fail(function () {
        document.getElementById('warehouseStockList').innerHTML = '<div class="text-center text-danger py-2 small">Failed to load stock.</div>';
        if (typeof callback === 'function') callback();
    });
}

function closeAllDropdowns() { document.querySelectorAll('.dn-product-dropdown').forEach(d => d.remove()); }

function showProductDropdown(rowId, inputEl) {
    closeAllDropdowns();
    if (!document.getElementById('dn_warehouse_id').value) {
        const d = document.createElement('div');
        d.className = 'dn-product-dropdown';
        const r = inputEl.getBoundingClientRect(), sY = window.pageYOffset || document.documentElement.scrollTop;
        d.style.cssText = `position:absolute;top:${r.bottom+sY+2}px;left:${r.left}px;width:${Math.max(r.width,260)}px;background:#fff8f0;border:1px solid #f0ad4e;border-radius:6px;padding:10px 14px;font-size:.85rem;color:#664d03;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.1);`;
        d.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Please select a warehouse first';
        document.body.appendChild(d);
        setTimeout(closeAllDropdowns, 2500);
        return;
    }
    if (warehouseStock.length === 0) { loadWarehouseStock(() => showProductDropdown(rowId, inputEl)); return; }

    const q = inputEl.value.trim().toLowerCase();
    const filtered = warehouseStock.filter(p => !p.is_service && (!q || p.product_name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q)));
    const r = inputEl.getBoundingClientRect();
    const sY = window.pageYOffset || document.documentElement.scrollTop;
    const sX = window.pageXOffset || document.documentElement.scrollLeft;
    const dropdown = document.createElement('div');
    dropdown.className = 'dn-product-dropdown';

    if (filtered.length === 0) {
        dropdown.style.cssText = `position:absolute;top:${r.bottom+sY+2}px;left:${r.left}px;width:${Math.max(r.width,260)}px;background:#fff;border:1px solid #ced4da;border-radius:6px;padding:10px 14px;font-size:.85rem;color:#888;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.1);`;
        dropdown.innerHTML = '<i class="bi bi-search me-2"></i>No products found';
        document.body.appendChild(dropdown);
        setTimeout(closeAllDropdowns, 2000);
        return;
    }
    dropdown.style.cssText = `position:absolute;top:${r.bottom+sY+2}px;left:${r.left+sX}px;width:${Math.max(r.width,280)}px;max-height:220px;overflow-y:auto;background:#fff;border:1px solid #ced4da;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:99999;`;
    filtered.forEach(p => {
        const item = document.createElement('div');
        item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;';
        const tracked = p.track_inventory == 1 || p.track_inventory === true;
        const txt = tracked ? `${p.available_quantity} ${p.unit}` : 'Non-tracked';
        const bg = tracked ? (p.available_quantity > 0 ? '#d1e7dd' : '#f8d7da') : '#fff3cd';
        const col = tracked ? (p.available_quantity > 0 ? '#0f5132' : '#842029') : '#664d03';
        item.innerHTML = `<div class="d-flex justify-content-between align-items-center">
            <div><div style="font-weight:600;font-size:.85rem;">${p.product_name}</div><small style="color:#888;">${p.sku || ''}</small></div>
            <span style="font-size:.75rem;padding:2px 8px;border-radius:20px;font-weight:600;background:${bg};color:${col};">${txt}</span></div>`;
        item.addEventListener('mousedown', function (e) { e.preventDefault(); selectProduct(rowId, p); closeAllDropdowns(); });
        item.addEventListener('mouseover', function () { this.style.background = '#f0f4ff'; });
        item.addEventListener('mouseout', function () { this.style.background = '#fff'; });
        dropdown.appendChild(item);
    });
    document.body.appendChild(dropdown);
}

function selectProduct(rowId, p) {
    document.getElementById('pid_' + rowId).value = p.product_id;
    document.getElementById('pname_' + rowId).value = p.product_name;
    document.getElementById('unit_' + rowId).textContent = p.unit;
    document.getElementById('unitval_' + rowId).value = p.unit;
    const avail = parseFloat(p.available_quantity) || 0;
    const cls = avail > 0 ? 'success' : 'danger';
    const availEl = document.getElementById('avail_' + rowId);
    if (availEl) {
        availEl.textContent = avail > 0 ? avail + ' ' + p.unit : '—';
        availEl.className = `badge bg-${cls} bg-opacity-10 text-${cls} border small`;
    }
    const qtyEl = document.getElementById('qty_' + rowId);
    if (qtyEl) qtyEl.focus();
    updateDNSummary();
}

function addDNItem(productId, productName, qty, unit, available) {
    productId = productId || ''; productName = productName || ''; qty = qty || '';
    unit = unit || 'pcs'; available = available || 0;
    if (productId && (available == 0 || available == '0')) {
        const s = warehouseStock.find(s => s.product_id == productId);
        if (s) available = s.available_quantity;
    }
    const rowId = 'dnrow_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const avail = parseFloat(available) || 0;
    const cls = avail > 0 ? 'success' : 'danger';
    const availTxt = (productId && avail > 0) ? avail + ' ' + unit : '—';
    const html = `
    <tr id="${rowId}">
        <td class="text-center text-muted fw-bold serial-num ps-2" style="width:50px;"></td>
        <td style="min-width:180px;">
            <div style="position:relative;">
                <input type="text" id="pname_${rowId}" class="form-control form-control-sm" placeholder="Type or click to search..."
                    value="${productName}" autocomplete="off"
                    oninput="showProductDropdown('${rowId}', this)" onfocus="showProductDropdown('${rowId}', this)"
                    onblur="setTimeout(closeAllDropdowns, 200)">
                <input type="hidden" name="product_id[]" id="pid_${rowId}" value="${productId}">
            </div>
        </td>
        <td class="text-center" style="width:110px;">
            <span class="badge bg-${cls} bg-opacity-10 text-${cls} border small" id="avail_${rowId}">${availTxt}</span>
        </td>
        <td style="width:130px;">
            <input type="number" id="qty_${rowId}" class="form-control form-control-sm qty-input" name="quantity[]"
                value="${qty}" min="0.001" step="0.001" placeholder="Qty" oninput="updateDNSummary()">
        </td>
        <td style="width:75px;">
            <span class="text-muted small fw-semibold" id="unit_${rowId}">${unit}</span>
            <input type="hidden" name="unit[]" value="${unit}" id="unitval_${rowId}">
        </td>
        <td class="text-center pe-2" style="width:48px;">
            <button type="button" class="btn btn-danger btn-sm" style="width:30px;height:30px;padding:0;" onclick="removeRow('${rowId}')">
                <i class="bi bi-trash" style="font-size:.75rem;"></i>
            </button>
        </td>
    </tr>`;
    $('#dnItemsBody').append(html);
    updateSerials();
    updateDNSummary();
}

function removeRow(rowId) { $('#' + rowId).remove(); updateSerials(); updateDNSummary(); }

function updateSerials() {
    const rows = $('#dnItemsBody tr');
    rows.each(function (i) { $(this).find('.serial-num').text(i + 1); });
    $('#dnItemsEmpty').toggle(rows.length === 0);
}

function updateDNSummary() {
    let totalItems = 0, totalQty = 0;
    $('#dnItemsBody tr').each(function () {
        const qty = parseFloat($(this).find('.qty-input').val()) || 0;
        if ($(this).find('input[name="product_id[]"]').val()) { totalItems++; totalQty += qty; }
    });
    $('#dnTotalItems').text(totalItems);
    $('#dnTotalQty').text(totalQty.toFixed(3));
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.dn-product-dropdown') && !e.target.closest('input[id^="pname_"]')) closeAllDropdowns();
});

function submitDN(status) {
    status = status || 'draft';
    const partyType = $('#dn_party_type').val();
    const partyId   = $('#dn_party_id').val();
    const warehouse = $('#dn_warehouse_id').val();
    const date      = $('[name="delivery_date"]').val();

    const partyNoun = partyType === 'subcontractor' ? 'sub-contractor' : (partyType === 'customer' ? 'customer' : 'supplier');
    if (!partyId)   { Swal.fire({ icon: 'warning', title: 'Required', text: 'Select the ' + partyNoun + '.' }); return; }
    if (!warehouse) { Swal.fire({ icon: 'warning', title: 'Required', text: 'Select a warehouse.' }); return; }
    if (!date)      { Swal.fire({ icon: 'warning', title: 'Required', text: 'Enter the DN date.' }); return; }

    const items = [];
    $('#dnItemsBody tr').each(function () {
        const productId = $(this).find('input[name="product_id[]"]').val();
        const qty = parseFloat($(this).find('input[name="quantity[]"]').val()) || 0;
        const unit = $(this).find('input[name="unit[]"]').val() || 'pcs';
        if (productId && qty > 0) items.push({ product_id: productId, quantity: qty, unit: unit });
    });
    if (items.length === 0) { Swal.fire({ icon: 'warning', title: 'No Valid Items', text: 'Add at least one item with a product and quantity.' }); return; }

    const fd = new FormData();
    fd.append('dn_type', 'outbound');
    fd.append('party_type', partyType);
    fd.append('party_id', partyId);
    fd.append('project_id', PROJECT_ID);
    fd.append('warehouse_id', warehouse);
    fd.append('delivery_date', date);
    fd.append('contact_person', $('[name="contact_person"]').val());
    fd.append('contact_phone', $('[name="contact_phone"]').val());
    fd.append('delivery_address', $('[name="delivery_address"]').val());
    fd.append('notes', $('[name="notes"]').val());
    fd.append('items', JSON.stringify(items));
    fd.append('status', status);
    <?php if ($is_edit): ?>fd.append('delivery_id', '<?= $edit_id ?>');<?php endif; ?>
    <?php if ($lpo): ?>fd.append('customer_lpo_id', '<?= $lpo_id ?>');<?php elseif ($is_edit && !empty($dn['customer_lpo_id'])): ?>fd.append('customer_lpo_id', '<?= (int)$dn['customer_lpo_id'] ?>');<?php endif; ?>

    Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.ajax({
        url: '<?= $is_edit ? buildUrl("api/update_dn.php") : buildUrl("api/create_dn.php") ?>',
        type: 'POST', data: fd, processData: false, contentType: false,
        success: function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, confirmButtonColor: '#0d6efd' })
                    .then(() => { window.location.href = '<?= $return_url ?>'; });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' }); }
    });
}
</script>

<style>
@media (max-width: 767px) {
    .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
    .dn-sticky-nav { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 6px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.06); }
    #dnItemsTable { min-width: 560px; }
}
</style>

<?php includeFooter(); ?>
