var procMlEditRowIdx = 0;
window._procEditNips = [];

function procMlEditBuildRow(idx, nips, selectedId, qty) {
    var opts = '<option value="">— Select NIP Product —</option>';
    nips.forEach(function(n) {
        var sel = String(n.product_id) === String(selectedId) ? 'selected' : '';
        opts += '<option value="' + n.product_id + '" ' + sel + '>' + n.product_name.replace(/"/g,'&quot;') + '</option>';
    });
    return '<tr id="proc-ml-edit-row-' + idx + '">'
        + '<td class="text-center fw-bold text-muted proc-ml-edit-sno"></td>'
        + '<td class="ps-3"><select name="nips[' + idx + '][product_id]" class="form-select form-select-sm proc-ml-edit-nip-select">' + opts + '</select></td>'
        + '<td><input type="number" name="nips[' + idx + '][quantity]" class="form-control form-control-sm text-end fw-bold" min="0.001" step="any" value="' + (qty || 1) + '" required></td>'
        + '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="$(\'#proc-ml-edit-row-' + idx + '\').remove();procMlEditRenumber();"><i class="bi bi-trash"></i></button></td>'
        + '</tr>';
}

function procMlEditRenumber() {
    $('#procMlEditTbody tr').each(function(i) { $(this).find('.proc-ml-edit-sno').text(i + 1); });
}

function procMlEditAddRow() {
    var idx  = procMlEditRowIdx++;
    $('#procMlEditTbody').append(procMlEditBuildRow(idx, window._procEditNips, '', 1));
    procMlEditRenumber();
}

function procMlEditWarehouseChanged() {
    var whId = $('#procMlEditWarehouse').val();
    var nips = (window._procEditAllNips || []).filter(function(n) {
        if (!whId) return true;
        return String(n.warehouse_id) === String(whId) || String(n.project_id) === String(PROC_PROJECT_ID);
    });
    window._procEditNips = nips;
    $('#procMlEditTbody .proc-ml-edit-nip-select').each(function() {
        var cur  = $(this).val();
        var opts = '<option value="">— Select NIP Product —</option>';
        nips.forEach(function(n) {
            var sel = String(n.product_id) === String(cur) ? 'selected' : '';
            opts += '<option value="' + n.product_id + '" ' + sel + '>' + n.product_name.replace(/"/g,'&quot;') + '</option>';
        });
        $(this).html(opts);
    });
}

function procMlEditOpen(id, name) {
    procMlEditRowIdx = 0;
    window._procEditNips    = [];
    window._procEditAllNips = [];
    $('#procMlEditTitle').html('<i class="bi bi-pencil me-2"></i>Edit: ' + name);
    $('#procMlEditBody').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>');
    new bootstrap.Modal(document.getElementById('procMlEditModal')).show();

    $.when(
        $.getJSON(PROC_ML_BASE_URL + '/api/get_nip_materials_form_data.php?project_id=' + PROC_PROJECT_ID),
        $.getJSON(PROC_ML_BASE_URL + '/api/get_material_list_for_edit.php?id=' + id)
    ).done(function(fRes, lRes) {
        var fData = fRes[0];
        var lData = lRes[0];
        if (!lData.success) { $('#procMlEditBody').html('<div class="alert alert-danger">' + lData.message + '</div>'); return; }
        var l = lData.list;
        window._procEditAllNips = fData.nip_products || [];
        window._procEditNips    = fData.nip_products || [];

        var whOpts = '<option value="">— No Specific Warehouse —</option>';
        (fData.warehouses || []).forEach(function(w) {
            var sel = String(w.warehouse_id) === String(l.warehouse_id) ? 'selected' : '';
            whOpts += '<option value="' + w.warehouse_id + '" ' + sel + '>' + w.warehouse_name + '</option>';
        });

        var nipRows = '';
        procMlEditRowIdx = 0;
        if (l.nips && l.nips.length) {
            l.nips.forEach(function(n) {
                nipRows += procMlEditBuildRow(procMlEditRowIdx++, window._procEditNips, n.nip_product_id, n.quantity);
            });
        } else {
            nipRows = procMlEditBuildRow(procMlEditRowIdx++, window._procEditNips, '', 1);
        }

        $('#procMlEditBody').html(
            '<input type="hidden" name="id" value="' + l.id + '">'
            + '<input type="hidden" name="project_id" value="' + PROC_PROJECT_ID + '">'
            + '<div id="procMlEditMsg" class="mb-3"></div>'
            + '<div class="mb-3"><label class="form-label fw-bold small">Material List Name <span class="text-danger">*</span></label>'
            + '<textarea class="form-control" name="name" id="procMlEditName" rows="2" required style="resize:vertical;">' + l.name.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</textarea></div>'
            + '<div class="row g-3 mb-4">'
            + '<div class="col-md-6"><label class="form-label fw-bold small">Project</label>'
            + '<input type="text" class="form-control form-control-sm bg-light" readonly value="<?= htmlspecialchars($project_name ?? '') ?>"></div>'
            + '<div class="col-md-6"><label class="form-label fw-bold small">Warehouse</label>'
            + '<select name="warehouse_id" id="procMlEditWarehouse" class="form-select form-select-sm" onchange="procMlEditWarehouseChanged()">' + whOpts + '</select></div>'
            + '</div>'
            + '<h6 class="fw-bold small text-uppercase text-muted mb-2"><i class="bi bi-list-ul me-1"></i>Non-Inventory Products</h6>'
            + '<div class="table-responsive rounded-3 border"><table class="table table-hover align-middle mb-0" id="procMlEditTable">'
            + '<thead class="text-white text-center" style="background:#0d6efd;"><tr class="small">'
            + '<th style="width:55px;">S/NO</th><th class="text-start ps-3">Non-Inventory Product</th>'
            + '<th style="width:20%;">Quantity</th><th style="width:55px;"></th>'
            + '</tr></thead>'
            + '<tbody id="procMlEditTbody">' + nipRows + '</tbody>'
            + '<tfoot class="bg-light"><tr><td colspan="4" class="ps-3 py-3">'
            + '<button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 shadow-sm" onclick="procMlEditAddRow()">'
            + '<i class="bi bi-plus-circle me-1"></i> Add NIP</button></td></tr></tfoot>'
            + '</table></div>'
        );
        procMlEditRenumber();
        // Re-filter NIPs for the pre-selected warehouse
        if (l.warehouse_id) procMlEditWarehouseChanged();
    }).fail(function() {
        $('#procMlEditBody').html('<div class="alert alert-danger">Failed to load data. Please try again.</div>');
    });
}

$('#procMlEditForm').on('submit', function(e) {
    e.preventDefault();
    var name = $('#procMlEditName').val().trim();
    if (!name) { $('#procMlEditMsg').html('<div class="alert alert-danger py-2">Material List Name is required.</div>'); return; }
    var hasNip = false;
    $('#procMlEditTbody .proc-ml-edit-nip-select').each(function() { if ($(this).val()) hasNip = true; });
    if (!hasNip) { $('#procMlEditMsg').html('<div class="alert alert-danger py-2">Select at least one Non-Inventory Product.</div>'); return; }
    var $btn = $('#procMlEditSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    fetch(PROC_ML_BASE_URL + '/api/update_material_list.php', { method: 'POST', body: new FormData(this) })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('procMlEditModal')).hide();
                Swal.fire({ icon: 'success', title: 'Updated!', text: res.message, timer: 2000, showConfirmButton: false });
                setTimeout(function() { loadProcMaterials(); }, 2100);
            } else {
                $('#procMlEditMsg').html('<div class="alert alert-danger py-2">' + res.message + '</div>');
            }
        }).catch(function() {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
            $('#procMlEditMsg').html('<div class="alert alert-danger py-2">Server error. Try again.</div>');
        });
});

