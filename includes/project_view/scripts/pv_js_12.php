$(document).ready(function() {
    // Handle Warehouse Form Submit via AJAX
    $(document).on('submit', '#editProjectWarehouseForm', function(e) {
        e.preventDefault();
        const $btn = $('#btnUpdateWarehouseFromProject');
        const originalText = $btn.html();
        
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        
        const formData = new FormData(this);
        $.ajax({
            url: APP_URL + '/api/stock/update_warehouse.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#editProjectWarehouseModal').modal('hide');
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: res.message || 'Warehouse updated successfully',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        loadProjectDetails(); // Refresh project data
                    });
                } else {
                    Swal.fire('Error', res.message || 'Update failed', 'error');
                }
            },
            error: () => Swal.fire('Error', 'System error during update', 'error'),
            complete: () => $btn.prop('disabled', false).html(originalText)
        });
    });
});

// ─── Delivery Order (DO) Functions ────────────────────────────────────────────

// Renders warehouse <option>s from an already-fetched warehouses array.
function renderCDOWarehouseOptions(warehouses) {
    const $wSel = $('#cdo_warehouse_id');
    $wSel.prop('disabled', false).html('<option value="">-- Select Warehouse --</option>');
    if (warehouses && warehouses.length > 0) {
        warehouses.forEach(function(w) {
            $wSel.append(`<option value="${w.warehouse_id}">${w.warehouse_name}</option>`);
        });
    } else {
        $wSel.append('<option value="" disabled>No warehouses for this project</option>');
    }
}

// Populates the Create DO warehouse dropdown, fetching project inventory
// first if the Inventory tab hasn't been opened yet this page view.
function populateCDOWarehouses() {
    if (projectData && projectData.inventory && projectData.inventory.warehouses) {
        renderCDOWarehouseOptions(projectData.inventory.warehouses);
        return;
    }
    $('#cdo_warehouse_id').prop('disabled', true).html('<option value="">Loading warehouses...</option>');
    loadProjectInventory(false, function() {
        const warehouses = (projectData && projectData.inventory && projectData.inventory.warehouses) ? projectData.inventory.warehouses : [];
        renderCDOWarehouseOptions(warehouses);
    });
}

function openCreateDOModal() {
    $('#createDOForm')[0].reset();
    $('#cdoAttachmentRows').empty();
    $('#cdoItemsBody').empty();
    cdoItemIdx = 0;
    const today = new Date().toISOString().split('T')[0];
    $('#cdo_do_date').val(today);
    $('#cdo_expected_date').val('');
    const projName = (projectData && projectData.data) ? (projectData.data.project_name || '') : '';
    $('#cdo_project_display').val(projName);

    // Populate suppliers
    const $sSel = $('#cdo_supplier_id');
    $sSel.html('<option value="">-- Select Supplier --</option>');
    const suppliers = (projectData && projectData.project_suppliers) ? projectData.project_suppliers : [];
    if (suppliers.length > 0) {
        suppliers.forEach(function(s) {
            $sSel.append(`<option value="${s.supplier_id}">${s.supplier_name}${s.company_name ? ' — '+s.company_name : ''}</option>`);
        });
    } else {
        $sSel.append('<option value="" disabled>No suppliers linked to this project</option>');
    }

    // Populate warehouses (project-specific). The Inventory tab is lazy-loaded
    // and may not have been opened yet in this page view, so projectData.inventory
    // can still be empty here — fetch it on demand rather than assuming it's ready.
    populateCDOWarehouses();

    // Clear stock cache when warehouse changes so dropdown reloads
    $('#cdo_warehouse_id').off('change.dostock').on('change.dostock', function() {
        delete doWarehouseStock[$(this).val()];
        $('#cdoItemsBody tr').each(function() {
            const rowId = $(this).attr('id');
            if (!rowId) return;
            const pfx = 'cdo';
            const pidEl = document.getElementById(pfx + 'Pid_' + rowId);
            if (pidEl) pidEl.value = '';
            const pnEl  = document.getElementById(pfx + 'Pname_' + rowId);
            if (pnEl) pnEl.value = '';
            const avBEl = document.getElementById(pfx + 'AvailB_' + rowId);
            if (avBEl) { avBEl.textContent = '—'; avBEl.className = 'badge bg-secondary bg-opacity-10 text-secondary border small'; }
            const avVEl = document.getElementById(pfx + 'AvailV_' + rowId);
            if (avVEl) avVEl.value = '0';
            const uEl   = document.getElementById(pfx + 'Unit_' + rowId);
            if (uEl) uEl.textContent = 'pcs';
            const uVEl  = document.getElementById(pfx + 'UnitV_' + rowId);
            if (uVEl) uVEl.value = 'pcs';
        });
    });

    $('#createDOModal').modal('show');
}

