// ══════════════════════════════════════════════════════════════════════════
// PROJECT NON-INVENTORY PRODUCTS TAB
// ══════════════════════════════════════════════════════════════════════════
const PROJ_NIP_URL   = '<?= rtrim(getUrl(''), '/') ?>';
const PROJ_NIP_WHMAP = <?= json_encode($proj_warehouses) ?>;
let _projNipAll      = [];
let projNipAddIdx    = 0;
let projNipEditIdx   = 0;

// ── Tab click ──────────────────────────────────────────────────────────────
$(document).on('click', '#proc-nip-products-tab', projNipLoadTable);

// ── Load table ─────────────────────────────────────────────────────────────
function projNipLoadTable() {
    const $c = $('#projNipContent');
    $c.html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    $.getJSON(PROJ_NIP_URL + '/api/get_project_nip_products.php?project_id=<?= $project_id ?>', function(res) {
        if (!res.success) { $c.html('<div class="alert alert-danger">Failed to load products.</div>'); return; }
        const s = res.stats;
        $('#projNipStats').html(`
            <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;"><div class="card-body p-3 text-center">
                <div class="fw-bold fs-4" style="color:#0f5132;">${s.total}</div><small style="color:#0f5132;">Total Products</small>
            </div></div></div>
            <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;"><div class="card-body p-3 text-center">
                <div class="fw-bold fs-4" style="color:#0f5132;">${s.active}</div><small style="color:#0f5132;">Active</small>
            </div></div></div>
            <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100" style="background-color:#d1e7dd;"><div class="card-body p-3 text-center">
                <div class="fw-bold fs-4" style="color:#0f5132;">${s.inactive}</div><small style="color:#0f5132;">Inactive</small>
            </div></div></div>`);
        _projNipAll = res.products;
        projNipRenderTable(res.products);
    }).fail(function() { $c.html('<div class="alert alert-danger">Failed to load. Please refresh.</div>'); });
}

function projNipFilter() {
    const q  = ($('#projNipSearch').val() || '').toLowerCase();
    const st = $('#projNipStatusFilter').val();
    let filtered = _projNipAll;
    if (q)  filtered = filtered.filter(p => (p.product_name||'').toLowerCase().includes(q) || (p.sku||'').toLowerCase().includes(q) || (p.contract_item_no||'').toLowerCase().includes(q));
    if (st) filtered = filtered.filter(p => p.status === st);
    projNipRenderTable(filtered);
}