// ── Delete List ───────────────────────────────────────────────────────────
function procMlDeleteList(id, name) {
    Swal.fire({
        title: 'Delete Material List?',
        html: '<p>This will permanently delete <strong>' + name + '</strong> and all its NIP assignments.</p><p class="text-danger small fw-bold">This cannot be undone.</p>',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post(PROC_ML_BASE_URL + '/api/delete_material_list.php', { id: id }, function(res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, timer: 1800, showConfirmButton: false });
                setTimeout(function() { loadProcMaterials(); }, 1900);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Delete failed.' });
            }
        }, 'json').fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); });
    });
}

// ── Add Materials form ────────────────────────────────────────────────────
var procMlAddRowIdx = 0;

function procMlBuildOptions(selectedId) {
    var nips = window._procAddCurrentNips || PROC_PROJECT_NIPS;
    var html = '<option value="">— Select NIP Product —</option>';
    nips.forEach(function(n) {
        var sel  = String(n.product_id) === String(selectedId) ? 'selected' : '';
        var name = n.product_name.replace(/"/g, '&quot;');
        html += '<option value="' + n.product_id + '" ' + sel + '>' + name + '</option>';
    });
    return html;
}

function procMlAddWarehouseChanged() {
    var whId = $('#procMlAddWarehouse').val();
    var nips = (window._procAddAllNips || PROC_PROJECT_NIPS).filter(function(n) {
        if (!whId) return true;
        return String(n.warehouse_id) === String(whId) || String(n.project_id) === String(PROC_PROJECT_ID);
    });
    window._procAddCurrentNips = nips;
    $('#procMlAddTbody .proc-ml-nip-select').each(function() {
        var cur  = $(this).val();
        var opts = '<option value="">— Select NIP Product —</option>';
        nips.forEach(function(n) {
            var sel = String(n.product_id) === String(cur) ? 'selected' : '';
            opts += '<option value="' + n.product_id + '" ' + sel + '>' + n.product_name.replace(/"/g,'&quot;') + '</option>';
        });
        $(this).html(opts);
    });
}

function procMlAddRow() {
    var idx  = procMlAddRowIdx++;
    var html = '<tr id="proc-ml-add-row-' + idx + '">'
        + '<td class="text-center fw-bold text-muted proc-ml-add-sno"></td>'
        + '<td class="ps-3"><select name="nips[' + idx + '][product_id]" class="form-select form-select-sm proc-ml-nip-select">'
        + procMlBuildOptions('') + '</select></td>'
        + '<td><input type="number" name="nips[' + idx + '][quantity]" class="form-control form-control-sm text-end fw-bold" min="0.001" step="any" value="1" required placeholder="Qty"></td>'
        + '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"'
        + ' onclick="$(\'#proc-ml-add-row-' + idx + '\').remove(); procMlRenumber();"><i class="bi bi-trash"></i></button></td>'
        + '</tr>';
    $('#procMlAddTbody').append(html);
    procMlRenumber();
}

function procMlRenumber() {
    $('#procMlAddTbody tr').each(function(i) { $(this).find('.proc-ml-add-sno').text(i + 1); });
}

document.getElementById('procAddNipMaterialsModal').addEventListener('show.bs.modal', function() {
    procMlAddRowIdx = 0;
    window._procAddAllNips     = [];
    window._procAddCurrentNips = [];
    $('#procMlAddTbody').empty();
    $('#procMlAddMsg').html('');
    $('#procMlAddWarehouse').html('<option value="">— Loading… —</option>');
    document.getElementById('procAddNipMaterialsForm').reset();
    $.getJSON(PROC_ML_BASE_URL + '/api/get_nip_materials_form_data.php?project_id=' + PROC_PROJECT_ID, function(res) {
        if (res.success) {
            window._procAddAllNips     = res.nip_products || [];
            window._procAddCurrentNips = res.nip_products || [];
            var wOpts = '<option value="">— No Specific Warehouse —</option>';
            (res.warehouses || []).forEach(function(w) {
                wOpts += '<option value="' + w.warehouse_id + '">' + w.warehouse_name + '</option>';
            });
            $('#procMlAddWarehouse').html(wOpts);
        } else {
            window._procAddCurrentNips = PROC_PROJECT_NIPS;
            $('#procMlAddWarehouse').html('<option value="">— No Specific Warehouse —</option>');
        }
        procMlAddRow();
    }).fail(function() {
        window._procAddCurrentNips = PROC_PROJECT_NIPS;
        $('#procMlAddWarehouse').html('<option value="">— No Specific Warehouse —</option>');
        procMlAddRow();
    });
});

$('#procAddNipMaterialsForm').on('submit', function(e) {
    e.preventDefault();
    var name = $('#procMlAddName').val().trim();
    if (!name) { $('#procMlAddMsg').html('<div class="alert alert-danger py-2">Material List Name is required.</div>'); return; }
    var hasNip = false;
    $('#procMlAddTbody .proc-ml-nip-select').each(function() { if ($(this).val()) hasNip = true; });
    if (!hasNip) { $('#procMlAddMsg').html('<div class="alert alert-danger py-2">Select at least one Non-Inventory Product.</div>'); return; }
    var $btn = $('#procMlAddSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    fetch(PROC_ML_BASE_URL + '/api/create_material_list.php', { method: 'POST', body: new FormData(this) })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Materials');
            if (res.success) {
                $('#procAddNipMaterialsModal').modal('hide');
                Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false });
                setTimeout(function() { loadProcMaterials(); }, 2100);
            } else {
                $('#procMlAddMsg').html('<div class="alert alert-danger py-2">' + res.message + '</div>');
            }
        }).catch(function() {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Materials');
            $('#procMlAddMsg').html('<div class="alert alert-danger py-2">Server error. Try again.</div>');
        });
});

