<?php
// File: app/bms/purchase/edit_nip_materials.php
// scope-audit: skip — NIP material edit form; scope by project_id pending Phase G-2
require_once __DIR__ . '/../../../roots.php';

// Enforce permission — must match the other NIP pages (nip_materials, view_nip_materials,
// view_material_list) so canEdit('nip_materials') in the list page agrees with the actual gate here.
autoEnforcePermission('nip_materials');

includeHeader();

$product_id = $_GET['id'] ?? 0;
if (!$product_id) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Invalid Product ID.</div></div>";
    includeFooter();
    exit;
}

// Fetch Product Info
$stmt = $pdo->prepare("
    SELECT p.*, 
           w.project_id,
           pr.project_name, 
           w.warehouse_name,
           tr.rate_name as tax_name, 
           tr.rate_percentage as tax_rate
    FROM products p
    LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
    LEFT JOIN projects pr ON w.project_id = pr.project_id
    LEFT JOIN tax_rates tr ON p.tax_id = tr.rate_id
    WHERE p.product_id = ? AND p.is_service = 1
");
$stmt->execute([$product_id]);
$row = $stmt->fetch();

if (!$row) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Product not found.</div></div>";
    includeFooter();
    exit;
}

// Fetch Lists for dropdowns
$warehouses_all = $pdo->query("SELECT warehouse_id, warehouse_name, project_id FROM warehouses WHERE status='active' OR warehouse_id = " . intval($row['warehouse_id'] ?? 0) . " ORDER BY warehouse_name ASC")->fetchAll();
$tax_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE status='active' OR rate_id = " . intval($row['tax_id'] ?? 0) . " ORDER BY rate_percentage ASC")->fetchAll();

