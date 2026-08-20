function renderReturns(returns) {
    const $list = $('#procReturnsTable');
    if (!returns || returns.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-arrow-return-left fs-1 mb-3"></i><p>No goods return notes found.</p></div>');
        return;
    }
    
    let html = '<div class="table-responsive"><table id="procReturnsInnerTable" class="table table-hover align-middle border"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>Return #</th><th>Supplier</th><th>Date</th><th>PO #</th><th>Items</th><th>Value</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    returns.forEach((r, idx) => {
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td><span class="fw-bold text-primary">${r.return_number}</span></td>
            <td>${r.supplier_name || 'N/A'}</td>
            <td>${formatDate(r.return_date)}</td>
            <td><small class="text-muted">${r.order_number || 'N/A'}</small></td>
            <td class="text-center"><span class="badge bg-secondary">${r.total_items}</span></td>
            <td class="fw-bold">${formatMoney(r.total_value)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(r.status)}">${r.status}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="purchase_return_view?id=${r.purchase_return_id}"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
                        ${r.status !== 'completed' ? `
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="changeReturnStatus(${r.purchase_return_id}, '${r.status}')"><i class="bi bi-arrow-repeat text-warning me-2"></i>Change Status</a></li>
                        ` : ''}
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteReturn(${r.purchase_return_id})"><i class="bi bi-trash me-2"></i>Delete</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $list.html(html);
    if ($.fn.DataTable.isDataTable('#procReturnsInnerTable')) $('#procReturnsInnerTable').DataTable().destroy();
    $('#procReturnsInnerTable').DataTable({ responsive: true, pageLength: 25, autoWidth: false, columnDefs: [{ orderable: false, targets: [0, 8] }] });
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('procReturnsInnerTable');
}

// ── Project-scoped Debit Notes (same files as the standalone module) ──────────
// Action links carry &project_id=${projectId} so the view/edit pages stay
// anchored to this project and show a one-click "Back to Project".
function createDebitNote() {
    window.location.href = '<?= getUrl('debit_note_create') ?>?project=' + projectId;
}

function renderProjectDebitNotes(notes) {
    const $list = $('#procDebitNotesTable');
    if (!notes || notes.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-receipt-cutoff fs-1 mb-3"></i><p>No debit notes found for this project.</p></div>');
        return;
    }
    let html = '<div class="table-responsive"><table id="procDebitNotesInnerTable" class="table table-hover align-middle border"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>Debit Note #</th><th>Supplier</th><th>Date</th><th>Return #</th><th>Items</th><th>Value</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    notes.forEach((r, idx) => {
        const pid = r.debit_note_id;
        const editLink = (r.status === 'pending')
            ? `<li><a class="dropdown-item py-2" href="debit_note_edit?id=${pid}&project_id=${projectId}"><i class="bi bi-pencil text-primary me-2"></i>Edit</a></li>`
            : '';
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td><span class="fw-bold text-primary">${r.debit_note_number}</span></td>
            <td>${r.supplier_name || 'N/A'}</td>
            <td>${formatDate(r.debit_date)}</td>
            <td><small class="text-muted">${r.return_number || '—'}</small></td>
            <td class="text-center"><span class="badge bg-secondary">${r.total_items || 0}</span></td>
            <td class="fw-bold">${formatMoney(r.grand_total)}</td>
            <td><span class="badge bg-${getStatusBadgeColor(r.status)}">${r.status}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="debit_note_view?id=${pid}&project_id=${projectId}"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
                        <li><a class="dropdown-item py-2" href="print_debit_note?id=${pid}" target="_blank"><i class="bi bi-printer text-secondary me-2"></i>Print</a></li>
                        ${editLink}
                        ${r.status !== 'paid' ? `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteProjectDebitNote(${pid})"><i class="bi bi-trash me-2"></i>Delete</a></li>` : ''}
                    </ul>
                </div>
            </td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $list.html(html);
    if ($.fn.DataTable.isDataTable('#procDebitNotesInnerTable')) $('#procDebitNotesInnerTable').DataTable().destroy();
    $('#procDebitNotesInnerTable').DataTable({ responsive: true, pageLength: 25, autoWidth: false, columnDefs: [{ orderable: false, targets: [0, 8] }] });
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('procDebitNotesInnerTable');
}

function deleteProjectDebitNote(id) {
    Swal.fire({
        title: 'Delete Debit Note?',
        text: 'This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '<?= buildUrl('api/purchase/delete_debit_note.php') ?>',
            type: 'POST', dataType: 'json',
            data: { debit_note_id: id, _csrf: (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '') },
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1500, showConfirmButton: false });
                    loadProjectDetails();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); }
        });
    });
}