// ── PDF Export ────────────────────────────────────────────────────────────
async function procExportMatPDF() {
    const { jsPDF }  = window.jspdf;
    const doc        = new jsPDF('p', 'pt', 'a4');
    const pw         = doc.internal.pageSize.getWidth();
    const ph         = doc.internal.pageSize.getHeight();
    Swal.fire({ title: 'Generating PDF…', icon: 'info', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    let logoData = null;
    if (PROC_ML_CO_LOGO) {
        try {
            logoData = await new Promise(resolve => {
                const img = new Image(); img.crossOrigin = 'Anonymous';
                img.onload  = () => resolve({ data: img, w: img.naturalWidth, h: img.naturalHeight });
                img.onerror = () => resolve(null);
                img.src = PROC_ML_CO_LOGO;
            });
        } catch(e) { logoData = null; }
    }
    let cy = 40;
    if (logoData) { const hs = 50/logoData.h, dw = logoData.w*hs; doc.addImage(logoData.data,'JPEG',(pw-dw)/2,cy,dw,50); cy+=70; }
    doc.setFontSize(22); doc.setTextColor(13,110,253); doc.setFont('helvetica','bold');
    doc.text(PROC_ML_CO_NAME.toUpperCase(), pw/2, cy, {align:'center'}); cy+=30;
    doc.setFontSize(16); doc.setTextColor(0,0,0);
    doc.text('MATERIALS LIST', pw/2, cy, {align:'center'}); cy+=15;
    doc.setDrawColor(13,110,253); doc.setLineWidth(2);
    doc.line(pw/2-100,cy,pw/2+100,cy); cy+=35;
    const head = [['S/NO','MATERIALS LIST NAME','LIST NO','WAREHOUSE']];
    const body = [];
    $('#procMatTableBody tr').each(function() {
        var $tr   = $(this);
        var sno   = $tr.find('.proc-mat-row-no').text().trim();
        var name  = $tr.find('td:eq(1) .fw-bold').first().text().trim();
        var listNo = $tr.find('td:eq(2) .badge').text().trim();
        var wh    = $tr.find('td:eq(3)').text().trim();
        if (name) body.push([sno, name, listNo, wh]);
    });
    var exportTime = new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
    doc.autoTable({
        head, body, startY: cy, theme: 'striped',
        headStyles: { fillColor:[13,110,253], textColor:255, halign:'center', fontSize:10, fontStyle:'bold', cellPadding:8 },
        styles: { fontSize:9, halign:'center', valign:'middle', cellPadding:5 },
        columnStyles: { 0:{cellWidth:40}, 1:{halign:'left',cellWidth:'auto'}, 2:{halign:'center',cellWidth:100}, 3:{halign:'center',cellWidth:100} },
        margin: { left:40, right:40, bottom:60 },
        didDrawPage: (data) => {
            doc.setFontSize(8); doc.setTextColor(150); doc.setFont('helvetica','normal');
            doc.text('This document was printed by ' + PROC_ML_USER + ' - ' + PROC_ML_ROLE + ' on ' + exportTime, pw/2, ph-35, {align:'center'});
            doc.setTextColor(13,110,253); doc.setFont('helvetica','bold');
            doc.text('Powered by BJP Technologies © <?= date('Y') ?>', pw/2, ph-20, {align:'center'});
            doc.setFont('helvetica','normal'); doc.setTextColor(150);
            doc.text('Page ' + data.pageNumber, pw-50, ph-20);
        }
    });
    doc.save('MaterialsLists_' + PROC_PROJECT_ID + '_' + new Date().toISOString().slice(0,10) + '.pdf');
    Swal.close();
}

// Load materials when tab is clicked
$(document).on('click', '#proc-materials-tab', function() {
    loadProcMaterials();
});

// ══════════════════════════════════════════════════════════════════════════
// VIEW NIP DETAILS (Project-scoped)
// ══════════════════════════════════════════════════════════════════════════
function viewProcNipDetails(productId) {
    $('#viewProcNipBody').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>');
    $('#viewProcNipFooter').html('<button class="btn btn-light border" data-bs-dismiss="modal">Close</button>');
    new bootstrap.Modal(document.getElementById('viewProcNipModal')).show();

    $.getJSON('<?= getUrl('api/get_nip_components') ?>?id=' + productId, function(res) {
        const p    = res.parent_product || {};
        const comps = res.components || res.data || [];

        const rawStatus = p.status || 'active';
        const statusLabels = {active:'Active',approved:'Approved',pending:'Pending',draft:'Draft',inactive:'Inactive'};
        const statusLabel  = statusLabels[rawStatus] || rawStatus;
        const statusCls    = ['active','approved'].includes(rawStatus) ? 'status-badge-active' : (rawStatus==='pending' ? 'status-badge-pending' : 'status-badge-draft');

        let compRows = '';
        if (comps.length === 0) {
            compRows = `<tr><td colspan="5" class="text-center py-4 text-muted">No material components linked yet.</td></tr>`;
        } else {
            comps.forEach((c, i) => {
                compRows += `<tr>
                    <td class="text-center text-muted">${i+1}</td>
                    <td>${c.component_name || c.product_name || '—'}</td>
                    <td class="text-center">${c.unit || ''}</td>
                    <td class="text-end">${parseFloat(c.qty_per_unit||0).toLocaleString()}</td>
                    <td class="text-end">${parseFloat(c.total_qty||0).toLocaleString()}</td>
                </tr>`;
            });
        }

        $('#viewProcNipBody').html(`
            <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="nip-product-avatar" style="width:48px;height:48px;font-size:1.3rem;"><i class="bi bi-gear text-primary"></i></div>
                    <div>
                        <h4 class="fw-bold mb-1 text-dark">${p.product_name || '—'}</h4>
                        <span class="badge ${statusCls}">${statusLabel}</span>
                        ${p.sku ? `<small class="text-muted ms-2">${p.sku}</small>` : ''}
                    </div>
                </div>
                <button class="btn btn-sm btn-light border d-print-none" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-3 text-center border rounded p-3">
                    <div class="text-muted small">Project</div><div class="fw-bold">${p.project_name || 'General'}</div>
                </div>
                <div class="col-md-3 text-center border rounded p-3">
                    <div class="text-muted small">Warehouse</div><div class="fw-bold">${p.warehouse_name || '—'}</div>
                </div>
                <div class="col-md-3 text-center border rounded p-3">
                    <div class="text-muted small">Selling Price</div><div class="fw-bold text-primary">TZS ${parseFloat(p.selling_price||0).toLocaleString()}</div>
                </div>
                <div class="col-md-3 text-center border rounded p-3">
                    <div class="text-muted small">Tax</div><div class="fw-bold">${p.tax_name ? p.tax_name + ' (' + p.tax_rate + '%)' : 'No Tax'}</div>
                </div>
            </div>
            <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="bi bi-list-check me-2 text-primary"></i>Material Components (${comps.length})</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="bg-white small"><tr>
                        <th style="width:5%">#</th><th>Component</th>
                        <th style="width:10%">Unit</th>
                        <th style="width:14%" class="text-end">Qty / Unit</th>
                        <th style="width:14%" class="text-end">Total Qty</th>
                    </tr></thead>
                    <tbody>${compRows}</tbody>
                </table>
            </div>
        `);

        $('#viewProcNipFooter').html(`
            <button class="btn btn-light border" data-bs-dismiss="modal">Close</button>
            <button class="btn btn-primary" onclick="$('#viewProcNipModal').modal('hide'); setTimeout(()=>editProcNipProduct(${productId}), 400);">
                <i class="bi bi-pencil me-1"></i> Edit
            </button>
        `);
    });
}

function changeProcNipStatus(productId, current) {
    const options = {active:'Active',approved:'Approved',pending:'Pending',draft:'Draft',inactive:'Inactive'};
    let selHtml = '';
    Object.entries(options).forEach(([v,l]) => {
        selHtml += `<option value="${v}" ${v===current?'selected':''}>${l}</option>`;
    });
    Swal.fire({
        title: 'Change Status',
        html: `<select id="swal-proc-nip-status" class="form-select mt-2">${selHtml}</select>`,
        showCancelButton: true,
        confirmButtonText: 'Update',
        confirmButtonColor: '#0d6efd',
        preConfirm: () => document.getElementById('swal-proc-nip-status').value
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= getUrl('api/update_nip_status') ?>', { product_id: productId, status: r.value }, function(res) {
            if (res.success) {
                Swal.fire({icon:'success', title:'Updated', text:res.message, timer:1500, showConfirmButton:false});
                setTimeout(() => loadProcMaterials(), 1600);
            } else {
                Swal.fire({icon:'error', title:'Error', text:res.message});
            }
        }, 'json');
    });
}

