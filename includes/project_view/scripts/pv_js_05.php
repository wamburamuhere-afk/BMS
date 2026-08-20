function createBudgetItem() {
    $('#addBudgetForm')[0].reset();
    $('#budget_id_field').val('');

    // Default to the current period — was previously not present at all, so
    // update_budget.php/add_budget.php silently fell back to today's date on
    // every create AND every edit (see editBudgetItem below for the edit fix).
    const _now = new Date();
    $('#proj_budget_year').val(String(_now.getFullYear()));
    $('#proj_budget_month').val(String(_now.getMonth() + 1));

    // Reset breakdown table
    $('#budgetBreakdownTable tbody').empty();
    addBudgetLineItem(); // Add one default row
    updateBudgetGrandTotal();

    // Reset budget name
    $('#budget_category_name').val('');

    // Reset Non-Inventory toggle
    $('#proj_budget_is_service').prop('checked', false);
    $('#proj_budget_is_service_value').val('0');
    $('#proj_budget_svc_toggle_wrap').show();

    // Hide payment info
    $('.payment-info-fields').hide().find('input').prop('required', false);

    // Hide status for creation, set default to draft
    $('#budget_status_container').hide();
    $('#budget_status_field').val('draft').trigger('change');

    $('#addBudgetItemModalTitle').html('<i class="bi bi-plus-circle me-2"></i>Add Project Budget Item');
    $('#addBudgetItemModal .modal-header').removeClass('bg-success').addClass('bg-primary');
    $('#btnSaveBudget').removeClass('btn-success').addClass('btn-primary').html('<i class="bi bi-check-circle me-1"></i> Save Budget');
    $('#addBudgetItemModal').modal('show');
}

function addBudgetLineItem(desc = '', units = '', qty = 1, price = 0, tax = 0) {
    qty = parseFloat(qty) || 1;
    price = parseFloat(price) || 0;
    tax = parseFloat(tax) || 0;
    const total = qty * price * (1 + tax / 100);
    const rowCount = $('#budgetBreakdownTable tbody tr').length + 1;

    const row = `
        <tr style="position: relative;">
            <td class="text-center fw-bold sno-cell">${rowCount}</td>
            <td style="position: relative;">
                <input type="text" name="item_desc[]" class="form-control form-control-sm budget-item-desc"
                       value="${desc}" autocomplete="off" placeholder="Select or search product..."
                       onkeyup="searchBudgetItem(this)" onfocus="searchBudgetItem(this)" onclick="searchBudgetItem(this)" required>
                <div class="budget-autocomplete-list" style="display:none;"></div>
            </td>
            <td><input type="text" name="item_units[]" class="form-control form-control-sm line-unit" value="${units}" placeholder="units"></td>
            <td><input type="number" name="item_qty[]" class="form-control form-control-sm text-center line-qty" value="${qty}" min="0.1" step="any" oninput="calcBudgetRow(this)" required></td>
            <td><input type="number" name="item_price[]" class="form-control form-control-sm text-end line-price" value="${price}" min="0" step="0.01" oninput="calcBudgetRow(this)" required></td>
            <td><input type="number" name="item_tax[]" class="form-control form-control-sm text-end line-tax" value="${tax}" min="0" max="100" step="0.01" oninput="calcBudgetRow(this)" placeholder="0"></td>
            <td><input type="number" name="item_total[]" class="form-control form-control-sm text-end line-total bg-light" value="${total.toFixed(2)}" readonly></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="$(this).closest('tr').remove(); updateBudgetGrandTotal(); renumberBudgetRows();"><i class="bi bi-trash"></i></button></td>
        </tr>
    `;

    $('#budgetBreakdownTable tbody').append(row);
    updateBudgetGrandTotal();
}