function projNipRenderTable(products) {
    const $c = $('#projNipContent');
    if (!products.length) {
        $c.html('<div class="text-center py-5 text-muted"><i class="bi bi-gear" style="font-size:3rem;opacity:.3;"></i><p class="mt-3">No non-inventory products found for this project.</p></div>');
        $('#projNipCountLabel').text('');
        return;
    }
    $('#projNipCountLabel').text(products.length + ' product(s)');
    let rows = '';
    products.forEach((p, i) => {
        const statusBadge = p.status === 'active'
            ? '<span class="badge bg-success">Active</span>'
            : `<span class="badge bg-secondary">${(p.status.charAt(0).toUpperCase()+p.status.slice(1))}</span>`;
        const taxInfo = p.tax_name ? `${p.tax_name} (${p.tax_rate}%)` : 'No Tax';
        const enc = JSON.stringify(p).replace(/"/g, '&quot;');
        const safeName = (p.product_name||'').replace(/'/g, "\\'");
        const itemCode = p.contract_item_no || p.sku || '—';
        rows += `<tr>
            <td class="ps-3 text-muted text-center fw-bold">${i+1}</td>
            <td><code class="small fw-bold text-primary">${itemCode}</code></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;">
                        <i class="bi bi-gear text-primary"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark">${p.product_name}</div>
                        ${p.sku ? `<small class="text-muted">${p.sku}</small>` : ''}
                    </div>
                </div>
            </td>
            <td class="fw-bold">TZS ${parseFloat(p.selling_price||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
            <td><span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">${taxInfo}</span></td>
            <td>${statusBadge}</td>
            <td class="text-end pe-3 d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-light border dropdown-toggle px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="${PROJ_NIP_URL}/service_view?id=${p.product_id}&from_project=<?= $project_id ?>"><i class="bi bi-layout-text-window text-primary me-2"></i> View Details</a></li>
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick='projNipOpenEdit(${enc})'><i class="bi bi-pencil text-warning me-2"></i> Edit</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="projNipDelete(${p.product_id},'${safeName}')"><i class="bi bi-trash me-2"></i> Delete</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
    });
    $c.html(`<div class="card border-0 shadow-sm rounded-4"><div class="card-body p-0"><div class="table-responsive">
        <table id="projNipInnerTable" class="table table-hover align-middle mb-0">
            <thead class="table-light text-uppercase small fw-bold">
                <tr>
                    <th class="ps-3" style="width:55px;">S/NO</th>
                    <th style="width:110px;">Item Code</th>
                    <th>Product Name</th>
                    <th style="width:150px;">Selling Price</th>
                    <th style="width:130px;">Tax</th>
                    <th style="width:90px;">Status</th>
                    <th class="text-end pe-3 d-print-none" style="width:80px;">Actions</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    </div></div></div>`);
    if ($.fn.DataTable.isDataTable('#projNipInnerTable')) $('#projNipInnerTable').DataTable().destroy();
    $('#projNipInnerTable').DataTable({ responsive: true, pageLength: 25, autoWidth: false, columnDefs: [{ orderable: false, targets: [0, 6] }] });
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('projNipInnerTable');
}

// ── Helpers: unit selector ─────────────────────────────────────────────────
function projNipCheckOtherUnit(sel, containerId) {
    if (sel.value !== 'other') return;
    document.getElementById(containerId).innerHTML = `<div class="input-group input-group-sm">
        <input type="text" class="form-control fw-bold border" name="unit" placeholder="Enter unit..." required autofocus>
        <button class="btn btn-outline-secondary" type="button" onclick="projNipResetUnit('${containerId}')"><i class="bi bi-x-lg"></i></button>
    </div>`;
}

function projNipResetUnit(containerId) {
    const isEdit = containerId.includes('Edit');
    const selId  = isEdit ? 'projNipEditUnitSelect' : 'projNipAddUnitSelect';
    document.getElementById(containerId).innerHTML = `
        <select class="form-select form-select-sm fw-bold border border-secondary border-opacity-25" name="unit" id="${selId}" onchange="projNipCheckOtherUnit(this,'${containerId}')">
            <option value="job">Job</option><option value="pcs">Pieces</option><option value="set">Set</option>
            <option value="box">Box</option><option value="ltr">Litre</option><option value="kg">Kg</option>
            <option value="other">Other (specify)</option>
        </select>`;
}

// ── Toggle steps ───────────────────────────────────────────────────────────
function projNipToggleStep(step) {
    $('#projNipStep1').toggle(step === 1);
    $('#projNipStep2').toggle(step === 2);
    const on = '#0d6efd', off = '#000';
    $('#projNipTab1').css({color: step===1?on:off, borderBottom: step===1?'2px solid #0d6efd':'none'});
    $('#projNipTab2').css({color: step===2?on:off, borderBottom: step===2?'2px solid #0d6efd':'none'});
}

function projNipEditToggleStep(step) {
    $('#projNipEditStep1').toggle(step === 1);
    $('#projNipEditStep2').toggle(step === 2);
    const on = '#0d6efd', off = '#000';
    $('#projNipEditTab1').css({color: step===1?on:off, borderBottom: step===1?'2px solid #0d6efd':'none'});
    $('#projNipEditTab2').css({color: step===2?on:off, borderBottom: step===2?'2px solid #0d6efd':'none'});
}

// ── Populate warehouse dropdown from PROJ_NIP_WHMAP ───────────────────────
function projNipFillWarehouse(selectId, selectedId) {
    const $s = $('#' + selectId);
    $s.html('<option value="">— Select Warehouse —</option>');
    PROJ_NIP_WHMAP.forEach(w => {
        const opt = new Option(w.warehouse_name, w.warehouse_id);
        if (String(w.warehouse_id) === String(selectedId)) opt.selected = true;
        $s.append(opt);
    });
    if (PROJ_NIP_WHMAP.length === 1) $s.val(PROJ_NIP_WHMAP[0].warehouse_id);
}

// ── Open Add ──────────────────────────────────────────────────────────────
function projNipOpenAdd() {
    document.getElementById('projNipAddForm').reset();
    $('#projNipAddMsg').html('');
    $('#projNipAddCompBody').empty();
    projNipAddIdx = 0;
    projNipResetUnit('projNipAddUnitContainer');
    projNipFillWarehouse('projNipAddWarehouseId', null);
    projNipToggleStep(1);
    projNipAddCompRow();
    new bootstrap.Modal(document.getElementById('projNipAddModal')).show();
}

// ── ADD component row ─────────────────────────────────────────────────────
function projNipAddCompRow(data) {
    const idx = projNipAddIdx++;
    const name = data ? (data.component_name || data.product_name || '') : '';
    const pid  = data ? (data.component_product_id || data.product_id || '') : '';
    const unit = data ? (data.unit || 'EA') : 'EA';
    const qty  = data ? (data.qty_per_unit || 1) : 1;
    const tot  = data ? (data.total_qty || 0) : 0;
    const cost = data ? (data.cost_price || 0) : 0;
    const html = `<tr id="projNipAddRow-${idx}">
        <td class="text-center fw-bold text-muted proj-nip-add-sno"></td>
        <td style="position:relative;">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control border-start-0 ps-0" placeholder="Search product..."
                    onkeyup="projNipSearchComp(this,${idx})" onclick="projNipSearchComp(this,${idx})" value="${name.replace(/"/g,'&quot;')}">
                <input type="hidden" name="components[${idx}][product_id]" value="${pid}" id="projNipAddPid-${idx}">
            </div>
            <div id="projNipAddRes-${idx}" class="position-absolute bg-white shadow rounded border d-none" style="z-index:1070;width:420px;max-height:220px;overflow-y:auto;top:100%;left:0;"></div>
        </td>
        <td><input type="text" name="components[${idx}][unit]" class="form-control form-control-sm text-center" value="${unit}" id="projNipAddUnit-${idx}"></td>
        <td><input type="number" name="components[${idx}][qty_per_unit]" class="form-control form-control-sm text-end" min="0.001" step="any"
            value="${qty}" id="projNipAddQty-${idx}" oninput="projNipReCalcRow(${idx})"></td>
        <input type="hidden" name="components[${idx}][total_qty]" value="${tot}" id="projNipAddTot-${idx}">
        <input type="hidden" id="projNipAddCostH-${idx}" value="${cost}">
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                onclick="$('#projNipAddRow-${idx}').remove(); projNipAddRenumber(); projNipCalcSum();">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>`;
    $('#projNipAddCompBody').append(html);
    projNipAddRenumber();
    if (data) projNipReCalcRow(idx);
}

function projNipAddRenumber() {
    $('#projNipAddCompBody tr').each(function(i) { $(this).find('.proj-nip-add-sno').text(i+1); });
}

function projNipReCalcRow(idx) {
    const qty  = parseFloat($(`#projNipAddQty-${idx}`).val()) || 0;
    const base = parseFloat($('#projNipAddAsmQty').val()) || 1;
    $(`#projNipAddTot-${idx}`).val((qty * base).toFixed(2));
    projNipCalcSum();
}

function projNipCalcSum() {
    let t = 0;
    $('#projNipAddCompBody tr').each(function() {
        const idx = $(this).attr('id').replace('projNipAddRow-','');
        t += (parseFloat($(`#projNipAddQty-${idx}`).val())||0) * (parseFloat($(`#projNipAddCostH-${idx}`).val())||0);
    });
    $('#projNipAddCostSum').val(t.toFixed(2));
}

function projNipAddRefreshCosts() {
    const whId = document.getElementById('projNipAddWarehouseId').value;
    if (!whId) return;
    $('#projNipAddCompBody tr').each(function() {
        const idx = $(this).attr('id').replace('projNipAddRow-','');
        const pid = $(`#projNipAddPid-${idx}`).val();
        if (!pid) return;
        fetch(`${PROJ_NIP_URL}/api/account/get_products.php?warehouse_id=${whId}&product_id=${pid}`)
            .then(r => r.json()).then(j => {
                if (j.success && j.data && j.data.length) {
                    $(`#projNipAddCostH-${idx}`).val(parseFloat(j.data[0].cost_price)||0);
                    projNipCalcSum();
                }
            });
    });
}

// ── Shared: body-appended dropdown (avoids overflow clipping in scrollable modal) ──
function _projNipDropdown(input, whId, idx, selectFnName) {
    $('.proj-nip-dd').remove();
    if (!whId) {
        const rect = input.getBoundingClientRect();
        const $dd = $('<div class="proj-nip-dd bg-white shadow rounded border" style="z-index:99999;position:fixed;min-width:320px;max-height:240px;overflow-y:auto;"></div>')
            .css({ top: rect.bottom + 2, left: rect.left })
            .html('<div class="p-2 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>Select a warehouse first.</div>');
        $('body').append($dd);
        setTimeout(() => $('.proj-nip-dd').remove(), 2500);
        return;
    }
    const rect = input.getBoundingClientRect();
    const $dd = $('<div class="proj-nip-dd bg-white shadow rounded border" style="z-index:99999;position:fixed;min-width:380px;max-width:480px;max-height:240px;overflow-y:auto;"></div>')
        .css({ top: rect.bottom + 2, left: rect.left })
        .html('<div class="p-2 text-muted small"><div class="spinner-border spinner-border-sm me-1"></div>Searching…</div>');
    $('body').append($dd);

    fetch(`${PROJ_NIP_URL}/api/account/get_products.php?search=${encodeURIComponent(input.value)}&warehouse_id=${whId}&is_service=0&active_only=1&limit=15`)
        .then(r => r.json()).then(data => {
            if (!$('.proj-nip-dd').length) return;
            if (data.success && data.data && data.data.length) {
                const items = data.data.map(p => {
                    const price = parseFloat(p.cost_price)||parseFloat(p.purchase_price)||0;
                    return `<button type="button" class="list-group-item list-group-item-action p-2 border-bottom"
                        onclick='${selectFnName}(${idx}, ${JSON.stringify(p).replace(/'/g,"&#39;")})'>
                        <div class="d-flex justify-content-between align-items-center">
                            <div style="flex:1;min-width:0;"><div class="fw-bold text-dark text-truncate" style="font-size:13px;">${p.product_name}</div>
                            <small class="text-muted">${p.sku||'No SKU'}</small></div>
                            <div class="text-end ms-2" style="white-space:nowrap;">
                                <small class="d-block text-muted">Stock: ${parseFloat(p.current_stock)||0}</small>
                                <span class="fw-bold text-primary" style="font-size:12px;">TZS ${price.toLocaleString()}</span>
                            </div>
                        </div></button>`;
                }).join('');
                $('.proj-nip-dd').html(`<div class="list-group list-group-flush">${items}</div>`);
            } else {
                $('.proj-nip-dd').html('<div class="p-2 text-muted small">No products found in this warehouse.</div>');
            }
        }).catch(() => $('.proj-nip-dd').remove());
}

$(document).on('click', function(e) {
    if (!$(e.target).closest('.proj-nip-dd, #projNipAddCompBody input[type="text"], #projNipEditCompBody input[type="text"]').length) {
        $('.proj-nip-dd').remove();
    }
});

// ── Component search (Add) ─────────────────────────────────────────────────
function projNipSearchComp(input, idx) {
    const whId = document.getElementById('projNipAddWarehouseId').value;
    _projNipDropdown(input, whId, idx, 'projNipSelectComp');
}

function projNipSelectComp(idx, prod) {
    $('.proj-nip-dd').remove();
    const price = parseFloat(prod.cost_price)||parseFloat(prod.purchase_price)||0;
    $(`#projNipAddRow-${idx} input[type="text"]`).val(prod.product_name);
    $(`#projNipAddPid-${idx}`).val(prod.product_id);
    $(`#projNipAddUnit-${idx}`).val(prod.unit||'EA');
    $(`#projNipAddCostH-${idx}`).val(price);
    projNipReCalcRow(idx);
}

// ── ADD form submit ────────────────────────────────────────────────────────
$('#projNipAddForm').on('submit', function(e) {
    e.preventDefault();
    const $btn = $('#projNipSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    fetch(`${PROJ_NIP_URL}/api/create_project_nip_product.php`, { method:'POST', body: new FormData(this) })
        .then(r => r.json()).then(res => {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Create Product');
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('projNipAddModal')).hide();
                Swal.fire({ icon:'success', title:'Product Created!', text:res.message, timer:2000, showConfirmButton:false });
                projNipLoadTable();
            } else {
                $('#projNipAddMsg').html(`<div class="alert alert-danger py-2">${res.message}</div>`);
            }
        }).catch(() => {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Create Product');
            $('#projNipAddMsg').html('<div class="alert alert-danger py-2">Server error. Try again.</div>');
        });
});

// ── Open Edit ─────────────────────────────────────────────────────────────
function projNipOpenEdit(product) {
    if (typeof product === 'string') product = JSON.parse(product);
    document.getElementById('projNipEditForm').reset();
    $('#projNipEditMsg').html('');
    $('#projNipEditCompBody').empty();
    projNipEditIdx = 0;

    document.getElementById('projNipEditId').value          = product.product_id;
    document.getElementById('projNipEditName').value        = product.product_name;
    document.getElementById('projNipEditDesc').value        = product.description || '';
    document.getElementById('projNipEditContractNo').value  = product.contract_item_no || '';
    document.getElementById('projNipEditSell').value        = product.selling_price || 0;
    document.getElementById('projNipEditCost').value        = product.cost_price || 0;
    document.getElementById('projNipEditAsmQty').value      = product.assembly_quantity || 1;
    document.getElementById('projNipEditTax').value         = product.tax_id || '';
    document.getElementById('projNipEditStatus').value      = product.status || 'active';

    // Unit
    projNipResetUnit('projNipEditUnitContainer');
    const stdUnits = ['job','pcs','set','box','ltr','kg'];
    const uSel = document.getElementById('projNipEditUnitSelect');
    if (uSel) {
        if (product.unit && !stdUnits.includes(product.unit)) {
            uSel.value = 'other';
            projNipCheckOtherUnit(uSel, 'projNipEditUnitContainer');
            const cu = document.querySelector('#projNipEditUnitContainer input[name="unit"]');
            if (cu) cu.value = product.unit;
        } else {
            uSel.value = product.unit || 'job';
        }
    }

    // Warehouse
    projNipFillWarehouse('projNipEditWarehouseId', product.warehouse_id);
    projNipEditToggleStep(1);
    new bootstrap.Modal(document.getElementById('projNipEditModal')).show();

    // Components
    fetch(`${PROJ_NIP_URL}/api/get_nip_components.php?id=${product.product_id}`)
        .then(r => r.json()).then(json => {
            if (json.success && json.data && json.data.length) {
                json.data.forEach(c => projNipEditAddCompRow(c));
                setTimeout(projNipEditRefreshCosts, 150);
            } else {
                projNipEditAddCompRow();
            }
        }).catch(() => projNipEditAddCompRow());
}

// ── EDIT component row ─────────────────────────────────────────────────────
function projNipEditAddCompRow(data) {
    const idx  = projNipEditIdx++;
    const name = data ? (data.component_name || data.product_name || '') : '';
    const pid  = data ? (data.component_product_id || data.product_id || '') : '';
    const unit = data ? (data.unit || 'EA') : 'EA';
    const qty  = data ? (data.qty_per_unit || 1) : 1;
    const tot  = data ? (data.total_qty || 0) : 0;
    const cost = data ? (data.cost_price || 0) : 0;
    const html = `<tr id="projNipEditRow-${idx}">
        <td class="text-center fw-bold text-muted proj-nip-edit-sno"></td>
        <td style="position:relative;">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control border-start-0 ps-0" placeholder="Search product..."
                    onkeyup="projNipEditSearchComp(this,${idx})" onclick="projNipEditSearchComp(this,${idx})" value="${name.replace(/"/g,'&quot;')}">
                <input type="hidden" name="components[${idx}][product_id]" value="${pid}" id="projNipEditPid-${idx}">
            </div>
            <div id="projNipEditRes-${idx}" class="position-absolute bg-white shadow rounded border d-none" style="z-index:1070;width:420px;max-height:220px;overflow-y:auto;top:100%;left:0;"></div>
        </td>
        <td><input type="text" name="components[${idx}][unit]" class="form-control form-control-sm text-center" value="${unit}" id="projNipEditUnit-${idx}"></td>
        <td><input type="number" name="components[${idx}][qty_per_unit]" class="form-control form-control-sm text-end" min="0.001" step="any"
            value="${qty}" id="projNipEditQty-${idx}" oninput="projNipEditReCalcRow(${idx})"></td>
        <input type="hidden" name="components[${idx}][total_qty]" value="${tot}" id="projNipEditTot-${idx}">
        <input type="hidden" id="projNipEditCostH-${idx}" value="${cost}">
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                onclick="$('#projNipEditRow-${idx}').remove(); projNipEditRenumber(); projNipEditCalcSum();">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>`;
    $('#projNipEditCompBody').append(html);
    projNipEditRenumber();
    if (data) projNipEditReCalcRow(idx);
}

function projNipEditRenumber() {
    $('#projNipEditCompBody tr').each(function(i) { $(this).find('.proj-nip-edit-sno').text(i+1); });
}

function projNipEditReCalcRow(idx) {
    const qty  = parseFloat($(`#projNipEditQty-${idx}`).val()) || 0;
    const base = parseFloat($('#projNipEditAsmQty').val()) || 1;
    $(`#projNipEditTot-${idx}`).val((qty * base).toFixed(2));
    projNipEditCalcSum();
}

function projNipEditCalcSum() {
    let t = 0;
    $('#projNipEditCompBody tr').each(function() {
        const idx = $(this).attr('id').replace('projNipEditRow-','');
        t += (parseFloat($(`#projNipEditQty-${idx}`).val())||0) * (parseFloat($(`#projNipEditCostH-${idx}`).val())||0);
    });
    $('#projNipEditCost').val(t.toFixed(2));
}

function projNipEditRefreshCosts() {
    const whId = document.getElementById('projNipEditWarehouseId').value;
    if (!whId) return;
    $('#projNipEditCompBody tr').each(function() {
        const idx = $(this).attr('id').replace('projNipEditRow-','');
        const pid = $(`#projNipEditPid-${idx}`).val();
        if (!pid) return;
        fetch(`${PROJ_NIP_URL}/api/account/get_products.php?warehouse_id=${whId}&product_id=${pid}`)
            .then(r => r.json()).then(j => {
                if (j.success && j.data && j.data.length) {
                    $(`#projNipEditCostH-${idx}`).val(parseFloat(j.data[0].cost_price)||0);
                    projNipEditCalcSum();
                }
            });
    });
}

// ── Component search (Edit) ────────────────────────────────────────────────
function projNipEditSearchComp(input, idx) {
    const whId = document.getElementById('projNipEditWarehouseId').value;
    _projNipDropdown(input, whId, idx, 'projNipEditSelectComp');
}

function projNipEditSelectComp(idx, prod) {
    $('.proj-nip-dd').remove();
    const price = parseFloat(prod.cost_price)||parseFloat(prod.purchase_price)||0;
    $(`#projNipEditRow-${idx} input[type="text"]`).val(prod.product_name);
    $(`#projNipEditPid-${idx}`).val(prod.product_id);
    $(`#projNipEditUnit-${idx}`).val(prod.unit||'EA');
    $(`#projNipEditCostH-${idx}`).val(price);
    projNipEditReCalcRow(idx);
}

$('#projNipEditWarehouseId').on('change', projNipEditRefreshCosts);

// ── EDIT form submit ───────────────────────────────────────────────────────
$('#projNipEditForm').on('submit', function(e) {
    e.preventDefault();
    const $btn = $('#projNipEditSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    fetch(`${PROJ_NIP_URL}/api/update_project_nip_product.php`, { method:'POST', body: new FormData(this) })
        .then(r => r.json()).then(res => {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('projNipEditModal')).hide();
                Swal.fire({ icon:'success', title:'Updated!', text:res.message, timer:2000, showConfirmButton:false });
                projNipLoadTable();
            } else {
                $('#projNipEditMsg').html(`<div class="alert alert-danger py-2">${res.message}</div>`);
            }
        }).catch(() => {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
            $('#projNipEditMsg').html('<div class="alert alert-danger py-2">Server error. Try again.</div>');
        });
});