// ══════════════════════════════════════════════════════════════════════════
// EDIT NIP PRODUCT (Project-scoped)
// ══════════════════════════════════════════════════════════════════════════
let editProcNipRowIdx = 0;

function editProcNipProduct(productId) {
    editProcNipRowIdx = 0;
    $('#editProcNipMsg').html('');
    $('#editProcNipCompBody').empty();
    $('#editProcNipTax').html('<option value="">No Tax</option>');
    $('#editProcNipWarehouse').html('<option value="">— Select Warehouse —</option>');
    new bootstrap.Modal(document.getElementById('editProcNipModal')).show();

    const projectId = <?= intval($project_id) ?>;

    // Load tax rates and warehouses from form data API
    $.getJSON('<?= getUrl('api/get_nip_materials_form_data') ?>?project_id=' + projectId, function(fData) {
        if (fData.success) {
            (fData.warehouses || []).forEach(w => {
                $('#editProcNipWarehouse').append(new Option(w.warehouse_name, w.warehouse_id));
            });
        }
        // Load tax rates inline
        <?php foreach ($tax_rates as $t): ?>
        $('#editProcNipTax').append($('<option>', {value:'<?= $t['rate_id'] ?>', 'data-rate':'<?= $t['rate_percentage'] ?>', text:'<?= htmlspecialchars($t['rate_name']) ?> (<?= $t['rate_percentage'] ?>%)'}));
        <?php endforeach; ?>
    });

    // Fetch product details + components
    $.getJSON('<?= getUrl('api/get_nip_components') ?>?id=' + productId, function(res) {
        const p    = res.parent_product || {};
        const comps = res.components || res.data || [];

        $('#editProcNipId').val(p.product_id || productId);
        $('#editProcNipName').val(p.product_name || '');
        $('#editProcNipSku').val(p.sku || '');
        $('#editProcNipContractNo').val(p.contract_item_no || '');
        $('#editProcNipStatus').val(p.status || 'active');
        $('#editProcNipSell').val(p.selling_price || '0.00');
        $('#editProcNipCost').val(p.cost_price || '0.00');
        $('#editProcNipProjectDisplay').val(p.project_name || 'General');
        $('#editProcNipProjectId').val(p.project_id || '');

        // Set tax after options are rendered
        setTimeout(() => {
            $('#editProcNipTax').val(p.tax_id || '');
            $('#editProcNipWarehouse').val(p.warehouse_id || '');
        }, 300);

        if (comps.length > 0) {
            comps.forEach(c => editProcNipAddRow(c));
        } else {
            editProcNipAddRow();
        }
    });
}

