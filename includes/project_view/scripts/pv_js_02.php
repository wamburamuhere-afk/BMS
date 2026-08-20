function getFileIcon(ext) {
    ext = (ext || '').toLowerCase();
    const icons = {
        'pdf': 'bi-file-earmark-pdf',
        'doc': 'bi-file-earmark-word',
        'docx': 'bi-file-earmark-word',
        'xls': 'bi-file-earmark-excel',
        'xlsx': 'bi-file-earmark-excel',
        'jpg': 'bi-file-earmark-image',
        'jpeg': 'bi-file-earmark-image',
        'png': 'bi-file-earmark-image',
        'gif': 'bi-file-earmark-image',
        'zip': 'bi-file-earmark-zip',
        'txt': 'bi-file-earmark-text'
    };
    return icons[ext] || 'bi-file-earmark';
}

function getFileColor(ext) {
    ext = (ext || '').toLowerCase();
    if (ext === 'pdf') return 'danger';
    if (['doc', 'docx'].includes(ext)) return 'primary';
    if (['xls', 'xlsx'].includes(ext)) return 'success';
    if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) return 'info';
    return 'secondary';
}

function populateEditForm(project) {
    $('#edit_project_id').val(project.project_id);
    $('#edit_project_name').val(project.project_name);
    
    // Customer dropdown
    $('#edit_customerSelect').val(project.customer_id || '').trigger('change');
    if (!project.customer_id) {
        $('#edit_client_name_hidden').val(project.client_name || '');
    }
    
    // Discipline - modern other
    const discCont = $('#edit_disciplineContainer');
    if (project.discipline === 'Other') {
        discCont.find('select').hide().val('Other');
        discCont.find('.modern-input-wrapper').show().find('input').val(project.discipline_other || '').prop('required', true);
    } else {
        discCont.find('select').show().val(project.discipline || '');
        discCont.find('.modern-input-wrapper').hide().find('input').val('').prop('required', false);
    }
    
    // Position - modern other
    const posCont = $('#edit_positionContainer');
    if (project.role_position === 'Other') {
        posCont.find('select').hide().val('Other');
        posCont.find('.modern-input-wrapper').show().find('input').val(project.role_position_other || '').prop('required', true);
    } else {
        posCont.find('select').show().val(project.role_position || '');
        posCont.find('.modern-input-wrapper').hide().find('input').val('').prop('required', false);
    }
    
    $('#edit_project_manager').val(project.project_manager || '');
    $('#edit_priority').val(project.priority);
    $('#edit_start_date').val(project.start_date);
    $('#edit_deadline').val(project.deadline || '');
    $('#edit_status').val(project.status);
    $('#edit_description').val(project.description || '');
    
    if (project.contract_attachment) {
        $('#edit_current_attachment').html(`<i class="bi bi-check-circle-fill text-success"></i> Existing: <a href="${APP_URL}/${project.contract_attachment}" target="_blank">View File</a>`);
    } else {
        $('#edit_current_attachment').html('<i class="bi bi-exclamation-circle text-warning"></i> No file attached');
    }
}

function renderTables(data) {
    // Update New Summary Counts
    $('#countSalesOrders').text(data.sales_orders.length);
    $('#countInvoices').text(data.invoices.length);
    $('#countPurchases').text(data.purchase_orders.length);
    $('#countVouchers').text(data.payment_vouchers.length);
    
    // Render Legacy counters (if any)
    $('#salesCount').text(data.sales_orders.length);
    $('#invoicesCount').text(data.invoices.length);
    $('#vouchersCount').text(data.payment_vouchers.length);
    $('#purchasesCount').text(data.purchase_orders.length);
    $('#expensesCount').text(data.expenses.length);
    
    // Render Tab Data (Full Tables & Expenses/Budgets)
    renderExpenses(data.expenses);
    loadProjectBudgetsAjax(1);
    // Inventory is no longer in this response — it loads independently via
    // loadProjectInventory() when the Inventory tab is opened (see doc-ready).

    // Render Documents and update tab count
    const totalDocs = renderDocs(data);
    $('#docs-tab span.badge').remove();
    if (totalDocs > 0) {
        $('#docs-tab').append(`<span class="badge bg-danger ms-2" style="font-size: 0.6rem;">${totalDocs}</span>`);
        $('#countDocuments').text(totalDocs); // For overview card
    } else {
        $('#countDocuments').text('0');
    }

    // Render Staff and update tab badge
    const totalStaff = data.staff ? data.staff.length : 0;
    renderProjectStaff(data.staff || []);
    $('#staff-tab span.badge').remove();
    if (totalStaff > 0) {
        $('#staff-tab').append(`<span class="badge bg-primary ms-2" style="font-size: 0.6rem;"></span>`);
    }

    // Render Suppliers and update tab badge
    const totalSuppliers = data.project_suppliers ? data.project_suppliers.length : 0;
    renderProjectSuppliers(data.project_suppliers || []);
    $('#suppliers-tab span.badge').remove();
    
    // Render Full Tab Tables
    renderSalesOrdersFull(data.sales_orders);
    renderInvoicesFull(data.invoices);
    renderPurchasesFull(data.purchase_orders);
    renderPurchases(data.purchase_orders);
    renderGRNs(data.grns);
    renderDNs(data.dns || []);
    renderDOs(data.dos || []);
    renderReturns(data.purchase_returns);
    renderProjectDebitNotes(data.debit_notes || []);
    renderRFQs(data.rfqs || []);
    renderVouchersFull(data.payment_vouchers);
    renderReports(data.financial_summary, data.progress_analysis);
}

