function openApplyLeaveModal() {
    $('#applyLeaveModalTitle').html('<i class="bi bi-calendar-x me-2"></i>Apply for Leave');
    $('#applyLeaveForm')[0].reset();
    $('#lv_leave_id').val('');
    $('#lv_status').val('pending');
    $('#lv_documentSection').hide();
    $('#lv_balanceInfo').html('<p class="text-muted mb-0 small">Select a staff member and leave type to view balance.</p>');
    loadProjectStaffDropdown('#lv_employee_id');
    loadProjectStaffDropdown('#lv_handover_to');
    $('#applyLeaveModal').modal('show');
}

function openEditLeaveModal(id) {
    $.getJSON(APP_URL + '/api/operations/get_project_leaves.php', {
        project_id: projectId, date_from: '2000-01-01', date_to: '2099-12-31'
    }, function(res) {
        const r = (res.data || []).find(x => x.leave_id == id);
        if (!r) return;
        $('#applyLeaveModalTitle').html('<i class="bi bi-pencil me-2"></i>Edit Leave');
        $('#applyLeaveForm')[0].reset();
        $('#lv_documentSection').hide();
        loadProjectStaffDropdown('#lv_employee_id', r.employee_id);
        loadProjectStaffDropdown('#lv_handover_to');
        $('#lv_leave_id').val(r.leave_id);
        $('#lv_type').val(r.leave_type);
        $('#lv_start_date').val(r.start_date);
        $('#lv_end_date').val(r.end_date);
        $('#lv_total_days').val(r.total_days);
        $('#lv_reason').val(r.reason);
        $('#lv_status').val(r.status);
        $('#lv_notes').val(r.notes || '');
        lvUpdateLeaveTypeInfo();
        lvUpdateLeaveBalance();
        $('#applyLeaveModal').modal('show');
    });
}

function viewLeaveRecord(id) {
    $('#viewLeaveModal').modal('show');
    $('#viewLeaveBody').html('<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>');
    $.getJSON(APP_URL + '/api/operations/get_project_leaves.php', {
        project_id: projectId, date_from: '2000-01-01', date_to: '2099-12-31'
    }, function(res) {
        const r = (res.data || []).find(x => x.leave_id == id);
        if (!r) { $('#viewLeaveBody').html('<p class="text-danger">Record not found.</p>'); return; }
        const statusBadge = { pending: 'bg-warning text-dark', approved: 'bg-success', rejected: 'bg-danger', cancelled: 'bg-secondary', taken: 'bg-info' };
        const badge = statusBadge[r.status] || 'bg-secondary';
        const typeLabel = { annual: 'Annual Leave', sick: 'Sick Leave', maternity: 'Maternity Leave', paternity: 'Paternity Leave', study: 'Study Leave', unpaid: 'Unpaid Leave', other: 'Other' };
        $('#viewLeaveBody').html(`
            <div class="text-center mb-4">
                <div class="bg-primary bg-opacity-10 text-primary rounded-circle mx-auto mb-3" style="width:64px;height:64px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-calendar-x fs-2"></i></div>
                <h5 class="fw-bold mb-1">${r.first_name} ${r.last_name}</h5>
                <span class="badge ${badge}">${r.status.charAt(0).toUpperCase() + r.status.slice(1)}</span>
            </div>
            <div class="card bg-light border-0" style="border-radius:12px;">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6"><small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.7rem;">Leave Type</small><strong>${typeLabel[r.leave_type] || r.leave_type}</strong></div>
                        <div class="col-6"><small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.7rem;">Employee #</small><strong>${r.employee_number}</strong></div>
                        <div class="col-6"><small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.7rem;">Start Date</small><strong>${r.start_date}</strong></div>
                        <div class="col-6"><small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.7rem;">End Date</small><strong>${r.end_date}</strong></div>
                        <div class="col-6"><small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.7rem;">Total Days</small><strong>${r.total_days}</strong></div>
                        <div class="col-6"><small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.7rem;">Department</small><strong>${r.department_name || '—'}</strong></div>
                        <div class="col-12"><small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.7rem;">Reason</small><strong>${r.reason}</strong></div>
                        ${r.notes ? '<div class="col-12"><small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.7rem;">Notes</small><strong>' + r.notes + '</strong></div>' : ''}
                    </div>
                </div>
            </div>`);
    });
}

