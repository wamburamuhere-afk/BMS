<?php
// File: app/bms/purchase/rfq_create.php
// scope-audit: skip — create/edit form; new RFQ has no prior record; edit path is gated by assertScopeForRecord in api/update_rfq.php
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('rfq');
includeHeader();

global $pdo;

$edit_id  = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$is_edit  = $edit_id > 0;
$rfq_data = null;
$rfq_items = [];

$existing_attachments = [];
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT r.*, s.supplier_name, w.warehouse_name FROM rfq r
        LEFT JOIN suppliers s ON r.supplier_id = s.supplier_id
        LEFT JOIN warehouses w ON r.warehouse_id = w.warehouse_id
        WHERE r.rfq_id = ?");
    $stmt->execute([$edit_id]);
    $rfq_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rfq_data) { header('Location: '.getUrl('rfq')); exit; }

    $stmt2 = $pdo->prepare("SELECT * FROM rfq_items WHERE rfq_id = ? ORDER BY item_order");
    $stmt2->execute([$edit_id]);
    $rfq_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $stmt3 = $pdo->prepare("SELECT * FROM rfq_attachments WHERE rfq_id = ? ORDER BY uploaded_at");
    $stmt3->execute([$edit_id]);
    $existing_attachments = $stmt3->fetchAll(PDO::FETCH_ASSOC);
}

// Scope: assigned project IDs for current user
$_rfq_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));

// Suppliers — scoped by project for non-admins
if (isAdmin()) {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_rfq_assigned)) {
    $_rfq_sph = implode(',', array_fill(0, count($_rfq_assigned), '?'));
    $_rfq_sstmt = $pdo->prepare("SELECT supplier_id, supplier_name FROM suppliers WHERE status='active' AND (project_id IS NULL OR project_id IN ($_rfq_sph)) ORDER BY supplier_name");
    $_rfq_sstmt->execute($_rfq_assigned);
    $suppliers = $_rfq_sstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status='active' AND project_id IS NULL ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
}