// ── Delete ─────────────────────────────────────────────────────────────────
function projNipDelete(id, name) {
    Swal.fire({
        title: 'Delete Non-Inventory Product?',
        html: `Are you sure you want to delete <strong>${name}</strong>?<br><small class="text-danger">This cannot be undone.</small>`,
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(`${PROJ_NIP_URL}/api/delete_product.php`, { product_id: id }, function(res) {
            if (res.success) { Swal.fire({ icon:'success', title:'Deleted!', timer:1200, showConfirmButton:false }); projNipLoadTable(); }
            else { Swal.fire({ icon:'error', title:'Error', text:res.message }); }
        }, 'json');
    });
}

// ============================================================
// PROJECT SUB-CONTRACTORS
// ============================================================
let projScTable = null;

const PROJ_SC_ID = <?= $project_id ?>;

function projScStatusBadge(status) {
    const map = { active:'success', inactive:'secondary', suspended:'warning', blacklisted:'danger' };
    return map[status] || 'secondary';
}

function projScLoadTable() {
    $.getJSON(APP_URL + '/api/get_project_sub_contractors.php', { project_id: PROJ_SC_ID }, function(res) {
        if (!res.success) return;
        const data = res.data;

        // Stats
        $('#proj-sc-total').text(data.length);
        $('#proj-sc-active').text(data.filter(s => s.status === 'active').length);
        $('#proj-sc-suspended').text(data.filter(s => s.status === 'suspended').length);
        $('#proj-sc-blacklisted').text(data.filter(s => s.status === 'blacklisted').length);

        if (projScTable) { projScTable.destroy(); projScTable = null; }
        const tbody = $('#proj-sc-table tbody').empty();

        let sn = 1;
        data.forEach(sc => {
            const badge = projScStatusBadge(sc.status);
            const statusLabel = sc.status.charAt(0).toUpperCase() + sc.status.slice(1);
            const addr = (sc.address || '').substring(0, 30) + ((sc.address || '').length > 30 ? '...' : '');
            const locationHidden = [sc.city || '', sc.country || ''].filter(Boolean).join(', ');
            const activateBtn  = sc.status !== 'active'      ? `<li><a class="dropdown-item py-2 rounded" href="#" onclick="projScUpdateStatus(${sc.supplier_id},'active')"><i class="bi bi-play-circle text-success me-2"></i>Activate</a></li>` : '';
            const suspendBtn   = sc.status !== 'suspended'   ? `<li><a class="dropdown-item py-2 rounded" href="#" onclick="projScUpdateStatus(${sc.supplier_id},'suspended')"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Suspend</a></li>` : '';
            const blacklistBtn = sc.status !== 'blacklisted' ? `<li><a class="dropdown-item py-2 rounded" href="#" onclick="projScUpdateStatus(${sc.supplier_id},'blacklisted')"><i class="bi bi-slash-circle text-danger me-2"></i>Blacklist</a></li>` : '';

            tbody.append(`<tr>
                <td class="text-center">${sn++}</td>
                <td><span style="background:#e9ecef;padding:2px 6px;border-radius:4px;font-family:monospace;font-weight:bold;">${sc.supplier_code || ''}</span></td>
                <td><strong>${sc.supplier_name}</strong></td>
                <td><div class="small">${sc.contact_person || ''}<br><i class="bi bi-telephone"></i> ${sc.phone || ''}</div></td>
                <td><div class="small">${addr}<br><strong>${sc.city || ''}${sc.country ? ', ' + sc.country : ''}</strong></div></td>
                <td><span class="badge bg-secondary">${sc.category_name || 'General'}</span></td>
                <td><span class="badge bg-${badge}">${statusLabel}</span></td>
                <td class="d-none">${locationHidden}</td>
                <td class="d-print-none text-center">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle px-2" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i>Actions</button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                            <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('sub_contractors/view') ?>?id=${sc.supplier_id}&from=project&project_id=<?= $project_id ?>"><i class="bi bi-eye text-info me-2"></i>View Details</a></li>
                            <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('sub_contractors') ?>?edit=${sc.supplier_id}&project=<?= $project_id ?>&back=sub-contractors"><i class="bi bi-pencil text-primary me-2"></i>Edit</a></li>
                            <li><a class="dropdown-item py-2 rounded" href="${APP_URL}/purchase_orders?supplier=${sc.supplier_id}"><i class="bi bi-cart text-success me-2"></i>View Orders</a></li>
                            <li><a class="dropdown-item py-2 rounded" href="${APP_URL}/suppliers/payments?id=${sc.supplier_id}"><i class="bi bi-cash-stack text-warning me-2"></i>View Payments</a></li>
                            <li><hr class="dropdown-divider"></li>
                            ${activateBtn}${suspendBtn}${blacklistBtn}
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 rounded text-warning" href="#" onclick="projScRemoveFromProject(${sc.supplier_id},'${(sc.supplier_name||'').replace(/'/g,"\\'")}')"><i class="bi bi-x-circle me-2"></i>Remove from Project</a></li>
                            <li><a class="dropdown-item py-2 rounded text-danger" href="#" onclick="projScDelete(${sc.supplier_id})"><i class="bi bi-trash me-2"></i>Delete</a></li>
                        </ul>
                    </div>
                </td>
            </tr>`);
        });

        projScTable = $('#proj-sc-table').DataTable({
            pageLength: 25,
            responsive: true,
            dom: 'Brtip',
            buttons: [
                { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: [0,1,2,3,4,5,6] } }
            ],
            columnDefs: [
                { targets: 7, visible: false, searchable: true }
            ]
        });
    });
}