function renderRFQs(rfqs) {
    const $list = $('#procRFQTable');
    if (!rfqs || rfqs.length === 0) {
        $list.html('<div class="py-5 text-center text-muted"><i class="bi bi-file-earmark-ruled fs-1 mb-3 d-block"></i><p>No RFQs found for this project.</p><a href="<?= getUrl('rfq_create') ?>?project=<?= $project_id ?>&back=rfq" class="btn btn-primary btn-sm mt-2"><i class="bi bi-plus-circle me-1"></i> Create RFQ</a></div>');
        return;
    }
    if ($.fn.DataTable && $.fn.DataTable.isDataTable('#dtRFQs')) $('#dtRFQs').DataTable().destroy();
    let html = '<div class="table-responsive"><table id="dtRFQs" class="table table-hover align-middle border" style="width:100%">'
             + '<thead class="table-light text-nowrap"><tr>'
             + '<th style="width:50px;">S/NO</th>'
             + '<th>RFQ Number</th>'
             + '<th>Date</th>'
             + '<th>Supplier</th>'
             + '<th>Warehouse</th>'
             + '<th>Status</th>'
             + '<th class="text-end d-print-none">Actions</th>'
             + '</tr></thead><tbody>';

    rfqs.forEach((r, idx) => {
        const sc = getStatusBadgeColor(r.status);
        const isApproved  = r.status === 'approved';
        const isDraft     = r.status === 'draft';
        const isReview    = r.status === 'review';
        const createPOOpt = isApproved && r.supplier_id
            ? `<li><hr class="dropdown-divider opacity-50"></li>
               <li><a class="dropdown-item py-2 text-primary fw-semibold" href="<?= getUrl('purchase_order_create') ?>?supplier=${r.supplier_id}&rfq_ref=${r.rfq_id}&project=<?= $project_id ?>&back=procurement">
                   <i class="bi bi-cart-plus me-2"></i>Create Purchase Order</a></li>` : '';
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td><span class="fw-bold text-primary">${safeOutput(r.rfq_number)}</span></td>
            <td><small>${formatDate(r.rfq_date)}</small></td>
            <td><small>${safeOutput(r.supplier_name) || 'N/A'}</small></td>
            <td><small>${safeOutput(r.warehouse_name) || 'N/A'}</small></td>
            <td><span class="badge bg-${sc} text-uppercase">${r.status}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-white border dropdown-toggle" style="background:#fff;" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item py-2" href="<?= getUrl('rfq_view') ?>?id=${r.rfq_id}&back=rfq"><i class="bi bi-eye text-primary me-2"></i>View</a></li>
                        ${isDraft ? `<li><a class="dropdown-item py-2 text-primary fw-semibold" href="<?= getUrl('rfq_view') ?>?id=${r.rfq_id}&back=rfq"><i class="bi bi-eye-fill me-2"></i>Review</a></li>` : ''}
                        ${isReview ? `<li><a class="dropdown-item py-2 text-success fw-semibold" href="#" onclick="approveRFQ(${r.rfq_id},'${r.rfq_number}');return false;"><i class="bi bi-check-circle me-2"></i>Approve</a></li>` : ''}
                        <li><a class="dropdown-item py-2" href="#" onclick="printRFQ(${r.rfq_id});return false;"><i class="bi bi-printer text-dark me-2"></i>Print</a></li>
                        ${isDraft ? `<li><a class="dropdown-item py-2" href="<?= getUrl('rfq_create') ?>?edit=${r.rfq_id}&project=<?= $project_id ?>&back=rfq"><i class="bi bi-pencil text-info me-2"></i>Edit</a></li>` : ''}
                        ${createPOOpt}
                        <li><hr class="dropdown-divider opacity-50"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="#" onclick="deleteRFQ(${r.rfq_id},'${r.rfq_number}');return false;"><i class="bi bi-trash me-2"></i>Delete</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    $list.html(html);
    if ($.fn.DataTable) {
        $('#dtRFQs').DataTable({ pageLength: 25, order: [[2,'desc']], autoWidth: false, responsive: false, dom: 'rtip' });
    }
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('dtRFQs');
}

function printRFQ(id) {
    window.open('<?= getUrl('print_rfq') ?>?id=' + id, '_blank');
}

function approveRFQ(id, number) {
    Swal.fire({
        title: 'Approve RFQ?',
        text: `RFQ #${number} will be marked as approved.`,
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#198754', confirmButtonText: 'Yes, Approve It', cancelButtonText: 'Cancel'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/approve_rfq') ?>', { rfq_id: id }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Approved!', text: res.message, timer: 2000, showConfirmButton: false })
                    .then(() => loadProjectDetails());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not approve RFQ.' });
            }
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' }));
    });
}

function deleteRFQ(id, number) {
    Swal.fire({
        title: 'Delete RFQ?',
        text: `RFQ #${number} will be permanently deleted and cannot be recovered.`,
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete It', cancelButtonText: 'Cancel'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/delete_rfq') ?>', { rfq_id: id }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 2000, showConfirmButton: false })
                    .then(() => loadProjectDetails());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not delete RFQ.' });
            }
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' }));
    });
}

