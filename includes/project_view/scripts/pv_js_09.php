function filterMilestoneLevels(type, levelLimit, btn) {
    let tableId = '';
    let rowClass = '';
    let caretClass = '';
    
    if (type === 'milestones') {
        tableId = '#milestonesTable'; rowClass = '.milestone-row'; caretClass = '.toggle-milestone-subtasks i';
    } else if (type === 'reporting') {
        tableId = '#reportingTable'; rowClass = '.reporting-row'; caretClass = '.toggle-reporting-subtasks i';
    } else if (type === 'performance') {
        tableId = '#performanceTable'; rowClass = '.perf-row'; caretClass = '.toggle-perf-subtasks i';
    }

    // Toggle button active state within its specific tab
    $(`.btn-milestone-filter[data-type="${type}"]`).removeClass('active');
    $(btn).addClass('active');

    if (levelLimit === 'all') {
        $(tableId + ' ' + rowClass).show();
        $(tableId).find(caretClass)
            .removeClass('bi-caret-right-fill')
            .addClass('bi-caret-down-fill');
    } else {
        $(tableId + ' ' + rowClass).each(function() {
            const level = parseInt($(this).attr('data-level')) || 0;
            if (level > levelLimit) {
                $(this).hide();
            } else {
                $(this).show();
            }
        });
        // Collapse main milestones icons if showing only main
        $(tableId + ' ' + rowClass + '[data-level="0"]').find(caretClass)
            .removeClass('bi-caret-down-fill')
            .addClass('bi-caret-right-fill');
    }
    
    // Reindex S/NOs
    if (type === 'milestones') reindexMilestones();
    if (type === 'reporting') reindexReporting();
    if (type === 'performance') reindexPerformance();
}

// Create Voucher Form Submit (FormData for File Upload)
$(document).on('submit', '#createVoucherForm', function(e) {
    e.preventDefault();
    
    // Validation: Check if amount exceeds remaining for specific expense
    const opt = $('#vc_expense_id option:selected');
    if (opt.data('type') === 'expense') {
        const id = opt.data('id');
        const total = parseFloat(opt.data('amount')) || 0;
        const paid = (projectData.payment_vouchers || [])
            .filter(v => v.expense_id == id && ['approved', 'paid'].includes(v.status))
            .reduce((sum, v) => sum + parseFloat(v.amount), 0);
        const remaining = total - paid;
        const inputAmount = parseFloat($('#vc_amount').val()) || 0;
        
        if (inputAmount > (remaining + 0.01)) { // Small buffer for floats
            $('#vc_amount_validation').text(`⚠️ Overpayment alert! Maximum allowed for this item is ${formatMoney(remaining)} TZS.`).show();
            Swal.fire('Validation Error', 'The payment amount exceeds the remaining balance of the selected expense.', 'warning');
            return;
        }
    }

    const $btn = $('#btnSaveVoucher');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing...');

    // Extract type-specific ID safely
    const val = $('#vc_expense_id').val();
    const formData = new FormData(this);
    
    if (val.startsWith('exp_')) {
        formData.set('expense_id', val.replace('exp_', ''));
    } else {
        formData.set('expense_id', '');
    }
    
    // Use ajax for FormData
    $.ajax({
        url: '/api/account/save_voucher.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $('#createVoucherModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Voucher Created!',
                    text: res.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => loadProjectDetails());
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Server communication failure.', 'error');
        },
        complete: function() {
            $btn.prop('disabled', false).html('<i class="bi bi-check-all me-1"></i> Confirm & Generate Voucher');
        }
    });
});

// Register Project Supplier Form Submit
$(document).on('submit', '#addProjectSupplierForm', function(e) {
    e.preventDefault();
    const $btn = $('#btnSaveProjectSupplier');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Registering...');

    const formData = new FormData(this);
    $.ajax({
        url: APP_URL + '/api/add_supplier.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $('#addProjectSupplierModal').modal('hide');
                $('#addProjectSupplierForm')[0].reset();
                Swal.fire({
                    icon: 'success',
                    title: 'Supplier Registered!',
                    text: res.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => loadProjectDetails());
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Server communication failure.', 'error');
        },
        complete: function() {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Register Supplier');
        }
    });
});