let cdoAttIdx = 0;
function addCDOAttachmentRow() {
    cdoAttIdx++;
    $('#cdoAttachmentRows').append(`
        <div class="row g-2 align-items-center mb-2 cdo-att-row" id="cdoAtt${cdoAttIdx}">
            <div class="col-md-5">
                <input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="Attachment name / description">
            </div>
            <div class="col-md-6">
                <input type="file" class="form-control form-control-sm" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-sm btn-outline-danger px-2" onclick="$('#cdoAtt${cdoAttIdx}').remove()"><i class="bi bi-trash3"></i></button>
            </div>
        </div>`);
}

let cdoItemIdx = 0;
function addCDOItemRow(data) {
    cdoItemIdx++;
    const idx    = cdoItemIdx;
    const rowId  = 'cdoItem' + idx;
    const rowNum = $('#cdoItemsBody tr').length + 1;
    const pName  = data ? (data.product_name  || '') : '';
    const pId    = data ? (data.product_id    || '') : '';
    const avail  = data ? parseFloat(data.available_qty || 0) : 0;
    const qty    = data ? (data.qty_to_issue  || '') : '';
    const unit   = data ? (data.unit          || 'pcs') : 'pcs';
    $('#cdoItemsBody').append(`
        <tr id="${rowId}">
            <td class="text-center text-muted fw-bold small" style="width:45px;">${rowNum}</td>
            <td style="min-width:180px;">
                <input type="text" id="cdoPname_${rowId}" class="form-control form-control-sm cdo-product-name"
                    placeholder="Type or click to search..." value="${pName}" autocomplete="off"
                    oninput="showDOProductDropdown('${rowId}',this,'cdo_warehouse_id','cdo')"
                    onfocus="showDOProductDropdown('${rowId}',this,'cdo_warehouse_id','cdo')"
                    onblur="setTimeout(()=>closeDOProductDropdowns(),200)">
                <input type="hidden" id="cdoPid_${rowId}"    class="cdo-product-id" value="${pId}">
                <input type="hidden" id="cdoAvailV_${rowId}" class="cdo-avail"      value="${avail}">
                <input type="hidden" id="cdoUnitV_${rowId}"  class="cdo-unit"       value="${unit}">
            </td>
            <td style="width:130px;">
                <input type="number" id="cdoQty_${rowId}" class="form-control form-control-sm text-end cdo-qty" min="0.001" step="0.001" value="${qty}" placeholder="Qty">
            </td>
            <td style="width:80px;" class="text-center">
                <span id="cdoUnit_${rowId}" class="text-muted small fw-semibold">${unit}</span>
            </td>
            <td class="text-center" style="width:48px;">
                <button type="button" class="btn btn-danger btn-sm" style="width:30px;height:30px;padding:0;" onclick="$('#${rowId}').remove();renumberCDOItems()">
                    <i class="bi bi-trash" style="font-size:.75rem;"></i>
                </button>
            </td>
        </tr>`);
}

function renumberCDOItems() {
    $('#cdoItemsBody tr').each(function(i) { $(this).find('td:first').text(i + 1); });
}