function renderExpenses(expenses) {
    const $c = $('#expensesTable');
    if (!expenses || expenses.length === 0) {
        $c.html('<p class="text-muted text-center py-4">No expenses linked to this project.</p>');
        return;
    }

    const allocBadge = (e) => {
        if (e.budget_id)  return '<span class="badge bg-info-soft text-info border border-info small"><i class="bi bi-piggy-bank me-1"></i>Budget</span>';
        if (e.voucher_id) return '<span class="badge bg-warning-soft text-warning border border-warning small"><i class="bi bi-wallet2 me-1"></i>PV Link</span>';
        return '<span class="badge bg-light text-muted border small">No Allocation</span>';
    };
    const catBadges = (e) => (e.categories && Array.isArray(e.categories) && e.categories.length > 0)
        ? e.categories.map(cat => `<span class="badge bg-info-soft text-info border border-info px-2 py-1" style="font-size: 0.65rem;">${cat.category_name || cat.name}</span>`).join(' ')
        : `<span class="badge bg-light text-primary border-0 small text-uppercase">${e.category_name || 'N/A'}</span>`;
    const exActions = (e) => {
        const dataObj = encodeURIComponent(JSON.stringify(e));
        return `<div class="dropdown">
            <button class="btn btn-light btn-sm dropdown-toggle shadow-sm border" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill text-primary"></i></button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                <li><a class="dropdown-item" href="<?= getUrl('expenses/details') ?>?id=${e.expense_id}"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
                <li><a class="dropdown-item" href="javascript:void(0)" onclick="editExpenseInline('${dataObj}')"><i class="bi bi-pencil text-info me-2"></i>Edit Detail</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteExpenseInline(${e.expense_id})"><i class="bi bi-trash me-2"></i>Delete</a></li>
            </ul>
        </div>`;
    };

    // Desktop: DataTable
    let html = '<div class="d-none d-lg-block"><table id="projExpensesDT" class="table table-hover align-middle border w-100"><thead class="table-light text-nowrap"><tr><th style="width:50px;">S/NO</th><th>Description</th><th>Allocation Source</th><th>Category</th><th>Date</th><th>Amount</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    expenses.forEach((e, idx) => {
        html += `<tr>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td>
                <div class="fw-bold text-dark">${e.description || 'N/A'}</div>
                ${e.reference_number ? `<small class="text-muted"><i class="bi bi-hash"></i>${e.reference_number}</small>` : ''}
            </td>
            <td>${allocBadge(e)}</td>
            <td><div class="d-flex flex-wrap gap-1">${catBadges(e)}</div></td>
            <td>${formatDate(e.expense_date)}</td>
            <td class="fw-bold text-danger">${formatMoney(e.amount)} TZS</td>
            <td><span class="badge bg-${getStatusBadgeColor(e.status)}">${e.status}</span></td>
            <td class="text-end d-print-none">${exActions(e)}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';

    // Mobile: card view
    html += '<div class="d-lg-none row g-2">';
    expenses.forEach((e) => {
        html += `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="fw-bold text-dark">${e.description || 'N/A'}</span>
                <span class="badge bg-${getStatusBadgeColor(e.status)}">${e.status}</span>
            </div>
            <div class="mb-1">${allocBadge(e)} ${catBadges(e)}</div>
            <div class="small text-muted mb-2"><i class="bi bi-calendar3 me-1"></i>${formatDate(e.expense_date)}</div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold text-danger">${formatMoney(e.amount)} TZS</span>
                ${exActions(e)}
            </div>
        </div></div></div>`;
    });
    html += '</div>';

    $c.html(html);
    if ($.fn.DataTable.isDataTable('#projExpensesDT')) $('#projExpensesDT').DataTable().destroy();
    $('#projExpensesDT').DataTable({ pageLength: 25, autoWidth: false, order: [[4, 'desc']], columnDefs: [{ orderable: false, targets: [0, 7] }] });
}

function renderBudgets(budgets, paginationInfo) {
    if (!budgets || budgets.length === 0) {
        $('#budgetContent').html(`
            <div class="text-center py-5 border rounded bg-light">
                <i class="bi bi-piggy-bank text-muted mb-3" style="font-size: 3rem;"></i>
                <h6 class="text-muted">No budget items found</h6>
                <button class="btn btn-primary btn-sm mt-3" onclick="createBudgetItem()">
                    <i class="bi bi-plus-circle me-1"></i> Add First Budget Item
                </button>
            </div>
        `);
        return;
    }

    window._budgetBreakdowns = {};
    let cards = '';
    let html = '<div class="d-none d-lg-block"><table class="table table-hover align-middle border w-100" id="budgetListTable"><thead class="table-light"><tr><th style="width:34px;"></th><th style="width:45px;">S/NO</th><th>Category</th><th style="width:110px;">Type</th><th>Period</th><th>Allocated</th><th>Actual</th><th>Variance</th><th>Status</th><th class="text-end d-print-none">Actions</th></tr></thead><tbody>';
    budgets.forEach((b, idx) => {
        const monthNames = ["", "Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        const allocated = parseFloat(b.allocated_amount) || 0;
        const actual = parseFloat(b.actual_amount) || 0;
        const variance = allocated - actual;
        const vClass = variance >= 0 ? 'text-success' : 'text-danger';

        // Complex data object for editing
        const dataObj = encodeURIComponent(JSON.stringify(b));

        // Parse line items — handle wrapper format
        let lineItems = [];
        let isService = false;
        try {
            const parsed = typeof b.line_items === 'string' ? JSON.parse(b.line_items) : (b.line_items || []);
            if (Array.isArray(parsed)) {
                lineItems = parsed;
            } else if (parsed && typeof parsed === 'object') {
                isService = parsed.is_service == 1;
                lineItems = parsed.items || [];
            }
        } catch(e) { lineItems = []; }

        const typeBadge = isService
            ? `<span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25" style="font-size:0.7rem;">Non-Inventory</span>`
            : `<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-size:0.7rem;">Inventory</span>`;

        // Build line items sub-table
        let subTableHtml = '';
        if (lineItems.length > 0) {
            subTableHtml = `<table class="table table-sm table-bordered mb-0 small" style="background:#f8f9fa;">
                <thead class="table-secondary">
                    <tr>
                        <th style="width:40px;" class="text-center">S/No</th>
                        <th class="text-center">Description</th>
                        <th style="width:90px;">Units</th>
                        <th style="width:60px;" class="text-center">Qty</th>
                        <th style="width:100px;" class="text-end">Price</th>
                        <th style="width:70px;" class="text-end">Tax %</th>
                        <th style="width:110px;" class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>`;
            lineItems.forEach((it, i) => {
                const taxRate = parseFloat(it.tax_rate || 0);
                const rowTotal = (it.qty || 0) * (it.price || 0) * (1 + taxRate / 100);
                subTableHtml += `<tr>
                    <td class="text-center fw-bold text-muted">${i + 1}</td>
                    <td>${it.desc || ''}</td>
                    <td><span class="badge bg-light text-dark border">${it.units || '—'}</span></td>
                    <td class="text-center">${it.qty}</td>
                    <td class="text-end">${formatMoney(it.price)}</td>
                    <td class="text-end">${taxRate > 0 ? taxRate.toFixed(2) + '%' : '—'}</td>
                    <td class="text-end fw-bold">${formatMoney(rowTotal)}</td>
                </tr>`;
            });
            subTableHtml += `</tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="6" class="text-end">Grand Total:</td>
                        <td class="text-end text-success">${formatMoney(allocated)}</td>
                    </tr>
                </tfoot>
            </table>`;
        } else {
            subTableHtml = '<p class="text-muted small p-2 mb-0"><i class="bi bi-info-circle me-1"></i>No line items recorded.</p>';
        }

        window._budgetBreakdowns[b.budget_id] = subTableHtml;
        // Same state machine + menu layout as budget.php's gear menu: View Details is a
        // real page link (not a local modal), Pay only appears when approved (and points
        // at the same page — "paying" happens there via Quick Add Expense, same as
        // external), Approve/Reject only appear while pending (was previously gated on
        // "not yet approved/rejected", which incorrectly offered them from any status).
        const _bDetailsUrl = '<?= getUrl('budget/details') ?>?category_id=' + b.category_id + '&year=' + b.budget_year + '&month=' + b.budget_month;
        const _bActions = `<li><a class="dropdown-item py-2" href="${_bDetailsUrl}"><i class="bi bi-eye text-info me-2"></i>View Details</a></li>
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="editBudgetItem('${dataObj}')"><i class="bi bi-pencil text-primary me-2"></i>Edit Detail</a></li>
                        ${b.status === 'approved' ? `<li><a class="dropdown-item py-2 text-success" href="${_bDetailsUrl}"><i class="bi bi-cash-coin me-2"></i>Pay</a></li><li><hr class="dropdown-divider"></li>` : ''}
                        ${b.status === 'pending' ? `<li><a class="dropdown-item py-2 text-success" href="javascript:void(0)" onclick="updateBudgetItemStatus(${b.budget_id}, 'approved')"><i class="bi bi-check-circle me-2"></i>Approve</a></li>
                        <li><a class="dropdown-item py-2 text-warning" href="javascript:void(0)" onclick="updateBudgetItemStatus(${b.budget_id}, 'rejected')"><i class="bi bi-x-circle me-2"></i>Reject</a></li>` : ''}
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteBudgetItem(${b.budget_id})"><i class="bi bi-trash me-2"></i>Delete</a></li>`;
        // Same badge derivation as budget.php's list: the workflow status (draft/
        // pending/approved/rejected) only ever changes via explicit approve/reject —
        // "Partially Paid"/"Paid" are never stored, they're a spending-state label
        // computed live from allocated vs actual once a budget is approved. Without
        // this, an approved budget's badge stayed stuck on "approved" forever
        // regardless of how much had actually been spent against it.
        const usagePct = allocated > 0 ? (actual / allocated) * 100 : 0;
        let spLabel, spCls;
        if (b.status === 'pending' || b.status === 'draft') {
            spLabel = 'Pending Approval'; spCls = 'bg-warning text-dark';
        } else if (b.status === 'rejected') {
            spLabel = 'Rejected'; spCls = 'bg-danger';
        } else if (b.status === 'approved') {
            if (allocated <= 0) { spLabel = 'No Budget'; spCls = 'bg-secondary'; }
            else if (usagePct === 0) { spLabel = 'Approved'; spCls = 'bg-success bg-opacity-10 text-success border border-success border-opacity-25'; }
            else if (usagePct < 100) { spLabel = 'Partially Paid'; spCls = 'bg-info bg-opacity-10 text-info border border-info border-opacity-25'; }
            else { spLabel = 'Paid'; spCls = 'bg-success'; }
        } else {
            spLabel = 'No Budget'; spCls = 'bg-secondary';
        }
        const _bStatus = `<span class="badge ${spCls}">${spLabel}</span>${b.rejection_reason ? `<div class="mt-1"><small class="${b.status === 'rejected' ? 'text-danger' : 'text-muted'} fw-bold" style="font-size:0.7rem;" title="${b.rejection_reason}"><i class="bi bi-info-circle me-1"></i>${b.status === 'rejected' ? 'View Reason' : 'Was Rejected'}</small></div>` : ''}`;

        html += `<tr data-bid="${b.budget_id}">
            <td class="dt-control text-center text-primary" style="cursor:pointer;"><i class="bi bi-chevron-right"></i></td>
            <td class="text-center fw-bold text-muted">${idx + 1}</td>
            <td class="fw-bold text-dark">${b.category_name || 'N/A'}</td>
            <td>${typeBadge}</td>
            <td>${monthNames[b.budget_month]} ${b.budget_year}</td>
            <td class="fw-bold">${formatMoney(allocated)}</td>
            <td class="text-primary">${formatMoney(actual)}</td>
            <td class="fw-bold ${vClass}">${variance >= 0 ? '+' : ''}${formatMoney(variance)}</td>
            <td>${_bStatus}</td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-light btn-sm dropdown-toggle shadow-sm border" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill text-primary"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">${_bActions}</ul>
                </div>
            </td>
        </tr>`;

        // Mobile card with a collapsible line-item breakdown
        const _bcid = 'bcard' + b.budget_id;
        cards += `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="fw-bold text-dark">${b.category_name || 'N/A'}</span>${_bStatus}
            </div>
            <div class="mb-2">${typeBadge} <span class="small text-muted ms-1"><i class="bi bi-calendar3 me-1"></i>${monthNames[b.budget_month]} ${b.budget_year}</span></div>
            <div class="row g-1 small mb-2 text-center">
                <div class="col-4">Allocated<div class="fw-bold">${formatMoney(allocated)}</div></div>
                <div class="col-4">Actual<div class="fw-bold text-primary">${formatMoney(actual)}</div></div>
                <div class="col-4">Variance<div class="fw-bold ${vClass}">${variance >= 0 ? '+' : ''}${formatMoney(variance)}</div></div>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#${_bcid}"><i class="bi bi-list-ul me-1"></i>Breakdown</button>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm dropdown-toggle shadow-sm border" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill text-primary"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">${_bActions}</ul>
                </div>
            </div>
            <div class="collapse mt-2" id="${_bcid}">${subTableHtml}</div>
        </div></div></div>`;
    });
    html += '</tbody></table></div>';
    html += '<div class="d-lg-none row g-2">' + cards + '</div>';

    $('#budgetContent').html(html);

    // Desktop DataTable (all rows loaded client-side) with expandable child rows for
    // the line-item breakdown. Page size follows the "Show" selector.
    if ($.fn.DataTable.isDataTable('#budgetListTable')) $('#budgetListTable').DataTable().destroy();
    const _budgetPageLen = (function () {
        const v = $('#budgetFilterPerPage').val();
        if (v === 'all') return -1;
        const n = parseInt(v, 10);
        return n > 0 ? n : 25;
    })();
    const bTable = $('#budgetListTable').DataTable({
        pageLength: _budgetPageLen,
        autoWidth: false,
        order: [[2, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, 1, 9] }]
    });
    $('#budgetListTable tbody').off('click.budgetChild', 'td.dt-control').on('click.budgetChild', 'td.dt-control', function () {
        const tr    = $(this).closest('tr');
        const row   = bTable.row(tr);
        const $icon = $(this).find('i');
        if (row.child.isShown()) {
            row.child.hide();
            $icon.removeClass('bi-chevron-down').addClass('bi-chevron-right');
        } else {
            const bid = tr.data('bid');
            row.child('<div class="px-3 py-2 bg-light">' + (window._budgetBreakdowns[bid] || '') + '</div>').show();
            $icon.removeClass('bi-chevron-right').addClass('bi-chevron-down');
        }
    });
}