function searchBudgetItem(input) {
    const query = input.value.toLowerCase();
    const $tr = $(input).closest('tr');
    const $list = $tr.find('.budget-autocomplete-list');
    
    if (!projectData || !projectData.inventory) {
        $list.hide();
        return;
    }

    const inventory = projectData.inventory;
    let allItems = [];

    // 1. Get items from current stock summary
    if (inventory.stock_summary && Array.isArray(inventory.stock_summary)) {
        inventory.stock_summary.forEach(s => {
            allItems.push({
                product_id: s.product_id,
                product_name: s.product_name,
                unit: s.unit,
                project_balance: s.project_balance,
                price: s.default_cost || 0
            });
        });
    }

    // 2. Add items from purchased list that might not be in stock yet
    if (inventory.purchased_items && Array.isArray(inventory.purchased_items)) {
        inventory.purchased_items.forEach(p => {
            if (!allItems.find(a => a.product_id == p.product_id)) {
                allItems.push({
                    product_id: p.product_id,
                    product_name: p.product_name,
                    unit: p.product_unit || p.unit,
                    project_balance: 0, 
                    price: p.unit_price || 0
                });
            }
        });
    }

    // Filter matches based on query
    const matches = query.length < 1 
        ? allItems 
        : allItems.filter(p => p.product_name.toLowerCase().includes(query));

    if (matches.length > 0) {
        let html = '<div class="budget-autocomplete-header"><span>Item Name</span><span>Unit</span><span>In Project</span><span>Price</span></div>';
        matches.forEach(m => {
            html += `
                <div class="budget-autocomplete-item" onclick="selectBudgetItem(this, '${m.product_name.replace(/'/g, "\\'")}', '${m.unit || ''}', '${m.price}')">
                    <span class="fw-bold text-dark">${m.product_name}</span>
                    <span class="small">${m.unit || '-'}</span>
                    <span class="${m.project_balance > 0 ? 'text-success' : 'text-muted'} fw-bold text-center">${m.project_balance}</span>
                    <span class="text-primary text-end">${formatMoney(m.price)}</span>
                </div>`;
        });
        $list.html(html).show();
    } else {
        $list.hide();
    }
}

function selectBudgetItem(item, name, unit, price) {
    const $tr = $(item).closest('tr');
    $tr.find('.budget-item-desc').val(name);
    $tr.find('.line-unit').val(unit === 'null' || !unit ? '' : unit);
    $tr.find('.line-qty').val(1);
    $tr.find('.line-price').val(price === 'null' ? 0 : price);
    
    $(item).closest('.budget-autocomplete-list').hide();
    calcBudgetRow($tr.find('.line-qty')[0]);
}

// Hide autocomplete when clicking outside
$(document).on('click', function(e) {
    if (!$(e.target).closest('.budget-item-desc').length && !$(e.target).closest('.budget-autocomplete-list').length) {
        $('.budget-autocomplete-list').hide();
    }
});




function renumberBudgetRows() {
    $('#budgetBreakdownTable tbody tr').each(function(index) {
        $(this).find('.sno-cell').text(index + 1);
    });
}

function calcBudgetRow(input) {
    const tr = $(input).closest('tr');
    const qty = parseFloat(tr.find('.line-qty').val()) || 0;
    const price = parseFloat(tr.find('.line-price').val()) || 0;
    const tax = parseFloat(tr.find('.line-tax').val()) || 0;
    const total = qty * price * (1 + tax / 100);
    tr.find('.line-total').val(total.toFixed(2));
    updateBudgetGrandTotal();
}

function updateBudgetGrandTotal() {
    let grand = 0;
    $('.line-total').each(function() {
        grand += parseFloat($(this).val()) || 0;
    });
    $('#budget_allocated_amount').val(grand.toFixed(2));
}

$(document).on('change', '#budget_status_field', function() {
    if ($(this).val() === 'paid') {
        $('.payment-info-fields').slideDown();
        $('.payment-info-fields input').prop('required', true);
    } else {
        $('.payment-info-fields').slideUp();
        $('.payment-info-fields input').prop('required', false);
    }
});