// Load on tab show
$(document).on('shown.bs.tab', '#proj-sc-tab', function() { projScLoadTable(); });

// ── Assign Existing Sub-Contractor (Select2 AJAX) ────────────────
function openAssignExistingScModal() {
    const $sel = $('#assignScSelect2');
    if ($sel.data('select2')) $sel.select2('destroy');
    $sel.empty();
    $sel.select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#assignExistingScModal'),
        placeholder: 'Type to search by name or code...',
        allowClear: true,
        width: '100%',
        minimumInputLength: 0,
        ajax: {
            url: APP_URL + '/api/get_sub_contractors_list.php',
            dataType: 'json',
            delay: 300,
            data: function(params) {
                return { search: params.term || '', exclude_project_id: PROJ_SC_ID };
            },
            processResults: function(res) {
                return { results: res.results || [] };
            },
            cache: false
        }
    });
    $('#assignExistingScModal').modal('show');
}

function confirmAssignExistingSc() {
    const supplierId = $('#assignScSelect2').val();
    if (!supplierId) { Swal.fire('Warning', 'Please select a sub-contractor.', 'warning'); return; }
    $.post(APP_URL + '/api/assign_sc_to_project.php', {
        action: 'assign', supplier_id: supplierId, project_id: PROJ_SC_ID
    }, function(res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('assignExistingScModal')).hide();
            Swal.fire({ icon: 'success', title: 'Assigned!', text: res.message, timer: 1500, showConfirmButton: false });
            projScLoadTable();
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
}
// ────────────────────────────────────────────────────────────────

// Add
$('#projScAddForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]').prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Saving...');
    $.ajax({
        url: APP_URL + '/api/add_sub_contractor.php',
        type: 'POST', data: new FormData(this), processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save');
            if (res.success) {
                // Link the newly created SC to this project in the junction table
                $.post(APP_URL + '/api/assign_sc_to_project.php', {
                    action: 'assign',
                    supplier_id: res.sub_contractor_id,
                    project_id: PROJ_SC_ID
                }).always(function() {
                    bootstrap.Modal.getInstance(document.getElementById('projScAddModal')).hide();
                    document.getElementById('projScAddForm').reset();
                    Swal.fire({ icon:'success', title:'Added!', text:res.message, timer:1500, showConfirmButton:false });
                    projScLoadTable();
                });
            } else { Swal.fire('Error', res.message, 'error'); }
        }
    });
});