function loadProjectBudgetsAjax(page) {
    page = page || 1;
    const year    = $('#budgetFilterYear').val() || 'all';
    const month   = $('#budgetFilterMonth').val() || 'all';
    const type    = $('#budgetFilterType').val() || 'all';
    // The Year/Month/Type filters are still applied server-side, but we now pull ALL
    // matching rows and let the desktop DataTable handle sort/search/paging (the
    // "Show" selector drives the DataTable page length in renderBudgets()).

    $('#budgetContent').html('<div class="text-center py-5"><span class="spinner-border text-primary"></span></div>');

    $.ajax({
        url: APP_URL + '/api/operations/get_project_budgets.php',
        type: 'GET',
        data: {
            project_id: <?= $project_id ?>,
            page: 1,
            per_page: 100000,
            year: year,
            month: month,
            type: type
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                renderBudgets(res.data);
            } else {
                $('#budgetContent').html('<div class="alert alert-danger">' + (res.message || 'Failed to load budgets') + '</div>');
            }
        },
        error: function() {
            $('#budgetContent').html('<div class="alert alert-danger">Error loading budget data.</div>');
        }
    });
}

function toggleProjectBudgetMode() {
    const isService = $('#proj_budget_is_service').is(':checked');
    $('#proj_budget_is_service_value').val(isService ? '1' : '0');
    // Clear rows and reset when mode changes
    $('#budgetBreakdownTable tbody').empty();
    addBudgetLineItem();
    updateBudgetGrandTotal();
}