function submitCreateDO() {
    const supplier_id  = $('#cdo_supplier_id').val();
    const warehouse_id = $('#cdo_warehouse_id').val();
    const do_date      = $('#cdo_do_date').val();
    if (!supplier_id)  { Swal.fire('Missing Field', 'Please select a Supplier.', 'warning'); return; }
    if (!warehouse_id) { Swal.fire('Missing Field', 'Please select a Warehouse.', 'warning'); return; }
    if (!do_date)      { Swal.fire('Missing Field', 'DO Date is required.', 'warning'); return; }

    const $btn = $('#btnSubmitCreateDO');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Creating...');

    const fd = new FormData($('#createDOForm')[0]);
    fd.append('supplier_id',    supplier_id);
    fd.append('warehouse_id',   warehouse_id);
    fd.append('project_id',     projectId);
    fd.append('do_date',        do_date);
    fd.append('expected_date',  $('#cdo_expected_date').val());
    fd.append('contact_person', $('#cdo_contact_person').val());
    fd.append('contact_phone',  $('#cdo_contact_phone').val());
    fd.append('notes',          $('#cdo_notes').val());

    const items = [];
    $('#cdoItemsBody tr').each(function() {
        const pn = $(this).find('.cdo-product-name').val().trim();
        if (!pn) return;
        items.push({
            product_name:  pn,
            product_id:    $(this).find('.cdo-product-id').val()  || '',
            available_qty: $(this).find('.cdo-avail').val()       || '0',
            qty_to_issue:  $(this).find('.cdo-qty').val()         || '1',
            unit:          $(this).find('.cdo-unit').val().trim()  || 'pcs'
        });
    });
    fd.append('items', JSON.stringify(items));

    $.ajax({
        url: APP_URL + '/api/operations/create_do_full.php',
        type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json'
    }).done(function(res) {
        if (res.success) {
            $('#createDOModal').modal('hide');
            Swal.fire({ icon:'success', title:'DO Created!', text:res.message, confirmButtonColor:'#0d6efd' })
                .then(function() { loadProjectDetails(); });
        } else { Swal.fire('Error', res.message, 'error'); }
    }).fail(function() {
        Swal.fire('Error', 'Server error. Please try again.', 'error');
    }).always(function() {
        $btn.prop('disabled', false).html('<i class="bi bi-send me-1"></i> Create Delivery Order');
    });
}

