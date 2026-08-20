var inspTable = null;
var inspCurrentId = null;
var inspInspectorCount = 1;

function inspLoadTable() {
    $.getJSON(APP_URL + '/api/operations/get_inspections.php', { project_id: <?= $project_id ?> }, function(res) {
        if (!res.success) return;
        var data = res.data;
        $('#insp-total').text(data.length);
        $('#insp-passed').text(data.filter(r => r.result === 'Pass').length);
        $('#insp-failed').text(data.filter(r => r.result === 'Fail').length);
        $('#insp-reinspect').text(data.filter(r => r.reinspection_required == 1).length);

        if (inspTable) { inspTable.destroy(); }
        var tbody = $('#proj-insp-table tbody').empty();
        data.forEach(function(r, idx) {
            var resultBadge = r.result === 'Pass' ? '<span class="badge bg-primary">Pass</span>'
                : r.result === 'Fail' ? '<span class="badge bg-primary bg-opacity-50">Fail</span>'
                : r.result ? '<span class="badge bg-primary bg-opacity-25 text-primary">' + r.result + '</span>'
                : '<span class="badge bg-light text-muted border">Pending</span>';
            var statusBadge = r.status === 'Completed' ? '<span class="badge bg-primary">Completed</span>'
                : r.status === 'Cancelled' ? '<span class="badge bg-secondary">Cancelled</span>'
                : '<span class="badge bg-light text-primary border border-primary">Pending</span>';
            var actions = '<div class="dropdown d-print-none">'
                + '<button class="btn btn-light btn-sm dropdown-toggle shadow-sm border" type="button" data-bs-toggle="dropdown" aria-expanded="false">'
                + '<i class="bi bi-gear-fill text-primary"></i></button>'
                + '<ul class="dropdown-menu dropdown-menu-end shadow border-0">'
                + '<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="inspView(' + r.inspection_id + ')"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>'
                + '<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="inspEdit(' + r.inspection_id + ')"><i class="bi bi-pencil text-info me-2"></i>Edit</a></li>'
                + '<li><hr class="dropdown-divider"></li>'
                + '<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="inspDelete(' + r.inspection_id + ')"><i class="bi bi-trash me-2"></i>Delete</a></li>'
                + '</ul></div>';
            tbody.append('<tr><td>' + (idx + 1) + '</td><td>' + (r.inspection_no || '') + '</td><td>' + (r.inspection_date || '') + '</td><td>' + (r.inspection_type || '') + '</td>'
                + '<td>' + (r.milestone_description || '-') + '</td><td>' + (r.inspector_name || '') + '</td><td>' + (r.location_area || '-') + '</td>'
                + '<td>' + resultBadge + '</td><td>' + statusBadge + '</td><td class="d-print-none">' + actions + '</td></tr>');
        });
        inspTable = $('#proj-insp-table').DataTable({ pageLength: 25, responsive: true, dom: 'rtip' });
    });
}

function inspApplyFilters() {
    if (!inspTable) return;
    inspTable.column(6).search($('#inspResultFilter').val()).draw();
    inspTable.column(7).search($('#inspStatusFilter').val()).draw();
}

function inspClearFilters() {
    $('#inspResultFilter, #inspStatusFilter').val('');
    if (inspTable) inspTable.columns().search('').draw();
}

// ── Shared: dynamic attachment row ───────────────────────────────────────────
function inspAddAttachRow(listId) {
    var row = '<div class="attach-row row g-2 mb-2">'
        + '<div class="col-md-5"><input type="text" class="form-control form-control-sm" name="attach_name[]" placeholder="Attachment name / description"></div>'
        + '<div class="col-md-6"><input type="file" class="form-control form-control-sm" name="attachments[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif"></div>'
        + '<div class="col-md-1 d-flex align-items-center justify-content-center">'
        + '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" onclick="$(this).closest(\'.attach-row\').remove()"><i class="bi bi-trash"></i></button>'
        + '</div></div>';
    $('#' + listId).append(row);
}

// ── Add Inspection: milestone cascade ─────────────────────────────────────────
function inspOnMilestoneChange(selectEl, level) {
    var val = $(selectEl).val();
    // Remove sub-blocks deeper than this level
    $('.insp-sub-block').filter(function() {
        return parseInt($(this).data('level')) > level;
    }).remove();
    $('#inspSubMilestoneId').val('');
    $('#inspScopeBlock').hide();
    if (!val) {
        if (level === 0) $('#inspSubMilestonesContainer').hide();
        return;
    }
    $.getJSON(APP_URL + '/api/operations/get_sub_milestones.php', { parent_id: val }, function(res) {
        if (res.success && res.milestones.length > 0) {
            var nextLevel = level + 1;
            var opts = '<option value="">-- Select Sub-Milestone --</option>';
            $.each(res.milestones, function(i, m) {
                opts += '<option value="' + m.id + '" data-scope="' + (m.scope || 0) + '">' + $('<span>').text(m.description).html() + '</option>';
            });
            var block = '<div class="insp-sub-block" data-level="' + nextLevel + '">'
                + '<label class="form-label fw-bold small">Sub-Milestone</label>'
                + '<select class="form-select form-select-sm" onchange="inspOnMilestoneChange(this,' + nextLevel + ')">' + opts + '</select>'
                + '</div>';
            $('#inspSubMilestonesBlocks').append(block);
            $('#inspSubMilestonesContainer').show();
        } else {
            // Deepest level — show scope fields
            var scope = $(selectEl).find(':selected').data('scope') || 0;
            $('#inspScopeDisplay').val(scope);
            $('#inspScopeBlock').show();
            if (level > 0) $('#inspSubMilestoneId').val(val);
        }
    });
}