function renderInventory(inventory) {
    // Cache for warehouse stock viewer
    _cachedInventory = inventory;

    // ── WAREHOUSES TABLE ONLY ──────────────────────────────────
    const $warehouseTable = $('#projectWarehousesSummaryTable');
    if (!inventory.warehouses || inventory.warehouses.length === 0) {
        $warehouseTable.html('<div class="p-5 text-center text-muted border rounded bg-light"><i class="bi bi-building mb-3 d-block" style="font-size:2.5rem;"></i><p class="fw-bold">No warehouses linked to this project.</p><p class="small">Add a warehouse and link it to this project to manage stock.</p></div>');
        return;
    }

    let html = '<div class="table-responsive"><table id="dtWarehouses" class="table table-hover align-middle mb-0"><thead class="bg-light text-uppercase small fw-bold"><tr>';
    html += '<th class="ps-3" style="width:50px;" data-priority="6">S/NO</th>';
    html += '<th data-priority="1">Warehouse Name</th>';
    html += '<th data-priority="4">Location</th>';
    html += '<th data-priority="3">Contact</th>';
    html += '<th data-priority="2">Status</th>';
    html += '<th class="text-end d-print-none" data-priority="1">Actions</th>';
    html += '</tr></thead><tbody>';

    inventory.warehouses.forEach((w, idx) => {
        const statusColor = w.status === 'active' ? 'success' : 'secondary';
        html += `<tr>
            <td class="ps-3 text-muted fw-bold">${idx + 1}</td>
            <td>
                <div class="fw-bold text-dark"><i class="bi bi-building text-primary me-2"></i>${w.warehouse_name}</div>
                <small class="text-muted">${w.warehouse_code || 'No Code'}</small>
            </td>
            <td><small class="text-muted">${w.location || 'N/A'}</small></td>
            <td>
                <div class="small fw-bold">${w.contact_person || 'N/A'}</div>
                <small class="text-muted">${w.phone || w.contact_phone || '-'}</small>
            </td>
            <td><span class="badge bg-${statusColor} small">${w.status.toUpperCase()}</span></td>
            <td class="text-end d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="viewWarehouseDetail(${w.warehouse_id}, '${w.warehouse_name.replace(/'/g, "\\'")}')"><i class="bi bi-eye text-primary me-2"></i>View Details</a></li>
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="loadWarehouseEditData(${w.warehouse_id})"><i class="bi bi-pencil text-warning me-2"></i>Edit Warehouse</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="manageWarehouseLocations(${w.warehouse_id})"><i class="bi bi-map text-info me-2"></i>Manage Locations</a></li>
                        <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="transferWarehouseStock(${w.warehouse_id})"><i class="bi bi-truck text-success me-2"></i>Transfer Stock</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteWarehouse(${w.warehouse_id}, '${w.warehouse_name.replace(/'/g, "\\'")}')"><i class="bi bi-trash text-danger me-2"></i>Delete</a></li>
                    </ul>
                </div>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    $warehouseTable.html(html);

    if ($.fn.DataTable.isDataTable('#dtWarehouses')) $('#dtWarehouses').DataTable().destroy();
    $('#dtWarehouses').DataTable({
        responsive: true,
        autoWidth: false,
        pageLength: 25,
        order: [[1, 'asc']],
        dom: '<"d-print-none"lf>rtip',
        columnDefs: [
            { responsivePriority: 6, targets: 0 },
            { responsivePriority: 1, targets: 1 },
            { responsivePriority: 4, targets: 2 },
            { responsivePriority: 3, targets: 3 },
            { responsivePriority: 2, targets: 4 },
            { responsivePriority: 1, targets: -1 }
        ]
    });

    // Recalculate when inventory tab becomes visible (fixes hidden-tab init problem)
    $('button[data-bs-target="#inventory"]').off('shown.bs.tab.wh').on('shown.bs.tab.wh', function () {
        var dt = $('#dtWarehouses').DataTable();
        dt.columns.adjust().responsive.recalc();
    });
}


// ── DELETE WAREHOUSE ──────────────────────────────────────────────────────
function deleteWarehouse(warehouseId, warehouseName) {
    Swal.fire({
        title: 'Delete Warehouse?',
        html: `<p>You are about to permanently delete <strong>${warehouseName}</strong>.</p><p class="text-danger small mb-0">This action cannot be undone.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        $.ajax({
            url: '/api/operations/delete_warehouse.php',
            type: 'POST',
            data: { warehouse_id: warehouseId, project_id: projectId },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, timer: 1500, showConfirmButton: false });
                    loadProjectDetails();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function() {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' });
            }
        });
    });
}