// Edit — load data
function projScEdit(id) {
    $.getJSON(APP_URL + '/api/get_sub_contractor.php', { id: id }, function(res) {
        if (!res.success) { Swal.fire('Error', res.message, 'error'); return; }
        const d = res.data;
        $('#pesc_id').val(d.supplier_id);
        $('#pesc_name').val(d.supplier_name);
        $('#pesc_company_name').val(d.company_name);
        $('#pesc_acronym').val(d.acronym);
        $('#pesc_type').val(d.supplier_type);
        $('#pesc_year').val(d.year);
        $('#pesc_category').val(d.category_id);
        $('#pesc_status').val(d.status);
        $('#pesc_credit_limit').val(d.credit_limit);
        $('#pesc_description').val(d.description);
        $('#pesc_contact_person').val(d.contact_person);
        $('#pesc_contact_title').val(d.contact_title);
        $('#pesc_email').val(d.email);
        $('#pesc_company_email').val(d.company_email);
        $('#pesc_phone').val(d.phone);
        $('#pesc_mobile').val(d.mobile);
        $('#pesc_fax').val(d.fax);
        $('#pesc_website').val(d.website);
        $('#pesc_country').val(d.country);
        $('#pesc_state').val(d.state);
        $('#pesc_city').val(d.city);
        $('#pesc_council').val(d.council);
        $('#pesc_ward').val(d.ward);
        $('#pesc_postal_code').val(d.postal_code);
        $('#pesc_address').val(d.address);
        $('#pesc_postal_address').val(d.postal_address);
        $('#pesc_tax_id').val(d.tax_id);
        $('#pesc_vat_number').val(d.vat_number);
        $('#pesc_payment_terms').val(d.payment_terms);
        $('#pesc_currency').val(d.currency);
        $('#pesc_bank_name').val(d.bank_name);
        $('#pesc_bank_account').val(d.bank_account);
        $('#pesc_bank_address').val(d.bank_address);
        $('#pesc_logo_display').html(d.logo_path ? `<img src="${APP_URL}/${d.logo_path}" style="max-height:45px;" class="img-thumbnail">` : '');
        $('#projScEditModal').modal('show');
    });
}