function renderProjectSuppliers(suppliers) {
    const $list = $('#projectSuppliersTable');
    if (!suppliers || suppliers.length === 0) {
        $list.html(`
            <div class="py-5 text-center text-muted border rounded bg-light-soft" style="border-radius: 12px;">
                <div class="stats-icon bg-light text-muted mx-auto mb-3" style="width: 70px; height: 70px; font-size: 2.5rem;">
                    <i class="bi bi-truck"></i>
                </div>
                <h6 class="text-muted fw-bold">No suppliers registered for this project yet.</h6>
                <p class="small text-muted mb-0">Link your procurement sources directly to this project.</p>
            </div>
        `);
        return;
    }

    let html = `
        <div class="table-responsive">
            <table id="projSuppliersTable" class="table table-hover align-middle border" style="border-radius: 12px;">
                <thead class="table-light">
                    <tr>
                        <th width="50" class="text-center">S/NO</th>
                        <th>Supplier Name</th>
                        <th>Contact</th>
                        <th>Category</th>
                        <th>Email / Phone</th>
                        <th class="text-center">Status</th>
                        <th class="text-end d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>`;

    suppliers.forEach((s, idx) => {
        const contact = s.contact_person ? `<div>${s.contact_person}</div>` : '';
        const email = s.email ? `<div><small class="text-muted"><i class="bi bi-envelope me-1"></i>${s.email}</small></div>` : '';
        const phone = s.phone ? `<div><small class="text-muted"><i class="bi bi-telephone me-1"></i>${s.phone}</small></div>` : '';
        
        html += `
            <tr>
                <td class="text-center text-muted small">${idx + 1}</td>
                <td>
                    <div class="fw-bold text-dark">${s.supplier_name}</div>
                    <small class="badge bg-light text-primary border border-primary-subtle" style="font-size: 0.65rem;">${s.supplier_code}</small>
                </td>
                <td>${contact || '<em class="text-muted small">N/A</em>'}</td>
                <td><span class="badge bg-info-soft text-info border border-info-subtle small text-uppercase">${s.category_name || 'General'}</span></td>
                <td>${email}${phone}</td>
                <td class="text-center">
                    <span class="badge bg-${getStatusBadgeColor(s.status)}">${s.status.toUpperCase()}</span>
                </td>
                <td class="text-end d-print-none">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 220px; max-height: 350px; overflow-y: auto;">
                            <li class="dropdown-header text-uppercase small fw-bold">Management</li>
                            <li><a class="dropdown-item py-2" href="suppliers/details?id=${s.supplier_id}&project=<?= $project_id ?>&back=suppliers"><i class="bi bi-eye text-info me-2"></i>View Details</a></li>
                            <li><a class="dropdown-item py-2" href="<?= getUrl('suppliers') ?>?edit=${s.supplier_id}&project=<?= $project_id ?>&back=suppliers"><i class="bi bi-pencil text-primary me-2"></i>Edit Supplier</a></li>
                            <li><a class="dropdown-item py-2" href="purchase_orders?supplier=${s.supplier_id}"><i class="bi bi-cart text-success me-2"></i>View Orders</a></li>
                           
                            
                            <li><hr class="dropdown-divider"></li>
                            <li class="dropdown-header text-uppercase small fw-bold">Procurement</li>
                            <li><a class="dropdown-item py-2 text-success fw-bold" href="purchase_order_create?supplier=${s.supplier_id}&project=${projectId}"><i class="bi bi-file-plus me-2"></i>New Order</a></li>
                            
                            <li><hr class="dropdown-divider"></li>
                            <li class="dropdown-header text-uppercase small fw-bold">Status Action</li>
                            ${s.status === 'active' ? 
                                `<li><a class="dropdown-item py-2 text-warning" href="javascript:void(0)" onclick="updateProjectSupplierStatus(${s.supplier_id}, 'inactive')"><i class="bi bi-pause-circle me-2"></i>Deactivate</a></li>` : 
                                `<li><a class="dropdown-item py-2 text-success" href="javascript:void(0)" onclick="updateProjectSupplierStatus(${s.supplier_id}, 'active')"><i class="bi bi-play-circle me-2"></i>Activate</a></li>`
                            }
                            ${s.status !== 'suspended' ? 
                                `<li><a class="dropdown-item py-2 text-warning" href="javascript:void(0)" onclick="updateProjectSupplierStatus(${s.supplier_id}, 'suspended')"><i class="bi bi-exclamation-triangle me-2"></i>Suspend</a></li>` : ''
                            }
                            ${s.status !== 'blacklisted' ? 
                                `<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="updateProjectSupplierStatus(${s.supplier_id}, 'blacklisted')"><i class="bi bi-ban me-2"></i>Blacklist</a></li>` : ''
                            }
                            
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-warning" href="javascript:void(0)" onclick="confirmDeleteSupplier(${s.supplier_id})"><i class="bi bi-link-45deg me-2"></i>Remove Project Link</a></li>
                            <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="confirmActualDeleteSupplier(${s.supplier_id})"><i class="bi bi-trash me-2"></i>Delete Supplier</a></li>
                        </ul>
                    </div>
                </td>
            </tr>`;
    });

    html += `
                </tbody>
            </table>
        </div>`;
    $list.html(html);
    if ($.fn.DataTable.isDataTable('#projSuppliersTable')) $('#projSuppliersTable').DataTable().destroy();
    $('#projSuppliersTable').DataTable({ responsive: true, pageLength: 25, autoWidth: false, columnDefs: [{ orderable: false, targets: [0, 6] }] });
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('projSuppliersTable');
}