// Warehouse Action Functions
// ── WAREHOUSE INVENTORY VIEWER ────────────────────────────────────────────
let _cachedInventory = null; // cache from last renderInventory call
let _whPrintData    = null; // cache for warehouse stock & history print

function viewWarehouseDetail(warehouseId, warehouseName) {
    const wh = (_cachedInventory && _cachedInventory.warehouses)
        ? _cachedInventory.warehouses.find(w => w.warehouse_id == warehouseId)
        : null;

    const name    = wh ? wh.warehouse_name              : (warehouseName || 'Warehouse');
    const code    = wh ? (wh.warehouse_code  || 'N/A')  : 'N/A';
    const loc     = wh ? (wh.location        || 'N/A')  : 'N/A';
    const contact = wh ? (wh.contact_person  || 'N/A')  : 'N/A';
    const phone   = wh ? (wh.phone || wh.contact_phone || '-') : '-';
    const status  = wh ? wh.status : 'active';
    const sc      = status === 'active' ? 'success' : 'secondary';

    const detailHtml = `
    <div class="text-start">
        <div class="row g-3 mb-4">
            <div class="col-sm-6">
                <div class="border rounded p-3 h-100 bg-light">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Warehouse Name</div>
                    <div class="fw-bold"><i class="bi bi-building text-primary me-1"></i>${name}</div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="border rounded p-3 h-100 bg-light">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Code</div>
                    <div class="fw-bold">${code}</div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="border rounded p-3 h-100 bg-light">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Location</div>
                    <div class="fw-bold"><i class="bi bi-geo-alt text-danger me-1"></i>${loc}</div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="border rounded p-3 h-100 bg-light">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Status</div>
                    <span class="badge bg-${sc} px-3 py-2">${status.toUpperCase()}</span>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="border rounded p-3 h-100 bg-light">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Contact Person</div>
                    <div class="fw-bold"><i class="bi bi-person text-info me-1"></i>${contact}</div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="border rounded p-3 h-100 bg-light">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Phone</div>
                    <div class="fw-bold"><i class="bi bi-telephone text-success me-1"></i>${phone}</div>
                </div>
            </div>
        </div>
        <div class="d-grid">
            <button type="button" class="btn btn-success btn-lg"
                onclick="window.location.href = APP_URL + '/warehouse_stock_view?warehouse_id=${warehouseId}&project_id=${projectId}';">
                <i class="bi bi-box-seam me-2"></i> View Stock & History
            </button>
        </div>
    </div>`;

    Swal.fire({
        title: '<i class="bi bi-building text-primary me-2"></i>Warehouse Details',
        html: detailHtml,
        width: 600,
        showCloseButton: true,
        showConfirmButton: false,
        customClass: { popup: 'text-start', htmlContainer: 'text-start' },
        footer: `<a href="${APP_URL}/warehouse_view?id=${warehouseId}&project_id=${projectId}" class="btn btn-outline-primary btn-sm me-2"><i class="bi bi-box-arrow-up-right me-1"></i>Open Full Page</a>
                 <button onclick="Swal.close(); loadWarehouseEditData(${warehouseId});" class="btn btn-outline-warning btn-sm"><i class="bi bi-pencil me-1"></i>Edit Warehouse</button>`
    });
}