// All warehouses with project_id for JS filtering — scoped for non-admins
if (isAdmin()) {
    $all_warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, IFNULL(project_id,0) as project_id FROM warehouses WHERE status='active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_rfq_assigned)) {
    $_rfq_wph = implode(',', array_fill(0, count($_rfq_assigned), '?'));
    $_rfq_wstmt = $pdo->prepare("SELECT warehouse_id, warehouse_name, IFNULL(project_id,0) as project_id FROM warehouses WHERE status='active' AND (project_id IS NULL OR project_id IN ($_rfq_wph)) ORDER BY warehouse_name");
    $_rfq_wstmt->execute($_rfq_assigned);
    $all_warehouses = $_rfq_wstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $all_warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, IFNULL(project_id,0) as project_id FROM warehouses WHERE status='active' AND project_id IS NULL ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Projects
$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

$projects = [];
if ($enable_projects) {
    if (isAdmin()) {
        $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status!='cancelled' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($_rfq_assigned)) {
        $_rfq_pph = implode(',', array_fill(0, count($_rfq_assigned), '?'));
        $_rfq_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status!='cancelled' AND project_id IN ($_rfq_pph) ORDER BY project_name");
        $_rfq_pstmt->execute($_rfq_assigned);
        $projects = $_rfq_pstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$selected_project   = $is_edit ? ($rfq_data['project_id'] ?? 0) : (isset($_GET['project']) ? intval($_GET['project']) : 0);
$selected_warehouse = $is_edit ? ($rfq_data['warehouse_id'] ?? 0) : 0;
$selected_supplier  = $is_edit ? ($rfq_data['supplier_id']  ?? 0) : 0;
?>

<div class="rfq-create-page p-2 p-md-3" style="background:#fff;min-height:100vh;">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('rfq') ?>">Request for Quotation</a></li>
            <li class="breadcrumb-item active"><?= $is_edit ? 'Edit RFQ' : 'Create RFQ' ?></li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h2 class="fw-bold mb-1">
                <i class="bi bi-file-earmark-plus text-primary me-2"></i><?= $is_edit ? 'Edit RFQ' : 'Create RFQ' ?>
            </h2>
            <p class="text-muted mb-0 small">
                <?= $is_edit ? 'Update the request for quotation details' : 'Create a new request for quotation to a supplier' ?>
            </p>
        </div>
        <a href="<?= getUrl('rfq') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>

    <form id="rfqForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <?php if ($is_edit): ?>
        <input type="hidden" name="rfq_id" value="<?= $edit_id ?>">
        <?php endif; ?>

        <!-- RFQ Details Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i>RFQ Details</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <!-- Supplier -->
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
                        <select class="form-select" name="supplier_id" id="supplier_id" required>
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['supplier_id'] ?>" <?= $selected_supplier == $s['supplier_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['supplier_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Project (Optional) -->
                    <?php if ($enable_projects): ?>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">
                            Project <span class="text-muted small fw-normal">(Optional)</span>
                        </label>
                        <select class="form-select" name="project_id" id="project_id"
                            onchange="filterRfqWarehouses(this.value)">
                            <option value="">No Project</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['project_id'] ?>" <?= $selected_project == $p['project_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['project_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Warehouse -->
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Warehouse <span class="text-danger">*</span></label>
                        <select class="form-select" name="warehouse_id" id="warehouse_id" required>
                            <option value="">Select Warehouse</option>
                            <?php foreach ($all_warehouses as $w): ?>
                            <option value="<?= $w['warehouse_id'] ?>"
                                data-project="<?= $w['project_id'] ?>"
                                <?= $selected_warehouse == $w['warehouse_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['warehouse_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted" id="warehouseHint">
                            <?php if ($enable_projects): ?>
                            Select a project first to filter warehouses, or leave project empty to see unlinked warehouses.
                            <?php else: ?>
                            Select the destination warehouse.
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- RFQ Date -->
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">RFQ Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="rfq_date"
                            value="<?= $is_edit ? htmlspecialchars($rfq_data['rfq_date'] ?? date('Y-m-d')) : date('Y-m-d') ?>" required>
                    </div>

                    <!-- Response Deadline -->
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">
                            Response Deadline <span class="text-muted small fw-normal">(Optional)</span>
                        </label>
                        <input type="date" class="form-control" name="deadline_date"
                            value="<?= $is_edit ? htmlspecialchars($rfq_data['deadline_date'] ?? '') : '' ?>">
                    </div>

                </div>
            </div>
        </div>

        <!-- RFQ Items Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2"></i>RFQ Items</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="itemsTable">
                        <thead class="text-uppercase small fw-bold" style="background:#f8fafc;">
                            <tr>
                                <th class="ps-4" style="width:55px;">S/No</th>
                                <th>Description <span class="text-danger">*</span></th>
                                <th style="width:140px;">Unit</th>
                                <th style="width:120px;">Qty <span class="text-danger">*</span></th>
                                <th class="text-center" style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <?php if ($is_edit && !empty($rfq_items)):
                                foreach ($rfq_items as $i => $item): ?>
                            <tr id="row_e<?= $i ?>">
                                <td class="ps-4 fw-bold text-muted serial-no"><?= $i+1 ?></td>
                                <td>
                                    <div class="input-group">
                                        <input type="text" class="form-control product-selector" name="description[]"
                                            value="<?= htmlspecialchars($item['description']) ?>"
                                            placeholder="Type to search product..." required
                                            oninput="openRfqProductSearch('row_e<?= $i ?>', this.value)"
                                            onclick="openRfqProductSearch('row_e<?= $i ?>', this.value)"
                                            autocomplete="off">
                                        <button type="button" class="btn btn-outline-secondary" onclick="openRfqProductSearch('row_e<?= $i ?>')">
                                            <i class="bi bi-search"></i>
                                        </button>
                                    </div>
                                    <input type="hidden" class="item-product-id" value="<?= intval($item['product_id'] ?? 0) ?: '' ?>">
                                </td>
                                <td><input type="text" class="form-control" name="unit[]"
                                    value="<?= htmlspecialchars($item['unit'] ?? '') ?>"
                                    placeholder="e.g. pcs, kg, m"></td>
                                <td><input type="number" class="form-control" name="qty[]"
                                    value="<?= htmlspecialchars($item['qty']) ?>"
                                    min="0.01" step="any" required></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger border-0"
                                        onclick="removeRow(this)" title="Remove item">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white border-top-0 pb-3 ps-3">
                <button type="button" class="btn btn-primary btn-sm" onclick="addItemRow()">
                    <i class="bi bi-plus-circle me-1"></i> Add Item
                </button>
            </div>
        </div>

        <!-- Attachments Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2"></i>Attachments <span class="text-muted small fw-normal">(Optional)</span></h6>
            </div>
            <div class="card-body">

                <?php if (!empty($existing_attachments)): ?>
                <!-- Existing attachments (edit mode) -->
                <p class="text-muted small fw-semibold mb-2">Saved attachments:</p>
                <div id="existingAttachmentsContainer">
                    <?php foreach ($existing_attachments as $att): ?>
                    <div class="d-flex align-items-center gap-2 mb-2 existing-att-row" id="existing_att_<?= $att['attachment_id'] ?>">
                        <i class="bi bi-file-earmark text-primary fs-5"></i>
                        <span class="fw-semibold"><?= htmlspecialchars($att['attachment_name'] ?: $att['original_name']) ?></span>
                        <a href="<?= getUrl($att['file_path']) ?>" target="_blank"
                           class="btn btn-sm btn-outline-primary py-0 px-2">
                            <i class="bi bi-eye me-1"></i>View
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2"
                                onclick="removeExistingAttachment(<?= $att['attachment_id'] ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <hr class="my-3">
                <p class="text-muted small mb-2">Add more attachments:</p>
                <?php else: ?>
                <p class="text-muted small mb-2">Add one or more attachments. Each attachment requires a name and a file.</p>
                <?php endif; ?>

                <!-- New attachment rows (added dynamically) -->
                <div id="newAttachmentsContainer"></div>

                <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="addAttachmentRow()">
                    <i class="bi bi-plus-circle me-1"></i> Add Attachment
                </button>
                <div class="form-text text-muted mt-2">Accepted: PDF, Word, Excel, images. Max 10 MB per file.</div>
            </div>
        </div>

        <!-- Submit Buttons -->
        <div class="d-flex justify-content-end gap-2">
            <a href="<?= getUrl('rfq') ?>" class="btn btn-outline-secondary px-4">Cancel</a>
            <button type="submit" class="btn btn-primary px-5 shadow-sm">
                <i class="bi bi-send me-1"></i>
                <?= $is_edit ? 'Update RFQ' : 'Submit RFQ' ?>
            </button>
        </div>
    </form>
</div>

<!-- Floating Product Search Panel -->
<div id="rfqProductSearchPanel" class="rfq-product-search shadow-lg border">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="bg-light sticky-top">
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Unit</th>
                </tr>
            </thead>
            <tbody id="rfqProductsSearchBody"></tbody>
        </table>
    </div>
</div>

<style>
.rfq-create-page .card{border-radius:.75rem;}
.rfq-create-page .form-label{font-size:.875rem;color:#374151;}
.rfq-create-page .table thead th{border-bottom:2px solid #e2e8f0;padding:.9rem 1rem;color:#475569;}
@media(max-width:575px){
    .rfq-create-page .table{min-width:500px;}
    .rfq-create-page .table-responsive{overflow-x:auto;}
}
.rfq-product-search{
    position:absolute;z-index:9999;background:#fff;border-radius:.5rem;
    max-height:320px;overflow-y:auto;display:none;min-width:420px;
}
.rfq-product-search thead th{padding:.5rem .75rem;font-size:.78rem;color:#475569;}
.rfq-product-search tbody tr{cursor:pointer;}
.rfq-product-search tbody tr:hover{background:#f0f7ff;}
.rfq-product-search tbody td{padding:.45rem .75rem;font-size:.85rem;vertical-align:middle;}
</style>

<script>
// ── Product search ─────────────────────────────────────────────────────
let rfqProductsList = [];
let rfqCurrentRowId = null;

async function rfqFetchProducts() {
    try {
        const r = await fetch('<?= getUrl('api/account/get_products.php') ?>?limit=1000');
        const d = await r.json();
        if (d.success) rfqProductsList = d.data;
    } catch(e) { console.error('Failed to fetch products:', e); }
}

function openRfqProductSearch(rowId, term = '') {
    rfqCurrentRowId = rowId;
    const input = document.querySelector('#' + rowId + ' .product-selector');
    const rect  = input.getBoundingClientRect();
    const panel = document.getElementById('rfqProductSearchPanel');
    panel.style.top     = (rect.bottom + window.scrollY + 2) + 'px';
    panel.style.left    = (rect.left   + window.scrollX) + 'px';
    panel.style.width   = Math.max(rect.width * 1.5, 460) + 'px';
    panel.style.display = 'block';
    rfqSearchProducts(term);
}

function rfqSearchProducts(term) {
    const tbody = document.getElementById('rfqProductsSearchBody');
    tbody.innerHTML = '';
    const q = (term || '').toLowerCase().trim();
    
    // Filter out Non-Inventory Products (is_service = 1) for RFQ accuracy
    let results = rfqProductsList.filter(p => parseInt(p.is_service) !== 1);

    if (q) {
        results = results.filter(p =>
            (p.product_name && p.product_name.toLowerCase().includes(q)) ||
            (p.sku && p.sku.toLowerCase().includes(q))
        );
    }
    if (!results.length) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted p-3 fst-italic">No match — will be created as new product on save</td></tr>';
        return;
    }
    results.slice(0, 50).forEach(p => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td><strong>${p.product_name}</strong><br><small class="text-muted">${p.sku || ''}</small></td><td>${p.sku || 'N/A'}</td><td>${p.unit || ''}</td>`;
        tr.onclick = () => rfqSelectProduct(p.product_id);
        tbody.appendChild(tr);
    });
}

function rfqSelectProduct(productId) {
    const p = rfqProductsList.find(x => x.product_id == productId);
    if (!p || !rfqCurrentRowId) return;
    const row = document.getElementById(rfqCurrentRowId);
    row.querySelector('.product-selector').value  = p.product_name;
    row.querySelector('.item-product-id').value   = p.product_id;
    const unitInput = row.querySelector('input[name="unit[]"]');
    if (unitInput && !unitInput.value && p.unit) unitInput.value = p.unit;
    document.getElementById('rfqProductSearchPanel').style.display = 'none';
    row.querySelector('input[name="qty[]"]').focus();
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.product-selector, #rfqProductSearchPanel')) {
        document.getElementById('rfqProductSearchPanel').style.display = 'none';
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('rfqProductSearchPanel').style.display = 'none';
});

rfqFetchProducts();
// ── End product search ─────────────────────────────────────────────

// ── Attachments ────────────────────────────────────────────────────
let _attIdx = 0;

function addAttachmentRow() {
    const idx = _attIdx++;
    const container = document.getElementById('newAttachmentsContainer');
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 align-items-center att-new-row';
    row.id = 'att_row_' + idx;
    row.innerHTML = `
        <div class="col-12 col-md-4">
            <input type="text" class="form-control form-control-sm" name="attachment_name[]"
                placeholder="Attachment name / description" maxlength="255">
        </div>
        <div class="col-12 col-md-6">
            <input type="file" class="form-control form-control-sm" name="attachment_file[]"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="document.getElementById('att_row_${idx}').remove()" title="Remove row">
                <i class="bi bi-trash"></i>
            </button>
        </div>`;
    container.appendChild(row);
    row.querySelector('input[name="attachment_name[]"]').focus();
}

<?php if ($is_edit): ?>
function removeExistingAttachment(attId) {
    Swal.fire({
        title: 'Remove attachment?',
        text: 'This file will be permanently deleted.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, remove',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        const csrf = document.querySelector('[name="_csrf"]').value;
        fetch('<?= getUrl('api/delete_rfq_attachment') ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded',
                      'X-CSRF-Token': csrf},
            body: 'attachment_id=' + attId + '&_csrf=' + encodeURIComponent(csrf)
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                document.getElementById('existing_att_' + attId)?.remove();
            } else {
                Swal.fire({icon:'error', title:'Error', text: res.message || 'Could not remove attachment.'});
            }
        })
        .catch(() => Swal.fire({icon:'error', title:'Error', text:'Server error.'}));
    });
}
<?php endif; ?>
// ── End attachments ────────────────────────────────────────────────────

// All warehouses passed from PHP for JS filtering
const rfqAllWarehouses = <?= json_encode(array_values(array_map(function($w){
    return ['warehouse_id'=>(int)$w['warehouse_id'],'warehouse_name'=>$w['warehouse_name'],'project_id'=>(int)$w['project_id']];
}, $all_warehouses))) ?>;

/**
 * Filter warehouse dropdown based on selected project.
 * - No project selected  → show warehouses with project_id = 0 (not linked to any project)
 * - Project selected     → show ONLY warehouses linked to that project
 * - If project has no warehouses → show empty with message
 */
function filterRfqWarehouses(projectId) {
    const sel   = document.getElementById('warehouse_id');
    const hint  = document.getElementById('warehouseHint');
    const curVal = sel.value;

    // Clear options
    sel.innerHTML = '<option value="">Select Warehouse</option>';

    let filtered;
    if (!projectId || projectId === '' || projectId === '0') {
        // No project: show warehouses NOT linked to any project
        filtered = rfqAllWarehouses.filter(w => w.project_id === 0);
        if (hint) hint.textContent = 'Showing warehouses not linked to any project.';
    } else {
        // Project selected: show ONLY warehouses of that project
        filtered = rfqAllWarehouses.filter(w => w.project_id === parseInt(projectId));
        if (hint) {
            hint.textContent = filtered.length === 0
                ? 'No warehouses found for this project.'
                : `Showing ${filtered.length} warehouse(s) for the selected project.`;
        }
    }

    filtered.forEach(w => {
        const opt       = document.createElement('option');
        opt.value       = w.warehouse_id;
        opt.textContent = w.warehouse_name;
        opt.setAttribute('data-project', w.project_id);
        if (parseInt(curVal) === w.warehouse_id) opt.selected = true;
        sel.appendChild(opt);
    });

    // If only one warehouse, auto-select it
    if (filtered.length === 1) sel.value = filtered[0].warehouse_id;
}

// Run on page load to respect pre-selected values
(function(){
    <?php if ($enable_projects): ?>
    filterRfqWarehouses(document.getElementById('project_id').value);
    // Re-select saved warehouse after filtering
    <?php if ($selected_warehouse): ?>
    document.getElementById('warehouse_id').value = '<?= $selected_warehouse ?>';
    <?php endif; ?>
    <?php else: ?>
    filterRfqWarehouses('');
    <?php endif; ?>
})();

// Item rows
function addItemRow() {
    const tbody = document.getElementById('itemsBody');
    const rowId = 'row_' + Date.now();
    const tr    = document.createElement('tr');
    tr.id = rowId;
    tr.innerHTML = `
        <td class="ps-4 fw-bold text-muted serial-no"></td>
        <td>
            <div class="input-group">
                <input type="text" class="form-control product-selector" name="description[]"
                    placeholder="Type to search product..." required
                    oninput="openRfqProductSearch('${rowId}', this.value)"
                    onclick="openRfqProductSearch('${rowId}', this.value)"
                    autocomplete="off">
                <button type="button" class="btn btn-outline-secondary" onclick="openRfqProductSearch('${rowId}')">
                    <i class="bi bi-search"></i>
                </button>
            </div>
            <input type="hidden" class="item-product-id">
        </td>
        <td><input type="text" class="form-control" name="unit[]" placeholder="e.g. pcs, kg, m"></td>
        <td><input type="number" class="form-control" name="qty[]" value="1" min="0.01" step="any" required></td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>`;
    tbody.appendChild(tr);
    updateSerials();
    tr.querySelector('.product-selector').focus();
}

function removeRow(btn) {
    btn.closest('tr').remove();
    updateSerials();
}

function updateSerials() {
    document.querySelectorAll('#itemsBody tr').forEach((tr, i) => {
        const sn = tr.querySelector('.serial-no');
        if (sn) sn.textContent = i + 1;
    });
}

// Add first row if creating new
<?php if (!$is_edit): ?>
addItemRow();
<?php else: ?>
updateSerials();
<?php endif; ?>

// Form submit
document.getElementById('rfqForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const rows = document.querySelectorAll('#itemsBody tr');
    if (!rows.length) {
        Swal.fire({icon:'warning',title:'No Items',text:'Please add at least one RFQ item.',confirmButtonColor:'#0d6efd',confirmButtonText:'OK'});
        return;
    }

    // Collect raw items
    const rawItems = [];
    rows.forEach(tr => {
        const desc      = tr.querySelector('.product-selector')?.value?.trim();
        const unit      = tr.querySelector('input[name="unit[]"]')?.value?.trim() || '';
        const qty       = parseFloat(tr.querySelector('input[name="qty[]"]')?.value) || 1;
        const productId = tr.querySelector('.item-product-id')?.value || '';
        if (desc) rawItems.push({description: desc, unit, qty, product_id: productId});
    });

    if (!rawItems.length) {
        Swal.fire({icon:'warning',title:'No Valid Items',text:'Please fill in item descriptions.',confirmButtonColor:'#0d6efd',confirmButtonText:'OK'});
        return;
    }

    const warehouseId = document.getElementById('warehouse_id').value;

    Swal.fire({title:'Saving RFQ...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});

    // For any item without a product_id, auto-create the product in the selected warehouse
    const items = [];
    for (const raw of rawItems) {
        let productId = raw.product_id;
        if (!productId && warehouseId) {
            try {
                const fd = new FormData();
                fd.append('product_name', raw.description);
                fd.append('unit', raw.unit || 'pcs');
                fd.append('warehouse_id', warehouseId);
                const r = await fetch('<?= getUrl('api/rfq_quick_add_product') ?>', {method:'POST', body:fd});
                const d = await r.json();
                if (d.success) productId = d.product_id;
            } catch(err) { console.error('Quick-add product failed:', err); }
        }
        items.push({description: raw.description, unit: raw.unit, qty: raw.qty, product_id: productId || null});
    }

    const formData = new FormData(this);
    formData.set('items', JSON.stringify(items));

    const url = '<?= $is_edit ? getUrl('api/update_rfq') : getUrl('api/create_rfq') ?>';

    try {
        const r   = await fetch(url, {method:'POST', body:formData});
        const res = await r.json();
        if (res.success) {
            Swal.fire({
                icon:'success',
                title:'<?= $is_edit ? 'RFQ Updated!' : 'RFQ Created!' ?>',
                text: res.message || 'RFQ saved successfully.',
                confirmButtonColor:'#198754',
                confirmButtonText:'OK'
            }).then(() => window.location.href = '<?= getUrl('rfq') ?>');
        } else {
            Swal.fire({icon:'error',title:'Failed',text:res.message||'An error occurred.',confirmButtonColor:'#0d6efd',confirmButtonText:'OK'});
        }
    } catch(err) {
        Swal.fire({icon:'error',title:'Server Error',text:'Please try again.',confirmButtonText:'OK'});
    }
});
</script>

<?php includeFooter(); ?>