function editProcNipAddRow(data) {
    const idx  = editProcNipRowIdx++;
    const u    = (data && data.unit) ? data.unit : 'EA';
    const q    = (data && data.qty_per_unit != null) ? data.qty_per_unit : 1;
    const name = (data && (data.component_name || data.product_name)) ? (data.component_name || data.product_name) : '';
    const pid  = (data && (data.component_product_id || data.product_id)) ? (data.component_product_id || data.product_id) : '';
    const cost = (data && data.component_cost) ? data.component_cost : 0;

    const html = `<tr id="edit-proc-nip-comp-${idx}">
        <td class="text-center text-muted edit-proc-nip-sno"></td>
        <td>
            <div class="position-relative">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0"
                        placeholder="Search product…"
                        onkeyup="editProcNipSearch(this,${idx})" onclick="editProcNipSearch(this,${idx})"
                        value="${name}" autocomplete="off">
                    <input type="hidden" name="components[${idx}][product_id]" value="${pid}" id="edit-proc-nip-pid-${idx}">
                    <input type="hidden" id="edit-proc-nip-cost-${idx}" value="${cost}">
                </div>
                <div id="edit-proc-nip-res-${idx}" class="position-absolute bg-white shadow rounded border d-none" style="z-index:1070;width:380px;max-height:220px;overflow-y:auto;top:100%;left:0;"></div>
            </div>
        </td>
        <td><input type="text" name="components[${idx}][unit]" class="form-control form-control-sm text-center" value="${u}" id="edit-proc-nip-unit-${idx}"></td>
        <td><input type="number" name="components[${idx}][qty_per_unit]" class="form-control form-control-sm text-end" value="${q}"
            min="0.001" step="any" id="edit-proc-nip-qty-${idx}" oninput="editProcNipRecalcCost()"></td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                onclick="$('#edit-proc-nip-comp-${idx}').remove(); editProcNipRenumber(); editProcNipRecalcCost();">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>`;
    $('#editProcNipCompBody').append(html);
    editProcNipRenumber();
    if (data) editProcNipRecalcCost();
}

function editProcNipRenumber() {
    $('#editProcNipCompBody tr').each(function(i) { $(this).find('.edit-proc-nip-sno').text(i + 1); });
}