// ── openWarehouseStock — fetch real data per warehouse via AJAX ──────
function openWarehouseStock(warehouseId, warehouseName) {
    // Show loading modal first
    Swal.fire({
        title: '<i class="bi bi-box-seam text-primary me-2"></i>Loading Stock...',
        html: '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-3 text-muted">Fetching warehouse stock data...</p></div>',
        width: '95%',
        showCloseButton: true,
        showConfirmButton: false,
        allowOutsideClick: false,
        customClass: { popup: 'text-start', htmlContainer: 'text-start' }
    });

    $.getJSON(APP_URL + '/api/get_warehouse_stock_detail', {
        warehouse_id: warehouseId,
        project_id:   projectId
    }, function(res) {
        if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load stock.' });
            return;
        }

        const d = res.data;
        _whPrintData = { warehouseName, warehouseId, ...d };
        const stockSummary = d.stock_summary  || [];
        const received     = d.received       || [];
        const issued       = d.issued         || [];
        const adjustments  = d.adjustments    || [];
        const movements    = d.movements      || [];

        // ── Build each tab ────────────────────────────────────
        // 1. Stock Summary — from product_stocks
        let summaryHtml = '';
        if (stockSummary.length === 0) {
            summaryHtml = '<div class="p-4 text-center text-muted"><i class="bi bi-box-seam d-block mb-2 fs-2 opacity-25"></i><p>No stock found in this warehouse.</p></div>';
        } else {
            summaryHtml = '<div class="table-responsive"><table id="whDtSummary" class="table table-sm table-hover align-middle mb-0"><thead class="table-light text-uppercase small fw-bold"><tr><th class="ps-2" style="width:45px;">S/NO</th><th>Product</th><th>SKU</th><th class="text-center">Stock Qty</th><th class="text-center">Reserved</th><th class="text-center">Available</th><th>Unit</th></tr></thead><tbody>';
            stockSummary.forEach((item, idx) => {
                const avClass = item.available_quantity > 0 ? 'text-success' : 'text-danger';
                summaryHtml += `<tr>
                    <td class="ps-2 text-muted fw-bold">${idx+1}</td>
                    <td><div class="fw-bold small">${item.product_name}</div><small class="text-muted">${item.category_name || ''}</small></td>
                    <td><code class="small">${item.sku || 'N/A'}</code></td>
                    <td class="text-center fw-bold">${parseFloat(item.stock_quantity).toFixed(3)}</td>
                    <td class="text-center text-warning">${parseFloat(item.reserved_quantity||0).toFixed(3)}</td>
                    <td class="text-center fw-bold ${avClass}">${parseFloat(item.available_quantity).toFixed(3)}</td>
                    <td><span class="badge bg-light text-dark border small">${item.unit || 'pcs'}</span></td>
                </tr>`;
            });
            summaryHtml += '</tbody></table></div>';
        }

        // 2. Materials Received — from GRN receipt_items
        let receivedHtml = '';
        if (received.length === 0) {
            receivedHtml = '<div class="p-4 text-center text-muted"><i class="bi bi-truck d-block mb-2 fs-2 opacity-25"></i><p>No materials received in this warehouse.</p></div>';
        } else {
            receivedHtml = '<div class="table-responsive"><table id="whDtReceived" class="table table-sm table-hover align-middle mb-0"><thead class="table-light text-uppercase small fw-bold"><tr><th class="ps-2" style="width:45px;">S/NO</th><th>Product</th><th>Date</th><th class="text-center">Qty</th><th>GRN #</th><th>Supplier</th><th>Status</th></tr></thead><tbody>';
            received.forEach((item, idx) => {
                receivedHtml += `<tr>
                    <td class="ps-2 text-muted fw-bold">${idx+1}</td>
                    <td><div class="fw-bold small">${item.product_name}</div><small class="text-muted">${item.sku||''}</small></td>
                    <td><small>${formatDate(item.receipt_date)}</small></td>
                    <td class="text-center fw-bold text-success">+${parseFloat(item.quantity_received).toFixed(3)}</td>
                    <td><span class="badge bg-light text-dark border small">${item.receipt_number}</span></td>
                    <td><small>${item.supplier_name||'N/A'}</small></td>
                    <td><span class="badge bg-${item.status==='completed'?'success':'secondary'} small">${item.status}</span></td>
                </tr>`;
            });
            receivedHtml += '</tbody></table></div>';
        }

        // 3. Materials Issued — from delivery_items (DN/DO)
        let issuedHtml = '';
        if (issued.length === 0) {
            issuedHtml = '<div class="p-4 text-center text-muted"><i class="bi bi-truck-flatbed d-block mb-2 fs-2 opacity-25"></i><p>No materials issued from this warehouse.</p></div>';
        } else {
            issuedHtml = '<div class="table-responsive"><table id="whDtIssued" class="table table-sm table-hover align-middle mb-0"><thead class="table-light text-uppercase small fw-bold"><tr><th class="ps-2" style="width:45px;">S/NO</th><th>Product</th><th>Date</th><th class="text-center">Qty</th><th>DN #</th><th>Supplier</th><th>Status</th></tr></thead><tbody>';
            issued.forEach((item, idx) => {
                issuedHtml += `<tr>
                    <td class="ps-2 text-muted fw-bold">${idx+1}</td>
                    <td><div class="fw-bold small">${item.product_name}</div><small class="text-muted">${item.sku||''}</small></td>
                    <td><small>${formatDate(item.delivery_date)}</small></td>
                    <td class="text-center fw-bold text-danger">-${parseFloat(item.quantity_delivered).toFixed(3)}</td>
                    <td><span class="badge bg-light text-primary border small">${item.delivery_number}</span></td>
                    <td><small>${item.supplier_name||'N/A'}</small></td>
                    <td><span class="badge bg-${item.dn_status==='delivered'?'success':item.dn_status==='approved'?'primary':'secondary'} small">${item.dn_status}</span></td>
                </tr>`;
            });
            issuedHtml += '</tbody></table></div>';
        }

        // 4. Adjustments — from stock_movements
        let adjHtml = '';
        if (adjustments.length === 0) {
            adjHtml = '<div class="p-4 text-center text-muted"><i class="bi bi-arrow-left-right d-block mb-2 fs-2 opacity-25"></i><p>No adjustments in this warehouse.</p></div>';
        } else {
            adjHtml = '<div class="table-responsive"><table id="whDtAdj" class="table table-sm table-hover align-middle mb-0"><thead class="table-light text-uppercase small fw-bold"><tr><th class="ps-2" style="width:45px;">S/NO</th><th>Date</th><th>Product</th><th>Type</th><th class="text-center">Qty</th><th>Adjusted By</th></tr></thead><tbody>';
            adjustments.forEach((item, idx) => {
                const isIn  = ['adjustment_in','found','correction'].includes(item.movement_type);
                const qSign = isIn ? '+' : '-';
                const qClass= isIn ? 'text-success' : 'text-danger';
                adjHtml += `<tr>
                    <td class="ps-2 text-muted fw-bold">${idx+1}</td>
                    <td><small>${formatDate(item.movement_date||item.created_at)}</small></td>
                    <td><div class="fw-bold small">${item.product_name}</div></td>
                    <td>${getMovementTypeBadge(item.movement_type)}</td>
                    <td class="text-center fw-bold ${qClass}">${qSign}${parseFloat(item.quantity).toFixed(3)}</td>
                    <td><small class="text-muted">${item.adjusted_by||'System'}</small></td>
                </tr>`;
            });
            adjHtml += '</tbody></table></div>';
        }

        // 5. Movement History — all movements
        let moveHtml = '';
        if (movements.length === 0) {
            moveHtml = '<div class="p-4 text-center text-muted"><i class="bi bi-clock-history d-block mb-2 fs-2 opacity-25"></i><p>No movement history in this warehouse.</p></div>';
        } else {
            moveHtml = '<div class="table-responsive"><table id="whDtMove" class="table table-sm table-hover align-middle mb-0"><thead class="table-light text-uppercase small fw-bold"><tr><th class="ps-2" style="width:45px;">S/NO</th><th>Date/Time</th><th>Type</th><th>Product</th><th class="text-center">Qty</th><th>Ref #</th></tr></thead><tbody>';
            movements.forEach((item, idx) => {
                const isOut = ['sale_out','adjustment_out','transfer_out','return_out','damaged','expired','theft','production_out','issue_out'].includes(item.movement_type);
                moveHtml += `<tr>
                    <td class="ps-2 text-muted fw-bold">${idx+1}</td>
                    <td><small>${formatDateTime(item.created_at)}</small></td>
                    <td>${getMovementTypeBadge(item.movement_type)}</td>
                    <td><div class="small fw-bold">${item.product_name}</div></td>
                    <td class="text-center fw-bold ${isOut?'text-danger':'text-success'}">${isOut?'-':'+'} ${parseFloat(item.quantity).toFixed(3)}</td>
                    <td><small class="text-primary">${item.reference_number||'N/A'}</small></td>
                </tr>`;
            });
            moveHtml += '</tbody></table></div>';
        }

        // ── Build modal ───────────────────────────────────────
        const uid = Date.now();
        const modalHtml = `
        <div class="mb-3">
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2 fs-6">
                <i class="bi bi-building me-2"></i>${warehouseName}
            </span>
        </div>
        <ul class="nav nav-tabs border-0 bg-light p-1 rounded mb-3 flex-nowrap overflow-auto" role="tablist">
            <li class="nav-item"><button class="nav-link active border-0 rounded py-2 px-3 small fw-bold text-nowrap" data-bs-toggle="tab" data-bs-target="#wh-s-${uid}" type="button">Stock Summary <span class="badge bg-success ms-1">${stockSummary.length}</span></button></li>
            <li class="nav-item"><button class="nav-link border-0 rounded py-2 px-3 small fw-bold text-nowrap" data-bs-toggle="tab" data-bs-target="#wh-r-${uid}" type="button">Materials Received <span class="badge bg-secondary ms-1">${received.length}</span></button></li>
            <li class="nav-item"><button class="nav-link border-0 rounded py-2 px-3 small fw-bold text-nowrap" data-bs-toggle="tab" data-bs-target="#wh-i-${uid}" type="button">Materials Issued <span class="badge bg-secondary ms-1">${issued.length}</span></button></li>
            <li class="nav-item"><button class="nav-link border-0 rounded py-2 px-3 small fw-bold text-nowrap" data-bs-toggle="tab" data-bs-target="#wh-a-${uid}" type="button">Adjustments <span class="badge bg-secondary ms-1">${adjustments.length}</span></button></li>
            <li class="nav-item"><button class="nav-link border-0 rounded py-2 px-3 small fw-bold text-nowrap" data-bs-toggle="tab" data-bs-target="#wh-m-${uid}" type="button">Movement History <span class="badge bg-secondary ms-1">${movements.length}</span></button></li>
        </ul>
        <div class="tab-content border rounded bg-white p-2">
            <div class="tab-pane fade show active" id="wh-s-${uid}">${summaryHtml}</div>
            <div class="tab-pane fade" id="wh-r-${uid}">${receivedHtml}</div>
            <div class="tab-pane fade" id="wh-i-${uid}">${issuedHtml}</div>
            <div class="tab-pane fade" id="wh-a-${uid}">${adjHtml}</div>
            <div class="tab-pane fade" id="wh-m-${uid}">${moveHtml}</div>
        </div>`;

        Swal.fire({
            title: '<i class="bi bi-box-seam text-primary me-2"></i>Warehouse Stock & History',
            html: modalHtml,
            width: '96%',
            showCloseButton: true,
            showConfirmButton: false,
            customClass: { popup: 'text-start', htmlContainer: 'text-start' },
            footer: `<button onclick="printWarehouseStock()" class="btn btn-outline-primary btn-sm me-2"><i class="bi bi-printer me-1"></i>Print</button>
                     <a href="${APP_URL}/stock_adjustments?project_id=${projectId}&warehouse_id=${warehouseId}" class="btn btn-success btn-sm me-2"><i class="bi bi-plus-circle me-1"></i>New Adjustment</a>
                     <button onclick="viewWarehouseDetail(${warehouseId}, '${warehouseName.replace(/'/g, "\\'")}')" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Details</button>`,
            didOpen: () => {
                // Init DataTables inside Swal
                ['#whDtSummary','#whDtReceived','#whDtIssued','#whDtAdj','#whDtMove'].forEach(id => {
                    if ($(id).length) {
                        $(id).DataTable({
                            responsive: true,
                            autoWidth: false,
                            pageLength: 25,
                            dom: '<"top"f>rt<"clear">',
                            columnDefs: [
                                {responsivePriority:1, targets:0},
                                {responsivePriority:1, targets:1},
                                {responsivePriority:2, targets:3},
                                {responsivePriority:3, targets:2},
                                {responsivePriority:1, targets:-1}
                            ]
                        });
                    }
                });
                // Recalculate DataTables when switching tabs inside Swal
                $('[data-bs-toggle="tab"]', Swal.getHtmlContainer()).on('shown.bs.tab', function () {
                    const map = {
                        ['#wh-s-' + uid]: '#whDtSummary',
                        ['#wh-r-' + uid]: '#whDtReceived',
                        ['#wh-i-' + uid]: '#whDtIssued',
                        ['#wh-a-' + uid]: '#whDtAdj',
                        ['#wh-m-' + uid]: '#whDtMove'
                    };
                    const tblId = map[$(this).data('bs-target')];
                    if (tblId && $.fn.DataTable.isDataTable(tblId)) {
                        $(tblId).DataTable().columns.adjust().responsive.recalc();
                    }
                });
            }
        });

    }).fail(function() {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load warehouse stock. Please try again.' });
    });
}