function editBudgetItem(encodedData) {
    const data = JSON.parse(decodeURIComponent(encodedData));
    const form = $('#addBudgetForm');

    // Fill basic fields
    $('#budget_id_field').val(data.budget_id);
    form.find('[name="category_other"]').val(data.category_name);
    // Previously missing entirely — without these, update_budget.php fell back to
    // today's date on every edit, silently moving the budget to the current period.
    if (data.budget_year)  $('#proj_budget_year').val(String(data.budget_year));
    if (data.budget_month) $('#proj_budget_month').val(String(data.budget_month));
    form.find('[name="status"]').val(data.status).trigger('change');
    form.find('[name="notes"]').val(data.notes);
    form.find('[name="payment_reference"]').val(data.payment_reference || '');

    // Parse line items (handle wrapper format {is_service, items:[]} or old array format)
    $('#budgetBreakdownTable tbody').empty();
    let items = [];
    let isService = false;
    try {
        const parsed = typeof data.line_items === 'string' ? JSON.parse(data.line_items) : (data.line_items || []);
        if (Array.isArray(parsed)) {
            items = parsed;
        } else if (parsed && typeof parsed === 'object') {
            isService = parsed.is_service == 1;
            items = parsed.items || [];
        }
    } catch(e) { items = []; }

    // Set Non-Inventory mode from saved data (hide toggle — mode is fixed from saved data)
    $('#proj_budget_is_service').prop('checked', isService);
    $('#proj_budget_is_service_value').val(isService ? '1' : '0');
    $('#proj_budget_svc_toggle_wrap').hide();

    if (items.length > 0) {
        items.forEach(it => {
            addBudgetLineItem(it.desc, it.units || '', it.qty, it.price, it.tax_rate || 0);
        });
    } else {
        addBudgetLineItem();
    }

    // Hide status for editing - sequential workflow managed via View/Actions
    $('#budget_status_container').hide();

    // Update Modal UI
    const btnText = data.status === 'rejected' ? 'Resubmit for Approval' : 'Update Budget';
    const btnIcon = data.status === 'rejected' ? 'bi-check-all' : 'bi-save';

    $('#addBudgetItemModalTitle').html('<i class="bi bi-pencil-square me-2"></i>Edit Budget Item');
    $('#addBudgetItemModal .modal-header').removeClass('bg-success').addClass('bg-primary');
    $('#btnSaveBudget').removeClass('btn-success').addClass('btn-primary').html(`<i class="bi ${btnIcon} me-1"></i> ${btnText}`);

    $('#addBudgetItemModal').modal('show');
}

function deleteBudgetItem(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/delete_budget.php', { budget_id: id }, function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: res.message || 'Budget item has been deleted.',
                        confirmButtonColor: '#3085d6',
                        confirmButtonText: 'OK'
                    });
                    loadProjectDetails();
                } else {
                    Swal.fire('Error', res.message || 'Failed to delete budget item', 'error');
                }
            }, 'json');
        }
    });
}

// Budget Form Submit (Handles both Add and Update)
$(document).on('submit', '#addBudgetForm', function(e) {
    e.preventDefault();
    const $btn = $('#btnSaveBudget');
    const isEdit = $('#budget_id_field').val() !== '';
    const apiUrl = isEdit ? '/api/account/update_budget.php' : '/api/account/add_budget.php';
    
    $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i> Processing...');
    
    // Use FormData for file upload
    const formData = new FormData(this);
    
    $.ajax({
        url: apiUrl,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $('#addBudgetItemModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: res.message || (isEdit ? 'Budget updated successfully' : 'Budget added successfully'),
                    confirmButtonColor: '#3085d6',
                    confirmButtonText: 'OK'
                }).then(() => {
                    loadProjectDetails();
                });
            } else {
                Swal.fire('Error', res.message || 'Failed to process budget', 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Server error occurred', 'error');
        },
        complete: function() {
            const btnText = isEdit ? 'Update Budget' : 'Save Budget';
            const btnIcon = isEdit ? 'bi-save' : 'bi-check-circle';
            $btn.prop('disabled', false).html(`<i class="bi ${btnIcon} me-1"></i> ${btnText}`);
        }
    });
});

function updateBudgetItemStatus(id, status) {
    if (status === 'rejected') {
        Swal.fire({
            title: 'Reject Budget Item?',
            text: 'Please provide a reason for rejection so the preparer can make necessary corrections.',
            input: 'textarea',
            inputLabel: 'Rejection Reason',
            inputPlaceholder: 'Enter the reason why this item is being rejected...',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Reject Item',
            preConfirm: (reason) => {
                if (!reason) {
                    Swal.showValidationMessage('A rejection reason is required');
                    return false;
                }
                return reason;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('/api/account/update_budget_status.php', { 
                    budget_id: id, 
                    status: status, 
                    rejection_reason: result.value 
                }, function(res) {
                    if (res.success) {
                        Swal.fire('Rejected!', 'Budget item has been rejected with your comments.', 'success');
                        loadProjectDetails();
                    } else {
                        Swal.fire('Error', res.message || 'Failed to update status', 'error');
                    }
                }, 'json');
            }
        });
    } else {
        const title = 'Approve Budget Item?';
        const text = 'This item will be counted towards the project budget.';
        const confirmColor = '#28a745';

        Swal.fire({
            title: title,
            text: text,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: confirmColor,
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Approve!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('/api/account/update_budget_status.php', { budget_id: id, status: status }, function(res) {
                    if (res.success) {
                        Swal.fire('Approved!', res.message, 'success');
                        loadProjectDetails();
                    } else {
                        Swal.fire('Error', res.message || 'Failed to update status', 'error');
                    }
                }, 'json');
            }
        });
    }
}

