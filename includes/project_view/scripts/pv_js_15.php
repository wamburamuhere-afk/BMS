function ipcEdit(id) {
    ipcCurrentId = id;
    $.getJSON(APP_URL + '/api/operations/get_ipc.php', { id: id }, function(res) {
        if (!res.success) return Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        var r = res.data;
        $('#edit_ipc_id').val(r.ipc_id);
        $('#ipc_edit_no').val(r.ipc_number || '');
        $('#edit_ipc_date').val(r.ipc_date || '');
        $('#edit_ipc_period_from').val(r.period_from || '');
        $('#edit_ipc_period_to').val(r.period_to || '');
        var editCustId = r.so_customer_id || '';
        ipcFilterSO(editCustId, 'edit');
        $('#ipc_edit_customer').val(editCustId);
        $('#ipc_edit_so').val(r.sales_order_id || '');
        $('#edit_ipc_status').val(r.status || 'Draft');
        $('#edit_ipc_notes').val(r.notes || '');
        var tbody = $('#ipcEditItemsBody').empty();
        var items = [];
        try { items = JSON.parse(r.items_json || '[]'); } catch(e) { items = []; }
        if (items.length === 0) items = [{}];
        items.forEach(function(item, i) { tbody.append(ipcNewItemRow('edit', item, i + 1)); });
        ipcCalc('edit');
        new bootstrap.Modal(document.getElementById('ipcEditModal')).show();
    });
}

function ipcUpdate() {
    var data = $('#ipcEditForm').serializeArray();
    var items = ipcGetItems('edit');
    items.forEach(function(item, i) {
        data.push({ name: 'items[' + i + '][product_name]', value: item.product_name });
        data.push({ name: 'items[' + i + '][quantity]',     value: item.quantity });
        data.push({ name: 'items[' + i + '][unit]',         value: item.unit });
        data.push({ name: 'items[' + i + '][unit_price]',   value: item.unit_price });
        data.push({ name: 'items[' + i + '][tax_percent]',  value: item.tax_percent });
    });
    $.post(APP_URL + '/api/operations/save_ipc.php', $.param(data), function(res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('ipcEditModal')).hide();
            ipcLoadTable();
            Swal.fire({ icon: 'success', title: 'Updated', text: res.message, timer: 2000, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }
    }, 'json');
}

function ipcView(id) {
    ipcCurrentId = id;
    $('#ipcViewBody').html('<div class="text-center p-4"><div class="spinner-border text-primary"></div></div>');
    $('#ipcCreateInvoiceBtn, #ipcViewEditBtn, #ipcReviewBtn, #ipcApproveBtn').hide();
    new bootstrap.Modal(document.getElementById('ipcViewModal')).show();
    $.getJSON(APP_URL + '/api/operations/get_ipc.php', { id: id }, function(res) {
        if (!res.success) return $('#ipcViewBody').html('<p class="text-danger">' + res.message + '</p>');
        var r = res.data;
        var fmt = function(n) { return parseFloat(n || 0).toLocaleString('en-TZ', { minimumFractionDigits: 2 }); };
        var customer = typeof projectData !== 'undefined' && projectData.data ? projectData.data.customer_name || '' : '';
        var items = [];
        try { items = JSON.parse(r.items_json || '[]'); } catch(e) { items = []; }
        var subtotal = 0, tax_sum = 0;
        var itemRows = items.map(function(item, i) {
            var line_sub = parseFloat(item.quantity || 0) * parseFloat(item.unit_price || 0);
            var tax_amt  = parseFloat(item.tax_amount || 0);
            subtotal += line_sub; tax_sum += tax_amt;
            return '<tr><td class="text-center">' + (i + 1) + '</td>'
                + '<td>' + ipcEscHtml(item.product_name) + '</td>'
                + '<td class="text-center">' + (item.quantity || '') + '</td>'
                + '<td class="text-center">' + ipcEscHtml(item.unit) + '</td>'
                + '<td class="text-end">' + fmt(item.unit_price) + '</td>'
                + '<td class="text-center">' + (item.tax_percent || 0) + '%</td>'
                + '<td class="text-end fw-bold">' + fmt(item.total) + '</td></tr>';
        }).join('');
        var html = '<div class="row g-2 mb-3">'
            + '<div class="col-md-3"><small class="text-muted d-block">IPC Number</small><strong>' + ipcEscHtml(r.ipc_number) + '</strong></div>'
            + '<div class="col-md-3"><small class="text-muted d-block">IPC Date</small><span>' + (r.ipc_date || '-') + '</span></div>'
            + '<div class="col-md-3"><small class="text-muted d-block">Period From</small><span>' + (r.period_from || '-') + '</span></div>'
            + '<div class="col-md-3"><small class="text-muted d-block">Period To</small><span>' + (r.period_to || '-') + '</span></div>'
            + '<div class="col-md-6"><small class="text-muted d-block">Customer</small><strong>' + ipcEscHtml(customer) + '</strong></div>'
            + '<div class="col-md-6"><small class="text-muted d-block">Sales Order</small><span>' + ipcEscHtml(r.order_number || '-') + '</span></div>'
            + '</div>'
            + '<div class="table-responsive mb-3"><table class="table table-bordered table-sm small">'
            + '<thead class="table-light"><tr><th width="35" class="text-center">#</th><th>Product / Item</th><th width="70" class="text-center">Qty</th><th width="60" class="text-center">Unit</th><th width="120" class="text-end">Unit Price</th><th width="65" class="text-center">Tax %</th><th width="120" class="text-end">Total</th></tr></thead>'
            + '<tbody>' + itemRows + '</tbody>'
            + '</table></div>'
            + '<div class="row justify-content-end mb-3"><div class="col-md-5">'
            + '<div class="d-flex justify-content-between mb-1 small"><span class="text-muted">Subtotal</span><span>' + fmt(subtotal) + '</span></div>'
            + '<div class="d-flex justify-content-between mb-1 small"><span class="text-muted">Tax</span><span>' + fmt(tax_sum) + '</span></div>'
            + '<hr class="my-1">'
            + '<div class="d-flex justify-content-between fw-bold"><span>Net Payable</span><span class="text-primary">TZS ' + fmt(r.net_payable) + '</span></div>'
            + '</div></div>'
            + '<div class="row g-2">'
            + '<div class="col-md-3"><small class="text-muted d-block">Status</small><strong>' + ipcEscHtml(r.status) + '</strong></div>'
            + (r.notes ? '<div class="col-12"><small class="text-muted d-block">Notes</small><span>' + ipcEscHtml(r.notes) + '</span></div>' : '')
            + '</div>';
        $('#ipcViewBody').html(html);
        if (r.status === 'Draft') {
            $('#ipcReviewBtn').show().off('click').on('click', function() { ipcUpdateStatus(id, 'Viewed'); });
            $('#ipcViewEditBtn').show().off('click').on('click', function() {
                bootstrap.Modal.getInstance(document.getElementById('ipcViewModal')).hide();
                ipcEdit(id);
            });
        }
        if (r.status === 'Viewed') {
            $('#ipcApproveBtn').show().off('click').on('click', function() { ipcUpdateStatus(id, 'Approved'); });
            $('#ipcViewEditBtn').show().off('click').on('click', function() {
                bootstrap.Modal.getInstance(document.getElementById('ipcViewModal')).hide();
                ipcEdit(id);
            });
        }
        if (r.status === 'Approved' && !r.invoice_id) {
            $('#ipcCreateInvoiceBtn').show().off('click').on('click', function() { ipcCreateInvoice(id); });
        }
        if (r.status === 'Approved') {
            $('#ipcViewEditBtn').show().off('click').on('click', function() {
                bootstrap.Modal.getInstance(document.getElementById('ipcViewModal')).hide();
                ipcEdit(id);
            });
        }
    });
}