function confirmDeleteSupplier(id) {
    Swal.fire({
        title: 'Remove Project Link?',
        text: "This will remove the direct link between this supplier and this project. The supplier record itself will remain in the system for general use.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f39c12',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, remove link'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/update_supplier.php', {
                supplier_id: id,
                project_id: '' // Unlink by clearing the project_id
            }, function(res) {
                if (res.success) {
                    Swal.fire('Unlinked!', 'The supplier is no longer specifically linked to this project.', 'success');
                    loadProjectDetails();
                } else {
                    Swal.fire('Error', res.message || 'Action failed', 'error');
                }
            }, 'json');
        }
    });
}

function confirmActualDeleteSupplier(id) {
    Swal.fire({
        title: 'Delete Supplier Permanently?',
        text: "Are you sure? This will completely remove the supplier from the entire system. This action cannot be undone!",
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, delete permanently!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/delete_supplier.php', {
                supplier_id: id
            }, function(res) {
                if (res.success) {
                    Swal.fire('Deleted!', res.message, 'success');
                    loadProjectDetails();
                } else {
                    Swal.fire('Error', res.message || 'Delete failed', 'error');
                }
            }, 'json');
        }
    });
}

function editProjectSupplier(id) {
    $.getJSON(APP_URL + '/api/get_supplier.php', { id: id }, function(res) {
        if (res.success) {
            const s = res.data;
            $('#eps_supplier_id').val(s.supplier_id);
            $('#eps_supplier_name').val(s.supplier_name);
            $('#eps_company_name').val(s.company_name);
            $('#eps_category_id').val(s.category_id);
            $('#eps_status').val(s.status);
            $('#eps_description').val(s.description);
            
            $('#eps_contact_person').val(s.contact_person);
            $('#eps_contact_title').val(s.contact_title);
            $('#eps_email').val(s.email);
            $('#eps_phone').val(s.phone);
            $('#eps_mobile').val(s.mobile);
            $('#eps_website').val(s.website);
            
            $('#eps_address').val(s.address);
            $('#eps_postal_code').val(s.postal_code);
            // Location cascade prefill — matches stored names to the defined lists;
            // unmatched legacy values are preserved as extra options.
            if (window.editSupplierCascade) window.editSupplierCascade.setValues({
                country:  s.country || 'Tanzania',
                region:   s.state || '',
                district: s.city || '',
                ward:     s.ward || '',
                village:  s.village || ''
            });
            
            $('#eps_tax_id').val(s.tax_id);
            $('#eps_vat_number').val(s.vat_number);
            $('#eps_payment_terms').val(s.payment_terms);
            $('#eps_currency').val(s.currency);
            $('#eps_bank_name').val(s.bank_name);
            $('#eps_bank_account').val(s.bank_account);
            $('#eps_project_id').val(s.project_id || projectId);

            $('#editProjectSupplierModal').modal('show');
            // Reset to first tab
            $('#editSupplierTabs button:first').tab('show');
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    });
}

$(document).on('submit', '#editProjectSupplierForm', function(e) {
    e.preventDefault();
    const $btn = $('#btnUpdateProjectSupplier');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Updating...');

    $.ajax({
        url: APP_URL + '/api/update_supplier.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $('#editProjectSupplierModal').modal('hide');
                Swal.fire('Updated!', 'Supplier profile has been updated.', 'success');
                loadProjectDetails();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Server communication failure.', 'error');
        },
        complete: function() {
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Update Profile');
        }
    });
});