function changeOrderStatus(id, currentStatus) {
    const statuses = ['draft', 'sent', 'approved', 'rejected', 'partially_invoiced', 'invoiced', 'cancelled'];
    let options = {};
    statuses.forEach(s => options[s] = s.toUpperCase());

    Swal.fire({
        title: 'Change Order Status',
        input: 'select',
        inputOptions: options,
        inputValue: currentStatus,
        showCancelButton: true,
        confirmButtonText: 'Update'
    }).then((result) => {
        if (result.isConfirmed) {
            updateOrderStatus(id, result.value);
        }
    });
}

function updateOrderStatus(id, status) {
    $.post('/api/account/update_sales_order_status.php', { id: id, status: status }, function(res) {
        if (res.success) {
            Swal.fire('Updated', res.message, 'success');
            loadProjectDetails();
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
}

function changeInvoiceStatus(id, currentStatus) {
    const statuses = ['draft', 'sent', 'paid', 'partially_paid', 'overdue', 'cancelled'];
    let options = {};
    statuses.forEach(s => options[s] = s.toUpperCase());

    Swal.fire({
        title: 'Change Invoice Status',
        input: 'select',
        inputOptions: options,
        inputValue: currentStatus,
        showCancelButton: true,
        confirmButtonText: 'Update'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/update_invoice_status.php', { id: id, status: result.value }, function(res) {
                if (res.success) {
                    Swal.fire('Updated', res.message, 'success');
                    loadProjectDetails();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function changePurchaseStatus(id, currentStatus) {
    const statuses = ['draft', 'sent', 'ordered', 'received', 'partially_received', 'cancelled'];
    let options = {};
    statuses.forEach(s => options[s] = s.toUpperCase());

    Swal.fire({
        title: 'Change Purchase Order Status',
        input: 'select',
        inputOptions: options,
        inputValue: currentStatus,
        showCancelButton: true,
        confirmButtonText: 'Update'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/update_purchase_order_status.php', { id: id, status: result.value }, function(res) {
                if (res.success) {
                    Swal.fire('Updated', res.message, 'success');
                    loadProjectDetails();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}


function addNote() {
    Swal.fire({
        title: 'Add Note',
        input: 'textarea',
        inputLabel: 'Project Note',
        inputPlaceholder: 'Enter your note here...',
        showCancelButton: true,
        confirmButtonText: 'Save Note',
        preConfirm: (note) => {
            if (!note || !note.trim()) {
                Swal.showValidationMessage('Please enter a note');
                return false;
            }
            return note.trim();
        }
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post(APP_URL + '/api/operations/add_project_note.php', { project_id: <?= $project_id ?>, note: result.value }, function(res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: 'Saved!', text: 'Your note has been added.', timer: 1500, showConfirmButton: false });
                clearNoteFilters();
            } else {
                Swal.fire('Error', (res && res.message) || 'Failed to save note.', 'error');
            }
        }, 'json').fail(function(xhr) {
            let msg = 'Could not save the note. Please try again.';
            if (xhr) {
                if (xhr.status === 403) msg = 'You do not have permission to add notes to this project.';
                else if (xhr.status === 419) msg = 'Your security token expired. Please refresh and try again.';
                else { try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {} }
            }
            Swal.fire('Error', msg, 'error');
        });
    });
}

function generateFinancialReport() {
    logReportAction('Generated Project Financial Report', 'User generated a professional financial report for project ID: ' + projectId);
    Swal.fire({
        title: 'Generating Report...',
        text: 'Please wait while we prepare your professional financial summary report',
        icon: 'info',
        showConfirmButton: false,
        timer: 1500
    }).then(() => {
        const reportUrl = '<?= getUrl("project-financial-report") ?>?id=' + projectId;
        window.open(reportUrl, '_blank');
    });
}

// ===== IN-PLACE ACTION HANDLERS =====

function showActionSuccess(message) {
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: message || 'Action completed successfully.',
        confirmButtonColor: '#3085d6',
        confirmButtonText: 'OK'
    }).then(() => {
        loadProjectDetails();
    });
}

// Invoice Actions
function changeInvoiceStatus(id, current) {
    const statuses = { 'draft': 'Draft', 'pending': 'Pending', 'sent': 'Sent', 'partial': 'Partial', 'paid': 'Paid', 'overdue': 'Overdue', 'cancelled': 'Cancelled' };
    let options = '';
    for (let k in statuses) options += `<option value="${k}" ${k === current ? 'selected' : ''}>${statuses[k]}</option>`;

    Swal.fire({
        title: 'Change Invoice Status',
        html: `<select id="swal-inv-status" class="form-select mt-3">${options}</select>`,
        showCancelButton: true,
        confirmButtonText: 'Update Status',
        confirmButtonColor: '#3085d6',
        preConfirm: () => document.getElementById('swal-inv-status').value
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/update_invoice_status.php', { invoice_id: id, status: result.value }, res => {
                if (res.success) showActionSuccess(res.message);
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

function buildInvoiceActions(i) {
    let a = `<li><a class="dropdown-item py-2" href="invoice_view?id=${i.invoice_id}"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>`;

    if (i.status === 'pending') {
        a += `<li><hr class="dropdown-divider opacity-50"></li>`;
        a += `<li><a class="dropdown-item py-2 text-warning fw-bold" href="javascript:void(0)" onclick="reviewInvoice(${i.invoice_id})"><i class="bi bi-search me-2"></i>Review</a></li>`;
    }
    if (i.status === 'reviewed') {
        a += `<li><hr class="dropdown-divider opacity-50"></li>`;
        a += `<li><a class="dropdown-item py-2 text-success fw-bold" href="javascript:void(0)" onclick="approveInvoice(${i.invoice_id})"><i class="bi bi-check-circle me-2"></i>Approve</a></li>`;
    }
    if (['pending', 'reviewed'].includes(i.status)) {
        a += `<li><a class="dropdown-item py-2" href="invoice_edit?id=${i.invoice_id}"><i class="bi bi-pencil text-info me-2"></i>Edit Invoice</a></li>`;
    }

    a += `<li><a class="dropdown-item py-2" href="invoice_print?id=${i.invoice_id}" target="_blank"><i class="bi bi-printer text-secondary me-2"></i>Print Invoice</a></li>`;

    if (i.status === 'approved' && parseFloat(i.balance_due) > 0) {
        a += `<li><hr class="dropdown-divider opacity-50"></li>`;
        a += `<li><a class="dropdown-item py-2 text-success fw-bold" href="payment_create?invoice=${i.invoice_id}"><i class="bi bi-cash-coin me-2"></i>Record Payment</a></li>`;
    }
    if (INVOICE_CAN_DELETE) {
        a += `<li><hr class="dropdown-divider opacity-50"></li>`;
        a += `<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteInvoice(${i.invoice_id})"><i class="bi bi-trash me-2"></i>Delete</a></li>`;
    }
    return a;
}

function reviewInvoice(id) {
    Swal.fire({ title: 'Mark as Reviewed?', text: 'Status will change to Reviewed.', icon: 'question', showCancelButton: true, confirmButtonText: 'Review' }).then(result => {
        if (!result.isConfirmed) return;
        $.post('/api/account/update_invoice_status.php', { invoice_id: id, status: 'reviewed' }, res => {
            if (res.success) { Swal.fire({ icon: 'success', title: 'Reviewed', text: res.message, timer: 1500, showConfirmButton: false }).then(() => loadProjectData()); }
            else Swal.fire('Error', res.message, 'error');
        }, 'json');
    });
}

function approveInvoice(id) {
    Swal.fire({ title: 'Approve this Invoice?', text: 'Status will change to Approved.', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve', confirmButtonColor: '#198754' }).then(result => {
        if (!result.isConfirmed) return;
        $.post('/api/account/update_invoice_status.php', { invoice_id: id, status: 'approved' }, res => {
            if (res.success) { Swal.fire({ icon: 'success', title: 'Approved', text: res.message, timer: 1500, showConfirmButton: false }).then(() => loadProjectData()); }
            else Swal.fire('Error', res.message, 'error');
        }, 'json');
    });
}

function deleteInvoice(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This invoice will be permanently deleted!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/delete_invoice.php', { invoice_id: id }, res => {
                if (res.success) showActionSuccess(res.message);
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

// Return Note Actions
function changeReturnStatus(id, currentStatus) {
    let options = {};
    if (currentStatus === 'pending') {
        options = {
            'approved': 'APPROVE RETURN',
            'cancelled': 'CANCEL RETURN'
        };
    } else if (currentStatus === 'approved') {
        options = {
            'completed': 'MARK AS COMPLETED',
            'cancelled': 'CANCEL RETURN'
        };
    } else {
        return; // No actions for completed
    }

    Swal.fire({
        title: 'Update Return Note Status',
        input: 'select',
        inputOptions: options,
        inputPlaceholder: 'Select an action...',
        showCancelButton: true,
        confirmButtonText: 'Proceed',
        confirmButtonColor: '#3085d6',
        inputValidator: (value) => {
            if (!value) return 'You must select an action';
        }
    }).then((result) => {
        if (result.isConfirmed) {
            updateReturnStatus(id, result.value);
        }
    });
}

function updateReturnStatus(id, status) {
    const titles = {
        'approved': 'Approve Return Note?',
        'completed': 'Mark Return Note as Completed?',
        'cancelled': 'Cancel Return Note?'
    };
    const texts = {
        'approved': 'Approving this will prepare stock for return deducton.',
        'completed': 'Completing this will finalize stock adjustments. This action is permanent.',
        'cancelled': 'Are you sure you want to cancel this return note?'
    };

    Swal.fire({
        title: titles[status] || 'Update Status?',
        text: texts[status] || 'This will change the return note status.',
        icon: status === 'cancelled' ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: status === 'cancelled' ? '#d33' : '#3085d6',
        confirmButtonText: 'Yes, Proceed'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/update_purchase_return_status.php', { return_id: id, status: status }, function(res) {
                if (res.success) {
                    showActionSuccess(res.message);
                } else {
                    Swal.fire('Error', res.message || 'Update failed', 'error');
                }
            }, 'json');
        }
    });
}

function deleteReturn(id) {
    Swal.fire({
        title: 'Delete Return Note?',
        text: "This record will be permanently removed!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/delete_purchase_return.php', { return_id: id }, function(res) {
                if (res.success) {
                    showActionSuccess(res.message);
                } else {
                    Swal.fire('Error', res.message || 'Delete failed', 'error');
                }
            }, 'json');
        }
    });
}

// ── NIP Materials (internal / project-scoped) ──────────────────────────────

let nipMatRowCount = 0;
let nipMatProductList = [];
let nipMatWarehouseList = [];

// Load form data when modal opens
document.getElementById('addNipMaterialsModal')?.addEventListener('show.bs.modal', function() {
    const projectId = <?= intval($project_id) ?>;
    $.getJSON('<?= getUrl('api/get_nip_materials_form_data') ?>?project_id=' + projectId, function(res) {
        if (!res.success) return;
        nipMatProductList = res.nip_products || [];
        nipMatWarehouseList = res.warehouses || [];

        const $prod = $('#nipMatProductId');
        $prod.find('option:not(:first)').remove();
        nipMatProductList.forEach(p => {
            const label = p.product_name + (p.component_count > 0 ? ' (' + p.component_count + ' existing)' : '');
            $prod.append(`<option value="${p.product_id}" data-cost="${p.cost_price}" data-sku="${p.sku}">${label}</option>`);
        });

        const $wh = $('#nipMatWarehouseId');
        $wh.find('option:not(:first)').remove();
        nipMatWarehouseList.forEach(w => {
            $wh.append(`<option value="${w.warehouse_id}">${w.warehouse_name}</option>`);
        });

        // Reset table
        $('#nipMatTbody').empty();
        nipMatRowCount = 0;
        addNipMatRow();
        $('#nipMatCostPreview').val('');
    });
});

// NIP product info hint
$('#nipMatProductId').on('change', function() {
    const opt = $(this).find('option:selected');
    const cost = parseFloat(opt.data('cost')) || 0;
    const sku  = opt.data('sku') || '';
    $('#nipMatProductInfo').text(sku ? 'SKU: ' + sku + ' | Current Cost: TSh ' + cost.toLocaleString() : '');
});

// Tax rate custom toggle
$('#nipMatTaxRate').on('change', function() {
    $('#nipMatCustomTaxWrap').toggle($(this).val() === 'custom');
});

function addNipMatRow(data) {
    nipMatRowCount++;
    const idx = nipMatRowCount;
    const u = (data && data.unit) ? data.unit : 'EA';
    const q = (data && data.qty_per_unit) ? data.qty_per_unit : 1;
    const name = (data && data.product_name) ? data.product_name : '';
    const pid = (data && data.product_id) ? data.product_id : '';
    const html = `<tr id="nip-mat-row-${idx}">
        <td class="text-center text-muted nip-mat-sno">${idx}</td>
        <td>
            <div class="position-relative">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0" placeholder="Search product…"
                        onkeyup="searchNipMatProduct(this, ${idx})" onclick="searchNipMatProduct(this, ${idx})"
                        value="${name}" autocomplete="off">
                    <input type="hidden" name="components[${idx}][product_id]" value="${pid}" id="nip-mat-pid-${idx}">
                </div>
                <div id="nip-mat-results-${idx}" class="position-absolute bg-white shadow-sm rounded-3 border d-none text-start" style="z-index:1060;width:380px;max-height:200px;overflow-y:auto;top:100%;left:0;"></div>
            </div>
        </td>
        <td>
            <input type="text" name="components[${idx}][unit]" class="form-control form-control-sm text-center" value="${u}" id="nip-mat-unit-${idx}">
        </td>
        <td>
            <input type="number" name="components[${idx}][qty_per_unit]" class="form-control form-control-sm text-end" value="${q}" min="0.001" step="any" required id="nip-mat-qty-${idx}">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="$('#nip-mat-row-${idx}').remove(); renumberNipMatRows();">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>`;
    $('#nipMatTbody').append(html);
}

function renumberNipMatRows() {
    $('#nipMatTbody tr').each(function(i) {
        $(this).find('.nip-mat-sno').text(i + 1);
    });
}

function searchNipMatProduct(input, idx) {
    const query = input.value;
    const warehouseId = $('#nipMatWarehouseId').val();
    const $results = $('#nip-mat-results-' + idx);

    if (!warehouseId) {
        $results.html('<div class="p-2 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> Please select a warehouse first</div>').removeClass('d-none');
        return;
    }
    $results.html('<div class="p-3 text-muted small"><div class="spinner-border spinner-border-sm me-2 text-primary"></div>Searching…</div>').removeClass('d-none');

    fetch(`${APP_URL}/api/account/get_products.php?search=${encodeURIComponent(query)}&warehouse_id=${warehouseId}&limit=10`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                const rows = data.data.map(p => {
                    const cost = parseFloat(p.cost_price) || parseFloat(p.purchase_price) || 0;
                    const safeP = JSON.stringify(p).replace(/'/g, "&#39;");
                    return `<button type="button" class="list-group-item list-group-item-action p-2 border-bottom"
                        onclick='selectNipMatProduct(${idx}, ${safeP})'>
                        <div class="d-flex justify-content-between align-items-center">
                            <div><div class="fw-bold small">${p.product_name}</div>
                            <div class="text-muted" style="font-size:11px;">${p.sku || ''}</div></div>
                            <span class="fw-bold text-primary small">TZS ${cost.toLocaleString()}</span>
                        </div></button>`;
                }).join('');
                $results.html(`<div class="list-group list-group-flush rounded-3">${rows}</div>`).removeClass('d-none');
            } else {
                $results.html('<div class="p-3 text-muted small">No products found in this warehouse</div>').removeClass('d-none');
            }
        });
}

function selectNipMatProduct(idx, prod) {
    $(`#nip-mat-results-${idx}`).addClass('d-none');
    $(`#nip-mat-pid-${idx}`).val(prod.product_id);
    $(`#nip-mat-unit-${idx}`).val(prod.unit || 'EA');
    $(`input[onkeyup="searchNipMatProduct(this, ${idx})"]`).val(prod.product_name);
}

// Close search dropdowns when clicking outside
$(document).on('click', function(e) {
    if (!$(e.target).closest('#nipMatTbody').length) {
        $('[id^="nip-mat-results-"]').addClass('d-none');
    }
});

// ── PROC MATERIALS: JS constants ─────────────────────────────────────────
const PROC_PROJECT_ID   = <?= intval($project_id) ?>;
const PROC_ML_BASE_URL  = '<?= rtrim(getUrl(''), '/') ?>';
const PROC_ML_CO_NAME   = '<?= addslashes($company_name) ?>';
const PROC_ML_CO_LOGO   = '<?= !empty($company_logo) ? addslashes(getUrl($company_logo)) : '' ?>';
const PROC_ML_USER      = '<?= addslashes(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))) ?>';
const PROC_ML_ROLE      = '<?= addslashes(ucwords($_SESSION['user_role'] ?? 'Staff')) ?>';
const PROC_PROJECT_NIPS  = <?= json_encode(array_values($proj_nip_products)) ?>;
const PROC_VIEW_ML_URL   = '<?= getUrl('view_material_list') ?>';

// ── Load / render ─────────────────────────────────────────────────────────
var procMatAllData = [];

function loadProcMaterials() {
    $('#procMaterialsCard').hide();
    $('#procMaterialsEmpty').show();
    if ($.fn.DataTable.isDataTable('#procMatTable')) { $('#procMatTable').DataTable().destroy(); }
    $('#procMatTableBody').empty();
    $.getJSON(PROC_ML_BASE_URL + '/api/get_material_lists.php?project_id=' + PROC_PROJECT_ID, function(res) {
        if (!res.success || !res.lists || res.lists.length === 0) {
            procMatAllData = [];
            $('#procMaterialsEmpty').show();
            $('#procMaterialsCard').hide();
            return;
        }
        procMatAllData = res.lists;
        var tbody = '';
        res.lists.forEach(function(r, i) {
            var listNo   = r.list_no || ('ML-' + (r.created_at || '').slice(0,10).replace(/-/g,'') + '-' + String(r.id).padStart(4,'0'));
            var warehouse = r.warehouse_name || '';
            var safeName  = r.name.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
            tbody += '<tr>'
                + '<td class="text-center text-muted fw-bold">' + (i + 1) + '</td>'
                + '<td><div class="fw-bold text-dark">' + r.name + '</div>'
                + '<small class="text-muted">' + r.nip_count + ' NIP' + (r.nip_count != 1 ? 's' : '') + '</small></td>'
                + '<td class="text-center"><span class="badge bg-primary" style="font-size:.8rem;letter-spacing:.5px;">' + listNo + '</span></td>'
                + '<td class="text-center text-muted">' + warehouse + '</td>'
                + '<td class="text-center pe-3 d-print-none">'
                + '<div class="dropdown">'
                + '<button class="btn btn-sm btn-light border dropdown-toggle px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>'
                + '<ul class="dropdown-menu dropdown-menu-end shadow border-0">'
                + '<li><a class="dropdown-item py-2" href="' + PROC_VIEW_ML_URL + '?id=' + r.id + '"><i class="bi bi-eye text-primary me-2"></i> View</a></li>'
                + '<li><hr class="dropdown-divider"></li>'
                + '<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="procMlEditOpen(' + r.id + ', \'' + safeName + '\')"><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>'
                + '<li><hr class="dropdown-divider"></li>'
                + '<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="procMlDeleteList(' + r.id + ', \'' + safeName + '\')"><i class="bi bi-trash me-2"></i> Delete</a></li>'
                + '</ul></div></td>'
                + '</tr>';
        });
        $('#procMatTableBody').html(tbody);
        $('#procMaterialsEmpty').hide();
        $('#procMaterialsCard').show();
        $('#procMatTable').DataTable({
            responsive: true,
            pageLength: 25,
            autoWidth: false,
            columnDefs: [{ orderable: false, targets: [0, 4] }]
        });
        if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('procMatTable');
    }).fail(function() {
        $('#procMaterialsEmpty').html('<div class="alert alert-danger">Failed to load materials. Please try again.</div>');
    });
}

// ── Edit Materials (List) ─────────────────────────────────────────────────