function ipcUpdateStatus(id, newStatus) {
    var label = newStatus === 'Viewed' ? 'Mark as Reviewed?' : 'Approve this IPC?';
    var text  = newStatus === 'Viewed' ? 'Status will change to Viewed.' : 'Status will change to Approved.';
    var btnTxt = newStatus === 'Viewed' ? 'Review' : 'Approve';
    Swal.fire({ title: label, text: text, icon: 'question', showCancelButton: true, confirmButtonText: btnTxt }).then(function(result) {
        if (!result.isConfirmed) return;
        $.post(APP_URL + '/api/operations/update_ipc_status.php', { ipc_id: id, status: newStatus }, function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('ipcViewModal')).hide();
                ipcLoadTable();
                Swal.fire({ icon: 'success', title: 'Updated', text: res.message, timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

function ipcCreateInvoice(id) {
    $.getJSON(APP_URL + '/api/operations/get_ipc.php', { id: id }, function(res) {
        if (!res.success) { Swal.fire('Error', res.message, 'error'); return; }
        var r = res.data;
        var customerId = r.so_customer_id || r.proj_customer_id || '';
        var url = APP_URL + '/invoice_create?ipc_id=' + id;
        if (r.project_id)     url += '&project='  + r.project_id;
        if (r.sales_order_id) url += '&order='    + r.sales_order_id;
        if (customerId)       url += '&customer='  + customerId;
        window.location.href = url;
    });
}

function ipcDelete(id) {
    Swal.fire({ title: 'Delete IPC?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' }).then(function(result) {
        if (!result.isConfirmed) return;
        $.post(APP_URL + '/api/operations/delete_ipc.php', { ipc_id: id }, function(res) {
            if (res.success) { ipcLoadTable(); Swal.fire({ icon: 'success', title: 'Deleted', timer: 1500, showConfirmButton: false }); }
            else Swal.fire({ icon: 'error', title: 'Error', text: res.message });
        }, 'json');
    });
}

function ipcDeleteFromModal() {
    var id = ipcCurrentId;
    if (!id) return;
    Swal.fire({ title: 'Delete IPC?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' }).then(function(result) {
        if (!result.isConfirmed) return;
        $.post(APP_URL + '/api/operations/delete_ipc.php', { ipc_id: id }, function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('ipcViewModal')).hide();
                ipcLoadTable();
                Swal.fire({ icon: 'success', title: 'Deleted', timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

document.getElementById('ipcAddModal').addEventListener('show.bs.modal', function() {
    $('#ipc_add_customer').val('');
    ipcFilterSO('', 'add');
});

$(document).on('shown.bs.tab', '#proj-ipc-tab', function() { ipcLoadTable(); });
// ── Expense Categorization Logic (Standardized) ─────────────────────────────
let expenseSchema = [];

function loadExpenseSchema(callback) {
    $.getJSON('/api/finance/get_expense_schema.php', function(res) {
        if (res.success) {
            expenseSchema = res.data;
            populateExpenseTypeDropdowns();
            if (callback) callback();
        }
    });
}

function populateExpenseTypeDropdowns() {
    const $types   = $('.expense-type-sel');
    const $cfgType = $('#cfg_type_id');

    let allOptions  = '<option value="">Select Type</option>';
    let projOptions = '<option value="">Select Type</option>';
    let cfgOptions  = '<option value="">-- Choose Type --</option>';

    expenseSchema.forEach(type => {
        allOptions += `<option value="${type.id}">${type.name}</option>`;
        cfgOptions += `<option value="${type.id}">${type.name}</option>`;
        // The project Expense Type selects honour the admin "Show in Projects"
        // flag (expense_types.show_project) — the single source of truth — instead
        // of a hardcoded name list, so the toggle on the Expense Types page
        // actually controls what appears here.
        if (Number(type.show_project) === 1) {
            projOptions += `<option value="${type.id}">${type.name}</option>`;
        }
    });

    // Project expense selects get flag-filtered options; other selects & config get all
    $('#ex_expense_type, #edit_expense_type').html(projOptions);
    $types.not('#ex_expense_type, #edit_expense_type').html(allOptions);
    $cfgType.html(cfgOptions);
}

$(document).on('change', '.expense-type-sel', function() {
    const typeId = $(this).val();
    const isEdit = $(this).attr('id') === 'edit_expense_type';
    const $catBlock      = isEdit ? $('.edit-expense-category-block') : $('.add-expense-category-block');
    const $cascadeCont   = isEdit ? $('#proj_edit_cascade_container') : $('#proj_add_cascade_container');
    const $selectedCatId = isEdit ? $('#proj_edit_selected_cat') : $('#proj_add_selected_cat');

    // Destroy any existing cascade selects
    $cascadeCont.find('.proj-cascade-sel').each(function() {
        if ($(this).data('select2')) $(this).select2('destroy');
    });
    $cascadeCont.empty();
    $selectedCatId.val('');

    if (!typeId) { $catBlock.hide(); return; }

    const typeData = expenseSchema.find(t => t.id == typeId);
    if (!typeData || !typeData.categories || !typeData.categories.length) {
        $catBlock.hide();
        return;
    }

    $catBlock.show();
    renderProjCascadeDropdown(typeData.categories, 0, isEdit);
});

function renderProjCascadeDropdown(categories, level, isEdit) {
    const $cont    = isEdit ? $('#proj_edit_cascade_container') : $('#proj_add_cascade_container');
    const modalId  = isEdit ? '#expenseActionModal' : '#addExpenseModal';
    const prefix   = isEdit ? 'edit' : 'add';

    // Remove any deeper levels
    $cont.find(`.proj-cascade-level[data-level="${level}"]`).nextAll().remove();
    $cont.find(`.proj-cascade-level[data-level="${level}"]`).remove();

    const $wrap = $(`<div class="proj-cascade-level mb-2" data-level="${level}"></div>`);
    let opts = `<option value="">— Select Category —</option>`;
    categories.forEach(c => { opts += `<option value="${c.id}" data-has-children="${c.children && c.children.length ? '1' : '0'}">${c.name}</option>`; });

    const $sel = $(`<select class="form-select proj-cascade-sel" data-level="${level}" data-prefix="${prefix}"></select>`).html(opts);
    $wrap.append($sel);
    $cont.append($wrap);

    $sel.select2({ theme: 'bootstrap-5', dropdownParent: $(modalId), placeholder: '— Select Category —', allowClear: true, width: '100%' });
}

// Preselect a saved category_id in the cascade (edit mode)
function preSelectCascade(targetId, isEdit) {
    if (!targetId) return;
    const typeId = isEdit ? $('#edit_expense_type').val() : $('#ex_expense_type').val();
    if (!typeId) return;
    const typeData = expenseSchema.find(t => t.id == typeId);
    if (!typeData || !typeData.categories) return;

    function findPath(cats, id, path) {
        for (const c of cats) {
            const cur = [...path, c.id];
            if (c.id == id) return cur;
            if (c.children && c.children.length) {
                const found = findPath(c.children, id, cur);
                if (found) return found;
            }
        }
        return null;
    }

    const path = findPath(typeData.categories, targetId, []);
    if (!path || !path.length) return;

    function selectLevel(pathIndex, level) {
        if (pathIndex >= path.length) return;
        const $cont = isEdit ? $('#proj_edit_cascade_container') : $('#proj_add_cascade_container');
        const $sel  = $cont.find(`.proj-cascade-level[data-level="${level}"] .proj-cascade-sel`);
        if (!$sel.length) return;
        $sel.val(path[pathIndex]).trigger('change');
        setTimeout(() => selectLevel(pathIndex + 1, level + 1), 80);
    }

    selectLevel(0, 0);
}

$(document).on('change', '.proj-cascade-sel', function() {
    const level    = parseInt($(this).data('level'));
    const prefix   = $(this).data('prefix');
    const isEdit   = prefix === 'edit';
    const $cont    = isEdit ? $('#proj_edit_cascade_container') : $('#proj_add_cascade_container');
    const $catId   = isEdit ? $('#proj_edit_selected_cat') : $('#proj_add_selected_cat');
    const typeId   = isEdit ? $('#edit_expense_type').val() : $('#ex_expense_type').val();
    const selVal   = $(this).val();

    // Remove deeper levels
    $cont.find(`.proj-cascade-level`).filter(function() {
        return parseInt($(this).data('level')) > level;
    }).each(function() {
        $(this).find('.proj-cascade-sel').each(function() { if ($(this).data('select2')) $(this).select2('destroy'); });
        $(this).remove();
    });

    $catId.val(selVal || '');

    if (!selVal) return;

    // Find children of selected category
    const typeData = expenseSchema.find(t => t.id == typeId);
    if (!typeData) return;

    function findChildren(cats, id) {
        for (const c of cats) {
            if (c.id == id) return c.children || [];
            if (c.children) { const r = findChildren(c.children, id); if (r !== null) return r; }
        }
        return null;
    }

    const children = findChildren(typeData.categories, selVal);
    if (children && children.length) {
        renderProjCascadeDropdown(children, level + 1, isEdit);
    }
});

function toggleAllCategories(check, mode) {
    const selector = mode === 'edit' ? '#edit_category_checkboxes .category-checkbox' : '#category_checkboxes .category-checkbox';
    $(selector).prop('checked', check);
}

function quickSaveCategory(mode) {
    const isEdit = mode === 'edit';
    const typeId = isEdit ? $('#edit_expense_type').val() : $('#ex_expense_type').val();
    const name = isEdit ? $('#edit_new_cat_name').val().trim() : $('#new_cat_name').val().trim();
    
    if (!typeId) return Swal.fire('Error', 'Please select an Expense Type first', 'error');
    if (!name) return Swal.fire('Error', 'Please enter a category name', 'error');

    const $btn = isEdit ? $('#btnEditQuickSaveCat') : $('#btnQuickSaveCat');
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

    $.post('/api/finance/manage_expense_schema.php', { action: 'add_category', type_id: typeId, name: name }, function(res) {
        $btn.prop('disabled', false).html(originalHtml);
        if (res.success) {
            if (isEdit) $('#edit_new_cat_name').val('').focus();
            else $('#new_cat_name').val('').focus();

            loadExpenseSchema(() => {
                // Capture currently selected category IDs
                const containerSelector = isEdit ? '#edit_category_checkboxes' : '#category_checkboxes';
                const selectedIds = [];
                $(`${containerSelector} .category-checkbox:checked`).each(function() {
                    selectedIds.push($(this).val());
                });

                // Refresh checkboxes for current type
                if (isEdit) $('#edit_expense_type').trigger('change');
                else $('#ex_expense_type').trigger('change');

                // Re-apply selections and check the new one
                setTimeout(() => {
                    const prefix = isEdit ? 'edit_' : '';
                    selectedIds.forEach(id => $(`#${prefix}cat_${id}`).prop('checked', true));
                    $(`#${prefix}cat_${res.id}`).prop('checked', true);
                }, 50);

                showActionSuccess('New Category added.');
            });
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
}

// Management Modal Handlers
function openExpenseConfigModal() {
    $('#expenseConfigModal').modal('show');
    loadExpenseSchema();
}

function toggleNewTypeInput() {
    $('#new_type_input_cont').toggle();
    $('#cfg_new_type_name').focus();
}

function saveNewExpenseType() {
    const name = $('#cfg_new_type_name').val().trim();
    if (!name) return Swal.fire('Error', 'Please enter a type name', 'error');

    $.post('/api/finance/manage_expense_schema.php', { action: 'add_type', name: name }, function(res) {
        if (res.success) {
            $('#cfg_new_type_name').val('');
            $('#new_type_input_cont').hide();
            loadExpenseSchema(() => {
                $('#cfg_type_id').val(res.id).trigger('change');
                showActionSuccess('New Expense Type created.');
            });
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
}

$('#cfg_type_id').on('change', function() {
    const typeId = $(this).val();
    if (!typeId) {
        $('#cfg_categories_section').hide();
        $('#cfg_no_type_selected').show();
        return;
    }

    $('#cfg_no_type_selected').hide();
    $('#cfg_categories_section').show();
    renderConfigCategories(typeId);
});

function renderConfigCategories(typeId) {
    const typeData = expenseSchema.find(t => t.id == typeId);
    const $list = $('#cfg_categories_list');
    $list.empty();

    if (typeData && typeData.categories.length > 0) {
        typeData.categories.forEach(cat => {
            $list.append(`
                <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <span class="fw-bold text-dark">${cat.name}</span>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-link text-primary p-0 me-2" onclick="editExpenseCategory(${cat.id}, '${cat.name}')"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-link text-danger p-0" onclick="deleteExpenseCategory(${cat.id})"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            `);
        });
    } else {
        $list.append('<div class="p-3 text-center text-muted small">No categories found for this type.</div>');
    }
}

function saveNewCategory() {
    const typeId = $('#cfg_type_id').val();
    const name = $('#cfg_new_cat_name').val().trim();
    if (!typeId || !name) return;

    $.post('/api/finance/manage_expense_schema.php', { action: 'add_category', type_id: typeId, name: name }, function(res) {
        if (res.success) {
            $('#cfg_new_cat_name').val('').focus();
            loadExpenseSchema(() => renderConfigCategories(typeId));
        }
    }, 'json');
}

function editExpenseCategory(id, currentName) {
    Swal.fire({
        title: 'Rename Category',
        input: 'text',
        inputValue: currentName,
        showCancelButton: true,
        confirmButtonText: 'Update',
        preConfirm: (val) => {
            if (!val.trim()) return Swal.showValidationMessage('Name is required');
            return val.trim();
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/finance/manage_expense_schema.php', { action: 'edit_category', id: id, name: result.value }, function(res) {
                if (res.success) {
                    loadExpenseSchema(() => renderConfigCategories($('#cfg_type_id').val()));
                }
            }, 'json');
        }
    });
}

function deleteExpenseCategory(id) {
    Swal.fire({
        title: 'Delete Category?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/finance/manage_expense_schema.php', { action: 'delete_category', id: id }, function(res) {
                if (res.success) {
                    loadExpenseSchema(() => renderConfigCategories($('#cfg_type_id').val()));
                }
            }, 'json');
        }
    });
}

// ============================================================
// HR MODULE INITIALIZATION
// ============================================================
$(document).ready(function() {

    // Set default dates for attendance filters — both default to today, so
    // opening the tab lands directly on today's markable roster (same as the
    // standalone Attendance page's Daily view). Widening the range switches
    // to a read-only aggregated summary instead (see renderProjectAttendance).
    const today = new Date().toISOString().split('T')[0];
    $('#attDateFrom').val(today);
    $('#attDateTo').val(today);

    // Set default dates for leave filters
    $('#lvDateFrom').val(today.substring(0, 4) + '-01-01');
    $('#lvDateTo').val(today.substring(0, 4) + '-12-31');

    // Set default payroll period
    $('#prPeriodFilter').val(today.substring(0, 7));
    $('#pr_period').val(today.substring(0, 7));
    $('#pr_ref_date').val(today);

    // Auto-load when HR sub-tabs are shown
    $('#hr-attendance-tab').on('shown.bs.tab', function() { loadProjectAttendance(); });
    $('#hr-leaves-tab').on('shown.bs.tab', function() { loadProjectLeaves(); });
    $('#hr-payroll-tab').on('shown.bs.tab', function() { loadProjectPayroll(); });

    // Leave modal: reset document section on hide
    $('#applyLeaveModal').on('hidden.bs.modal', function() {
        $('#lv_documentSection').hide();
        $('#lv_balanceInfo').html('<p class="text-muted mb-0 small">Select a staff member and leave type to view balance.</p>');
        $('#lv-leave-message').html('');
    });
});

// ============================================================
// HR: ATTENDANCE
// ============================================================
let projectAttViewMode = 'day';

function loadProjectAttendance() {
    const from   = $('#attDateFrom').val() || new Date().toISOString().split('T')[0];
    const to     = $('#attDateTo').val()   || new Date().toISOString().split('T')[0];
    const status = $('#attStatusFilter').val();

    $('#attPrintPeriod').text('Period: ' + from + ' to ' + to);
    $('#hrAttendanceContent').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');

    $.getJSON(APP_URL + '/api/operations/get_project_attendance.php', {
        project_id: projectId, date_from: from, date_to: to, status: status
    }, function(res) {
        if (res.success) {
            const s = res.stats;
            $('#attStatPresent').text(s.present || 0);
            $('#attStatAbsent').text(s.absent || 0);
            $('#attStatLate').text(s.late || 0);
            $('#attStatHalfDay').text(s.half_day || 0);
            $('#attStatOnLeave').text(s.leave || 0);
            $('#attStatHours').text(parseFloat(s.total_hours || 0).toFixed(1));
            projectAttViewMode = res.view_mode || 'day';
            renderProjectAttendance(res.data, projectAttViewMode);
        } else {
            $('#hrAttendanceContent').html('<div class="alert alert-danger">' + res.message + '</div>');
        }
    }).fail(function() {
        $('#hrAttendanceContent').html('<div class="alert alert-danger">Failed to load attendance data.</div>');
    });
}

function renderProjectAttendance(data, viewMode) {
    if (!data || data.length === 0) {
        $('#hrAttendanceContent').html(`
            <div class="text-center py-5 text-muted border rounded" style="border-radius:12px;">
                <i class="bi bi-calendar-check display-4 opacity-25"></i>
                <p class="mt-2">No staff assigned to this project.</p>
            </div>`);
        return;
    }

    if (viewMode === 'range') {
        renderProjectAttendanceRange(data);
    } else {
        renderProjectAttendanceDay(data);
    }
}

// Single date selected (default) — one row per employee, directly markable
// in place, same behaviour as the standalone Attendance page's Daily view.
function renderProjectAttendanceDay(data) {
    const statusBadge = {
        present: 'bg-success', absent: 'bg-danger', late: 'bg-warning text-dark',
        half_day: 'bg-info', leave: 'bg-secondary', weekend: 'bg-dark'
    };

    let html = `<div class="table-responsive">
        <table class="table table-hover align-middle border" style="border-radius:12px; overflow:hidden;">
            <thead class="table-light">
                <tr>
                    <th class="text-center" width="50">S/NO</th>
                    <th>Staff Member</th>
                    <th>Department / Role</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Hours</th>
                    <th>Status</th>
                    <th class="text-end d-print-none">Actions</th>
                </tr>
            </thead><tbody>`;

    data.forEach((r, i) => {
        const name  = r.first_name + ' ' + r.last_name;
        const badge = statusBadge[r.status] || 'bg-secondary';
        const label = r.status.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
        const cin   = r.check_in_time  ? r.check_in_time.substring(0, 5)  : '';
        const cout  = r.check_out_time ? r.check_out_time.substring(0, 5) : '';
        html += `<tr data-emp-id="${r.employee_id}" data-att-date="${r.attendance_date}" data-att-status="${r.status}">
            <td class="text-center text-muted small">${i + 1}</td>
            <td>
                <div class="fw-bold text-dark">${name}</div>
                <small class="badge bg-light text-primary border border-primary-subtle" style="font-size:0.65rem;">${r.employee_number}</small>
            </td>
            <td>
                <div>${r.designation_name || 'Staff'}</div>
                <small class="text-muted">${r.department_name || 'General'}</small>
            </td>
            <td>
                <input type="time" class="form-control form-control-sm proj-att-checkin d-print-none" style="min-width:90px;" value="${cin}" onchange="projectUpdateAttTime(this)">
                <span class="d-none d-print-inline small">${r.check_in_time || '—'}</span>
            </td>
            <td>
                <input type="time" class="form-control form-control-sm proj-att-checkout d-print-none" style="min-width:90px;" value="${cout}" onchange="projectUpdateAttTime(this)">
                <span class="d-none d-print-inline small">${r.check_out_time || '—'}</span>
            </td>
            <td><small class="proj-att-hours">${r.total_hours ? parseFloat(r.total_hours).toFixed(1) + 'h' : '—'}</small></td>
            <td>
                <span class="badge ${badge} d-none d-print-inline">${label}</span>
                <div class="btn-group btn-group-sm d-print-none" role="group">
                    <button type="button" class="btn btn-${r.status === 'present'  ? 'success'        : 'outline-success'} status-btn" onclick="projectQuickMark(this,'present','09:00','17:00')"  title="Mark Present"><i class="bi bi-check-circle"></i> P</button>
                    <button type="button" class="btn btn-${r.status === 'late'     ? 'warning'        : 'outline-warning'} status-btn" onclick="projectQuickMark(this,'late','10:00','17:00')"     title="Mark Late"><i class="bi bi-clock"></i> L</button>
                    <button type="button" class="btn btn-${r.status === 'absent'   ? 'danger'         : 'outline-danger'}  status-btn" onclick="projectQuickMark(this,'absent','','')"              title="Mark Absent"><i class="bi bi-x-circle"></i> A</button>
                    <button type="button" class="btn btn-${r.status === 'half_day' ? 'info text-dark' : 'outline-info'}    status-btn" onclick="projectQuickMark(this,'half_day','09:00','13:00')" title="Mark Half Day"><i class="bi bi-dash-circle"></i> H</button>
                </div>
            </td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="attendance?employee=${r.employee_id}"><i class="bi bi-clock-history me-2"></i>View History</a></li>
                        ${r.existing_record ? `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteProjectAttendance(${r.employee_id}, '${r.attendance_date}', '${name}')"><i class="bi bi-trash me-2"></i>Delete</a></li>` : ''}
                    </ul>
                </div>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    $('#hrAttendanceContent').html(html);
    initHrDropdowns('#hrAttendanceContent');
}

// A wider date range was picked — read-only aggregate, one row per employee
// (same shape as the standalone Attendance page's week/month view).
function renderProjectAttendanceRange(data) {
    let html = `<div class="table-responsive">
        <table class="table table-hover align-middle border" style="border-radius:12px; overflow:hidden;">
            <thead class="table-light">
                <tr>
                    <th class="text-center" width="50">S/NO</th>
                    <th>Staff Member</th>
                    <th>Department / Role</th>
                    <th class="text-center">Total Hours</th>
                    <th class="text-center">Present</th>
                    <th class="text-center">Late</th>
                    <th class="text-center">Half Day</th>
                    <th class="text-center">Absent</th>
                    <th class="text-center">On Leave</th>
                    <th class="text-end d-print-none">Actions</th>
                </tr>
            </thead><tbody>`;

    data.forEach((r, i) => {
        const name = r.first_name + ' ' + r.last_name;
        html += `<tr>
            <td class="text-center text-muted small">${i + 1}</td>
            <td>
                <div class="fw-bold text-dark">${name}</div>
                <small class="badge bg-light text-primary border border-primary-subtle" style="font-size:0.65rem;">${r.employee_number}</small>
            </td>
            <td>
                <div>${r.designation_name || 'Staff'}</div>
                <small class="text-muted">${r.department_name || 'General'}</small>
            </td>
            <td class="text-center fw-bold text-primary">${parseFloat(r.total_hours || 0).toFixed(1)}h</td>
            <td class="text-center">${r.present_count || 0}</td>
            <td class="text-center text-warning fw-bold">${r.late_count || 0}</td>
            <td class="text-center text-info">${r.half_day_count || 0}</td>
            <td class="text-center text-danger fw-bold">${r.absent_count || 0}</td>
            <td class="text-center text-secondary">${r.leave_count || 0}</td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="attendance?employee=${r.employee_id}"><i class="bi bi-clock-history me-2"></i>View History</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    $('#hrAttendanceContent').html(html);
    initHrDropdowns('#hrAttendanceContent');
}

function projectQuickMark(btn, status, defaultCheckIn, defaultCheckOut) {
    const $row  = $(btn).closest('tr');
    const empId = $row.data('emp-id');
    const date  = $row.data('att-date');
    const cin   = $row.find('.proj-att-checkin').val()  || defaultCheckIn;
    const cout  = $row.find('.proj-att-checkout').val() || defaultCheckOut;

    $.post(APP_URL + '/api/operations/save_project_attendance.php', {
        project_id: projectId, employee_id: empId, attendance_date: date,
        status: status, check_in_time: cin, check_out_time: cout
    }, function(res) {
        if (res.success) { loadProjectAttendance(); }
        else { Swal.fire('Error', res.message, 'error'); }
    }, 'json');
}

function projectUpdateAttTime(input) {
    const $row   = $(input).closest('tr');
    const empId  = $row.data('emp-id');
    const date   = $row.data('att-date');
    const status = $row.data('att-status');
    const cin    = $row.find('.proj-att-checkin').val();
    const cout   = $row.find('.proj-att-checkout').val();

    $.post(APP_URL + '/api/operations/save_project_attendance.php', {
        project_id: projectId, employee_id: empId, attendance_date: date,
        status: status, check_in_time: cin, check_out_time: cout
    }, function(res) {
        if (res.success) { loadProjectAttendance(); }
        else { Swal.fire('Error', res.message, 'error'); }
    }, 'json');
}

function deleteProjectAttendance(employeeId, date, name) {
    Swal.fire({ title: 'Delete Record?', text: 'Delete attendance record for ' + name + '?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Delete' })
    .then(r => {
        if (r.isConfirmed) {
            $.post(APP_URL + '/api/delete_attendance.php', { employee_id: employeeId, attendance_date: date }, function(res) {
                if (res.success) { Swal.fire({icon:'success', title:'Deleted!', text: res.message, timer:1500, showConfirmButton:false}); loadProjectAttendance(); }
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

// ============================================================
// HR: LEAVES
// ============================================================
function loadProjectLeaves() {
    const from   = $('#lvDateFrom').val();
    const to     = $('#lvDateTo').val();
    const status = $('#lvStatusFilter').val();
    const type   = $('#lvTypeFilter').val();

    $('#leavePrintPeriod').text('Period: ' + (from || '—') + ' to ' + (to || '—'));
    $('#hrLeavesContent').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');

    $.getJSON(APP_URL + '/api/operations/get_project_leaves.php', {
        project_id: projectId, date_from: from, date_to: to, status: status, leave_type: type
    }, function(res) {
        if (res.success) {
            const s = res.stats;
            $('#lvStatTotal').text(s.total || 0);
            $('#lvStatPending').text(s.pending || 0);
            $('#lvStatApproved').text(s.approved || 0);
            $('#lvStatRejected').text(s.rejected || 0);
            $('#lvStatCancelled').text(s.cancelled || 0);
            $('#lvStatDays').text(parseFloat(s.total_days || 0).toFixed(1));
            renderProjectLeaves(res.data);
        } else {
            $('#hrLeavesContent').html('<div class="alert alert-danger">' + res.message + '</div>');
        }
    }).fail(function() {
        $('#hrLeavesContent').html('<div class="alert alert-danger">Failed to load leave records.</div>');
    });
}

function renderProjectLeaves(data) {
    if (!data || data.length === 0) {
        $('#hrLeavesContent').html(`
            <div class="text-center py-5 text-muted border rounded" style="border-radius:12px;">
                <i class="bi bi-calendar-x display-4 opacity-25"></i>
                <p class="mt-2">No leave records found.</p>
                <button class="btn btn-sm btn-primary" onclick="openApplyLeaveModal()"><i class="bi bi-plus-lg me-1"></i> Apply Leave</button>
            </div>`);
        return;
    }

    const statusBadge = { pending: 'bg-warning text-dark', approved: 'bg-success', rejected: 'bg-danger', cancelled: 'bg-secondary', taken: 'bg-info' };
    const typeLabel   = { annual: 'Annual', sick: 'Sick', maternity: 'Maternity', paternity: 'Paternity', study: 'Study', unpaid: 'Unpaid', other: 'Other' };

    let html = `<div class="table-responsive">
        <table class="table table-hover align-middle border" style="border-radius:12px; overflow:hidden;">
            <thead class="table-light">
                <tr>
                    <th class="text-center" width="50">S/NO</th>
                    <th>Staff Member</th>
                    <th>Leave Type</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th class="text-center">Days</th>
                    <th>Status</th>
                    <th class="text-end d-print-none">Actions</th>
                </tr>
            </thead><tbody>`;

    data.forEach((r, i) => {
        const name  = r.first_name + ' ' + r.last_name;
        const badge = statusBadge[r.status] || 'bg-secondary';
        const stat  = r.status.charAt(0).toUpperCase() + r.status.slice(1);
        const type  = typeLabel[r.leave_type] || r.leave_type;
        html += `<tr>
            <td class="text-center text-muted small">${i + 1}</td>
            <td>
                <div class="fw-bold text-dark">${name}</div>
                <small class="badge bg-light text-primary border border-primary-subtle" style="font-size:0.65rem;">${r.employee_number}</small>
            </td>
            <td><span class="badge bg-light text-dark border">${type}</span></td>
            <td><small>${r.start_date}</small></td>
            <td><small>${r.end_date}</small></td>
            <td class="text-center fw-bold">${r.total_days}</td>
            <td><span class="badge ${badge}">${stat}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="viewLeaveRecord(${r.leave_id})"><i class="bi bi-eye text-info me-2"></i>View</a></li>
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="openEditLeaveModal(${r.leave_id})"><i class="bi bi-pencil text-warning me-2"></i>Edit</a></li>
                        ${r.status === 'pending' ? `<li><a class="dropdown-item py-2 text-success" href="javascript:void(0)" onclick="updateLeaveStatus(${r.leave_id},'approved')"><i class="bi bi-check-circle me-2"></i>Approve</a></li>
                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="updateLeaveStatus(${r.leave_id},'rejected')"><i class="bi bi-x-circle me-2"></i>Reject</a></li>` : ''}
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteLeaveRecord(${r.leave_id}, '${name}')"><i class="bi bi-trash me-2"></i>Delete</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    $('#hrLeavesContent').html(html);
    initHrDropdowns('#hrLeavesContent');
}

function lvCalculateDays() {
    const s = $('#lv_start_date').val(), e = $('#lv_end_date').val();
    const half = $('#lv_half_day').val();
    if (s && e) {
        const start = new Date(s), end = new Date(e);
        if (end < start) {
            Swal.fire({ icon: 'warning', title: 'Invalid Dates', text: 'End date cannot be before start date.', confirmButtonColor: '#3085d6' });
            $('#lv_total_days').val(0);
            $('#lv_end_date').val('');
            return;
        }
        let days = Math.ceil((end - start) / 86400000) + 1;
        if (half) days = Math.max(0.5, days - 0.5);
        $('#lv_total_days').val(days);
        lvUpdateLeaveBalance();
    }
}

function lvUpdateLeaveTypeInfo() {
    const sel = $('#lv_type').find(':selected');
    if (sel.data('requires-doc') == 1) $('#lv_documentSection').show();
    else $('#lv_documentSection').hide();
    lvUpdateLeaveBalance();
}

function lvUpdateLeaveBalance() {
    const empId    = $('#lv_employee_id').val();
    const typeName = $('#lv_type').find(':selected').data('type-name') || '';
    const totalDays = parseFloat($('#lv_total_days').val()) || 0;
    if (!empId || !typeName) {
        $('#lv_balanceInfo').html('<p class="text-muted mb-0 small">Select a staff member and leave type to view balance.</p>');
        return;
    }
    $.getJSON(APP_URL + '/api/get_leave_balance.php', { employee_id: empId, leave_type: typeName }, function(res) {
        if (!res.success) { $('#lv_balanceInfo').html('<p class="text-muted mb-0 small">Could not load balance.</p>'); return; }
        const maxDays   = res.max_days_per_year || 0;
        const usedDays  = parseFloat(res.balance.used_days) || 0;
        const remaining = maxDays - usedDays;
        const pct       = maxDays > 0 ? Math.min(100, (usedDays / maxDays) * 100) : 0;
        let html = `<div class="row text-center g-2 mb-2">
            <div class="col-4"><h5 class="text-primary mb-0">${remaining}</h5><small class="text-muted">Remaining</small></div>
            <div class="col-4"><h5 class="mb-0">${usedDays}</h5><small class="text-muted">Used</small></div>
            <div class="col-4"><h5 class="mb-0">${maxDays}</h5><small class="text-muted">Annual Limit</small></div>
        </div>
        <div class="progress" style="height:8px;"><div class="progress-bar bg-success" style="width:${pct}%"></div></div>`;
        if (totalDays > 0) {
            const after = remaining - totalDays;
            html += after < 0
                ? `<div class="alert alert-danger mt-2 mb-0 py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>Will exceed annual limit by ${Math.abs(after)} days.</div>`
                : `<div class="alert alert-info mt-2 mb-0 py-2 small"><i class="bi bi-info-circle me-1"></i>After this leave: ${after} days remaining.</div>`;
        }
        $('#lv_balanceInfo').html(html);
    });
}