// Edit — submit
$('#projScEditForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]').prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Saving...');
    $.ajax({
        url: APP_URL + '/api/update_sub_contractor.php',
        type: 'POST', data: new FormData(this), processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Update');
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('projScEditModal')).hide();
                Swal.fire({ icon:'success', title:'Updated!', timer:1500, showConfirmButton:false });
                projScLoadTable();
            } else { Swal.fire('Error', res.message, 'error'); }
        }
    });
});

// View
function projScView(id) {
    $.getJSON(APP_URL + '/api/get_sub_contractor.php', { id: id }, function(res) {
        if (!res.success) { Swal.fire('Error', res.message, 'error'); return; }
        const d = res.data;
        const na = v => v || 'N/A';
        const badge = projScStatusBadge(d.status);
        const statusLabel = d.status.charAt(0).toUpperCase() + d.status.slice(1);
        $('#pvsc_title').text(d.supplier_name + (d.company_name ? ' • ' + d.company_name : ''));
        $('#pvsc_status_badge').html(`<span class="badge bg-${badge}">${statusLabel}</span>`);
        $('#pvsc_code').text(na(d.supplier_code));
        $('#pvsc_type').text(na(d.supplier_type));
        $('#pvsc_category').text(na(d.category_name));
        $('#pvsc_year').text(na(d.year));
        $('#pvsc_tin').text(na(d.tax_id));
        $('#pvsc_vat').text(na(d.vat_number));
        $('#pvsc_credit_limit').text(na(d.credit_limit));
        $('#pvsc_bank_name').text(na(d.bank_name));
        $('#pvsc_bank_account').text(na(d.bank_account));
        $('#pvsc_currency').text(na(d.currency));
        $('#pvsc_payment_terms').text(na(d.payment_terms));
        $('#pvsc_contact_person').text(na(d.contact_person));
        $('#pvsc_contact_title').text(na(d.contact_title));
        $('#pvsc_email').text(na(d.email));
        $('#pvsc_phone').text(na(d.phone));
        $('#pvsc_mobile').text(na(d.mobile));
        $('#pvsc_website').text(na(d.website));
        $('#pvsc_address').text(na(d.address));
        $('#pvsc_postal_address').text(na(d.postal_address));
        $('#pvsc_city').text(na(d.city));
        $('#pvsc_state').text(na(d.state));
        $('#pvsc_ward').text(na(d.ward));
        $('#pvsc_country').text(na(d.country));
        $('#pvsc_description').text(d.description || 'No notes.');
        $('#pvsc_edit_btn').off('click').on('click', function() {
            bootstrap.Modal.getInstance(document.getElementById('projScViewModal')).hide();
            setTimeout(() => projScEdit(id), 400);
        });
        $('#projScViewModal').modal('show');
    });
}