// ── Add Inspection: multiple inspectors ───────────────────────────────────────
function inspAddInspectorRow() {
    var idx = inspInspectorCount++;
    var row = '<div class="inspector-row row g-2 mb-2" data-idx="' + idx + '">'
        + '<div class="col-md-5"><input type="text" class="form-control form-control-sm" name="insp_name[]" placeholder="Inspector Name *" required></div>'
        + '<div class="col-md-6"><input type="text" class="form-control form-control-sm" name="insp_org[]" placeholder="Organisation"></div>'
        + '<div class="col-md-1 d-flex align-items-center justify-content-center">'
        + '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" onclick="$(this).closest(\'.inspector-row\').remove()">'
        + '<i class="bi bi-trash"></i></button></div></div>';
    $('#inspectorsList').append(row);
}

// ── Add Inspection: save (FormData for file uploads) ──────────────────────────
function inspSave() {
    var firstInspName = $('#inspectorsList [name="insp_name[]"]').first().val();
    if (!firstInspName || !firstInspName.trim()) {
        Swal.fire({ icon: 'warning', title: 'Required', text: 'At least one inspector name is required.' });
        return;
    }
    var fd = new FormData(document.getElementById('inspAddForm'));
    $.ajax({
        url: APP_URL + '/api/operations/save_inspection.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('inspAddModal')).hide();
                document.getElementById('inspAddForm').reset();
                $('#inspSubMilestonesBlocks').empty();
                $('#inspSubMilestonesContainer').hide();
                $('#inspScopeBlock').hide();
                $('#inspSubMilestoneId').val('');
                $('#inspectorsList').html(
                    '<div class="inspector-row row g-2 mb-2" data-idx="0">'
                    + '<div class="col-md-5"><input type="text" class="form-control form-control-sm" name="insp_name[]" placeholder="Inspector Name *" required></div>'
                    + '<div class="col-md-6"><input type="text" class="form-control form-control-sm" name="insp_org[]" placeholder="Organisation"></div>'
                    + '<div class="col-md-1 d-flex align-items-center justify-content-center"></div></div>'
                );
                inspInspectorCount = 1;
                $('#inspAttachList').html(
                    '<div class="attach-row row g-2 mb-2">'
                    + '<div class="col-md-5"><input type="text" class="form-control form-control-sm" name="attach_name[]" placeholder="Attachment name / description"></div>'
                    + '<div class="col-md-6"><input type="file" class="form-control form-control-sm" name="attachments[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif"></div>'
                    + '<div class="col-md-1 d-flex align-items-center justify-content-center"></div></div>'
                );
                inspLoadTable();
                Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' });
        }
    });
}

function inspEdit(id) {
    inspCurrentId = id;
    $.getJSON(APP_URL + '/api/operations/get_inspection.php', { id: id }, function(res) {
        if (!res.success) return Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        var r = res.data;
        $('#edit_insp_id').val(r.inspection_id);
        $('#edit_insp_milestone').val(r.milestone_id || '');
        $('#edit_insp_type').val(r.inspection_type || 'Site');
        $('#edit_insp_date').val(r.inspection_date || '');
        $('#edit_insp_time').val(r.inspection_time || '');
        $('#edit_insp_location').val(r.location_area || '');
        $('#edit_insp_result').val(r.result || '');
        $('#edit_insp_defects').val(r.defects_found || '');
        $('#edit_insp_corrective').val(r.corrective_action || '');
        $('#edit_insp_reinsp_req').val(r.reinspection_required || '0');
        $('#edit_insp_reinsp_date').val(r.reinspection_date || '');
        $('#edit_insp_status').val(r.status || 'Pending');
        $('#edit_insp_signedby').val(r.signed_off_by || '');
        $('#edit_insp_notes').val(r.notes || '');
        // Load inspectors
        $('#editInspectorsList').empty();
        editInspectorCount = 0;
        var inspectors = (res.inspectors && res.inspectors.length > 0)
            ? res.inspectors
            : [{ inspector_name: r.inspector_name || '', inspector_org: r.inspector_org || '' }];
        $.each(inspectors, function(i, ins) {
            editInspAddRow(ins.inspector_name, ins.inspector_org);
        });
        new bootstrap.Modal(document.getElementById('inspEditModal')).show();
    });
}

// ── Edit inspection helpers ───────────────────────────────────────────────────
var editInspectorCount = 0;

function editInspAddRow(name, org) {
    var idx = editInspectorCount++;
    var canDelete = idx > 0;
    var delBtn = canDelete
        ? '<button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" onclick="$(this).closest(\'.inspector-row\').remove()"><i class="bi bi-trash"></i></button>'
        : '';
    var row = '<div class="inspector-row row g-2 mb-2" data-idx="' + idx + '">'
        + '<div class="col-md-5"><input type="text" class="form-control form-control-sm" name="insp_name[]" placeholder="Inspector Name *" value="' + ($('<span>').text(name || '').html()) + '" required></div>'
        + '<div class="col-md-6"><input type="text" class="form-control form-control-sm" name="insp_org[]" placeholder="Organisation" value="' + ($('<span>').text(org || '').html()) + '"></div>'
        + '<div class="col-md-1 d-flex align-items-center justify-content-center">' + delBtn + '</div>'
        + '</div>';
    $('#editInspectorsList').append(row);
}