function editProcNipRecalcCost() {
    let subtotal = 0;
    $('#editProcNipCompBody tr').each(function() {
        const idx  = $(this).attr('id').replace('edit-proc-nip-comp-','');
        const qty  = parseFloat($(`#edit-proc-nip-qty-${idx}`).val()) || 0;
        const cost = parseFloat($(`#edit-proc-nip-cost-${idx}`).val()) || 0;
        subtotal += qty * cost;
    });
    const taxRate = parseFloat($('#editProcNipTax option:selected').data('rate')) || 0;
    const total = subtotal * (1 + taxRate / 100);
    $('#editProcNipCost').val(total.toFixed(2));
}

function editProcNipSearch(input, idx) {
    const whId = $('#editProcNipWarehouse').val();
    const $res = $(`#edit-proc-nip-res-${idx}`);
    if (!whId) {
        $res.html('<div class="p-2 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>Select a warehouse first</div>').removeClass('d-none');
        return;
    }
    $res.html('<div class="p-3 text-muted small"><div class="spinner-border spinner-border-sm me-2 text-primary"></div>Searching…</div>').removeClass('d-none');
    fetch(`${APP_URL}/api/account/get_products.php?search=${encodeURIComponent(input.value)}&warehouse_id=${whId}&is_service=0&active_only=1&limit=10`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                const rows = data.data.map(p => {
                    const cost = parseFloat(p.cost_price) || 0;
                    const safe = JSON.stringify(p).replace(/'/g,"&#39;");
                    return `<button type="button" class="list-group-item list-group-item-action p-2 border-bottom"
                        onclick='editProcNipSelectMat(${idx},${safe})'>
                        <div class="d-flex justify-content-between align-items-start">
                            <div><div class="fw-bold small">${p.product_name}</div>
                            <div class="text-muted" style="font-size:11px;">${p.sku||''}</div></div>
                            <span class="fw-bold text-primary small">TZS ${cost.toLocaleString()}</span>
                        </div></button>`;
                }).join('');
                $res.html(`<div class="list-group list-group-flush">${rows}</div>`).removeClass('d-none');
            } else {
                $res.html('<div class="p-3 text-muted small">No inventory products found</div>').removeClass('d-none');
            }
        });
}

function editProcNipSelectMat(idx, prod) {
    $(`#edit-proc-nip-res-${idx}`).addClass('d-none');
    $(`#edit-proc-nip-pid-${idx}`).val(prod.product_id);
    $(`#edit-proc-nip-cost-${idx}`).val(parseFloat(prod.cost_price) || 0);
    $(`#edit-proc-nip-unit-${idx}`).val(prod.unit || 'EA');
    $(`#edit-proc-nip-comp-${idx} input[type="text"]`).val(prod.product_name);
    editProcNipRecalcCost();
}

$(document).on('click', function(e) {
    if (!$(e.target).closest('#editProcNipCompBody').length) $('[id^="edit-proc-nip-res-"]').addClass('d-none');
});

$('#editProcNipWarehouse').on('change', function() {
    const whId = $(this).val();
    if (!whId) return;
    $('#editProcNipCompBody tr').each(function() {
        const idx = $(this).attr('id').replace('edit-proc-nip-comp-','');
        const pid = $(`#edit-proc-nip-pid-${idx}`).val();
        if (!pid) return;
        fetch(`${APP_URL}/api/account/get_products.php?warehouse_id=${whId}&product_id=${pid}&is_service=0`)
            .then(r => r.json())
            .then(json => {
                if (json.success && json.data && json.data.length > 0) {
                    $(`#edit-proc-nip-cost-${idx}`).val(parseFloat(json.data[0].cost_price) || 0);
                    editProcNipRecalcCost();
                }
            });
    });
});

$('#editProcNipForm').on('submit', function(e) {
    e.preventDefault();
    const $btn = $('#editProcNipSaveBtn');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');

    const fd = new FormData(this);

    $.ajax({
        url: '<?= getUrl('api/update_nip_product') ?>',
        type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>Save Changes');
            if (res.success) {
                $('#editProcNipModal').modal('hide');
                Swal.fire({icon:'success', title:'Saved!', text:res.message||'Product updated.', timer:1800, showConfirmButton:false});
                setTimeout(() => loadProcMaterials(), 1900);
            } else {
                $('#editProcNipMsg').html(`<div class="alert alert-danger">${res.message||'Update failed.'}</div>`);
            }
        },
        error: function() {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>Save Changes');
            Swal.fire({icon:'error', title:'Error', text:'Server error.'});
        }
    });
});