function updateLeaveStatus(id, status) {
    const label = status.charAt(0).toUpperCase() + status.slice(1);
    Swal.fire({ title: label + ' Leave?', icon: 'question', showCancelButton: true, confirmButtonColor: status === 'approved' ? '#28a745' : '#d33', confirmButtonText: 'Yes, ' + label })
    .then(r => {
        if (r.isConfirmed) {
            const apiMap = { approved: '/api/approve_leave.php', rejected: '/api/reject_leave.php', cancelled: '/api/cancel_leave.php' };
            const url = apiMap[status] || '/api/approve_leave.php';
            $.post(APP_URL + url, { leave_id: id }, function(res) {
                if (res.success) { Swal.fire({ icon: 'success', title: label + '!', timer: 1200, showConfirmButton: false }); loadProjectLeaves(); }
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

function deleteLeaveRecord(id, name) {
    Swal.fire({ title: 'Delete Leave?', text: 'Delete leave record for ' + name + '?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Delete' })
    .then(r => {
        if (r.isConfirmed) {
            $.post(APP_URL + '/api/delete_leave.php', { leave_id: id }, function(res) {
                if (res.success) { Swal.fire('Deleted!', 'Leave record deleted.', 'success'); loadProjectLeaves(); }
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

$('#applyLeaveForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#btnSaveLeave');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    const fd = new FormData(this);
    fd.append('project_id', projectId);
    $.ajax({
        url: APP_URL + '/api/operations/save_project_leave.php',
        type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Submit Leave Application');
            if (res.success) {
                $('#applyLeaveModal').modal('hide');
                Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 1500, showConfirmButton: false });
                loadProjectLeaves();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Submit Leave Application');
            Swal.fire('Error', 'Request failed.', 'error');
        }
    });
});

// ============================================================
// HR: PAYROLL
// ============================================================
function loadProjectPayroll() {
    const period = $('#prPeriodFilter').val() || new Date().toISOString().substring(0, 7);
    const status = $('#prStatusFilter').val();

    const d = new Date(period + '-01');
    $('#payrollPrintPeriod').text('Period: ' + d.toLocaleString('default', { month: 'long', year: 'numeric' }));
    $('#hrPayrollContent').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');

    $.getJSON(APP_URL + '/api/operations/get_project_payroll.php', {
        project_id: projectId, period: period, status: status
    }, function(res) {
        if (res.success) {
            const s = res.stats;
            $('#prStatActive').text(s.active || 0);
            $('#prStatPaid').text(s.paid || 0);
            $('#prStatPending').text(s.pending || 0);
            $('#prStatTotal').text(formatMoney(s.total_payout || 0) + ' TZS');
            renderProjectPayroll(res.data);
        } else {
            $('#hrPayrollContent').html('<div class="alert alert-danger">' + res.message + '</div>');
        }
    }).fail(function() {
        $('#hrPayrollContent').html('<div class="alert alert-danger">Failed to load payroll data.</div>');
    });
}

function renderProjectPayroll(data) {
    if (!data || data.length === 0) {
        $('#hrPayrollContent').html(`
            <div class="text-center py-5 text-muted border rounded" style="border-radius:12px;">
                <i class="bi bi-cash-coin display-4 opacity-25"></i>
                <p class="mt-2">No payroll records found for the selected period.</p>
                <button class="btn btn-sm btn-primary" onclick="openProcessPayrollModal()"><i class="bi bi-gear me-1"></i> Process Payroll</button>
            </div>`);
        return;
    }

    const statusBadge = { pending: 'bg-warning text-dark', approved: 'bg-info', paid: 'bg-success', cancelled: 'bg-danger', processing: 'bg-secondary' };

    let html = `<div class="table-responsive">
        <table class="table table-hover align-middle border" style="border-radius:12px; overflow:hidden;">
            <thead class="table-light">
                <tr>
                    <th class="text-center" width="50">S/NO</th>
                    <th>Staff Member</th>
                    <th>Department</th>
                    <th class="text-end">Basic Salary</th>
                    <th class="text-end">Gross</th>
                    <th class="text-end">Deductions</th>
                    <th class="text-end">Net Salary</th>
                    <th class="text-center">Status</th>
                    <th class="text-end d-print-none">Actions</th>
                </tr>
            </thead><tbody>`;

    data.forEach((r, i) => {
        const name   = r.first_name + ' ' + r.last_name;
        const s      = r.payment_status || r.status;
        const badge  = statusBadge[s] || 'bg-secondary';
        const stat   = s.charAt(0).toUpperCase() + s.slice(1);
        html += `<tr>
            <td class="text-center text-muted small">${i + 1}</td>
            <td>
                <div class="fw-bold text-dark">${name}</div>
                <small class="badge bg-light text-primary border border-primary-subtle" style="font-size:0.65rem;">${r.employee_number}</small>
            </td>
            <td><small class="text-muted">${r.department_name || '—'}</small></td>
            <td class="text-end"><small>${formatMoney(r.basic_salary)} TZS</small></td>
            <td class="text-end"><small>${formatMoney(r.gross_salary)} TZS</small></td>
            <td class="text-end"><small class="text-danger">${formatMoney(r.deductions)} TZS</small></td>
            <td class="text-end fw-bold">${formatMoney(r.net_salary)} TZS</td>
            <td class="text-center"><span class="badge ${badge}">${stat}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="viewPayrollRecord(${r.payroll_id})"><i class="bi bi-receipt text-info me-2"></i>View Payslip</a></li>
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="openEditPayrollModal(${r.payroll_id})"><i class="bi bi-pencil text-warning me-2"></i>Edit</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deletePayrollRecord(${r.payroll_id}, '${name}')"><i class="bi bi-trash me-2"></i>Delete</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    $('#hrPayrollContent').html(html);
    initHrDropdowns('#hrPayrollContent');
}

function prPreviewPayroll() {
    const period = $('#pr_period').val();
    if (!period) return;
    const fd = new FormData($('#processPayrollForm')[0]);
    fd.set('project_id', projectId);
    $('#prPayrollPreview').show();
    $('#prPreviewBody').html('<tr><td colspan="5" class="text-center py-3"><div class="spinner-border spinner-border-sm text-success me-2"></div>Calculating...</td></tr>');
    $.ajax({
        url: APP_URL + '/api/operations/preview_project_payroll.php',
        type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            if (res.success && res.data.length > 0) {
                let html = '';
                res.data.forEach(function(emp) {
                    html += `<tr>
                        <td class="fw-bold">${emp.employee_name}</td>
                        <td class="text-end">${parseFloat(emp.basic_salary).toLocaleString()}</td>
                        <td class="text-end text-success">+${parseFloat(emp.allowances).toLocaleString()}</td>
                        <td class="text-end text-danger">-${parseFloat(emp.deductions).toLocaleString()}</td>
                        <td class="text-end fw-bold text-success">${parseFloat(emp.net_salary).toLocaleString()}</td>
                    </tr>`;
                });
                $('#prPreviewBody').html(html);
                $('#prPreviewCount').text(res.data.length + ' Employees');
            } else {
                $('#prPreviewBody').html(`<tr><td colspan="5" class="text-center py-3 text-muted">${res.message || 'No staff found.'}</td></tr>`);
                $('#prPreviewCount').text('0 Employees');
            }
        },
        error: function() {
            $('#prPreviewBody').html('<tr><td colspan="5" class="text-center py-3 text-danger">Failed to load preview.</td></tr>');
        }
    });
}

function openProcessPayrollModal() {
    $('#processPayrollForm')[0].reset();
    const today = new Date().toISOString().split('T')[0];
    $('#pr_period').val(today.substring(0, 7));
    $('#pr_ref_date').val(today);
    $('#pr_allowances').prop('checked', true);
    $('#pr_deductions').prop('checked', true);
    $('#prPayrollPreview').hide();
    $('#process-payroll-message').html('');
    $('#processPayrollModal').modal('show');
}

$('#processPayrollForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#btnProcessPayroll');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Processing...');
    const fd = new FormData(this);
    fd.append('project_id', projectId);
    $.ajax({
        url: APP_URL + '/api/operations/process_project_payroll.php',
        type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="bi bi-check2-circle me-2"></i>Execute Final Processing');
            if (res.success) {
                $('#processPayrollModal').modal('hide');
                Swal.fire({ icon: 'success', title: 'Done!', text: res.message, confirmButtonColor: '#28a745' });
                loadProjectPayroll();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            btn.prop('disabled', false).html('<i class="bi bi-check2-circle me-2"></i>Execute Final Processing');
            Swal.fire('Error', 'Request failed.', 'error');
        }
    });
});

function openEditPayrollModal(id) {
    $.getJSON(APP_URL + '/api/operations/get_project_payroll.php', {
        project_id: projectId, period: $('#prPeriodFilter').val() || new Date().toISOString().substring(0, 7)
    }, function(res) {
        const r = (res.data || []).find(x => x.payroll_id == id);
        if (!r) return;
        $('#ep_payroll_id').val(r.payroll_id);
        $('#ep_staff_name').val(r.first_name + ' ' + r.last_name);
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $('#ep_period_display').val((months[r.month - 1] || r.month) + ' ' + r.year);
        $('#ep_basic_salary').val(r.basic_salary);
        $('#ep_allowances').val(r.allowances);
        $('#ep_deductions').val(r.deductions);
        $('#ep_tax_amount').val(r.tax_amount || 0);
        $('#ep_payment_method').val(r.payment_method || 'bank');
        $('#ep_status').val(r.payment_status || 'pending');
        $('#ep_notes').val(r.notes || '');
        // compute initial net preview
        const net = (parseFloat(r.basic_salary)||0) + (parseFloat(r.allowances)||0) - (parseFloat(r.deductions)||0) - (parseFloat(r.tax_amount)||0);
        $('#ep_net_preview').text(net.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' TZS');
        $('#editPayrollModal').modal('show');
    });
}

$(document).on('input change', '.ep-calc', function() {
    const basic  = parseFloat($('#ep_basic_salary').val()) || 0;
    const allow  = parseFloat($('#ep_allowances').val())   || 0;
    const deduct = parseFloat($('#ep_deductions').val())   || 0;
    const tax    = parseFloat($('#ep_tax_amount').val())   || 0;
    const net    = basic + allow - deduct - tax;
    $('#ep_net_preview').text(net.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' TZS');
});

$('#editPayrollForm').on('submit', function(e) {
    e.preventDefault();
    $.post(APP_URL + '/api/update_payroll.php', $(this).serialize(), function(res) {
        if (res.success) {
            $('#editPayrollModal').modal('hide');
            Swal.fire({ icon: 'success', title: 'Updated!', text: res.message, timer: 1500, showConfirmButton: false });
            loadProjectPayroll();
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
});

function viewPayrollRecord(id) {
    $('#viewPayrollModal').modal('show');
    $('#viewPayrollBody').html('<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>');
    $('#printPayslipBtn').attr('href', APP_URL + '/payslip?id=' + id);
    $.getJSON(APP_URL + '/api/operations/get_project_payroll.php', {
        project_id: projectId, period: $('#prPeriodFilter').val() || new Date().toISOString().substring(0, 7)
    }, function(res) {
        const r = (res.data || []).find(x => x.payroll_id == id);
        if (!r) { $('#viewPayrollBody').html('<p class="text-danger">Record not found.</p>'); return; }
        const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        const period = (months[r.month - 1] || r.month) + ' ' + r.year;
        const s = r.payment_status || r.status;
        const statusBadge = { pending: 'bg-warning text-dark', approved: 'bg-info text-white', paid: 'bg-success text-white', cancelled: 'bg-danger text-white' };
        const badge = statusBadge[s] || 'bg-secondary text-white';
        const totalDeductions = (parseFloat(r.tax_amount) || 0) + (parseFloat(r.deductions) || 0);
        $('#viewPayrollBody').html(`
            <div class="text-center mb-3 pb-3 border-bottom">
                <h5 class="fw-bold mb-1">${r.first_name} ${r.last_name}</h5>
                <small class="text-muted">${r.employee_number} &bull; ${r.department_name || '—'}</small><br>
                <span class="badge ${badge} mt-1">${s.charAt(0).toUpperCase() + s.slice(1)}</span>
            </div>
            <div class="row g-3 mb-3 text-center border-bottom pb-3">
                <div class="col-4">
                    <div class="small text-muted text-uppercase fw-bold" style="font-size:0.65rem;">Payroll #</div>
                    <div class="fw-semibold small">${r.payroll_number || '—'}</div>
                </div>
                <div class="col-4">
                    <div class="small text-muted text-uppercase fw-bold" style="font-size:0.65rem;">Period</div>
                    <div class="fw-semibold small">${period}</div>
                </div>
                <div class="col-4">
                    <div class="small text-muted text-uppercase fw-bold" style="font-size:0.65rem;">Department</div>
                    <div class="fw-semibold small">${r.department_name || '—'}</div>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6 pe-3">
                    <div class="small text-muted text-uppercase fw-bold mb-2" style="font-size:0.65rem;">Earnings</div>
                    <table class="table table-sm">
                        <thead><tr><th style="font-size:0.75rem;color:#444;">Description</th><th class="text-end" style="font-size:0.75rem;color:#444;">Amount</th></tr></thead>
                        <tbody>
                            <tr><td class="small">Basic Salary</td><td class="text-end small">${formatMoney(r.basic_salary)}</td></tr>
                            <tr><td class="small">Allowances</td><td class="text-end small">${formatMoney(r.allowances)}</td></tr>
                        </tbody>
                        <tfoot><tr style="background:#fcfcfc;font-weight:700;"><td class="small">Gross Earnings</td><td class="text-end small">${formatMoney(r.gross_salary)}</td></tr></tfoot>
                    </table>
                </div>
                <div class="col-6 ps-3">
                    <div class="small text-muted text-uppercase fw-bold mb-2" style="font-size:0.65rem;">Deductions</div>
                    <table class="table table-sm">
                        <thead><tr><th style="font-size:0.75rem;color:#444;">Description</th><th class="text-end" style="font-size:0.75rem;color:#444;">Amount</th></tr></thead>
                        <tbody>
                            <tr><td class="small">Income Tax (PAYE)</td><td class="text-end small">${formatMoney(r.tax_amount)}</td></tr>
                            <tr><td class="small">General Deductions</td><td class="text-end small">${formatMoney(r.deductions)}</td></tr>
                        </tbody>
                        <tfoot><tr style="background:#fcfcfc;font-weight:700;" class="text-danger"><td class="small">Total Deductions</td><td class="text-end small">${formatMoney(totalDeductions)}</td></tr></tfoot>
                    </table>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center p-3 rounded-3" style="background:#f8f9ff;border:1px solid #eef0ff;">
                <div>
                    <div class="fw-bold text-primary">Net Salary Distributed</div>
                    <div class="text-muted small">Period: ${period}</div>
                </div>
                <div class="text-end">
                    <div class="fw-bold fs-4 text-primary">TSh ${formatMoney(r.net_salary)}</div>
                </div>
            </div>`);
    });
}

function deletePayrollRecord(id, name) {
    Swal.fire({ title: 'Delete Record?', text: 'Delete payroll record for ' + name + '?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Delete' })
    .then(r => {
        if (r.isConfirmed) {
            $.post(APP_URL + '/api/delete_payroll.php', { payroll_id: id }, function(res) {
                if (res.success) { Swal.fire('Deleted!', 'Payroll record deleted.', 'success'); loadProjectPayroll(); }
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

// ============================================================
// HR SHARED: load project staff into a dropdown
// ============================================================
function loadProjectStaffDropdown(selector, selectedId) {
    const $sel = $(selector);
    function reinitS2() {
        if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
        $sel.select2({
            theme: 'bootstrap-5',
            dropdownParent: $sel.closest('.modal'),
            placeholder: 'Select Staff',
            allowClear: true,
            width: '100%'
        });
        if (selectedId) $sel.val(selectedId).trigger('change.select2');
    }
    $sel.html('<option value="">Loading...</option>');
    if (projectData && projectData.staff && projectData.staff.length > 0) {
        let opts = '<option value="">Select Staff</option>';
        projectData.staff.forEach(s => {
            const sel = (selectedId && s.employee_id == selectedId) ? ' selected' : '';
            opts += `<option value="${s.employee_id}"${sel}>${s.first_name} ${s.last_name} (${s.employee_number})</option>`;
        });
        $sel.html(opts);
        reinitS2();
    } else {
        $.getJSON(APP_URL + '/api/operations/get_project.php', { id: projectId }, function(res) {
            let opts = '<option value="">Select Staff</option>';
            (res.staff || []).forEach(s => {
                const sel = (selectedId && s.employee_id == selectedId) ? ' selected' : '';
                opts += `<option value="${s.employee_id}"${sel}>${s.first_name} ${s.last_name} (${s.employee_number})</option>`;
            });
            $sel.html(opts);
            reinitS2();
        });
    }
}

// Footer text: "This report was" only when the Reports tab (#performance) is active.
// All other tabs keep "This document was". Restored after every print/export.
function _bmsSetFooterContext() {
    var tab = document.getElementById('performance');
    var line1 = document.querySelector('.bms-print-footer .bpf-line1');
    if (!line1) return;
    var isReports = tab && (tab.classList.contains('show') || tab.classList.contains('active'));
    var want = isReports ? 'This report was' : 'This document was';
    line1.childNodes.forEach(function (n) {
        if (n.nodeType === 3) {
            n.textContent = n.textContent.replace('This report was', 'This document was').replace('This document was', want);
        }
    });
}
window.addEventListener('beforeprint', _bmsSetFooterContext);
window.addEventListener('afterprint', function () {
    var line1 = document.querySelector('.bms-print-footer .bpf-line1');
    if (!line1) return;
    line1.childNodes.forEach(function (n) {
        if (n.nodeType === 3) n.textContent = n.textContent.replace('This report was', 'This document was');
    });
});