function inspUpdate() {
    var firstInspName = $('#editInspectorsList [name="insp_name[]"]').first().val();
    if (!firstInspName || !firstInspName.trim()) {
        Swal.fire({ icon: 'warning', title: 'Required', text: 'At least one inspector name is required.' });
        return;
    }
    var fd = new FormData(document.getElementById('inspEditForm'));
    $.ajax({
        url: APP_URL + '/api/operations/save_inspection.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('inspEditModal')).hide();
                inspLoadTable();
                Swal.fire({ icon: 'success', title: 'Updated', text: res.message, timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' });
        }
    });
}

function inspView(id) {
    window.open(APP_URL + '/inspection_view?id=' + id, '_blank');
}

function inspDelete(id) {
    Swal.fire({ title: 'Delete Inspection?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' }).then(function(result) {
        if (!result.isConfirmed) return;
        $.post(APP_URL + '/api/operations/delete_inspection.php', { inspection_id: id }, function(res) {
            if (res.success) { inspLoadTable(); Swal.fire({ icon: 'success', title: 'Deleted', timer: 1500, showConfirmButton: false }); }
            else Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }, 'json');
    });
}

$(document).on('shown.bs.tab', '#proj-inspections-tab', function() { inspLoadTable(); });

// ─────────────────────────────────────────────
// SC PAYMENTS MODULE (SC mode only)
// ─────────────────────────────────────────────
function loadScPayments() {
    const $tbody = $('#scPaymentsBody');
    $tbody.html('<tr><td colspan="9" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2 text-success"></span>Loading payments...</td></tr>');
    $.getJSON(APP_URL + '/api/sc/get_payments.php', { supplier_id: scId, project_id: projectId }, function(res) {
        if (!res.success) {
            $tbody.html('<tr><td colspan="9" class="text-center py-4 text-danger small">' + res.message + '</td></tr>');
            return;
        }
        if (!res.payments || res.payments.length === 0) {
            $tbody.html('<tr><td colspan="9" class="text-center py-4 text-muted small">No payments recorded for this sub-contractor on this project.</td></tr>');
            $('#scPaymentsTotalBar').hide();
            return;
        }
        let html = '';
        const methodMap = { cash: 'Cash', bank_transfer: 'Bank Transfer', cheque: 'Cheque', mobile_money: 'Mobile Money', other: 'Other' };
        const statusMap = { completed: 'success', pending: 'warning', cancelled: 'secondary' };
        res.payments.forEach(function(p, i) {
            html += `<tr>
                <td class="text-center text-muted">${i + 1}</td>
                <td>${p.payment_date || '-'}</td>
                <td class="fw-bold">${parseFloat(p.amount).toLocaleString('en-US', {minimumFractionDigits:2})}</td>
                <td>${p.currency}</td>
                <td>${methodMap[p.payment_method] || p.payment_method}</td>
                <td>${p.reference_number || '-'}</td>
                <td>${p.receipt_number || '-'}</td>
                <td><span class="badge bg-${statusMap[p.status] || 'secondary'}">${p.status}</span></td>
                <td class="text-center d-print-none">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill me-1"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                            <li><a class="dropdown-item py-2 rounded text-danger" href="javascript:void(0)" onclick="deleteScPayment(${p.id})">
                                <i class="bi bi-trash me-2"></i>Delete
                            </a></li>
                        </ul>
                    </div>
                </td>
            </tr>`;
        });
        $tbody.html(html);
        const total = parseFloat(res.total || 0);
        $('#scPaymentsTotalAmt').text(res.payments[0].currency + ' ' + total.toLocaleString('en-US', {minimumFractionDigits:2}));
        $('#scPaymentsTotalBar').show();
    }).fail(function() {
        $tbody.html('<tr><td colspan="9" class="text-center py-4 text-danger small">Failed to load payments. Please try again.</td></tr>');
    });
}

function openScPaymentModal() {
    $('#scPaymentMsg').empty();
    $('#scPayDate').val(new Date().toISOString().split('T')[0]);
    $('#scPayAmount').val('');
    $('#scPayCurrency').val('TZS');
    $('#scPayMethod').val('');
    $('#scPayAccount').val('');
    $('#scPayRef').val('');
    $('#scPayReceipt').val('');
    $('#scPayNotes').val('');
    $('#scAddPaymentModal').modal('show');
}

function saveScPayment() {
    const amount = parseFloat($('#scPayAmount').val());
    const method = $('#scPayMethod').val();
    const account = $('#scPayAccount').val();
    if (!amount || amount <= 0) {
        $('#scPaymentMsg').html('<div class="alert alert-warning py-2 small mb-0">Please enter a valid amount.</div>');
        return;
    }
    if (!method) {
        $('#scPaymentMsg').html('<div class="alert alert-warning py-2 small mb-0">Please select a payment method.</div>');
        return;
    }
    if (!account) {
        $('#scPaymentMsg').html('<div class="alert alert-warning py-2 small mb-0">Please choose the account the payment was made from (Paid From).</div>');
        return;
    }
    $.post(APP_URL + '/api/sc/add_payment.php', {
        supplier_id: scId,
        project_id: projectId,
        payment_date: $('#scPayDate').val(),
        amount: amount,
        currency: $('#scPayCurrency').val(),
        payment_method: method,
        paid_from_account_id: account,
        reference_number: $('#scPayRef').val(),
        receipt_number: $('#scPayReceipt').val(),
        notes: $('#scPayNotes').val()
    }, function(res) {
        if (res.success) {
            $('#scAddPaymentModal').modal('hide');
            Swal.fire({ icon: 'success', title: 'Payment Recorded', timer: 1500, showConfirmButton: false });
            loadScPayments();
        } else {
            $('#scPaymentMsg').html('<div class="alert alert-danger py-2 small mb-0">' + res.message + '</div>');
        }
    }, 'json');
}

function deleteScPayment(id) {
    Swal.fire({ title: 'Delete Payment?', text: 'This cannot be undone.', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete'
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post(APP_URL + '/api/sc/delete_payment.php', { id: id }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false });
                loadScPayments();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }, 'json');
    });
}

// ── Supplier Project Payments (read-only, via purchase_orders) ───────────────
let suppPayLoaded = false;

function loadSupplierProjectPayments() {
    $('#suppPaymentsContent').html('<div class="py-5 text-center text-muted"><span class="spinner-border spinner-border-sm me-2"></span> Loading...</div>');
    $('#suppPaymentsTotalBar').addClass('d-none');
    $.getJSON('<?= buildUrl('api/suppliers/get_project_payments.php') ?>', {
        supplier_id: viewSupplierId,
        project_id:  projectId
    }, function(res) {
        if (!res.success) {
            $('#suppPaymentsContent').html('<div class="py-4 text-center text-danger"><i class="bi bi-exclamation-circle me-2"></i>' + (res.message || 'Failed to load payments.') + '</div>');
            return;
        }
        renderSupplierProjectPayments(res.payments);
        if (res.payments.length > 0) {
            $('#suppPaymentsTotalAmt').text(res.currency + ' ' + parseFloat(res.total).toLocaleString('en-US', {minimumFractionDigits:2}));
            $('#suppPaymentsTotalBar').removeClass('d-none');
        }
        suppPayLoaded = true;
    }).fail(function() {
        $('#suppPaymentsContent').html('<div class="py-4 text-center text-danger"><i class="bi bi-exclamation-circle me-2"></i> Server error. Please try again.</div>');
    });
}