function openEditDO(doId) {
    const doData = currentDOsData.find(function(d) { return d.do_id == doId; });
    if (!doData) { Swal.fire('Error', 'DO data not found. Please refresh.', 'error'); return; }

    $('#edit_do_id').val(doId);
    $('#edit_do_date').val(doData.do_date || '');
    $('#edit_expected_date').val(doData.expected_date || '');
    $('#edit_contact_person').val(doData.contact_person || '');
    $('#edit_contact_phone').val(doData.contact_phone || '');
    $('#edit_do_notes').val(doData.notes || '');
    $('#editDONewAttachmentRows').empty();
    doAttRemoveList = [];
    editDOItemIdx = 0;

    // Project display
    const projName = (projectData && projectData.data) ? (projectData.data.project_name || '') : '';
    $('#edit_project_display').val(projName);

    // Populate suppliers
    const $sSel = $('#edit_supplier_id');
    $sSel.html('<option value="">-- Select Supplier --</option>');
    const suppliers = (projectData && projectData.project_suppliers) ? projectData.project_suppliers : [];
    if (suppliers.length > 0) {
        suppliers.forEach(function(s) {
            const sel = (s.supplier_id == doData.supplier_id) ? ' selected' : '';
            $sSel.append(`<option value="${s.supplier_id}"${sel}>${s.supplier_name}${s.company_name ? ' — '+s.company_name : ''}</option>`);
        });
    } else {
        $sSel.append('<option value="" disabled>No suppliers linked to this project</option>');
    }

    // Populate warehouses
    const $wSel = $('#edit_warehouse_id');
    $wSel.html('<option value="">-- Select Warehouse --</option>');
    const warehouses = (projectData && projectData.inventory && projectData.inventory.warehouses) ? projectData.inventory.warehouses : [];
    if (warehouses.length > 0) {
        warehouses.forEach(function(w) {
            const sel = (String(w.warehouse_id) === String(doData.warehouse_id)) ? ' selected' : '';
            $wSel.append(`<option value="${w.warehouse_id}"${sel}>${w.warehouse_name}</option>`);
        });
        // Fallback: if saved warehouse_id not in project list, add it
        if (doData.warehouse_id && !warehouses.find(function(w){ return String(w.warehouse_id) === String(doData.warehouse_id); })) {
            $wSel.prepend(`<option value="${doData.warehouse_id}" selected>${doData.warehouse_name || 'Selected Warehouse'}</option>`);
        }
    } else if (doData.warehouse_id) {
        $wSel.append(`<option value="${doData.warehouse_id}" selected>${doData.warehouse_name || 'Selected Warehouse'}</option>`);
    } else {
        $wSel.append('<option value="" disabled>No warehouses for this project</option>');
    }

    // Load existing items
    $.get(APP_URL + '/api/operations/get_do_items.php', { do_id: doId }, function(res) {
        $('#editDOItemsBody').empty();
        editDOItemIdx = 0;
        if (res.success && res.items && res.items.length > 0) {
            res.items.forEach(function(item) { addEditDOItemRow(item); });
        }
    }, 'json').fail(function() { $('#editDOItemsBody').empty(); });

    // Load existing attachments
    $.get(APP_URL + '/api/operations/get_do_attachments.php', { do_id: doId }, function(res) {
        const $cont = $('#editDOExistingAttachments');
        if (res.success && res.attachments.length > 0) {
            let html = '';
            res.attachments.forEach(function(a) {
                const safeName    = (a.attachment_name || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                const safeDisplay = (a.attachment_name || 'No file chosen').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                html += `<div class="row g-2 align-items-center mb-2 edit-existing-att" id="eatt${a.do_attachment_id}">
                    <div class="col-md-5">
                        <input type="text" class="form-control form-control-sm" name="existing_att_names[${a.do_attachment_id}]" value="${safeName}" placeholder="Attachment name">
                    </div>
                    <div class="col d-flex align-items-center gap-2">
                        <label class="btn btn-sm btn-outline-secondary px-2 mb-0" style="cursor:pointer;white-space:nowrap;">
                            Choose File
                            <input type="file" name="existing_att_files[${a.do_attachment_id}]" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="updateAttFilename(this,'eattFname_${a.do_attachment_id}')">
                        </label>
                        <span id="eattFname_${a.do_attachment_id}" class="small text-muted text-truncate" style="max-width:160px;">${safeDisplay}</span>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-outline-danger" style="width:30px;height:30px;padding:0;" onclick="markDOAttRemove(${a.do_attachment_id})" title="Remove"><i class="bi bi-trash3" style="font-size:.75rem;"></i></button>
                    </div>
                </div>`;
            });
            $cont.html(html);
        } else { $cont.html('<p class="text-muted small mb-0">No attachments.</p>'); }
    }, 'json');

    $('#editDOModal').modal('show');
}

let editDOAttIdx = 0;
function addEditDOAttachmentRow() {
    editDOAttIdx++;
    $('#editDONewAttachmentRows').append(`
        <div class="row g-2 align-items-center mb-2" id="editDONewAtt${editDOAttIdx}">
            <div class="col-md-5"><input type="text" class="form-control form-control-sm" name="new_attachment_names[]" placeholder="Attachment name"></div>
            <div class="col-md-6"><input type="file" class="form-control form-control-sm" name="new_attachments[]" accept=".pdf,.jpg,.jpeg,.png"></div>
            <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger px-2" onclick="$('#editDONewAtt${editDOAttIdx}').remove()"><i class="bi bi-trash3"></i></button></div>
        </div>`);
}

let editDOItemIdx = 0;
function addEditDOItemRow(data) {
    editDOItemIdx++;
    const idx    = editDOItemIdx;
    const rowId  = 'edoItem' + idx;
    const rowNum = $('#editDOItemsBody tr').length + 1;
    const pName  = data ? (data.product_name  || '') : '';
    const pId    = data ? (data.product_id    || '') : '';
    const avail  = data ? parseFloat(data.available_qty || 0) : 0;
    const qty    = data ? (data.qty_to_issue  || '') : '';
    const unit   = data ? (data.unit          || 'pcs') : 'pcs';
    const itemId = data ? (data.item_id       || '') : '';
    $('#editDOItemsBody').append(`
        <tr id="${rowId}">
            <td class="text-center text-muted fw-bold small" style="width:45px;">${rowNum}</td>
            <td style="min-width:180px;">
                <input type="text" id="edoPname_${rowId}" class="form-control form-control-sm edo-product-name"
                    placeholder="Type or click to search..." value="${pName}" autocomplete="off"
                    oninput="showDOProductDropdown('${rowId}',this,'edit_warehouse_id','edo')"
                    onfocus="showDOProductDropdown('${rowId}',this,'edit_warehouse_id','edo')"
                    onblur="setTimeout(()=>closeDOProductDropdowns(),200)">
                <input type="hidden" id="edoPid_${rowId}"    class="edo-product-id" value="${pId}">
                <input type="hidden" id="edoItemId_${rowId}" class="edo-item-id"    value="${itemId}">
                <input type="hidden" id="edoAvailV_${rowId}" class="edo-avail"      value="${avail}">
                <input type="hidden" id="edoUnitV_${rowId}"  class="edo-unit"       value="${unit}">
            </td>
            <td style="width:130px;">
                <input type="number" id="edoQty_${rowId}" class="form-control form-control-sm text-end edo-qty" min="0.001" step="0.001" value="${qty}" placeholder="Qty">
            </td>
            <td style="width:80px;" class="text-center">
                <span id="edoUnit_${rowId}" class="text-muted small fw-semibold">${unit}</span>
            </td>
            <td class="text-center" style="width:48px;">
                <button type="button" class="btn btn-danger btn-sm" style="width:30px;height:30px;padding:0;" onclick="$('#${rowId}').remove();renumberEditDOItems()">
                    <i class="bi bi-trash" style="font-size:.75rem;"></i>
                </button>
            </td>
        </tr>`);
}

function renumberEditDOItems() {
    $('#editDOItemsBody tr').each(function(i) { $(this).find('td:first').text(i + 1); });
}

// ─── DO Product Dropdown (DN-style body-appended) ────────────────────────────
let doWarehouseStock = {}; // cache: warehouse_id → products array

function closeDOProductDropdowns() {
    document.querySelectorAll('.do-product-dropdown').forEach(function(d) { d.remove(); });
}

function showDOProductDropdown(rowId, inputEl, warehouseSelectId, mode) {
    closeDOProductDropdowns();

    const warehouseId = document.getElementById(warehouseSelectId).value;
    if (!warehouseId) {
        const dd = document.createElement('div');
        dd.className = 'do-product-dropdown';
        const rect   = inputEl.getBoundingClientRect();
        const scrollY = window.pageYOffset || document.documentElement.scrollTop;
        dd.style.cssText = `position:absolute;top:${rect.bottom+scrollY+2}px;left:${rect.left}px;width:${Math.max(rect.width,260)}px;background:#fff8f0;border:1px solid #f0ad4e;border-radius:6px;padding:10px 14px;font-size:.85rem;color:#664d03;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.1);`;
        dd.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>Please select a warehouse first';
        document.body.appendChild(dd);
        setTimeout(closeDOProductDropdowns, 2500);
        return;
    }

    const q = inputEl.value.trim().toLowerCase();

    if (doWarehouseStock[warehouseId]) {
        _renderDODropdown(rowId, inputEl, warehouseId, q, mode);
    } else {
        // Show loading spinner dropdown
        const dd = document.createElement('div');
        dd.className = 'do-product-dropdown';
        const rect   = inputEl.getBoundingClientRect();
        const scrollY = window.pageYOffset || document.documentElement.scrollTop;
        dd.style.cssText = `position:absolute;top:${rect.bottom+scrollY+2}px;left:${rect.left}px;width:${Math.max(rect.width,280)}px;background:#fff;border:1px solid #ced4da;border-radius:6px;padding:10px 14px;font-size:.85rem;color:#666;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.1);`;
        dd.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading products...';
        document.body.appendChild(dd);
        $.getJSON(APP_URL + '/api/get_project_warehouse_stock', { warehouse_id: warehouseId, project_id: projectId }, function(res) {
            doWarehouseStock[warehouseId] = (res.success && res.data) ? res.data : [];
            closeDOProductDropdowns();
            _renderDODropdown(rowId, inputEl, warehouseId, q, mode);
        }).fail(function() {
            closeDOProductDropdowns();
        });
    }
}

function _renderDODropdown(rowId, inputEl, warehouseId, q, mode) {
    const stock = doWarehouseStock[warehouseId] || [];
    const filtered = stock.filter(function(p) {
        return !q || p.product_name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q);
    });

    const rect    = inputEl.getBoundingClientRect();
    const scrollY = window.pageYOffset || document.documentElement.scrollTop;
    const scrollX = window.pageXOffset || document.documentElement.scrollLeft;

    const dd = document.createElement('div');
    dd.className = 'do-product-dropdown';
    dd.style.cssText = `position:absolute;top:${rect.bottom+scrollY+2}px;left:${rect.left+scrollX}px;width:${Math.max(rect.width,300)}px;max-height:240px;overflow-y:auto;background:#fff;border:1px solid #ced4da;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:99999;`;

    if (filtered.length === 0) {
        dd.innerHTML = `<div style="padding:10px 14px;font-size:.85rem;color:#888;"><i class="bi bi-search me-2"></i>${stock.length === 0 ? 'No products in this warehouse.' : 'No matching products.'}</div>`;
        document.body.appendChild(dd);
        setTimeout(closeDOProductDropdowns, 2000);
        return;
    }

    filtered.forEach(function(p) {
        const avail    = parseFloat(p.available_quantity) || 0;
        const isTracked = p.track_inventory == 1 || p.track_inventory === true;
        const availTxt  = isTracked ? (avail + ' ' + p.unit) : 'Non-tracked';
        const availBg   = isTracked ? (avail > 0 ? '#d1e7dd' : '#f8d7da') : '#fff3cd';
        const availClr  = isTracked ? (avail > 0 ? '#0f5132' : '#842029') : '#664d03';

        const item = document.createElement('div');
        item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;';
        item.innerHTML = `<div class="d-flex justify-content-between align-items-center">
            <div>
                <div style="font-weight:600;font-size:.85rem;">${p.product_name}</div>
                <small style="color:#888;">${p.sku || ''}</small>
            </div>
            <span style="font-size:.75rem;padding:2px 8px;border-radius:20px;font-weight:600;background:${availBg};color:${availClr};">${availTxt}</span>
        </div>`;
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            _selectDOProduct(rowId, p, mode);
            closeDOProductDropdowns();
        });
        item.addEventListener('mouseover',  function() { this.style.background = '#f0f4ff'; });
        item.addEventListener('mouseout',   function() { this.style.background = '#fff'; });
        dd.appendChild(item);
    });

    document.body.appendChild(dd);
}