function renderSalesOrders(orders) {
    const $list = $('#salesOrdersTable');
    if (orders.length === 0) {
        $list.html('<div class="p-4 text-center text-muted"><i class="bi bi-cart shadow-sm p-3 rounded-circle mb-3 d-inline-block"></i><p>No Sales Orders yet</p></div>');
        return;
    }
    
    let html = '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Order #</th><th>Amount</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
    orders.slice(0, 5).forEach(o => {
        html += `<tr>
            <td><span class="fw-bold">${o.order_number}</span></td>
            <td>${formatMoney(o.grand_total)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(o.status)}">${o.status}</span></td>
            <td class="text-end">
                <a href="sales_order_view?id=${o.sales_order_id}" class="btn btn-sm btn-light border"><i class="bi bi-eye"></i></a>
            </td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $list.html(html);
}

function renderSalesOrdersFull(orders) {
    const $list = $('#salesOrdersTableFull');
    if (!orders || orders.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-cart fs-1 mb-3"></i><p>No sales orders linked to this project.</p></div>');
        return;
    }

    // Shared Actions dropdown (used by both the desktop table and the mobile cards)
    const soActions = (o) => `
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-gear"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                <li><a class="dropdown-item py-2" href="sales_order_view?id=${o.sales_order_id}"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
                <li><a class="dropdown-item py-2" href="sales_order_edit?id=${o.sales_order_id}"><i class="bi bi-pencil text-info me-2"></i>Edit Order</a></li>
                <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="changeOrderStatus(${o.sales_order_id}, '${o.status}')"><i class="bi bi-arrow-repeat text-warning me-2"></i>Change Status</a></li>
                <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="updateOrderStatus(${o.sales_order_id}, 'approved')"><i class="bi bi-check-circle text-success me-2"></i>Approve Order</a></li>
                <li><a class="dropdown-item py-2" href="invoice_create?id=${o.sales_order_id}"><i class="bi bi-receipt text-success me-2"></i>Create Invoice</a></li>
                <li><a class="dropdown-item py-2 text-warning" href="javascript:void(0)" onclick="updateOrderStatus(${o.sales_order_id}, 'cancelled')"><i class="bi bi-x-octagon me-2"></i>Cancel Order</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteOrder(${o.sales_order_id})"><i class="bi bi-trash me-2"></i>Delete</a></li>
            </ul>
        </div>`;

    // Desktop: DataTable (search / sort / paging). Hidden on small screens.
    let html = '<div class="d-none d-lg-block"><table id="salesOrdersDT" class="table table-hover align-middle border w-100"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>Order Number</th><th>Customer</th><th>Order Date</th><th>Subtotal</th><th>Tax</th><th>Grand Total</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    orders.forEach((o, idx) => {
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td><a href="sales_order_view?id=${o.sales_order_id}" class="fw-bold text-primary">${o.order_number}</a></td>
            <td>${o.customer_name || 'N/A'}</td>
            <td>${formatDate(o.order_date)}</td>
            <td>${formatMoney(o.subtotal)}</td>
            <td>${formatMoney(o.tax_amount)}</td>
            <td class="fw-bold text-dark">${formatMoney(o.grand_total)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(o.status)}">${o.status}</span></td>
            <td class="text-end d-print-none">${soActions(o)}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';

    // Mobile: card view (matches the rest of the system). Shown below lg.
    html += '<div class="d-lg-none row g-2">';
    orders.forEach((o) => {
        html += `<div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <a href="sales_order_view?id=${o.sales_order_id}" class="fw-bold text-primary">${o.order_number}</a>
                        <span class="badge bg-${getStatusBadgeColor(o.status)}">${o.status}</span>
                    </div>
                    <div class="small text-muted mb-1"><i class="bi bi-person me-1"></i>${o.customer_name || 'N/A'}</div>
                    <div class="small text-muted mb-2"><i class="bi bi-calendar3 me-1"></i>${formatDate(o.order_date)}</div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-dark">${formatMoney(o.grand_total)}</span>
                        ${soActions(o)}
                    </div>
                </div>
            </div>
        </div>`;
    });
    html += '</div>';

    $list.html(html);
    if ($.fn.DataTable.isDataTable('#salesOrdersDT')) $('#salesOrdersDT').DataTable().destroy();
    $('#salesOrdersDT').DataTable({ pageLength: 25, autoWidth: false, order: [[3, 'desc']], columnDefs: [{ orderable: false, targets: [0, 8] }] });
}

function renderInvoices(invoices) {
    const $list = $('#invoicesTable');
    if (invoices.length === 0) {
        $list.html('<div class="p-4 text-center text-muted"><i class="bi bi-receipt shadow-sm p-3 rounded-circle mb-3 d-inline-block"></i><p>No Invoices yet</p></div>');
        return;
    }
    
    let html = '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Invoice #</th><th>Amount</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
    invoices.slice(0, 5).forEach(i => {
        html += `<tr>
            <td><span class="fw-bold">${i.invoice_number}</span></td>
            <td class="text-success fw-bold">${formatMoney(i.grand_total)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(i.status)}">${i.status}</span></td>
            <td class="text-end">
                <a href="invoice_view?id=${i.invoice_id}" class="btn btn-sm btn-light border"><i class="bi bi-eye"></i></a>
            </td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $list.html(html);
}

function renderInvoicesFull(invoices) {
    const $list = $('#invoicesTableFull');
    if (!invoices || invoices.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-receipt fs-1 mb-3"></i><p>No invoices linked to this project.</p></div>');
        return;
    }

    // Shared Actions dropdown (desktop table + mobile cards)
    const invActions = (i) => `
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-gear"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                ${buildInvoiceActions(i)}
            </ul>
        </div>`;

    // Desktop: DataTable (search / sort / paging). Hidden on small screens.
    let html = '<div class="d-none d-lg-block"><table id="invoicesDT" class="table table-hover align-middle border w-100"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>Invoice Number</th><th>Customer</th><th>Date</th><th>Subtotal</th><th>Discount</th><th>Tax</th><th>Grand Total</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    invoices.forEach((i, idx) => {
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td><a href="invoice_view?id=${i.invoice_id}" class="fw-bold text-success">${i.invoice_number}</a></td>
            <td>${i.customer_name || 'N/A'}</td>
            <td>${formatDate(i.invoice_date)}</td>
            <td>${formatMoney(i.subtotal)}</td>
            <td class="text-danger">${formatMoney(i.discount_amount)}</td>
            <td>${formatMoney(i.tax_amount)}</td>
            <td class="fw-bold text-success">${formatMoney(i.grand_total)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(i.status)}">${i.status}</span></td>
            <td class="text-end d-print-none">${invActions(i)}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';

    // Mobile: card view (matches the rest of the system). Shown below lg.
    html += '<div class="d-lg-none row g-2">';
    invoices.forEach((i) => {
        html += `<div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <a href="invoice_view?id=${i.invoice_id}" class="fw-bold text-success">${i.invoice_number}</a>
                        <span class="badge bg-${getStatusBadgeColor(i.status)}">${i.status}</span>
                    </div>
                    <div class="small text-muted mb-1"><i class="bi bi-person me-1"></i>${i.customer_name || 'N/A'}</div>
                    <div class="small text-muted mb-2"><i class="bi bi-calendar3 me-1"></i>${formatDate(i.invoice_date)}</div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-success">${formatMoney(i.grand_total)}</span>
                        ${invActions(i)}
                    </div>
                </div>
            </div>
        </div>`;
    });
    html += '</div>';

    $list.html(html);
    if ($.fn.DataTable.isDataTable('#invoicesDT')) $('#invoicesDT').DataTable().destroy();
    $('#invoicesDT').DataTable({ pageLength: 25, autoWidth: false, order: [[3, 'desc']], columnDefs: [{ orderable: false, targets: [0, 9] }] });
}

// The Sales-section DataTables are built while their tab is hidden, so their
// column widths need a nudge the first time the tab becomes visible.
$(document).on('shown.bs.tab', '[data-bs-target="#sales"]', function () {
    if ($.fn.DataTable.isDataTable('#salesOrdersDT')) $('#salesOrdersDT').DataTable().columns.adjust();
});
$(document).on('shown.bs.tab', '[data-bs-target="#invoices"]', function () {
    if ($.fn.DataTable.isDataTable('#invoicesDT')) $('#invoicesDT').DataTable().columns.adjust();
});
$(document).on('shown.bs.tab', '[data-bs-target="#proj-ipc"]', function () {
    if ($.fn.DataTable.isDataTable('#proj-ipc-table')) $('#proj-ipc-table').DataTable().columns.adjust();
});
$(document).on('shown.bs.tab', '[data-bs-target="#expenses"]', function () {
    if ($.fn.DataTable.isDataTable('#projExpensesDT')) $('#projExpensesDT').DataTable().columns.adjust();
});
$(document).on('shown.bs.tab', '[data-bs-target="#vouchers"]', function () {
    if ($.fn.DataTable.isDataTable('#projVouchersDT')) $('#projVouchersDT').DataTable().columns.adjust();
});
$(document).on('shown.bs.tab', '[data-bs-target="#budget"]', function () {
    if ($.fn.DataTable.isDataTable('#budgetListTable')) $('#budgetListTable').DataTable().columns.adjust();
});

// ── Project Received Invoices (supplier_invoices WHERE project_id = ?) ────────
let projRiLoaded = false;

function loadProjectReceivedInvoices() {
    $('#proj-ri-content').html('<div class="py-5 text-center text-muted"><span class="spinner-border spinner-border-sm me-2"></span> Loading...</div>');
    const riParams = { action: 'list', project_id: <?= $project_id ?> };
    <?php if ($supplier_mode): ?>riParams.supplier_id = <?= $view_supplier_id ?>;<?php endif; ?>
    $.getJSON('<?= buildUrl('api/received_invoices.php') ?>', riParams, function(res) {
        if (res.success) {
            renderProjectReceivedInvoices(res.data);
            projRiLoaded = true;
        } else {
            $('#proj-ri-content').html('<div class="py-4 text-center text-danger"><i class="bi bi-exclamation-circle me-2"></i>' + (res.message || 'Failed to load invoices.') + '</div>');
        }
    }).fail(function() {
        $('#proj-ri-content').html('<div class="py-4 text-center text-danger"><i class="bi bi-exclamation-circle me-2"></i> Server error. Please refresh and try again.</div>');
    });
}

// ── Project Bills (received_invoices) — mirrors the standalone Bills page ────
// Columns match the external Bills page; Project is implicit (we are inside one)
// so it is not shown. Create / Edit / Record Payment delegate to the external
// page with this project pre-selected and locked.
const PRI_VIEW_URL    = '<?= getUrl('received_invoices_view') ?>';
const PRI_PAGE_URL    = '<?= getUrl('received_invoices') ?>';
const PRI_API_URL     = '<?= buildUrl('api/received_invoices.php') ?>';
const PRI_CSRF        = '<?= csrf_token() ?>';
const PRI_PROJECT_ID  = <?= (int)$project_id ?>;
const PRI_CAN_EDIT    = <?= json_encode($ri_can_edit) ?>;
const PRI_CAN_DELETE  = <?= json_encode($ri_can_delete) ?>;
const PRI_CAN_REVIEW  = <?= json_encode($ri_can_review) ?>;
const PRI_CAN_APPROVE = <?= json_encode($ri_can_approve) ?>;

function priTypeBadge(t) {
    return t === 'supplier'
        ? '<span class="badge bg-primary"><i class="bi bi-building me-1"></i>Supplier</span>'
        : '<span class="badge bg-info text-dark"><i class="bi bi-people me-1"></i>Sub-Contractor</span>';
}

function priStatusBadge(s) {
    const labels = { pending: 'Pending', reviewed: 'Reviewed', approved: 'Approved', partial: 'Partial', paid: 'Paid', rejected: 'Rejected', cancelled: 'Cancelled' };
    const map    = { pending: 'warning', reviewed: 'info', approved: 'primary', partial: 'warning', paid: 'success', rejected: 'danger', cancelled: 'secondary' };
    return `<span class="badge bg-${map[s] || 'secondary'}">${labels[s] || safeOutput(s)}</span>`;
}

function priPoProject(r) {
    if (r.invoice_type === 'supplier') {
        return r.po_number ? `<span class="badge bg-light text-dark border">${safeOutput(r.po_number)}</span>` : '—';
    }
    return r.project_name ? `<small>${safeOutput(r.project_name)}${r.sc_invoice_basis ? ' / ' + safeOutput(r.sc_invoice_basis) : ''}</small>` : '—';
}

function priDueDate(r) {
    if (!r.due_date) return '<span class="text-muted small">—</span>';
    const today = new Date().toISOString().split('T')[0];
    const overdue = r.status === 'approved' && r.due_date < today;
    return formatDate(r.due_date) + (overdue ? ' <span class="badge bg-danger ms-1" style="font-size:.63rem">Overdue</span>' : '');
}

function priActions(r) {
    const ref = safeOutput(r.invoice_ref);
    let items = `<li><a class="dropdown-item py-2" href="${PRI_VIEW_URL}?id=${r.id}"><i class="bi bi-eye text-primary me-2"></i> View</a></li>`;
    items += `<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="priViewAttachment('${r.attachment ? safeOutput(r.attachment) : ''}')"><i class="bi bi-paperclip text-secondary me-2"></i> View/Download Attachment</a></li>`;
    if (PRI_CAN_REVIEW && r.status === 'pending')
        items += `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="priChangeStatus(${r.id},'reviewed','${ref}')"><i class="bi bi-check2 text-info me-2"></i> Mark Reviewed</a></li>`;
    if (PRI_CAN_APPROVE && r.status === 'reviewed')
        items += `<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="priChangeStatus(${r.id},'approved','${ref}')"><i class="bi bi-check-circle text-primary me-2"></i> Approve</a></li>`;
    if (PRI_CAN_APPROVE && (r.status === 'approved' || r.status === 'partial'))
        items += `<li><a class="dropdown-item py-2" href="${PRI_PAGE_URL}?pay=${r.id}"><i class="bi bi-cash-coin text-success me-2"></i> Record Payment</a></li>`;
    if (PRI_CAN_EDIT)
        items += `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2" href="${PRI_PAGE_URL}?edit=${r.id}&lock_project=${PRI_PROJECT_ID}"><i class="bi bi-pencil text-info me-2"></i> Edit</a></li>`;
    const paid = (parseFloat(r.amount_paid) > 0) || r.status === 'partial' || r.status === 'paid';
    if (PRI_CAN_DELETE && !paid)
        items += `<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="priDelete(${r.id},'${ref}')"><i class="bi bi-trash me-2"></i> Delete</a></li>`;
    return `<div class="dropdown">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow">${items}</ul>
    </div>`;
}

function priViewAttachment(path) {
    if (!path) { Swal.fire({ icon: 'info', title: 'No attachment', text: 'This bill has no attachment.' }); return; }
    window.open(path, '_blank');
}

function priChangeStatus(id, newStatus, ref) {
    const labels = { reviewed: 'Mark Reviewed', approved: 'Approve' };
    Swal.fire({ title: labels[newStatus] + '?', text: 'Bill: ' + ref, icon: 'question', showCancelButton: true, confirmButtonColor: '#0d6efd', confirmButtonText: 'Yes, ' + labels[newStatus] })
        .then(function (r) {
            if (!r.isConfirmed) return;
            $.post(PRI_API_URL + '?action=change_status', { id: id, new_status: newStatus, _csrf: PRI_CSRF }, function (res) {
                if (res.success) { Swal.fire({ icon: 'success', title: 'Done!', text: res.message, timer: 1600, showConfirmButton: false }); loadProjectReceivedInvoices(); }
                else Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }, 'json');
        });
}

function priDelete(id, ref) {
    Swal.fire({ title: 'Delete Bill?', text: 'Bill "' + ref + '" will be deleted. This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete' })
        .then(function (r) {
            if (!r.isConfirmed) return;
            $.post(PRI_API_URL + '?action=delete', { id: id, _csrf: PRI_CSRF }, function (res) {
                if (res.success) { Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1600, showConfirmButton: false }); loadProjectReceivedInvoices(); }
                else Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }, 'json');
        });
}