// Fetch Components
$comp_stmt = $pdo->prepare("
    SELECT c.*, p.product_name, p.sku, p.cost_price as component_cost
    FROM product_assembly_components c
    LEFT JOIN products p ON c.component_product_id = p.product_id
    WHERE c.parent_product_id = ?
    ORDER BY c.id ASC
");
$comp_stmt->execute([$product_id]);
$components = $comp_stmt->fetchAll();

$c_name = get_setting('company_name', 'BMS');
$c_logo = get_setting('company_logo');
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            
            <!-- Form Frame -->
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden">
                <!-- Blue Header -->
                <div class="card-header bg-primary text-white py-3 px-4">
                    <h5 class="fw-bold mb-0 text-uppercase letter-spacing-1">
                        <i class="bi bi-pencil-square me-2"></i>Edit NIP Materials
                    </h5>
                </div>

                <div class="card-body p-4 p-md-5 bg-white">
                    <form id="editNipFormMain">
                        <input type="hidden" name="product_id" value="<?= $product_id ?>">

                        <div class="row g-4">
                            <!-- Basic Info Row -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Product Name</label>
                                <input type="text" class="form-control fw-bold border-primary shadow-sm" 
                                       name="product_name" value="<?= htmlspecialchars($row['product_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Status</label>
                                <select class="form-select border-primary shadow-sm fw-bold" name="status">
                                    <option value="active" <?= $row['status']=='active'?'selected':'' ?>>Active</option>
                                    <option value="approved" <?= $row['status']=='approved'?'selected':'' ?>>Approved</option>
                                    <option value="pending" <?= $row['status']=='pending'?'selected':'' ?>>Pending</option>
                                    <option value="draft" <?= $row['status']=='draft'?'selected':'' ?>>Draft</option>
                                    <option value="inactive" <?= $row['status']=='inactive'?'selected':'' ?>>Inactive</option>
                                </select>
                            </div>

                            <!-- Placement Info Row -->
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Project Link</label>
                                <input type="text" class="form-control bg-light fw-bold" value="<?= htmlspecialchars($row['project_name'] ?? '— General (No Project) —') ?>" readonly>
                                <input type="hidden" name="project_id" id="editNipProjectMain" value="<?= $row['project_id'] ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Tax Status</label>
                                <select class="form-select" name="tax_id" id="editNipTaxMain" onchange="recalcCostMain()">
                                    <option value="" data-rate="0">No Tax</option>
                                    <?php foreach ($tax_rates as $t): ?>
                                    <option value="<?= $t['rate_id'] ?>" data-rate="<?= $t['rate_percentage'] ?>" <?= $row['tax_id']==$t['rate_id']?'selected':'' ?>><?= htmlspecialchars($t['rate_name']) ?> (<?= $t['rate_percentage'] ?>%)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Warehouse Destination</label>
                                <select class="form-select fw-bold border-primary" name="warehouse_id" id="editNipWarehouseMain" required>
                                    <option value="">— Select Warehouse —</option>
                                    <?php foreach ($warehouses_all as $w): ?>
                                    <option value="<?= $w['warehouse_id'] ?>" data-project="<?= $w['project_id'] ?>" <?= $row['warehouse_id']==$w['warehouse_id']?'selected':'' ?>><?= htmlspecialchars($w['warehouse_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Pricing Info -->
                            <div class="col-12 mt-4">
                                <div class="p-3 bg-light rounded-3 border d-flex justify-content-between align-items-center">
                                    <div class="flex-grow-1 me-4">
                                        <label class="form-label fw-bold small text-primary mb-1">Selling Price (TZS)</label>
                                        <input type="number" class="form-control form-control-lg border-primary fw-bold text-primary" name="selling_price" value="<?= $row['selling_price'] ?>" step="0.01" required>
                                    </div>
                                    <div class="flex-grow-1">
                                        <label class="form-label fw-bold small text-muted mb-1">Estimated Cost (TZS)</label>
                                        <input type="number" class="form-control form-control-lg bg-white border-0 fw-bold fs-4" id="editNipCostMain" name="cost_price" value="<?= $row['cost_price'] ?>" readonly>
                                    </div>
                                </div>
                            </div>

                            <!-- Components Table -->
                            <div class="col-12 mt-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold mb-0 text-uppercase small text-muted"><i class="bi bi-list-ul me-2"></i>Material Components</h6>
                                </div>
                                <div class="table-responsive border rounded-3 shadow-sm overflow-hidden">
                                    <table class="table table-hover align-middle mb-0" id="editNipCompTableMain">
                                        <thead class="bg-light">
                                            <tr class="small text-uppercase">
                                                <th style="width:5%" class="text-center py-3 ps-3">#</th>
                                                <th style="width:55%">Material Description</th>
                                                <th style="width:15%" class="text-center">Unit</th>
                                                <th style="width:15%" class="text-end">Qty</th>
                                                <th style="width:10%" class="text-center"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="editNipCompBodyMain">
                                            <!-- Rows injected by JS -->
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-outline-primary btn-sm px-4 fw-bold shadow-sm" onclick="addCompRowMain()">
                                        <i class="bi bi-plus-circle me-1"></i> Add Material Row
                                    </button>
                                </div>
                            </div>

                            <!-- Footer Actions -->
                            <div class="col-12 mt-5 text-end">
                                <hr class="mb-4 opacity-50">
                                <a href="<?= getUrl('view_nip_materials') ?>?id=<?= $product_id ?>" class="btn btn-link text-muted text-decoration-none fw-bold me-3">
                                    Cancel
                                </a>
                                <button type="submit" class="btn btn-primary btn-lg px-5 shadow-sm fw-bold rounded-3" id="mainSaveBtn">
                                    <i class="bi bi-check-circle me-2"></i>Update Materials
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
    .letter-spacing-1 { letter-spacing: 1px; }
    .tiny { font-size: 0.65rem; }
    .border-dashed { border-style: dashed !important; }
    .form-control:focus, .form-select:focus {
        background-color: #fff !important;
        border-color: #0d6efd !important;
        box-shadow: none;
    }
    .nip-search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 1050;
        background: #fff;
        border: 1px solid #0d6efd;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 15px 35px rgba(13, 110, 253, 0.15);
        max-height: 280px;
        overflow-y: auto;
    }
    #editNipCompBodyMain tr { transition: all 0.2s; }
    #editNipCompBodyMain tr:hover { background: #f8faff; }
</style>



<script>
const ALL_WH = <?= json_encode($warehouses_all) ?>;
const NIP_URL = "<?= getUrl('nip_materials') ?>";
let rowIdx = 0;

function filterWarehousesMain(projId) {
    const $sel = $('#editNipWarehouseMain');
    const curr = $sel.val();
    $sel.find('option:not(:first-child)').each(function() {
        const p = $(this).data('project');
        if (!projId) {
            $(this).toggle(!p || p == 0);
        } else {
            $(this).toggle(String(p) === String(projId));
        }
    });
    // If current selected is hidden, reset to blank
    if ($sel.find('option:selected').css('display') === 'none') {
        $sel.val('');
    }
}

function addCompRowMain(data = null) {
    const idx = rowIdx++;
    const name = data ? (data.product_name || '') : '';
    const pid  = data ? (data.component_product_id || '') : '';
    const unit = data ? (data.unit || 'EA') : 'EA';
    const qty  = data ? (data.qty_per_unit || 1) : 1;
    const cost = data ? (data.component_cost || 0) : 0;

    const html = `
    <tr id="row-${idx}" class="comp-row">
        <td class="text-center text-muted small ps-4 sno"></td>
        <td>
            <div class="position-relative">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0 desc-input" 
                           placeholder="Search inventory..." 
                           onkeyup="searchInv(this, ${idx})" onclick="searchInv(this, ${idx})"
                           value="${name}" autocomplete="off">
                    <input type="hidden" name="components[${idx}][product_id]" value="${pid}" id="pid-${idx}">
                    <input type="hidden" name="components[${idx}][total_qty]" value="${qty}" id="total-qty-${idx}">
                    <input type="hidden" class="cost-hidden" id="cost-${idx}" value="${cost}">
                </div>
                <div id="res-${idx}" class="nip-search-results d-none"></div>
            </div>
        </td>
        <td><input type="text" name="components[${idx}][unit]" class="form-control form-control-sm text-center fw-bold" value="${unit}"></td>
        <td><input type="number" name="components[${idx}][qty_per_unit]" class="form-control form-control-sm text-end fw-bold" value="${qty}" step="any" oninput="updateTotalQty(${idx}); recalcCostMain();"></td>
        <td class="text-center pe-4">
            <button type="button" class="btn btn-link text-danger p-0" onclick="removeRow(${idx})"><i class="bi bi-trash fs-5"></i></button>
        </td>
    </tr>`;
    $('#editNipCompBodyMain').append(html);
    renumberMain();
    recalcCostMain();
}

function updateTotalQty(idx) {
    const qty = $(`#row-${idx} input[name$="[qty_per_unit]"]`).val();
    $(`#total-qty-${idx}`).val(qty);
}

function removeRow(idx) {
    $(`#row-${idx}`).remove();
    renumberMain();
    recalcCostMain();
}

function renumberMain() {
    $('#editNipCompBodyMain tr').each((i, el) => { $(el).find('.sno').text(i + 1); });
}

function searchInv(input, idx) {
    const whId = $('#editNipWarehouseMain').val();
    const $res = $(`#res-${idx}`);
    if (!whId) {
        $res.html('<div class="p-2 text-warning small">Select warehouse first</div>').removeClass('d-none');
        return;
    }
    if (!input.value) { $res.addClass('d-none'); return; }

    $res.html('<div class="p-2 small text-muted">Searching...</div>').removeClass('d-none');
    fetch(`${NIP_URL}/api/account/get_products.php?search=${encodeURIComponent(input.value)}&warehouse_id=${whId}&is_service=0&active_only=1&limit=8`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                let html = '<div class="list-group list-group-flush">';
                data.data.forEach(p => {
                    const safe = JSON.stringify(p).replace(/'/g, "&#39;");
                    html += `
                    <button type="button" class="list-group-item list-group-item-action p-2" onclick='selectInv(${idx}, ${safe})'>
                        <div class="d-flex justify-content-between">
                            <span class="small fw-bold">${p.product_name}</span>
                            <span class="small text-primary">TZS ${parseFloat(p.cost_price).toLocaleString()}</span>
                        </div>
                    </button>`;
                });
                html += '</div>';
                $res.html(html).removeClass('d-none');
            } else {
                $res.html('<div class="p-2 small text-muted">No products found</div>');
            }
        });
}

function selectInv(idx, p) {
    $(`#pid-${idx}`).val(p.product_id);
    $(`#cost-${idx}`).val(p.cost_price);
    $(`#row-${idx} .desc-input`).val(p.product_name);
    $(`#res-${idx}`).addClass('d-none');
    recalcCostMain();
}

function recalcCostMain() {
    let sub = 0;
    $('.comp-row').each(function() {
        const qty = parseFloat($(this).find('input[name$="[qty_per_unit]"]').val()) || 0;
        const c   = parseFloat($(this).find('.cost-hidden').val()) || 0;
        sub += qty * c;
    });
    const tax = parseFloat($('#editNipTaxMain option:selected').data('rate')) || 0;
    const total = sub * (1 + tax / 100);
    $('#editNipCostMain').val(total.toFixed(2));
}

$(document).on('click', e => { if (!$(e.target).closest('.position-relative').length) $('.nip-search-results').addClass('d-none'); });

$(document).ready(() => {
    // Load existing components
    const existing = <?= json_encode($components) ?>;
    if (existing.length > 0) {
        existing.forEach(c => addCompRowMain(c));
    } else {
        addCompRowMain();
    }
    filterWarehousesMain($('#editNipProjectMain').val());
});

$('#editNipFormMain').on('submit', function(e) {
    e.preventDefault();
    const $btn = $('#mainSaveBtn');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

    $.ajax({
        url: "<?= getUrl('api/update_nip_product') ?>",
        type: 'POST',
        data: new FormData(this),
        processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Saved!', text: 'Material updated successfully.', timer: 1500, showConfirmButton: false });
                setTimeout(() => location.href = "<?= getUrl('view_nip_materials') ?>?id=<?= $product_id ?>", 1600);
            } else {
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-2"></i>Update Materials');
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        },
        error: (xhr) => {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-2"></i>Update Materials');
            console.error(xhr.responseText);
            Swal.fire({ icon: 'error', title: 'Server Error', text: 'Could not connect to server or route not found.' });
        }
    });
});
</script>

<?php includeFooter(); ?>