function _selectDOProduct(rowId, p, mode) {
    const avail = parseFloat(p.available_quantity) || 0;
    const unit  = p.unit || 'pcs';
    const cls   = avail > 0 ? 'success' : 'danger';
    const pfx   = mode; // 'cdo' or 'edo'

    const pnameEl  = document.getElementById(pfx + 'Pname_'  + rowId);
    const pidEl    = document.getElementById(pfx + 'Pid_'    + rowId);
    const availVEl = document.getElementById(pfx + 'AvailV_' + rowId);
    const availBEl = document.getElementById(pfx + 'AvailB_' + rowId);
    const unitEl   = document.getElementById(pfx + 'Unit_'   + rowId);
    const unitVEl  = document.getElementById(pfx + 'UnitV_'  + rowId);
    const qtyEl    = document.getElementById(pfx + 'Qty_'    + rowId);

    if (pnameEl)  pnameEl.value = p.product_name;
    if (pidEl)    pidEl.value   = p.product_id;
    if (availVEl) availVEl.value = avail;
    if (availBEl) { availBEl.textContent = avail + ' ' + unit; availBEl.className = `badge bg-${cls} bg-opacity-10 text-${cls} border small`; }
    if (unitEl)   unitEl.textContent = unit;
    if (unitVEl)  unitVEl.value = unit;
    if (qtyEl)    qtyEl.focus();
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.do-product-dropdown') && !e.target.closest('input[id^="cdoPname_"]') && !e.target.closest('input[id^="edoPname_"]')) {
        closeDOProductDropdowns();
    }
});