function updateProjectSupplierStatus(id, status) {
    const actionMap = {
        'active': 'activate',
        'inactive': 'deactivate',
        'suspended': 'suspend',
        'blacklisted': 'blacklist'
    };
    const action = actionMap[status] || 'update';

    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${action} this supplier?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#aaa',
        confirmButtonText: `Yes, ${action} it!`
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/update_supplier_status.php', {
                supplier_id: id,
                status: status
            }, function(res) {
                if (res.success) {
                    Swal.fire('Updated!', res.message, 'success');
                    loadProjectDetails();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function initHrDropdowns(containerId) {
    if (typeof bootstrap === 'undefined') return;
    $(containerId).find('[data-bs-toggle="dropdown"]').each(function() {
        var inst = bootstrap.Dropdown.getInstance(this);
        if (inst) inst.dispose();
        new bootstrap.Dropdown(this, { popperConfig: { strategy: 'fixed' } });
    });
}

function renderProjectStaff(staff) {
    const $list = $('#projectStaffTable');
    if (!staff || staff.length === 0) {
        $list.html(`
            <div class="py-5 text-center text-muted border rounded bg-light-soft" style="border-radius: 12px;">
                <div class="stats-icon bg-light text-muted mx-auto mb-3" style="width: 70px; height: 70px; font-size: 2.5rem;">
                    <i class="bi bi-people"></i>
                </div>
                <h6 class="text-muted fw-bold">No staff assigned to this project yet.</h6>
                <p class="small text-muted mb-0">Assign existing employees or create new ones for this project.</p>
                <div class="mt-3">
                    <button class="btn btn-sm btn-primary" onclick="openAssignStaffModal()">Assign Existing Staff</button>
                    <a class="btn btn-sm btn-success" href="<?= getUrl('employees') ?>?action=new&project=<?= $project_id ?>&back=staff">Create New Staff Member</a>
                </div>
            </div>
        `);
        return;
    }

    let html = `
        <div class="table-responsive">
            <table class="table table-hover align-middle border" style="border-radius: 12px; overflow: hidden;">
                <thead class="table-light">
                    <tr>
                        <th width="50" class="text-center">S/NO</th>
                        <th><i class="bi bi-people me-2"></i>Staff Member</th>
                        <th>Department / Role</th>
                        <th>Contact Info</th>
                        <th class="text-end d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>`;

    staff.forEach((s, idx) => {
        const name = `${s.first_name} ${s.last_name}`;
        const email = s.email ? `<div><small class="text-muted"><i class="bi bi-envelope me-1"></i>${s.email}</small></div>` : '';
        const phone = s.phone ? `<div><small class="text-muted"><i class="bi bi-telephone me-1"></i>${s.phone}</small></div>` : '';
        
        html += `
            <tr>
                <td class="text-center text-muted small">${idx + 1}</td>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="stats-icon bg-light-soft text-primary me-2" style="width: 32px; height: 32px; font-size: 0.9rem;">
                            <i class="bi bi-person-fill"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">${name}</div>
                            <small class="badge bg-light text-primary border border-primary-subtle" style="font-size: 0.65rem;">${s.employee_number}</small>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="text-dark">${s.designation_name || 'Staff'}</div>
                    <div class="small text-muted">${s.department_name || 'General'}</div>
                </td>
                <td>${email}${phone}</td>
                <td class="text-end d-print-none">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <li class="dropdown-header text-uppercase small fw-bold">Staff Actions</li>
                            <li><a class="dropdown-item py-2" href="employee_details?id=${s.employee_id}&project=<?= $project_id ?>&back=staff"><i class="bi bi-eye text-info me-2"></i>View Details</a></li>
                            <li><a class="dropdown-item py-2" href="<?= getUrl('employees') ?>?edit_id=${s.employee_id}&project=<?= $project_id ?>&back=staff"><i class="bi bi-pencil text-primary me-2"></i>Edit</a></li>
                            <li><a class="dropdown-item py-2" href="payroll?employee=${s.employee_id}"><i class="bi bi-cash-coin text-success me-2"></i>Payroll</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-warning" href="javascript:void(0)" onclick="unassignStaff(${s.employee_id}, '${name}')"><i class="bi bi-person-dash me-2"></i>Remove from Project</a></li>
                            <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteProjectStaff(${s.employee_id}, '${name}')"><i class="bi bi-slash-circle me-2"></i>Inactivate Staff</a></li>
                        </ul>
                    </div>
                </td>
            </tr>`;
    });

    html += `
                </tbody>
            </table>
        </div>`;
    $list.html(html);
    initHrDropdowns('#projectStaffTable');
}

function unassignStaff(id, name) {
    Swal.fire({
        title: 'Unassign Staff?',
        text: `Are you sure you want to remove ${name} from this project? The employee record will not be deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, unassign'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/operations/update_staff_project.php', {
                employee_id: id,
                project_id: null
            }, function(res) {
                if (res.success) {
                    Swal.fire('Unassigned!', 'The employee has been removed from this project.', 'success');
                    loadProjectDetails();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

// Inactivate a staff member (same action as the external HR > Employees list).
// Non-destructive: nothing is deleted, and they can be reactivated later from
// Inactive Employees. Affects the whole system, not just this project.
function deleteProjectStaff(id, name) {
    var safeName = $('<div>').text(name).html();
    Swal.fire({
        title: 'Inactivate Staff?',
        html:
            '<p class="text-start mb-3">This deactivates <strong>' + safeName + '</strong> everywhere in the system ' +
            '(attendance, leave, payroll, reporting), not just this project. Nothing is deleted — every past record ' +
            'stays intact, and they can be reactivated later from Inactive Employees.</p>' +
            '<div class="text-start mb-2">' +
            '  <label class="form-label small fw-bold">Reason</label>' +
            '  <select id="pv_inactivate_outcome" class="form-select form-select-sm mb-2">' +
            '    <option value="terminated">Contract Terminated</option>' +
            '    <option value="resigned">Resigned</option>' +
            '    <option value="failed_probation">Failed Probation</option>' +
            '  </select>' +
            '  <textarea id="pv_inactivate_reason" class="form-control form-control-sm" rows="2" placeholder="Optional note..."></textarea>' +
            '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, inactivate',
        focusConfirm: false,
        preConfirm: () => ({
            outcome: document.getElementById('pv_inactivate_outcome').value,
            reason: document.getElementById('pv_inactivate_reason').value
        })
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/inactivate_employee', {
                employee_id: id,
                outcome: result.value.outcome,
                reason: result.value.reason
            }, function(res) {
                if (res.success) {
                    Swal.fire('Inactivated!', res.message || 'Staff member inactivated.', 'success');
                    loadProjectDetails();
                } else {
                    Swal.fire('Error', res.message || 'Inactivate failed.', 'error');
                }
            }, 'json');
        }
    });
}

function openAssignStaffModal() {
    // Load unassigned staff and show modal
    $.getJSON(APP_URL + '/api/operations/get_unassigned_staff.php', function(res) {
        if (res.success) {
            let options = '<option value="">Select Employee to Assign</option>';
            res.data.forEach(e => {
                options += `<option value="${e.employee_id}">${e.first_name} ${e.last_name} (${e.employee_number}) - ${e.designation_name || ''}</option>`;
            });
            $('#assign_employee_id').html(options);
            $('#assignStaffModal').modal('show');
        } else {
            Swal.fire('Error', 'Failed to load employees.', 'error');
        }
    });
}

$(document).on('submit', '#assignStaffForm', function(e) {
    e.preventDefault();
    const employeeId = $('#assign_employee_id').val();
    if (!employeeId) return;

    const $btn = $('#btnConfirmAssignStaff');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Assigning...');

    $.post(APP_URL + '/api/operations/update_staff_project.php', {
        employee_id: employeeId,
        project_id: projectId
    }, function(res) {
        if (res.success) {
            $('#assignStaffModal').modal('hide');
            Swal.fire('Assigned!', 'Employee has been added to the project team.', 'success');
            loadProjectDetails();
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json').always(() => {
        $btn.prop('disabled', false).html('Confirm Assignment');
    });
});

/* --- Project Planning, Review & Schedules Logic --- */
// Keep track of the highest global ID to ensure unique row IDs
let planningMaxId = 0;

function addPlanningTaskRow(data = null, parentId = null, insertAfterId = null, specificId = null, isLocked = false) {
    if (specificId) {
        // Extract numeric ID from string like 'plan_row_5'
        const numId = parseInt(specificId.replace('plan_row_', '')) || 0;
        if (numId > planningMaxId) planningMaxId = numId;
    } else {
        planningMaxId++;
    }
    
    const $tbody = $('#planningTable tbody');
    const rowId = specificId || `plan_row_${planningMaxId}`;
    
    // Calculate level based on parent
    let level = 0;
    if (parentId) {
        const $parent = $(`#${parentId}`);
        if ($parent.length) {
            level = (parseInt($parent.attr('data-level')) || 0) + 1;
        } else {
            // If parent not found in DOM yet (during loading), we trust data.level if available
            level = data && data.level ? data.level : 0;
        }
    } else if (data && data.level) {
        level = data.level;
    }

    const taskName = data ? data.task_name : '';
    const duration = data ? data.duration_days : '';
    const startDate = data ? data.start_date : '';
    const finishDate = data ? data.finish_date : '';
    
    // Style for locked rows
    const lockedAttr = isLocked ? 'readonly' : '';
    const lockedBg = isLocked ? 'background:transparent; border-color:transparent;' : '';

    const rowHtml = `
        <tr id="${rowId}" 
            class="planning-task-row ${level === 0 ? 'table-light' : ''}" 
            data-parent="${parentId || ''}" 
            data-level="${level}"
            style="${level === 0 ? 'font-weight: 800;' : 'font-weight: 400;'}">
            <td class="text-center fw-bold task-id-cell text-dark" style="vertical-align: middle;">-</td>
            <td style="padding-left: ${level * 40 + 15}px !important;">
                <div class="d-flex align-items-center">
                    <button class="btn btn-sm p-0 border-0 me-1 toggle-subtasks" 
                            id="toggle_${rowId}" 
                            onclick="togglePlanningSubtasks('${rowId}')" 
                            style="visibility: hidden; width: 20px; outline: none !important; box-shadow: none !important;">
                        <i class="bi bi-caret-down-fill text-muted"></i>
                    </button>
                    <div class="flex-grow-1">
                        <input type="text" class="form-control form-control-sm task-name border-0 p-0" 
                               value="${taskName}" 
                               style="${level === 0 ? 'font-weight: 800; font-size: 1rem;' : 'font-size: 0.9rem;'} ${lockedBg} background-color: transparent;"
                               placeholder="${level === 0 ? 'ENTER MAIN PHASE...' : 'Enter sub-task name...'}" ${lockedAttr}>
                        <div class="small mt-1 phase-balance-chip" style="display: none;">
                            <span class="badge bg-light text-muted border" style="font-weight: 500;">
                                <i class="bi bi-pie-chart-fill me-1"></i> <span class="phase-allocated-days">0</span> / <span class="phase-total-days">0</span> allocated
                            </span>
                        </div>
                    </div>
                </div>
            </td>
            <td><input type="number" class="form-control form-control-sm task-duration text-center" value="${duration}" onchange="calculateDatesFromDependencies()" placeholder="0" ${lockedAttr} style="${lockedBg}"></td>
            <td><input type="date" class="form-control form-control-sm task-start text-center" value="${startDate}" onchange="calculateDatesFromDependencies()" onfocus="enforceDateConstraints('${rowId}')" ${lockedAttr} style="${lockedBg}"></td>
            <td><input type="date" class="form-control form-control-sm task-finish text-center" value="${finishDate}" readonly style="background: #f8f9fa; ${lockedBg ? 'border-color:transparent;' : ''}"></td>
            <td class="text-center d-print-none">
                ${level === 0 ? `
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="unlockPhase('${rowId}')"><i class="bi bi-pencil-square text-primary me-2"></i>Unlock for Editing</a></li>
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="addPlanningTaskRow(null, '${rowId}')"><i class="bi bi-plus-lg text-success me-2"></i>Add Sub-task</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="removePlanningTaskRow('${rowId}')"><i class="bi bi-trash me-2"></i>Delete Phase</a></li>
                        </ul>
                    </div>
                ` : `
                    <div class="btn-group subtask-actions" style="${isLocked ? 'display:none' : ''}">
                        <button class="btn btn-sm btn-light border text-primary" onclick="addPlanningTaskRow(null, '${rowId}')" title="Add Sub-item">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                        <button class="btn btn-sm btn-light border text-danger" onclick="removePlanningTaskRow('${rowId}')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `}
            </td>
        </tr>
    `;

    if (insertAfterId) {
        $(`#${insertAfterId}`).after(rowHtml);
    } else if (parentId) {
        // Find the absolute last row in the branch starting at parentId
        let $parent = $(`#${parentId}`);
        let myLevel = parseInt($parent.attr('data-level')) || 0;
        let $lastChild = $parent;
        let $curr = $parent.next();
        
        while ($curr.length && (parseInt($curr.attr('data-level')) || 0) > myLevel) {
            $lastChild = $curr;
            $curr = $curr.next();
        }
        $lastChild.after(rowHtml);
    } else {
        $tbody.append(rowHtml);
    }

    reindexTaskIds();
    if (!data) calculateDatesFromDependencies(); // Live update for new manual rows
}

function togglePlanningSubtasks(rowId) {
    const $row = $(`#${rowId}`);
    const $icon = $row.find('.toggle-subtasks i');
    const isCollapsed = $icon.hasClass('bi-caret-right-fill');
    
    // Toggle icon
    if (isCollapsed) {
        $icon.removeClass('bi-caret-right-fill').addClass('bi-caret-down-fill');
    } else {
        $icon.removeClass('bi-caret-down-fill').addClass('bi-caret-right-fill');
    }

    // Toggle immediate children and handle recursively if showing
    recursiveToggle(rowId, !isCollapsed);
    reindexTaskIds();
}

function recursiveToggle(parentId, hide) {
    $(`#planningTable tbody tr[data-parent="${parentId}"]`).each(function() {
        const childId = $(this).attr('id');
        if (hide) {
            $(this).hide();
            // If we hide a row, all its descendants must be hidden too
            recursiveToggle(childId, true);
        } else {
            $(this).show();
            // If we show a row, only show its children if it's NOT collapsed itself
            const $childIcon = $(this).find('.toggle-subtasks i');
            if (!$childIcon.hasClass('bi-caret-right-fill')) {
                recursiveToggle(childId, false);
            }
        }
    });
}

function expandCollapseAllPlanning(expand) {
    $('.planning-task-row').each(function() {
        const rowId = $(this).attr('id');
        const hasChildren = $(`.planning-task-row[data-parent="${rowId}"]`).length > 0;
        
        if (hasChildren) {
            const $icon = $(this).find('.toggle-subtasks i');
            if (expand) {
                $icon.removeClass('bi-caret-right-fill').addClass('bi-caret-down-fill');
            } else {
                $icon.removeClass('bi-caret-down-fill').addClass('bi-caret-right-fill');
            }
        }
    });

    // Handle visibility
    if (expand) {
        $('.planning-task-row').show();
    } else {
        $('.planning-task-row').each(function() {
            const level = parseInt($(this).attr('data-level')) || 0;
            if (level > 0) {
                $(this).hide();
            } else {
                $(this).show();
            }
        });
    }
    reindexTaskIds();
}

function removePlanningTaskRow(rowId) {
    // Also remove children
    const $row = $(`#${rowId}`);
    const myLevel = parseInt($row.attr('data-level')) || 0;
    
    let $next = $row.next();
    while ($next.length && (parseInt($next.attr('data-level')) || 0) > myLevel) {
        const toRem = $next;
        $next = $next.next();
        toRem.remove();
    }
    
    $row.remove();
    reindexTaskIds();
    calculateDatesFromDependencies();
}

function reindexTaskIds() {
    let count = 0;
    $('#planningTable tbody tr').each(function() {
        if ($(this).css('display') !== 'none') {
            count++;
            $(this).find('.task-id-cell').text(count);
        }
    });
}

function reindexReviewTaskIds() {
    let count = 0;
    $('.review-task-row').each(function() {
        if ($(this).css('display') !== 'none') {
            count++;
            $(this).find('.review-id-cell').text(count);
        }
    });
}

function reindexScheduleTaskIds() {
    let count = 0;
    $('.schedule-data-row').each(function() {
        if ($(this).css('display') !== 'none') {
            count++;
            $(this).find('.schedule-id-cell').text(count);
        }
    });
}

function calculateDatesFromDependencies() {
    const rows = [];
    $('.planning-task-row').each(function() {
        rows.push({
            id: $(this).attr('id'),
            el: $(this),
            duration: parseInt($(this).find('.task-duration').val()) || 0,
            start: $(this).find('.task-start').val(),
            finish: '',
            level: parseInt($(this).attr('data-level')) || 0,
            parent: $(this).attr('data-parent') || null,
            allocated: 0 // Initialize for calculation
        });
    });

    // 1. Calculate Dates & Hierarchy Balances
    rows.forEach(row => {
        // Auto-set Finish Date (Correct Inclusive Calculation)
        if (row.start && row.duration > 0) {
            const startStr = row.start + 'T00:00:00'; // Ensure local timezone
            const start = new Date(startStr);
            const finish = new Date(start);
            // If duration is 10 days, we add 9 days to the start date
            finish.setDate(start.getDate() + (row.duration - 1));
            
            const y = finish.getFullYear();
            const m = String(finish.getMonth() + 1).padStart(2, '0');
            const d = String(finish.getDate()).padStart(2, '0');
            row.finish = `${y}-${m}-${d}`;
            row.el.find('.task-finish').val(row.finish);
        } else if (row.start && row.duration === 0) {
            row.finish = row.start;
            row.el.find('.task-finish').val(row.finish);
        }

        // Reset child allocation tracker for all rows
        row.allocated = 0;
        row.el.find('.phase-balance-chip').hide();
    });

    // 2. Sum up child durations to parents
    // We go from bottom to top to handle multiple levels
    const maxLevel = Math.max(...rows.map(r => r.level), 0);
    for (let l = maxLevel; l > 0; l--) {
        rows.forEach(row => {
            if (row.level === l && row.parent) {
                const parentRow = rows.find(r => r.id === row.parent);
                if (parentRow) {
                    parentRow.allocated += row.duration;
                }
            }
        });
    }

    // 3. Update Visuals for Parents
    let totalAllocatedPhases = 0;
    rows.forEach(row => {
        // Check if I have children (i am a parent if any row has me as parent)
        const hasChildren = rows.some(r => r.parent === row.id);
        const $toggleBtn = row.el.find('.toggle-subtasks');
        
        if (hasChildren) {
            $toggleBtn.css('visibility', 'visible');
            row.el.find('.phase-balance-chip').show();
            row.el.find('.phase-allocated-days').text(row.allocated);
            row.el.find('.phase-total-days').text(row.duration);
            
            const $badge = row.el.find('.phase-balance-chip span');
            if (row.allocated > row.duration) {
                $badge.removeClass('bg-light text-muted bg-success text-white').addClass('bg-danger text-white border-danger');
            } else if (row.allocated === row.duration && row.duration > 0) {
                $badge.removeClass('bg-light text-muted bg-danger text-white').addClass('bg-success text-white border-success');
            } else {
                $badge.removeClass('bg-danger text-white bg-success text-white').addClass('bg-light text-muted border');
            }
        } else {
            $toggleBtn.css('visibility', 'hidden');
        }
        
        // Sum top-level only for project total validation
        if (row.level === 0) {
            totalAllocatedPhases += row.duration;
        }
    });

    // Project Wide Validation (STRICT LIVE FEEDBACK)
    if (projectData && projectData.data) {
        const p = projectData.data;
        const pStart = p.start_date;
        const pEnd = p.deadline;
        const pTotal = Math.ceil((new Date(pEnd) - new Date(pStart)) / (1000*60*60*24)) + 1; // Inclusive
        
        const remainingDays = pTotal - totalAllocatedPhases;
        
        // Update summary box color based on balance
        const $sumBox = $('#summaryPlanAllocated').closest('.card');
        const $remField = $('#summaryPlanAllocated');
        const $statusField = $('#summaryPlanStatus');
        
        $remField.text(remainingDays + ' Days');

        if (remainingDays < 0) {
            $sumBox.css('background-color', '#f8d7da'); // Error Red
            $remField.css('color', '#842029');
            $statusField.text('Exceeded').css('color', '#842029').addClass('fw-bold');
        } else if (remainingDays === 0 && pTotal > 0) {
            $sumBox.css('background-color', '#d1e7dd'); // Balanced Green
            $remField.css('color', '#0f5132');
            $statusField.text('Balanced').css('color', '#0f5132').addClass('fw-bold');
        } else {
            $sumBox.css('background-color', '#d1e7dd');
            $remField.css('color', '#0f5132');
            $statusField.text('Remaining').css('color', '#0f5132').removeClass('fw-bold');
        }

        let hasHierarchyViolation = false;
        rows.forEach(row => {
            // Reset state
            row.el.find('.task-start, .task-finish').removeClass('is-invalid border-danger');

            // 1. Check against Project Boundaries
            if (pStart && row.start && row.start < pStart) row.el.find('.task-start').addClass('is-invalid');
            if (pEnd && row.finish && row.finish > pEnd) row.el.find('.task-finish').addClass('is-invalid border-danger');

            // 2. Check against Parent Boundaries
            if (row.parent) {
                const parentRow = rows.find(r => r.id === row.parent);
                if (parentRow && parentRow.start && parentRow.finish) {
                    if (row.start && row.start < parentRow.start) {
                        row.el.find('.task-start').addClass('is-invalid');
                        hasHierarchyViolation = true;
                    }
                    if (row.finish && row.finish > parentRow.finish) {
                        row.el.find('.task-finish').addClass('is-invalid border-danger');
                        hasHierarchyViolation = true;
                    }
                }
            }
        });

        if (hasHierarchyViolation) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            Toast.fire({
                icon: 'warning',
                title: 'Date Violation',
                text: 'Sub-task timeline must be within its parent phase dates.'
            });
        }
    }
}