function renderSupplierProjectPayments(payments) {
    const $el = $('#suppPaymentsContent');
    if (!payments.length) {
        $el.html('<div class="py-5 text-center text-muted"><i class="bi bi-cash-stack fs-1 mb-3 d-block"></i><p>No payments recorded for this supplier on this project.</p></div>');
        return;
    }
    const methodMap   = { cash:'Cash', bank_transfer:'Bank Transfer', cheque:'Cheque', mobile_money:'Mobile Money', credit_card:'Credit Card', other:'Other' };
    const statusColors = { completed:'success', pending:'warning', reviewed:'info', approved:'success', failed:'danger', cancelled:'secondary' };
    let html = '<div class="table-responsive"><table class="table table-hover align-middle border"><thead class="table-light text-nowrap small fw-bold text-muted"><tr>'
        + '<th style="width:50px;" class="text-center">S/No</th>'
        + '<th>Payment #</th>'
        + '<th>PO Number</th>'
        + '<th>Date</th>'
        + '<th class="text-end">Amount</th>'
        + '<th>Currency</th>'
        + '<th>Method</th>'
        + '<th>Reference</th>'
        + '<th>Status</th>'
        + '<th class="text-center d-print-none">Actions</th>'
        + '</tr></thead><tbody>';
    payments.forEach(function(p, i) {
        const sc = statusColors[p.status] || 'secondary';
        const isPending  = p.status === 'pending';
        const isReviewed = p.status === 'reviewed';
        const isApproved = p.status === 'approved';
        const canDel     = !isApproved;

        let actions = `<li><a class="dropdown-item py-2 rounded" href="#" onclick="viewSuppPayment(${p.payment_id});return false;"><i class="bi bi-eye text-info me-2"></i> View Details</a></li>`;
        if (isPending)  actions += `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2 rounded" href="#" onclick="editSuppPayment(${p.payment_id});return false;"><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>`;
        if (isPending)  actions += `<li><a class="dropdown-item py-2 rounded" href="#" onclick="changeSuppPayStatus(${p.payment_id},'reviewed');return false;"><i class="bi bi-check2 text-info me-2"></i> Mark Reviewed</a></li>`;
        if (isReviewed) actions += `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2 rounded" href="#" onclick="changeSuppPayStatus(${p.payment_id},'approved');return false;"><i class="bi bi-check2-all text-success me-2"></i> Approve</a></li>`;
        if (canDel)     actions += `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2 rounded text-danger" href="#" onclick="deleteSuppPayment(${p.payment_id});return false;"><i class="bi bi-trash text-danger me-2"></i> Delete</a></li>`;

        html += `<tr>
            <td class="text-center text-muted">${i + 1}</td>
            <td class="fw-bold">${safeOutput(p.payment_number) || '—'}</td>
            <td>${safeOutput(p.po_number) || '—'}</td>
            <td>${p.payment_date ? formatDate(p.payment_date) : '—'}</td>
            <td class="fw-bold text-end">${parseFloat(p.amount).toLocaleString('en-US', {minimumFractionDigits:2})}</td>
            <td>${safeOutput(p.currency)}</td>
            <td>${methodMap[p.payment_method] || safeOutput(p.payment_method)}</td>
            <td>${safeOutput(p.reference_number) || safeOutput(p.transaction_id) || safeOutput(p.cheque_number) || '—'}</td>
            <td><span class="badge bg-${sc}">${safeOutput(p.status)}</span></td>
            <td class="text-center d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear-fill"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${actions}</ul>
                </div>
            </td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $el.html(html);
}

function viewSuppPayment(id) {
    $.getJSON('<?= buildUrl('api/suppliers/get_project_payment.php') ?>', { id: id }, function(res) {
        if (!res.success) { Swal.fire('Error', res.message, 'error'); return; }
        const d = res.data;
        const methodMap  = { cash:'Cash', bank_transfer:'Bank Transfer', cheque:'Cheque', mobile_money:'Mobile Money', credit_card:'Credit Card', other:'Other' };
        const statusColors = { pending:'bg-warning text-dark', reviewed:'bg-info text-white', approved:'bg-success text-white', completed:'bg-success text-white', cancelled:'bg-secondary text-white', failed:'bg-danger text-white' };
        const badge = `<span class="badge ${statusColors[d.status] || 'bg-secondary'} text-uppercase">${safeOutput(d.status)}</span>`;
        Swal.fire({
            title: 'Payment — ' + safeOutput(d.payment_number),
            html: `<div class="text-start">
                <div class="row g-2 mb-3">
                    <div class="col-6"><p class="text-muted small mb-0">Status</p>${badge}</div>
                    <div class="col-6"><p class="text-muted small mb-0">PO Number</p><strong>${safeOutput(d.po_number) || '—'}</strong></div>
                    <div class="col-6"><p class="text-muted small mb-0">Payment Date</p><strong>${safeOutput(d.payment_date) || '—'}</strong></div>
                    <div class="col-6"><p class="text-muted small mb-0">Amount</p><strong>${d.currency} ${parseFloat(d.amount).toLocaleString('en-US',{minimumFractionDigits:2})}</strong></div>
                    <div class="col-6"><p class="text-muted small mb-0">Method</p><strong>${methodMap[d.payment_method] || safeOutput(d.payment_method)}</strong></div>
                    <div class="col-6"><p class="text-muted small mb-0">Reference</p><strong>${safeOutput(d.reference_number) || safeOutput(d.cheque_number) || safeOutput(d.transaction_id) || '—'}</strong></div>
                    <div class="col-6"><p class="text-muted small mb-0">Bank</p><strong>${safeOutput(d.bank_name) || '—'}</strong></div>
                    <div class="col-6"><p class="text-muted small mb-0">Recorded By</p><strong>${safeOutput(d.recorded_by) || '—'}</strong></div>
                </div>
                ${d.notes ? `<p class="text-muted small mb-1">Notes</p><p>${safeOutput(d.notes)}</p>` : ''}
            </div>`,
            width: 520,
            confirmButtonText: 'Close',
            confirmButtonColor: '#6c757d'
        });
    }).fail(function() { Swal.fire('Error', 'Could not load payment details.', 'error'); });
}

function editSuppPayment(id) {
    $.getJSON('<?= buildUrl('api/suppliers/get_project_payment.php') ?>', { id: id }, function(res) {
        if (!res.success) { Swal.fire('Error', res.message, 'error'); return; }
        if (res.data.status !== 'pending') { Swal.fire('Not Allowed', 'Only pending payments can be edited.', 'warning'); return; }
        const d = res.data;
        $('#editSuppPayId').val(d.payment_id);
        $('#editSuppPayDate').val(d.payment_date);
        $('#editSuppPayAmount').val(d.amount);
        $('#editSuppPayCurrency').val(d.currency);
        $('#editSuppPayMethod').val(d.payment_method);
        $('#editSuppPayRef').val(d.reference_number || '');
        $('#editSuppPayNotes').val(d.notes || '');
        $('#editSuppPaymentMsg').empty();

        // Load POs for dropdown
        $.getJSON('<?= buildUrl('api/suppliers/get_project_payments.php') ?>', {
            action: 'get_pos', supplier_id: viewSupplierId, project_id: projectId
        }, function(poRes) {
            let opts = '<option value="">Select PO...</option>';
            (poRes.data || []).forEach(function(po) {
                const sel = po.purchase_order_id == d.purchase_order_id ? ' selected' : '';
                opts += `<option value="${po.purchase_order_id}"${sel}>${safeOutput(po.order_number)}</option>`;
            });
            $('#editSuppPayPO').html(opts);
        });

        new bootstrap.Modal(document.getElementById('suppEditPaymentModal')).show();
    }).fail(function() { Swal.fire('Error', 'Could not load payment.', 'error'); });
}

function saveSuppPaymentEdit() {
    const id     = $('#editSuppPayId').val();
    const amount = parseFloat($('#editSuppPayAmount').val());
    const method = $('#editSuppPayMethod').val();
    if (!amount || amount <= 0) { $('#editSuppPaymentMsg').html('<div class="alert alert-warning py-2 small mb-0">Enter a valid amount.</div>'); return; }
    if (!method)                { $('#editSuppPaymentMsg').html('<div class="alert alert-warning py-2 small mb-0">Select a payment method.</div>'); return; }

    const btn = $('#editSuppPaySaveBtn'), orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    $.post('<?= buildUrl('api/suppliers/update_project_payment.php') ?>', {
        _csrf:             '<?= csrf_token() ?>',
        payment_id:        id,
        purchase_order_id: $('#editSuppPayPO').val(),
        payment_date:      $('#editSuppPayDate').val(),
        amount:            amount,
        currency:          $('#editSuppPayCurrency').val(),
        payment_method:    method,
        reference_number:  $('#editSuppPayRef').val(),
        notes:             $('#editSuppPayNotes').val()
    }, function(res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('suppEditPaymentModal')).hide();
            Swal.fire({ icon:'success', title:'Updated!', text: res.message, timer:1800, showConfirmButton:false });
            suppPayLoaded = false; loadSupplierProjectPayments();
        } else {
            $('#editSuppPaymentMsg').html('<div class="alert alert-danger py-2 small mb-0">' + res.message + '</div>');
        }
    }, 'json').fail(function() {
        $('#editSuppPaymentMsg').html('<div class="alert alert-danger py-2 small mb-0">Server error.</div>');
    }).always(function() { btn.prop('disabled', false).html(orig); });
}

function deleteSuppPayment(id) {
    Swal.fire({ title:'Delete Payment?', text:'This cannot be undone.', icon:'warning',
        showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Delete'
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/suppliers/delete_project_payment.php') ?>', {
            _csrf: '<?= csrf_token() ?>', payment_id: id
        }, function(res) {
            if (res.success) {
                Swal.fire({ icon:'success', title:'Deleted', timer:1400, showConfirmButton:false });
                suppPayLoaded = false; loadSupplierProjectPayments();
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json').fail(function() { Swal.fire('Error', 'Server error.', 'error'); });
    });
}

function changeSuppPayStatus(id, newStatus) {
    const labels = { reviewed:'Mark as Reviewed', approved:'Approve Payment' };
    const icons  = { reviewed:'bi-check2 text-info', approved:'bi-check2-all text-success' };
    Swal.fire({ title: labels[newStatus] || 'Change Status', text:'Are you sure?', icon:'question',
        showCancelButton:true, confirmButtonColor: newStatus==='approved' ? '#198754' : '#0dcaf0',
        confirmButtonText: labels[newStatus] || 'Confirm'
    }).then(function(r) {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/suppliers/change_payment_status.php') ?>', {
            _csrf: '<?= csrf_token() ?>', payment_id: id, new_status: newStatus
        }, function(res) {
            if (res.success) {
                Swal.fire({ icon:'success', title:'Done!', text: res.message, timer:1800, showConfirmButton:false });
                suppPayLoaded = false; loadSupplierProjectPayments();
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json').fail(function() { Swal.fire('Error', 'Server error.', 'error'); });
    });
}

function openSuppPaymentModal() {
    $('#suppPaymentMsg').empty();
    $('#suppPayDate').val(new Date().toISOString().split('T')[0]);
    $('#suppPayAmount').val('');
    $('#suppPayCurrency').val('TZS');
    $('#suppPayMethod').val('');
    $('#suppPayRef').val('');
    $('#suppPayNotes').val('');
    $('#suppPayPOBalance').text('');

    // Load POs for this supplier + project
    $('#suppPayPO').html('<option value="">Loading...</option>').prop('disabled', true);
    $.getJSON('<?= buildUrl('api/suppliers/get_project_payments.php') ?>', {
        action: 'get_pos', supplier_id: viewSupplierId, project_id: projectId
    }, function(res) {
        const pos = res.data || [];
        if (!Array.isArray(pos) || pos.length === 0) {
            $('#suppPayPO').html('<option value="">No approved POs found for this supplier on this project</option>');
        } else {
            let opts = '<option value="">Select PO...</option>';
            pos.forEach(function(po) {
                const bal = parseFloat(po.grand_total || 0) - parseFloat(po.paid_amount || 0);
                opts += `<option value="${po.purchase_order_id}" data-balance="${bal}" data-currency="${po.currency || 'TZS'}">${safeOutput(po.order_number)} — Balance: ${po.currency || 'TZS'} ${bal.toLocaleString('en-US',{minimumFractionDigits:2})}</option>`;
            });
            $('#suppPayPO').html(opts);
        }
        $('#suppPayPO').prop('disabled', false);
    }).fail(function() {
        $('#suppPayPO').html('<option value="">Failed to load POs</option>');
    });

    new bootstrap.Modal(document.getElementById('suppAddPaymentModal')).show();
}

// Show remaining balance when PO is selected
$(document).on('change', '#suppPayPO', function() {
    const opt = $(this).find(':selected');
    const bal = parseFloat(opt.data('balance') || 0);
    const cur = opt.data('currency') || 'TZS';
    if ($(this).val()) {
        $('#suppPayPOBalance').html('<span class="text-success fw-bold">Outstanding balance: ' + cur + ' ' + bal.toLocaleString('en-US',{minimumFractionDigits:2}) + '</span>');
        $('#suppPayCurrency').val(cur);
    } else {
        $('#suppPayPOBalance').text('');
    }
});

function saveSuppPayment() {
    const po_id  = $('#suppPayPO').val();
    const amount = parseFloat($('#suppPayAmount').val());
    const method = $('#suppPayMethod').val();

    if (!po_id) {
        $('#suppPaymentMsg').html('<div class="alert alert-warning py-2 small mb-0">Please select a Purchase Order.</div>');
        return;
    }
    if (!amount || amount <= 0) {
        $('#suppPaymentMsg').html('<div class="alert alert-warning py-2 small mb-0">Please enter a valid amount.</div>');
        return;
    }
    if (!method) {
        $('#suppPaymentMsg').html('<div class="alert alert-warning py-2 small mb-0">Please select a payment method.</div>');
        return;
    }

    const btn  = $('#suppPaySaveBtn');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    $.post('<?= buildUrl('api/suppliers/add_project_payment.php') ?>', {
        _csrf:             '<?= csrf_token() ?>',
        supplier_id:       viewSupplierId,
        project_id:        projectId,
        purchase_order_id: po_id,
        payment_date:      $('#suppPayDate').val(),
        amount:            amount,
        currency:          $('#suppPayCurrency').val(),
        payment_method:    method,
        reference_number:  $('#suppPayRef').val(),
        notes:             $('#suppPayNotes').val()
    }, function(res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('suppAddPaymentModal')).hide();
            Swal.fire({ icon: 'success', title: 'Payment Recorded', text: res.message, timer: 1800, showConfirmButton: false });
            suppPayLoaded = false;
            loadSupplierProjectPayments();
        } else {
            $('#suppPaymentMsg').html('<div class="alert alert-danger py-2 small mb-0">' + res.message + '</div>');
        }
    }, 'json').fail(function() {
        $('#suppPaymentMsg').html('<div class="alert alert-danger py-2 small mb-0">Server error. Please try again.</div>');
    }).always(function() {
        btn.prop('disabled', false).html(orig);
    });
}

$(document).on('shown.bs.tab', '#sc-payments-tab', function() {
    if (supplierMode) {
        if (!suppPayLoaded) loadSupplierProjectPayments();
    } else {
        loadScPayments();
    }
});

// ─────────────────────────────────────────────
// IPC MODULE
// ─────────────────────────────────────────────
var ipcTable = null;
var ipcCurrentId = null;
var IPC_CAN_DELETE     = <?= isAdmin() ? 'true' : 'false' ?>;
var INVOICE_CAN_DELETE = <?= isAdmin() ? 'true' : 'false' ?>;
var DN_IS_ADMIN        = <?= isAdmin() ? 'true' : 'false' ?>;

function ipcEscHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function ipcNewItemRow(mode, item, no) {
    item = item || {};
    var qty = (item.quantity !== undefined && item.quantity !== '') ? item.quantity : 1;
    var total = (item.total !== undefined) ? parseFloat(item.total).toLocaleString('en-TZ', { minimumFractionDigits: 2 }) : '0.00';
    return '<tr>'
        + '<td class="ipc-row-no text-center">' + (no || '') + '</td>'
        + '<td><input type="text" class="form-control form-control-sm border-0" data-field="product_name" value="' + ipcEscHtml(item.product_name) + '" placeholder="Product or description"></td>'
        + '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm border-0 text-center" data-field="quantity" value="' + qty + '" oninput="ipcCalc(\'' + mode + '\')"></td>'
        + '<td><input type="text" class="form-control form-control-sm border-0 text-center" data-field="unit" value="' + ipcEscHtml(item.unit) + '" placeholder="pcs"></td>'
        + '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm border-0 text-end" data-field="unit_price" value="' + (item.unit_price || '') + '" placeholder="0.00" oninput="ipcCalc(\'' + mode + '\')"></td>'
        + '<td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm border-0 text-center" data-field="tax_percent" value="' + (item.tax_percent !== undefined ? item.tax_percent : 0) + '" oninput="ipcCalc(\'' + mode + '\')"></td>'
        + '<td class="text-end fw-bold small" data-field-display="total">' + total + '</td>'
        + '<td class="d-print-none text-center"><button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="ipcRemoveItem(this,\'' + mode + '\')"><i class="bi bi-trash"></i></button></td>'
        + '</tr>';
}

function ipcAddItem(mode) {
    var tbody = $('#ipc' + (mode === 'add' ? 'Add' : 'Edit') + 'ItemsBody');
    tbody.append(ipcNewItemRow(mode, {}, tbody.find('tr').length + 1));
}

function ipcRemoveItem(btn, mode) {
    $(btn).closest('tr').remove();
    var tbody = $('#ipc' + (mode === 'add' ? 'Add' : 'Edit') + 'ItemsBody');
    tbody.find('tr').each(function(i) { $(this).find('.ipc-row-no').text(i + 1); });
    ipcCalc(mode);
}

function ipcGetItems(mode) {
    var prefix = mode === 'add' ? 'Add' : 'Edit';
    var items = [];
    $('#ipc' + prefix + 'ItemsBody tr').each(function() {
        items.push({
            product_name: $(this).find('[data-field="product_name"]').val() || '',
            quantity:     $(this).find('[data-field="quantity"]').val() || '1',
            unit:         $(this).find('[data-field="unit"]').val() || '',
            unit_price:   $(this).find('[data-field="unit_price"]').val() || '0',
            tax_percent:  $(this).find('[data-field="tax_percent"]').val() || '0'
        });
    });
    return items;
}

function ipcCalc(mode) {
    var prefix   = mode === 'add' ? 'Add' : 'Edit';
    var idPfx    = mode === 'add' ? 'add' : 'edit';
    var subtotal = 0, tax_total = 0;
    $('#ipc' + prefix + 'ItemsBody tr').each(function() {
        var qty        = parseFloat($(this).find('[data-field="quantity"]').val()) || 0;
        var unit_price = parseFloat($(this).find('[data-field="unit_price"]').val()) || 0;
        var tax_pct    = parseFloat($(this).find('[data-field="tax_percent"]').val()) || 0;
        var line_sub   = Math.round(qty * unit_price * 100) / 100;
        var tax_amt    = Math.round(line_sub * tax_pct / 100 * 100) / 100;
        $(this).find('[data-field-display="total"]').text((line_sub + tax_amt).toLocaleString('en-TZ', { minimumFractionDigits: 2 }));
        subtotal  += line_sub;
        tax_total += tax_amt;
    });
    subtotal  = Math.round(subtotal * 100) / 100;
    tax_total = Math.round(tax_total * 100) / 100;
    var gross    = Math.round((subtotal + tax_total) * 100) / 100;
    var ret_pct  = parseFloat($('#ipc_' + idPfx + '_retention_pct').val()) || 0;
    var previous = parseFloat($('#ipc_' + idPfx + '_previous').val()) || 0;
    var ret_amt  = Math.round(gross * ret_pct / 100 * 100) / 100;
    var net      = Math.round((gross - ret_amt - previous) * 100) / 100;
    var fmt = function(n) { return n.toLocaleString('en-TZ', { minimumFractionDigits: 2 }); };
    $('#ipc_' + idPfx + '_subtotal').text(fmt(subtotal));
    $('#ipc_' + idPfx + '_tax').text(fmt(tax_total));
    $('#ipc_' + idPfx + '_gross').text(fmt(gross));
    $('#ipc_' + idPfx + '_retention_display').text('- ' + fmt(ret_amt));
    $('#ipc_' + idPfx + '_net').text(fmt(net));
}

function ipcFilterSO(customerId, mode) {
    var so = $('#ipc_' + mode + '_so');
    so.find('option').each(function() {
        var opt = $(this);
        if (!opt.val()) return;
        if (!customerId || String(opt.data('customer')) === String(customerId)) {
            opt.show().prop('disabled', false);
        } else {
            opt.hide().prop('disabled', true);
        }
    });
    so.val('');
    var tbody = mode === 'add' ? $('#ipcAddItemsBody') : $('#ipcEditItemsBody');
    tbody.html(ipcNewItemRow(mode, {}, 1));
    ipcCalc(mode);
}

function ipcLoadOrderItems(soId, mode) {
    var tbody = mode === 'add' ? $('#ipcAddItemsBody') : $('#ipcEditItemsBody');
    if (!soId) {
        tbody.html(ipcNewItemRow(mode, {}, 1));
        ipcCalc(mode);
        return;
    }
    $.getJSON(APP_URL + '/api/operations/get_so_items_for_ipc.php', { so_id: soId }, function(res) {
        if (!res.success || !res.items || !res.items.length) {
            tbody.html(ipcNewItemRow(mode, {}, 1));
            ipcCalc(mode);
            return;
        }
        tbody.empty();
        res.items.forEach(function(item, i) {
            tbody.append(ipcNewItemRow(mode, {
                product_name: item.product_name,
                quantity:     item.quantity,
                unit:         item.unit,
                unit_price:   item.unit_price,
                tax_percent:  item.tax_rate
            }, i + 1));
        });
        ipcCalc(mode);
    });
}

function ipcLoadTable() {
    $.getJSON(APP_URL + '/api/operations/get_ipcs.php', { project_id: <?= $project_id ?> }, function(res) {
        if (!res.success) return;
        var data = res.data;
        $('#ipc-total').text(data.length);
        $('#ipc-draft').text(data.filter(r => r.status === 'Draft' || r.status === 'Viewed').length);
        $('#ipc-approved').text(data.filter(r => r.status === 'Approved').length);
        $('#ipc-paid').text(data.filter(r => r.status === 'Paid').length);

        if (ipcTable) { ipcTable.destroy(); }
        var tbody = $('#proj-ipc-table tbody').empty();
        var cards = '';
        if (!data.length) {
            $('#proj-ipc-cards').html('<div class="col-12"><div class="py-5 text-center text-muted"><i class="bi bi-file-earmark-check fs-1 mb-3 d-block"></i>No IPCs for this project.</div></div>');
        }
        data.forEach(function(r, idx) {
            var statusBadge = r.status === 'Approved' ? '<span class="badge bg-success">Approved</span>'
                : r.status === 'Paid' ? '<span class="badge bg-primary">Invoiced</span>'
                : r.status === 'Viewed' ? '<span class="badge bg-info">Viewed</span>'
                : r.status === 'Rejected' ? '<span class="badge bg-secondary">Rejected</span>'
                : '<span class="badge bg-secondary">' + r.status + '</span>';
            var fmt = function(n) { return parseFloat(n || 0).toLocaleString('en-TZ', { minimumFractionDigits: 2 }); };
            var period = (r.period_from || '') + (r.period_to ? ' – ' + r.period_to : '');
            var reviewItem = r.status === 'Draft'
                ? '<li><hr class="dropdown-divider"></li>'
                  + '<li><a class="dropdown-item py-2 text-warning fw-bold" href="javascript:void(0)" onclick="ipcView(' + r.ipc_id + ')"><i class="bi bi-search me-2"></i>Review</a></li>'
                : '';
            var approveItem = r.status === 'Viewed'
                ? '<li><hr class="dropdown-divider"></li>'
                  + '<li><a class="dropdown-item py-2 text-success fw-bold" href="javascript:void(0)" onclick="ipcUpdateStatus(' + r.ipc_id + ',\'Approved\')"><i class="bi bi-check-circle me-2"></i>Approve</a></li>'
                : '';
            var createInvoiceItem = (r.status === 'Approved' && !r.invoice_id)
                ? '<li><hr class="dropdown-divider"></li>'
                  + '<li><a class="dropdown-item py-2 text-success fw-bold" href="javascript:void(0)" onclick="ipcCreateInvoice(' + r.ipc_id + ')"><i class="bi bi-receipt me-2"></i>Create Invoice</a></li>'
                : '';
            var editDelete = '<li><hr class="dropdown-divider"></li>'
                + '<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="ipcEdit(' + r.ipc_id + ')"><i class="bi bi-pencil text-info me-2"></i>Edit</a></li>'
                + (IPC_CAN_DELETE
                    ? '<li><hr class="dropdown-divider"></li>'
                      + '<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="ipcDelete(' + r.ipc_id + ')"><i class="bi bi-trash me-2"></i>Delete</a></li>'
                    : '');
            var actions = '<div class="dropdown d-print-none">'
                + '<button class="btn btn-light btn-sm dropdown-toggle shadow-sm border" type="button" data-bs-toggle="dropdown" aria-expanded="false">'
                + '<i class="bi bi-gear-fill text-primary"></i></button>'
                + '<ul class="dropdown-menu dropdown-menu-end shadow border-0">'
                + '<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="ipcView(' + r.ipc_id + ')"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>'
                + reviewItem + approveItem + createInvoiceItem + editDelete + '</ul></div>';
            tbody.append('<tr><td>' + (idx + 1) + '</td><td>' + (r.ipc_number || '') + '</td><td>' + (r.ipc_date || '-') + '</td><td class="small">' + period + '</td>'
                + '<td class="small">' + ipcEscHtml(r.customer_name || '-') + '</td>'
                + '<td class="small">' + ipcEscHtml(r.order_number || '-') + '</td>'
                + '<td class="text-end fw-bold text-primary">' + fmt(r.net_payable) + '</td>'
                + '<td>' + statusBadge + '</td><td class="d-print-none">' + actions + '</td></tr>');

            // Mobile card (mirrors the row)
            cards += '<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">'
                + '<div class="d-flex justify-content-between align-items-start mb-2">'
                + '<span class="fw-bold text-primary">' + (r.ipc_number || '') + '</span>' + statusBadge + '</div>'
                + '<div class="small text-muted mb-1"><i class="bi bi-calendar3 me-1"></i>' + (r.ipc_date || '-') + (period ? ' &middot; ' + period : '') + '</div>'
                + '<div class="small text-muted mb-1"><i class="bi bi-person me-1"></i>' + ipcEscHtml(r.customer_name || '-') + '</div>'
                + '<div class="small text-muted mb-2"><i class="bi bi-cart me-1"></i>' + ipcEscHtml(r.order_number || '-') + '</div>'
                + '<div class="d-flex justify-content-between align-items-center">'
                + '<span class="fw-bold text-primary">TZS ' + fmt(r.net_payable) + '</span>' + actions + '</div>'
                + '</div></div></div>';
        });
        if (data.length) { $('#proj-ipc-cards').html(cards); }
        ipcTable = $('#proj-ipc-table').DataTable({ pageLength: 25, autoWidth: false, dom: 'frtip', columnDefs: [{ orderable: false, targets: [0, 8] }] });
    });
}

function ipcSave() {
    var data = $('#ipcAddForm').serializeArray();
    var items = ipcGetItems('add');
    items.forEach(function(item, i) {
        data.push({ name: 'items[' + i + '][product_name]', value: item.product_name });
        data.push({ name: 'items[' + i + '][quantity]',     value: item.quantity });
        data.push({ name: 'items[' + i + '][unit]',         value: item.unit });
        data.push({ name: 'items[' + i + '][unit_price]',   value: item.unit_price });
        data.push({ name: 'items[' + i + '][tax_percent]',  value: item.tax_percent });
    });
    $.post(APP_URL + '/api/operations/save_ipc.php', $.param(data), function(res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('ipcAddModal')).hide();
            $('#ipcAddForm')[0].reset();
            $('#ipcAddItemsBody').html(ipcNewItemRow('add', {}, 1));
            $('#ipc_add_subtotal, #ipc_add_tax, #ipc_add_gross, #ipc_add_net').text('0.00');
            $('#ipc_add_retention_display').text('- 0.00');
            $('#ipc_add_customer').val('');
            ipcFilterSO('', 'add');
            ipcLoadTable();
            Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 2000, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    }, 'json');
}