let doAttRemoveList = [];
function updateAttFilename(inputEl, spanId) {
    const span = document.getElementById(spanId);
    if (span) span.textContent = (inputEl.files && inputEl.files.length) ? inputEl.files[0].name : 'No file chosen';
}

function markDOAttRemove(attId) {
    doAttRemoveList.push(attId);
    $('#eatt' + attId).addClass('opacity-50');
    $('#eatt' + attId + ' input, #eatt' + attId + ' button, #eatt' + attId + ' label').prop('disabled', true);
}

function submitEditDO() {
    const supplier_id  = $('#edit_supplier_id').val();
    const warehouse_id = $('#edit_warehouse_id').val();
    const do_date      = $('#edit_do_date').val();
    if (!supplier_id)  { Swal.fire('Missing Field', 'Please select a Supplier.', 'warning'); return; }
    if (!warehouse_id) { Swal.fire('Missing Field', 'Please select a Warehouse.', 'warning'); return; }
    if (!do_date)      { Swal.fire('Missing Field', 'DO date is required.', 'warning'); return; }

    const $btn = $('#btnSubmitEditDO');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    const fd = new FormData($('#editDOForm')[0]);
    fd.append('do_id',                 $('#edit_do_id').val());
    fd.append('supplier_id',           supplier_id);
    fd.append('warehouse_id',          warehouse_id);
    fd.append('do_date',               do_date);
    fd.append('expected_date',         $('#edit_expected_date').val());
    fd.append('contact_person',        $('#edit_contact_person').val());
    fd.append('contact_phone',         $('#edit_contact_phone').val());
    fd.append('notes',                 $('#edit_do_notes').val());
    fd.append('remove_attachment_ids', JSON.stringify(doAttRemoveList));

    const items = [];
    $('#editDOItemsBody tr').each(function() {
        const pn = $(this).find('.edo-product-name').val().trim();
        if (!pn) return;
        items.push({
            item_id:       $(this).find('.edo-item-id').val()    || '',
            product_name:  pn,
            product_id:    $(this).find('.edo-product-id').val() || '',
            available_qty: $(this).find('.edo-avail').val()      || '0',
            qty_to_issue:  $(this).find('.edo-qty').val()        || '1',
            unit:          $(this).find('.edo-unit').val().trim() || 'pcs'
        });
    });
    fd.append('items', JSON.stringify(items));

    $.ajax({
        url: APP_URL + '/api/operations/edit_do.php',
        type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json'
    }).done(function(res) {
        if (res.success) {
            $('#editDOModal').modal('hide');
            doAttRemoveList = [];
            Swal.fire({ icon:'success', title:'Updated!', text:res.message, timer:1800, showConfirmButton:false })
                .then(function() { loadProjectDetails(); });
        } else { Swal.fire('Error', res.message, 'error'); }
    }).fail(function() {
        Swal.fire('Error', 'Server error. Please try again.', 'error');
    }).always(function() {
        $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
    });
}