function renderProjectReceivedInvoices(rows) {
    const $el = $('#proj-ri-content');
    if (!rows.length) {
        $el.html('<div class="py-5 text-center text-muted"><i class="bi bi-file-invoice-dollar fs-1 mb-3 d-block"></i><p>No bills linked to this project.</p></div>');
        return;
    }
    let html = '<div class="table-responsive"><table class="table table-hover align-middle border"><thead class="table-light text-nowrap"><tr>'
        + '<th style="width:50px;">S/NO</th>'
        + '<th>Invoice Ref</th>'
        + '<th>Type</th>'
        + '<th>From</th>'
        + '<th>Date Raised</th>'
        + '<th>Due Date</th>'
        + '<th>PO / Project</th>'
        + '<th class="text-end">Amount (TZS)</th>'
        + '<th>Status</th>'
        + '<th class="text-end d-print-none">Actions</th>'
        + '</tr></thead><tbody>';
    rows.forEach((r, idx) => {
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td class="fw-bold">${safeOutput(r.invoice_ref)}</td>
            <td>${priTypeBadge(r.invoice_type)}</td>
            <td>${safeOutput(r.party_name)}</td>
            <td>${r.date_raised ? formatDate(r.date_raised) : '—'}</td>
            <td>${priDueDate(r)}</td>
            <td>${priPoProject(r)}</td>
            <td class="fw-bold text-end">${formatMoney(r.amount)}</td>
            <td>${priStatusBadge(r.status)}</td>
            <td class="text-end d-print-none">${priActions(r)}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $el.html(html);
}

// Lazy-load when tab is first activated
$(document).on('shown.bs.tab', '[data-bs-target="#proj-received-invoices"]', function () {
    if (!projRiLoaded) loadProjectReceivedInvoices();
});

function renderVouchers(vouchers) {
    const $list = $('#vouchersTable');
    if (vouchers.length === 0) {
        $list.html('<div class="p-4 text-center text-muted"><i class="bi bi-wallet shadow-sm p-3 rounded-circle mb-3 d-inline-block"></i><p>No Vouchers yet</p></div>');
        return;
    }
    
    let html = '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Voucher #</th><th>Amount</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>';
    vouchers.slice(0, 5).forEach(v => {
        html += `<tr>
            <td><span class="fw-bold">${v.voucher_number}</span></td>
            <td class="text-danger fw-bold">${formatMoney(v.amount)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(v.status)}">${v.status}</span></td>
            <td class="text-end">
                <a href="javascript:void(0)" onclick="viewVoucherDetails('${encodeURIComponent(JSON.stringify(v))}')" class="btn btn-sm btn-light border"><i class="bi bi-eye"></i></a>
            </td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $list.html(html);
}

function renderVouchersFull(vouchers) {
    const $list = $('#vouchersTableFull');
    if (!vouchers || vouchers.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-wallet fs-1 mb-3"></i><p>No payment vouchers linked to this project.</p></div>');
        return;
    }

    const pvActions = (v) => {
        const s = v.status;
        let statusItems = '';
        // Same state machine as api/account/update_voucher_status.php + external
        // payment_vouchers.php's pvActions() — only offer transitions the backend
        // actually accepts, instead of a free-choice dropdown that let you request
        // an invalid move (e.g. pending -> approved) and get a hard error back.
        if (s === 'pending' || s === 'draft') {
            statusItems += `<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="pvChangeStatus(${v.id}, 'reviewed', 'Mark as Reviewed?', 'This voucher will be marked as reviewed.')"><i class="bi bi-check2 text-info me-2"></i>Mark as Reviewed</a></li>`;
        }
        if (s === 'reviewed') {
            statusItems += `<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="pvChangeStatus(${v.id}, 'approved', 'Approve Voucher?', 'Approving will post the expense to the General Ledger.')"><i class="bi bi-check2-all text-success me-2"></i>Approve</a></li>`;
            statusItems += `<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="pvChangeStatus(${v.id}, 'cancelled', 'Cancel Voucher?', 'This cannot be undone. The voucher will be cancelled.')"><i class="bi bi-x-circle text-danger me-2"></i>Cancel Voucher</a></li>`;
        }
        if (s === 'approved' || s === 'partially_paid') {
            statusItems += `<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick='openPayVoucher(${JSON.stringify(v)})'><i class="bi bi-cash-coin text-primary me-2"></i>${s === 'partially_paid' ? 'Pay Remaining' : 'Pay Voucher'}</a></li>`;
        }
        const canDelete = (s === 'pending' || s === 'draft' || s === 'reviewed')
            ? `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteVoucher(${v.id})"><i class="bi bi-trash me-2"></i>Delete</a></li>`
            : '';

        return `<div class="dropdown">
            <button class="btn btn-sm btn-outline-danger dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="viewVoucherDetails('${encodeURIComponent(JSON.stringify(v))}')"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
                <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="printVoucher(${v.id})"><i class="bi bi-printer text-primary me-2"></i>Print Voucher</a></li>
                ${statusItems ? '<li><hr class="dropdown-divider"></li>' + statusItems : ''}
                ${canDelete}
            </ul>
        </div>`;
    };

    // Desktop: DataTable
    let html = '<div class="d-none d-lg-block"><table id="projVouchersDT" class="table table-hover align-middle border w-100"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>Voucher Number</th><th>Payee</th><th>Date</th><th>Category</th><th>Amount</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    vouchers.forEach((v, idx) => {
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td><span class="fw-bold">${v.voucher_number}</span></td>
            <td>${v.payee_name || 'N/A'}</td>
            <td>${formatDate(v.vouch_date)}</td>
            <td><small class="text-muted">${v.category_name || 'N/A'}</small></td>
            <td class="fw-bold text-danger">${formatMoney(v.amount)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(v.status)}">${v.status}</span></td>
            <td class="text-end d-print-none">${pvActions(v)}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';

    // Mobile: card view
    html += '<div class="d-lg-none row g-2">';
    vouchers.forEach((v) => {
        html += `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="fw-bold text-dark">${v.voucher_number}</span>
                <span class="badge bg-${getStatusBadgeColor(v.status)}">${v.status}</span>
            </div>
            <div class="small text-muted mb-1"><i class="bi bi-person me-1"></i>${v.payee_name || 'N/A'}</div>
            <div class="small text-muted mb-1"><i class="bi bi-tag me-1"></i>${v.category_name || 'N/A'}</div>
            <div class="small text-muted mb-2"><i class="bi bi-calendar3 me-1"></i>${formatDate(v.vouch_date)}</div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold text-danger">${formatMoney(v.amount)}</span>
                ${pvActions(v)}
            </div>
        </div></div></div>`;
    });
    html += '</div>';

    $list.html(html);
    if ($.fn.DataTable.isDataTable('#projVouchersDT')) $('#projVouchersDT').DataTable().destroy();
    $('#projVouchersDT').DataTable({ pageLength: 25, autoWidth: false, order: [[3, 'desc']], columnDefs: [{ orderable: false, targets: [0, 7] }] });
}

function renderPurchases(purchases) {
    const $list = $('#procOrdersTable');
    if (purchases.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-file-earmark-text fs-1 mb-3"></i><p>No supply orders linked to this project.</p></div>');
        return;
    }
    let html = '<div class="table-responsive"><table id="procPOInnerTable" class="table table-hover align-middle border"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>Order Number</th><th>Supplier</th><th>Date</th><th>Tax</th><th>Grand Total</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    purchases.forEach((p, idx) => {
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td><span class="fw-bold text-dark">${p.order_number}</span></td>
            <td>${p.supplier_name || 'N/A'}</td>
            <td>${formatDate(p.order_date)}</td>
            <td>${formatMoney(p.tax_amount)}</td>
            <td class="fw-bold text-dark">${formatMoney(p.grand_total)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(p.status)}">${p.status}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="purchase_order_details?id=${p.purchase_order_id}"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
                        <li><a class="dropdown-item py-2" href="purchase_order_create?edit=${p.purchase_order_id}&project=<?= $project_id ?>&type=supply_order&back=procurement"><i class="bi bi-pencil text-info me-2"></i>Edit Order</a></li>
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="printPurchaseOrder(${p.purchase_order_id})"><i class="bi bi-printer text-dark me-2"></i>Print Order</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deletePurchase(${p.purchase_order_id})"><i class="bi bi-trash me-2"></i>Delete</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $list.html(html);
    if ($.fn.DataTable.isDataTable('#procPOInnerTable')) $('#procPOInnerTable').DataTable().destroy();
    $('#procPOInnerTable').DataTable({ responsive: true, pageLength: 25, autoWidth: false, columnDefs: [{ orderable: false, targets: [0, 7] }] });
}

function renderPurchasesFull(purchases) {
    const $list = $('#purchasesTableFull');
    if (supplierMode && viewSupplierId) {
        purchases = purchases.filter(p => p.supplier_id == viewSupplierId);
    }
    if (purchases.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-bag fs-1 mb-3"></i><p>No purchase orders linked to this project.</p></div>');
        return;
    }
    let html = '<div class="table-responsive"><table id="procPOFullInnerTable" class="table table-hover align-middle border"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>Order Number</th><th>Supplier</th><th>Date</th><th>Tax</th><th>Grand Total</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    purchases.forEach((p, idx) => {
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td><a href="purchase_order_details?id=${p.purchase_order_id}" class="fw-bold text-primary">${p.order_number}</a></td>
            <td>${p.supplier_name || 'N/A'}</td>
            <td>${formatDate(p.order_date)}</td>
            <td>${formatMoney(p.tax_amount)}</td>
            <td class="fw-bold text-dark">${formatMoney(p.grand_total)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(p.status)}">${p.status}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="purchase_order_details?id=${p.purchase_order_id}"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
                        <li><a class="dropdown-item py-2" href="purchase_order_create?edit=${p.purchase_order_id}&project=<?= $project_id ?>&back=procurement"><i class="bi bi-pencil text-info me-2"></i>Edit Order</a></li>
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="printPurchaseOrder(${p.purchase_order_id})"><i class="bi bi-printer text-dark me-2"></i>Print Order</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deletePurchase(${p.purchase_order_id})"><i class="bi bi-trash me-2"></i>Delete</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $list.html(html);
    if ($.fn.DataTable.isDataTable('#procPOFullInnerTable')) $('#procPOFullInnerTable').DataTable().destroy();
    $('#procPOFullInnerTable').DataTable({ responsive: true, pageLength: 25, autoWidth: false, columnDefs: [{ orderable: false, targets: [0, 7] }] });
}

function renderDNs(dns) {
    const $list = $('#procDNTable');
    if (!dns || dns.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-truck-flatbed fs-1 mb-3 d-block"></i><p>No Delivery Notes found for this project.</p><a href="<?= getUrl('dn_create') ?>?project_id=' + projectId + '" class="btn btn-primary btn-sm mt-2"><i class="bi bi-plus-circle me-1"></i> New Delivery Note</a></div>');
        return;
    }
    const statusColors = { draft:'secondary', review:'warning', approved:'success' };
    if ($.fn.DataTable.isDataTable('#dtDNs')) $('#dtDNs').DataTable().destroy();
    let html = '<div class="table-responsive"><table id="dtDNs" class="table table-hover align-middle mb-0" style="width:100%"><thead class="table-light text-uppercase small fw-bold"><tr>'
             + '<th class="ps-3" style="width:50px;">S/NO</th>'
             + '<th>DN Number</th>'
             + '<th>DO Ref</th>'
             + '<th>Supplier</th>'
             + '<th>Date</th>'
             + '<th class="text-center" style="width:70px;">Items</th>'
             + '<th style="width:110px;">Status</th>'
             + '<th class="text-end d-print-none" style="width:80px;">Actions</th>'
             + '</tr></thead><tbody>';

    dns.forEach((d, idx) => {
        const sc         = statusColors[d.status] || 'secondary';
        const isDraft    = d.status === 'draft';
        const isReview   = d.status === 'review';
        const isApproved = d.status === 'approved';
        const canEdit    = isDraft || isReview;
        const canDelete  = DN_IS_ADMIN || isDraft || isReview || d.status === 'pending' || d.status === 'cancelled';

        const reviewBtn = isDraft
            ? `<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="changeDNStatus(${d.delivery_id},'review')"><i class="bi bi-send text-warning me-2"></i>Submit for Review</a></li>`
            : '';
        const approveBtn = isReview
            ? `<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="changeDNStatus(${d.delivery_id},'approved')"><i class="bi bi-check2-all text-success me-2"></i>Approve</a></li>`
            : '';
        const doRef = d.do_id
            ? `<a href="<?= getUrl('do_view') ?>?id=${d.do_id}" class="badge bg-light text-primary border text-decoration-none">${d.do_number || 'DO#'+d.do_id}</a>`
            : `<span class="text-muted small">—</span>`;
        const deleteBtn = canDelete
            ? `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteDN(${d.delivery_id},'${d.delivery_number}')"><i class="bi bi-trash me-2"></i>Delete</a></li>`
            : '';

        html += `<tr>
            <td class="ps-3 text-muted fw-bold">${idx + 1}</td>
            <td><div class="fw-bold text-primary">${d.delivery_number}</div><small class="text-muted">${formatDate(d.delivery_date)}</small></td>
            <td>${doRef}</td>
            <td><small>${d.supplier_name || 'N/A'}</small></td>
            <td><small>${formatDate(d.delivery_date)}</small></td>
            <td class="text-center"><span class="badge bg-secondary">${d.total_items}</span></td>
            <td><span class="badge bg-${sc} text-nowrap">${d.status.toUpperCase()}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle px-2" type="button" data-bs-toggle="dropdown" data-bs-strategy="fixed"><i class="bi bi-gear"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width:185px;">
                        <li><a class="dropdown-item py-2" href="<?= getUrl('dn_view') ?>?id=${d.delivery_id}"><i class="bi bi-eye text-primary me-2"></i>View</a></li>
                        ${canEdit ? `<li><a class="dropdown-item py-2" href="<?= getUrl('dn_create') ?>?project_id=${projectId}&edit=${d.delivery_id}"><i class="bi bi-pencil text-warning me-2"></i>Edit</a></li>` : ''}
                        ${reviewBtn}
                        ${approveBtn}
                        ${deleteBtn}
                    </ul>
                </div>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    $list.html(html);
    if ($.fn.DataTable.isDataTable('#dtDNs')) $('#dtDNs').DataTable().destroy();
    $('#dtDNs').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[4,'desc']],
        dom: '<"d-print-none"lf>rtip',
        columnDefs: [
            {responsivePriority:1, targets:0},
            {responsivePriority:1, targets:1},
            {responsivePriority:2, targets:2},
            {responsivePriority:3, targets:3},
            {responsivePriority:4, targets:4},
            {responsivePriority:3, targets:5},
            {responsivePriority:1, targets:6},
            {responsivePriority:1, targets:-1}
        ]
    });
}

function changeDNStatus(dnId, newStatus) {
    const msgs = {
        review:   { title:'Submit for Review?', text:'This DN will be sent for review.', color:'#ffc107', btn:'Yes, Submit' },
        approved: { title:'Approve DN?', text:'Once approved, no more status changes are allowed.', color:'#198754', btn:'Yes, Approve' }
    };
    const m = msgs[newStatus] || { title:'Update Status?', text:'', color:'#0d6efd', btn:'Yes' };
    Swal.fire({ title:m.title, text:m.text, icon:'question', showCancelButton:true, confirmButtonColor:m.color, confirmButtonText:m.btn })
    .then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title:'Updating...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
        $.post(APP_URL + '/api/operations/change_dn_status.php', { delivery_id: dnId, status: newStatus }, function(res) {
            if (res.success) { Swal.fire({icon:'success', title:'Updated!', text:res.message, timer:1800, showConfirmButton:false}).then(()=>loadProjectDetails()); }
            else { Swal.fire({icon:'error', title:'Error', text:res.message}); }
        }, 'json');
    });
}

function deleteDN(dnId, dnNumber) {
    Swal.fire({ title:'Delete DN?', html:`Delete <strong>${dnNumber}</strong>? This cannot be undone.`, icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, Delete' })
    .then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title:'Deleting...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
        $.post('<?= getUrl("api/delete_dn") ?>', { delivery_id: dnId }, function(res) {
            if (res.success) { Swal.fire({icon:'success', title:'Deleted!', text:res.message}).then(() => loadProjectDetails()); }
            else { Swal.fire({icon:'error', title:'Error', text:res.message}); }
        }, 'json');
    });
}

let currentDOsData = [];
function renderDOs(dos) {
    currentDOsData = dos || [];
    const $list = $('#procDOTable');
    if (!dos || dos.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-file-earmark-check fs-1 mb-3 d-block"></i><p>No Delivery Orders found.</p><p class="small text-muted">Click <strong>Create DO</strong> to add a Delivery Order for this project.</p></div>');
        return;
    }
    const statusColors = { draft:'secondary', pending:'warning', approved:'success' };
    if ($.fn.DataTable.isDataTable('#dtDOs')) $('#dtDOs').DataTable().destroy();

    let html = `
        <div class="table-responsive">
            <table id="dtDOs" class="table table-hover align-middle mb-0 w-100">
                <thead class="table-light text-uppercase small fw-bold">
                    <tr>
                        <th class="text-center" style="width:50px;">S/NO</th>
                        <th>DO Number</th>
                        <th>Supplier</th>
                        <th style="width:110px;">Date</th>
                        <th class="text-center" style="width:70px;">DNs</th>
                        <th class="text-center" style="width:60px;">Atts</th>
                        <th style="width:110px;">Status</th>
                        <th class="text-end d-print-none" style="width:70px;">Actions</th>
                    </tr>
                </thead>
                <tbody>`;

    dos.forEach((d, idx) => {
        const sc          = statusColors[d.status] || 'secondary';
        const isDraft     = d.status === 'draft';
        const isApproved  = d.status === 'approved';
        const canEdit     = !isApproved;
        const canDelete   = !isApproved;

        html += `<tr>
            <td class="text-center text-muted fw-bold">${idx + 1}</td>
            <td><div class="fw-bold text-primary">${d.do_number}</div><small class="text-muted">${formatDate(d.do_date)}</small></td>
            <td><small>${d.supplier_name || 'N/A'}</small></td>
            <td><small>${formatDate(d.do_date)}</small></td>
            <td class="text-center"><span class="badge bg-secondary">${d.total_dns||0}</span></td>
            <td class="text-center"><span class="badge bg-secondary">${d.total_attachments||0}</span></td>
            <td><span class="badge bg-${sc}">${d.status.toUpperCase()}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-strategy="fixed"><i class="bi bi-gear"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width:190px;">
                        <li><a class="dropdown-item py-2" href="<?= getUrl('do_view') ?>?id=${d.do_id}"><i class="bi bi-eye text-primary me-2"></i>View / Print</a></li>
                        ${canEdit ? `<li><button class="dropdown-item py-2" onclick="openEditDO(${d.do_id})"><i class="bi bi-pencil text-warning me-2"></i>Edit</button></li>` : ''}
                        ${isDraft ? `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="changeDOStatus(${d.do_id},'pending')"><i class="bi bi-arrow-right-circle text-warning me-2"></i>Move to Pending</a></li>` : ''}
                        ${canDelete ? `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 text-danger" onclick="deleteDO(${d.do_id},'${d.do_number}')"><i class="bi bi-trash me-2"></i>Delete</button></li>` : ''}
                    </ul>
                </div>
            </td>
        </tr>`;
    });

    html += `</tbody></table></div>`;
    $list.html(html);

    if ($.fn.DataTable.isDataTable('#dtDOs')) $('#dtDOs').DataTable().destroy();
    $('#dtDOs').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[3, 'desc']],
        autoWidth: false,
        dom: '<"d-print-none"lf>rtip',
        columnDefs: [
            { targets: [0,1,6], responsivePriority: 1 },
            { targets: [2,4,5], responsivePriority: 2 },
            { targets: [3],     responsivePriority: 3 },
            { targets: -1,      orderable: false, responsivePriority: 1 }
        ]
    });
}

function renderGRNs(grns) {
    const $grnList = $('#procGRNTable');
    const $dnList = $('#procDNTable');
    
    if (!grns || grns.length === 0) {
        $grnList.html('<div class="py-5 text-center text-muted"><i class="bi bi-check2-square fs-1 mb-3"></i><p>No goods received notes (GRN) found.</p></div>');
        $dnList.html('<div class="py-5 text-center text-muted"><i class="bi bi-truck-flatbed fs-1 mb-3"></i><p>No delivery notes (DN) found.</p></div>');
        return;
    }
    
    let grnHtml = '<div class="table-responsive"><table id="procGRNInnerTable" class="table table-hover align-middle border"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>GRN Number</th><th>Supplier</th><th>Date</th><th>PO #</th><th>DN Ref</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    let dnHtml = '<div class="table-responsive"><table id="procGRNDNInnerTable" class="table table-hover align-middle border"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>DN Reference</th><th>Supplier</th><th>Date</th><th>PO #</th><th>GRN Ref</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    
    let dnsCount = 0;
    let grnCount = 0;
    
    grns.forEach(g => {
        grnCount++;
        const row = `<tr>
            <td class="text-center fw-bold text-muted">${grnCount}</td>
            <td><span class="fw-bold text-dark">${g.receipt_number}</span></td>
            <td>${g.supplier_name || 'N/A'}</td>
            <td>${formatDate(g.receipt_date)}</td>
            <td><small class="badge bg-light text-primary border">${g.order_number || 'N/A'}</small></td>
            <td>${g.delivery_note || '<small class="text-muted">None</small>'}</td>
            <td><span class="badge bg-${getStatusBadgeColor(g.status)}">${g.status}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="grn_view?id=${g.receipt_id}&project_id=<?= $project_id ?>"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
                        <li><a class="dropdown-item py-2" href="grn_edit?id=${g.receipt_id}&project_id=<?= $project_id ?>"><i class="bi bi-pencil text-info me-2"></i>Edit GRN</a></li>
                        <li><a class="dropdown-item py-2" href="grn_print?id=${g.receipt_id}" target="_blank"><i class="bi bi-printer text-dark me-2"></i>Print GRN</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
        grnHtml += row;
        
        if (g.delivery_note) {
            dnsCount++;
            dnHtml += `<tr>
                <td class="text-center fw-bold text-muted">${dnsCount}</td>
                <td><span class="fw-bold text-info">${g.delivery_note}</span></td>
                <td>${g.supplier_name || 'N/A'}</td>
                <td>${formatDate(g.receipt_date)}</td>
                <td><small class="badge bg-light text-primary border">${g.order_number || 'N/A'}</small></td>
                <td><small class="text-muted">${g.receipt_number}</small></td>
                <td><span class="badge bg-${getStatusBadgeColor(g.status)}">${g.status}</span></td>
                <td class="text-end d-print-none">
                    <a href="grn_view?id=${g.receipt_id}&project_id=<?= $project_id ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                </td>
            </tr>`;
        }
    });
    
    grnHtml += '</tbody></table></div>';
    dnHtml += '</tbody></table></div>';
    
    $grnList.html(grnHtml);
    if ($.fn.DataTable.isDataTable('#procGRNInnerTable')) $('#procGRNInnerTable').DataTable().destroy();
    $('#procGRNInnerTable').DataTable({ responsive: true, pageLength: 25, autoWidth: false, columnDefs: [{ orderable: false, targets: [0, 7] }] });

    if (dnsCount === 0) {
        $dnList.html('<div class="py-5 text-center text-muted"><i class="bi bi-truck-flatbed fs-1 mb-3"></i><p>No delivery notes (DN) found for this project.</p></div>');
    } else {
        $dnList.html(dnHtml);
        if ($.fn.DataTable.isDataTable('#procGRNDNInnerTable')) $('#procGRNDNInnerTable').DataTable().destroy();
        $('#procGRNDNInnerTable').DataTable({ responsive: true, pageLength: 25, autoWidth: false, columnDefs: [{ orderable: false, targets: [0, 7] }] });
    }
}