// Order Actions
function changeOrderStatus(id, current) {
    const statuses = { 'draft': 'Draft', 'pending': 'Pending', 'confirmed': 'Confirmed', 'approved': 'Approved', 'processing': 'Processing', 'shipped': 'Shipped', 'delivered': 'Delivered', 'cancelled': 'Cancelled' };
    let options = '';
    for (let k in statuses) options += `<option value="${k}" ${k === current ? 'selected' : ''}>${statuses[k]}</option>`;

    Swal.fire({
        title: 'Change Order Status',
        html: `<select id="swal-so-status" class="form-select mt-3">${options}</select>`,
        showCancelButton: true,
        confirmButtonText: 'Update Status',
        confirmButtonColor: '#3085d6',
        preConfirm: () => document.getElementById('swal-so-status').value
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/update_sales_order_status.php', { order_id: id, status: result.value }, res => {
                if (res.success) showActionSuccess(res.message);
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

function updateOrderStatus(id, newStatus) {
    $.post('/api/account/update_sales_order_status.php', { order_id: id, status: newStatus }, res => {
        if (res.success) showActionSuccess(res.message);
        else Swal.fire('Error', res.message, 'error');
    }, 'json');
}

function deleteOrder(id) {
    Swal.fire({
        title: 'Delete Order?',
        text: "This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/delete_sales_order.php', { order_id: id }, res => {
                if (res.success) showActionSuccess(res.message);
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

// Expense Actions
function editExpenseInline(encodedData) {
    const e = JSON.parse(decodeURIComponent(encodedData));
    const modal = $('#expenseActionModal');
    const form  = $('#expenseActionForm');

    form.find('[name="expense_id"]').val(e.expense_id);
    form.find('[name="expense_date"]').val(e.expense_date);
    form.find('[name="amount"]').val(e.amount);
    form.find('[name="description"]').val(e.description);
    form.find('[name="notes"]').val(e.notes || '');
    $('#edit_ex_bank_account_id').val(e.bank_account_id || '').trigger('change');
    $('#edit_expense_type').val(e.type_id || '').trigger('change');

    // Preselect saved category in cascade (uses e.categories array from DB mapping)
    if (e.categories && e.categories.length) {
        setTimeout(() => preSelectCascade(e.categories[0].category_id, true), 250);
    }

    // Paid To (unified)
    const paidToType = e.paid_to_type || '';
    $('#edit_ex_paid_to_type').val(paidToType).trigger('change');
    if (paidToType && e.paid_to_id) {
        setTimeout(() => {
            $('#edit_paid_to_id_select').val(e.paid_to_id).trigger('change');
        }, 150);
    }

    modal.modal('show');
}

// Add Expense modal — unified paid-to dropdown (supplier/staff/sub_contractor)
const projSubContractorsData = <?= json_encode(array_map(fn($s) => ['id' => $s['supplier_id'], 'name' => $s['supplier_name']], $sub_contractors)) ?>;
const projSuppliersData      = <?= json_encode(array_map(fn($s) => ['id' => $s['supplier_id'], 'name' => $s['supplier_name']], $all_suppliers)) ?>;
const projStaffData          = <?= json_encode(array_map(fn($e) => ['id' => $e['employee_id'], 'name' => trim($e['first_name'] . ' ' . $e['last_name'])], $all_employees)) ?>;

$('#ex_paid_to_type').on('change', function() {
    const type     = $(this).val();
    const $block   = $('#proj_paid_to_id_block');
    const $select  = $('#proj_paid_to_id_select');
    const labelMap = { supplier: 'Supplier', staff: 'Staff Member', sub_contractor: 'Sub Contractor' };
    const dataMap  = { supplier: projSuppliersData, staff: projStaffData, sub_contractor: projSubContractorsData };

    if ($select.data('select2')) $select.select2('destroy');
    $select.empty().append('<option value="">Select...</option>');

    if (type && dataMap[type]) {
        dataMap[type].forEach(d => $select.append(`<option value="${d.id}">${d.name}</option>`));
        $('#proj_paid_to_id_label').text(labelMap[type] || 'Payee');
        $block.removeClass('d-none');
        $select.select2({ theme: 'bootstrap-5', dropdownParent: $('#addExpenseModal'), placeholder: 'Select...', allowClear: true, width: '100%' });
    } else {
        $block.addClass('d-none');
    }
});

// Edit Expense modal — unified paid-to dropdown
$('#edit_ex_paid_to_type').on('change', function() {
    const type     = $(this).val();
    const $block   = $('#edit_paid_to_id_block');
    const $select  = $('#edit_paid_to_id_select');
    const labelMap = { supplier: 'Supplier', staff: 'Staff Member', sub_contractor: 'Sub Contractor' };
    const dataMap  = { supplier: projSuppliersData, staff: projStaffData, sub_contractor: projSubContractorsData };

    if ($select.data('select2')) $select.select2('destroy');
    $select.empty().append('<option value="">Select...</option>');

    if (type && dataMap[type]) {
        dataMap[type].forEach(d => $select.append(`<option value="${d.id}">${d.name}</option>`));
        $('#edit_paid_to_id_label').text(labelMap[type] || 'Payee');
        $block.removeClass('d-none');
        $select.select2({ theme: 'bootstrap-5', dropdownParent: $('#expenseActionModal'), placeholder: 'Select...', allowClear: true, width: '100%' });
    } else {
        $block.addClass('d-none');
    }
});

$('#expenseActionForm').on('submit', function(e) {
    e.preventDefault();
    const $btn = $(this).find('button[type="submit"]');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Updating...');



    $.post('<?= buildUrl('api/account/update_expense.php') ?>', $(this).serialize(), res => {
        if (res.success) {
            $('#expenseActionModal').modal('hide');
            showActionSuccess(res.message);
            loadProjectDetails();
        } else {
            Swal.fire('Error', res.message || 'Failed to update expense', 'error');
        }
    }, 'json').fail(function(xhr) {
        Swal.fire('Error', expenseErrorMsg(xhr), 'error');
    }).always(() => {
        $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i> Update Expense');
    });
});

function deleteExpenseInline(id) {
    Swal.fire({
        title: 'Delete Expense?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/delete_expense.php', { expense_id: id }, res => {
                if (res.success) showActionSuccess(res.message);
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

// Purchase Actions
function changePurchaseStatus(id, current) {
    const statuses = { 'draft': 'Draft', 'ordered': 'Ordered', 'received': 'Received', 'partial': 'Partial', 'cancelled': 'Cancelled' };
    let options = '';
    for (let k in statuses) options += `<option value="${k}" ${k === current ? 'selected' : ''}>${statuses[k]}</option>`;

    Swal.fire({
        title: 'Change Purchase Status',
        html: `<select id="swal-po-status" class="form-select mt-3">${options}</select>`,
        showCancelButton: true,
        confirmButtonText: 'Update Status',
        confirmButtonColor: '#28a745',
        preConfirm: () => document.getElementById('swal-po-status').value
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/update_purchase_order_status.php', { purchase_order_id: id, status: result.value }, res => {
                if (res.success) showActionSuccess(res.message);
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

function deletePurchase(id) {
    Swal.fire({
        title: 'Delete Purchase Order?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/delete_purchase_order.php', { order_id: id }, res => {
                if (res.success) showActionSuccess(res.message);
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

// Voucher Actions
function printVoucher(id) {
    window.open('<?= getUrl('payment_voucher_print') ?>?id=' + id, '_blank').focus();
}

// Mirrors payment_vouchers.php's viewVoucherDetails() — same modal content/fields,
// same Payment History fetch, so a project-linked voucher looks identical to one
// viewed from the standalone Payment Vouchers page. `v` already carries every column
// this needs (projectData.payment_vouchers is `pv.*` + category_name); project_name
// isn't on the row since it's implicit here, so it's filled from the current project.
function viewVoucherDetails(encodedData) {
    const v = JSON.parse(decodeURIComponent(encodedData));

    const dateStr = v.vouch_date ? formatDate(v.vouch_date) : 'N/A';
    $('#pv_detail_voucher_no').text(v.voucher_number || ('#' + v.id));
    $('#pv_detail_date').text(dateStr);
    $('#pv_detail_status_badge').html(`<span class="badge bg-${getStatusBadgeColor(v.status)} text-uppercase px-3">${safeOutput(v.status)}</span>`);
    $('#pv_detail_method_badge').html(`<span class="badge bg-light text-dark border text-uppercase px-3">${safeOutput((v.payment_method || '').replace(/_/g, ' '))}</span>`);
    $('#pv_detail_payee').text(v.payee_name || 'N/A');
    $('#pv_detail_category').text(v.expense_account_name || v.category_name || 'Uncategorized');
    $('#pv_detail_amount').text(formatMoney(v.amount) + ' TZS');
    $('#pv_detail_words').text(v.amount_in_words ? 'In Words: ' + v.amount_in_words : '');
    $('#pv_detail_reference').text(v.reference_number || 'None');
    $('#pv_detail_description').text(v.description || 'No description provided');
    $('#pv_detail_user').text(v.prepared_by_name || v.username || 'System Admin');
    $('#pv_detail_project').text((projectData && projectData.data && projectData.data.project_name) || 'N/A');
    $('#pv_detail_print_btn').off('click').on('click', () => printVoucher(v.id));

    $('#pv_detail_payments_section').hide();
    if (['approved', 'partially_paid', 'paid'].includes(v.status)) {
        $.getJSON('<?= buildUrl('api/account/get_voucher_payments.php') ?>', { voucher_id: v.id }, function(res) {
            if (res.success && res.payments.length) {
                let rows = res.payments.map((p, i) => `
                    <div class="d-flex justify-content-between align-items-start py-2 ${i > 0 ? 'border-top' : ''}">
                        <div>
                            <div class="fw-bold small">${safeOutput(p.payment_date)}</div>
                            <div class="text-muted" style="font-size:.75rem;">${safeOutput((p.payment_method || '').replace(/_/g, ' '))} ${p.reference_number ? '· ' + safeOutput(p.reference_number) : ''}</div>
                            <div class="text-muted" style="font-size:.75rem;">${safeOutput(p.bank_code ? p.bank_code + ' — ' : '')}${safeOutput(p.bank_name || '—')}</div>
                        </div>
                        <strong class="text-primary">${formatMoney(p.amount)} TZS</strong>
                    </div>`).join('');
                rows += `<div class="d-flex justify-content-between border-top pt-2 mt-1">
                            <strong class="small text-muted">Total Paid</strong>
                            <strong class="text-success">${formatMoney(res.total_paid)} TZS</strong>
                         </div>`;
                $('#pv_detail_payments_list').html(rows);
                $('#pv_detail_payments_section').show();
            }
        });
    }

    new bootstrap.Modal(document.getElementById('pvDetailsModal')).show();
}

// Mirrors payment_vouchers.php's pvChangeStatus()/submitVoucherStatus() — only ever
// called with a transition the state machine in update_voucher_status.php actually
// allows (pvActions() above only renders the buttons valid for the voucher's current
// status), so this never sends an invalid request the backend has to reject.
function pvChangeStatus(id, newStatus, title, text) {
    Swal.fire({
        title: title,
        text: text,
        icon: newStatus === 'cancelled' ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: newStatus === 'cancelled' ? '#dc3545' : '#0d6efd',
        confirmButtonText: newStatus === 'cancelled' ? 'Yes, Cancel' : 'Confirm',
        cancelButtonText: 'Back'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('/api/account/update_voucher_status.php', { id: id, status: newStatus }, res => {
            if (res.success) { showActionSuccess(res.message); loadProjectDetails(); }
            else Swal.fire('Error', res.message, 'error');
        }, 'json');
    });
}

// Mirrors payment_vouchers.php's openPayVoucher() — same Pay Voucher modal/fields,
// same record_voucher_payment.php endpoint, so an approved project voucher can
// actually be paid (previously there was no way to reach 'paid' from a project at all).