function changeDOStatus(doId, newStatus) {
    const msgs = {
        pending:  { title:'Move to Pending?', text:'This DO will be moved to Pending status.', color:'#ffc107', btn:'Yes, Move' },
        approved: { title:'Approve DO?', text:'Once approved, no more status changes are allowed.', color:'#198754', btn:'Yes, Approve' }
    };
    const m = msgs[newStatus] || { title:'Update Status?', text:'', color:'#0d6efd', btn:'Yes' };
    Swal.fire({ title:m.title, text:m.text, icon:'question', showCancelButton:true, confirmButtonColor:m.color, confirmButtonText:m.btn })
    .then(function(r) {
        if (!r.isConfirmed) return;
        Swal.fire({ title:'Updating...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
        $.post(APP_URL + '/api/operations/change_do_status.php', { do_id: doId, status: newStatus }, function(res) {
            if (res.success) { Swal.fire({icon:'success', title:'Updated!', text:res.message, timer:1800, showConfirmButton:false}).then(()=>loadProjectDetails()); }
            else { Swal.fire({icon:'error', title:'Error', text:res.message}); }
        }, 'json');
    });
}

function deleteDO(doId, doNumber) {
    Swal.fire({
        title: 'Delete Delivery Order?',
        html: `This will permanently delete DO <strong>${doNumber}</strong>. This cannot be undone.`,
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(function(r) {
        if (r.isConfirmed) {
            Swal.fire({ title:'Deleting...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
            $.post(APP_URL + '/api/operations/delete_do.php', { do_id: doId }, function(res) {
                if (res.success) {
                    Swal.fire({ icon:'success', title:'Deleted!', text:res.message, timer:1800, showConfirmButton:false })
                        .then(function() { loadProjectDetails(); });
                } else { Swal.fire('Error', res.message, 'error'); }
            }, 'json');
        }
    });
}

// Activate tab from URL ?tab= parameter (used by return_url redirects)
(function() {
    const params = new URLSearchParams(window.location.search);
    const tabParam = params.get('tab');
    if (!tabParam) return;
    const map = {
        'procurement':     'purchases-tab',
        'proc-orders':     'purchases-tab',
        'proc-debit-notes': 'proc-debit-notes-tab',
        'rfq':             'proc-rfq-tab',
        'grn':             'proc-grn-tab',
        'inventory':       'inventory-tab',
        'do':              'proc-do-tab',
        'dn':              'proc-dn-tab',
        'budget':          'budget-tab',
        'vouchers':        'vouchers-tab',
        'expenses':        'expenses-tab',
        'nip-products':    'proc-nip-products-tab',
        'sub-contractors': 'proj-sc-tab',
        'suppliers':       'suppliers-tab',
        'staff':           'staff-tab',
    };
    const targetId = map[tabParam];
    if (!targetId) return;

    function closeAllDropdowns() {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(toggle) {
            const dd = bootstrap.Dropdown.getInstance(toggle);
            if (dd) dd.hide();
            toggle.classList.remove('show');
            toggle.setAttribute('aria-expanded', 'false');
            const parent = toggle.closest('.dropdown, .nav-item');
            if (parent) {
                const menu = parent.querySelector('.dropdown-menu');
                if (menu) menu.classList.remove('show');
            }
        });
    }

    const tryActivate = () => {
        const btn = document.getElementById(targetId);
        if (!btn) return;
        bootstrap.Tab.getOrCreateInstance(btn).show();
        // Bootstrap Tab's _toggleDropDown sets active+aria-expanded="true" on the
        // parent dropdown-toggle when the tab lives inside a dropdown-menu.
        // Reset all dropdowns immediately after so Procurements does not appear open.
        setTimeout(closeAllDropdowns, 0);
    };

    if (document.readyState === 'complete') {
        tryActivate();
    } else {
        window.addEventListener('load', tryActivate);
    }

    // Guard against bfcache: browser back button may restore the page with a
    // dropdown frozen open from the moment the user clicked away.
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) setTimeout(closeAllDropdowns, 0);
    });
})();

// Auto-open Add Materials modal when open_add=1 is in URL (from service_view context)
(function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('open_add') !== '1') return;
    const tryOpen = function() {
        var modalEl = document.getElementById('procAddNipMaterialsModal');
        if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };
    if (document.readyState === 'complete') {
        tryOpen();
    } else {
        window.addEventListener('load', tryOpen);
    }
})();