// Delete
function projScDelete(id) {
    Swal.fire({
        title: 'Delete Sub-Contractor?', text: 'This cannot be undone!',
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, delete!'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(APP_URL + '/api/delete_sub_contractor.php', { supplier_id: id }, function(res) {
            if (res.success) { Swal.fire({ icon:'success', title:'Deleted!', timer:1200, showConfirmButton:false }); projScLoadTable(); }
            else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

// Status
function projScUpdateStatus(id, status) {
    const label = status.charAt(0).toUpperCase() + status.slice(1);
    const color = status === 'active' ? '#198754' : status === 'blacklisted' ? '#dc3545' : '#ffc107';
    Swal.fire({
        title: label + ' Sub-Contractor?', icon: 'question',
        showCancelButton: true, confirmButtonColor: color, confirmButtonText: 'Yes, ' + label + '!'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(APP_URL + '/api/update_sub_contractor_status.php', { supplier_id: id, status: status }, function(res) {
            if (res.success) { Swal.fire({ icon:'success', title:'Updated!', timer:1200, showConfirmButton:false }); projScLoadTable(); }
            else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

// Filters
function projScApplyFilters() {
    if (!projScTable) return;
    projScTable.column(6).search($('#projScStatusFilter').val());
    projScTable.column(5).search($('#projScCategoryFilter').val());
    const city    = $('#projScCityFilter').val().trim();
    const country = $('#projScCountryFilter').val().trim();
    const locSearch = [city, country].filter(Boolean).join('|');
    projScTable.column(7).search(locSearch, locSearch.includes('|'), false);
    projScTable.draw();
}

function projScClearFilters() {
    $('#projScStatusFilter, #projScCategoryFilter').val('');
    $('#projScCountryFilter, #projScCityFilter').val('');
    if (projScTable) projScTable.columns().search('').draw();
}

function projScExport() {
    if (!projScTable) { Swal.fire('Info', 'Load the table first.', 'info'); return; }
    projScTable.button('.buttons-excel').trigger();
}

function projScPrint() {
    const win = window.open('', '_blank');
    if (!win) return;

    const coName     = companyName;
    const logoSrc    = companyLogo ? (APP_URL + '/' + companyLogo.replace(/^\//, '')) : '';
    const logoHtml   = logoSrc ? `<img src="${logoSrc}" style="max-height:70px;width:auto;display:block;margin:0 auto 8px;">` : '';
    const projName   = <?= json_encode($project_name) ?>;
    const contractNo = <?= json_encode($contract_no ?? '') ?>;
    const printedBy  = <?= json_encode(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) . ' — ' . ucwords($_SESSION['user_role'] ?? 'Staff')) ?>;

    $.getJSON(APP_URL + '/api/get_project_sub_contractors.php', { project_id: PROJ_SC_ID }, function(res) {
        const data = res.success ? res.data : [];
        const badgeBg  = { active:'#198754', inactive:'#6c757d', suspended:'#856404', blacklisted:'#dc3545' };
        const badgeFg  = { active:'#fff',    inactive:'#fff',    suspended:'#fff',    blacklisted:'#fff'    };

        let rows = '';
        data.forEach((sc, i) => {
            const bg  = badgeBg[sc.status]  || '#6c757d';
            const fg  = badgeFg[sc.status]  || '#fff';
            const lbl = sc.status.charAt(0).toUpperCase() + sc.status.slice(1);
            rows += `<tr>
                <td style="text-align:center;">${i + 1}</td>
                <td style="font-family:monospace;font-weight:bold;">${sc.supplier_code || ''}</td>
                <td><strong>${sc.supplier_name}</strong></td>
                <td>${sc.contact_person || ''}${sc.phone ? '<br>' + sc.phone : ''}</td>
                <td>${sc.address || ''}${sc.city ? ', ' + sc.city : ''}</td>
                <td>${sc.category_name || 'General'}</td>
                <td><span style="background:${bg};color:${fg};padding:2px 8px;border-radius:4px;font-size:10.5px;font-weight:600;">${lbl}</span></td>
            </tr>`;
        });
        if (!rows) rows = '<tr><td colspan="7" style="text-align:center;color:#888;padding:20px;">No sub-contractors assigned to this project.</td></tr>';

        const now = new Date().toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });

        win.document.write(`<!DOCTYPE html><html><head>
            <meta charset="UTF-8">
            <title>Sub-Contractors — ${projName}</title>
            <style>
                * { box-sizing:border-box; margin:0; padding:0; }
                body { background:#fff; font-family:Arial,sans-serif; padding:28px 32px; font-size:12.5px; color:#222; }
                .prt-header { text-align:center; border-bottom:2px solid #0d6efd; padding-bottom:14px; margin-bottom:18px; }
                .prt-header .co-name { font-size:20px; font-weight:800; color:#0d6efd; text-transform:uppercase; }
                .prt-header .doc-title { font-size:15px; font-weight:700; margin:4px 0 2px; }
                .prt-header .proj-name { font-size:13px; color:#333; font-weight:600; }
                .prt-header .gen-date { font-size:10.5px; color:#999; margin-top:3px; }
                .prt-table { width:100%; border-collapse:collapse; margin-bottom:18px; font-size:12px; }
                .prt-table th { background:#f8f9fa; border-bottom:2px solid #dee2e6; padding:7px 10px; text-align:left; font-size:11.5px; }
                .prt-table td { padding:7px 10px; border-bottom:1px solid #f0f0f0; vertical-align:top; }
                .prt-footer { border-top:1px solid #eee; padding-top:8px; text-align:center; font-size:10px; color:#888; margin-top:24px; }
                .prt-footer strong { color:#0d6efd; }
                @page { margin:16mm; }
            </style>
        </head><body>
            <div class="prt-header">
                ${logoHtml}
                <div class="co-name">${coName}</div>
                <div class="doc-title">PROJECT SUB-CONTRACTORS</div>
                <div class="proj-name">${projName}${contractNo ? ' &bull; Contract No: ' + contractNo : ''}</div>
                <div class="gen-date">Generated: ${now}</div>
            </div>
            <table class="prt-table">
                <thead><tr>
                    <th style="width:5%;">#</th>
                    <th style="width:10%;">Code</th>
                    <th style="width:22%;">Name</th>
                    <th style="width:18%;">Contact</th>
                    <th style="width:20%;">Address</th>
                    <th style="width:15%;">Category</th>
                    <th style="width:10%;">Status</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
            <div class="prt-footer">
                Printed by <strong>${printedBy}</strong> on ${now}<br>
                <strong>Powered by BJP Technologies &copy; ${new Date().getFullYear()}</strong>
            </div>
            <script>window.onload=function(){ window.print(); window.onafterprint=function(){ window.close(); }; }<\/script>
        </body></html>`);
        win.document.close();
    });
}

function projScRemoveFromProject(supplierId, name) {
    Swal.fire({
        title: 'Remove from Project?',
        text: '"' + name + '" will be unlinked from this project but not deleted.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Remove'
    }).then(r => {
        if (r.isConfirmed) {
            $.post(APP_URL + '/api/assign_sc_to_project.php', {
                action: 'unassign', supplier_id: supplierId, project_id: PROJ_SC_ID
            }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Removed!', text: res.message, timer: 1500, showConfirmButton: false });
                    projScLoadTable();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

// ─────────────────────────────────────────────
// INSPECTIONS MODULE
// ─────────────────────────────────────────────
