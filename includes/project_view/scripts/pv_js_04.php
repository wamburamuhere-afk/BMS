function printWarehouseStock() {
    if (!_whPrintData) return;

    const wName        = _whPrintData.warehouseName;
    const stockSummary = _whPrintData.stock_summary || [];
    const received     = _whPrintData.received      || [];
    const issued       = _whPrintData.issued         || [];
    const adjustments  = _whPrintData.adjustments   || [];
    const movements    = _whPrintData.movements      || [];

    // Build a simple print table
    function pTable(rows, headers, rowFn, emptyMsg) {
        if (!rows.length) return `<p style="color:#6c757d;font-size:9pt;font-style:italic;padding:6px 0;">${emptyMsg}</p>`;
        let t = `<table style="width:100%;border-collapse:collapse;font-size:9pt;">
            <thead><tr>` +
            headers.map(h => `<th style="border:1px solid #dee2e6;padding:5px 7px;background:#f8f9fa;font-weight:700;font-size:8pt;text-transform:uppercase;">${h}</th>`).join('') +
            `</tr></thead><tbody>`;
        rows.forEach((r, i) => {
            t += '<tr>' + rowFn(r, i).map(c => `<td style="border:1px solid #dee2e6;padding:5px 7px;vertical-align:middle;">${c}</td>`).join('') + '</tr>';
        });
        return t + '</tbody></table>';
    }

    function sec(title, content) {
        return `<div style="margin-bottom:18px;">
            <div style="background:#0d6efd;color:#fff;padding:6px 10px;font-weight:700;font-size:10pt;text-transform:uppercase;margin-bottom:6px;border-radius:3px;">${title}</div>
            ${content}
        </div>`;
    }

    const s1 = pTable(stockSummary,
        ['S/NO','Product','SKU','Category','Stock Qty','Reserved','Available','Unit'],
        (r,i) => [i+1, r.product_name, r.sku||'N/A', r.category_name||'—', parseFloat(r.stock_quantity).toFixed(3), parseFloat(r.reserved_quantity||0).toFixed(3), parseFloat(r.available_quantity).toFixed(3), r.unit||'pcs'],
        'No stock found in this warehouse.');

    const s2 = pTable(received,
        ['S/NO','Product','SKU','GRN #','Date','Qty Received','Supplier','Status'],
        (r,i) => [i+1, r.product_name, r.sku||'N/A', r.receipt_number, formatDate(r.receipt_date), parseFloat(r.quantity_received).toFixed(3), r.supplier_name||'N/A', r.status],
        'No materials received in this warehouse.');

    const s3 = pTable(issued,
        ['S/NO','Product','SKU','DN #','Date','Qty Issued','Supplier','Status'],
        (r,i) => [i+1, r.product_name, r.sku||'N/A', r.delivery_number, formatDate(r.delivery_date), parseFloat(r.quantity_delivered).toFixed(3), r.supplier_name||'N/A', r.dn_status],
        'No materials issued from this warehouse.');

    const s4 = pTable(adjustments,
        ['S/NO','Date','Product','SKU','Type','Quantity','Adjusted By'],
        (r,i) => [i+1, formatDate(r.movement_date||r.created_at), r.product_name, r.sku||'N/A', r.movement_type.replace(/_/g,' '), parseFloat(r.quantity).toFixed(3), r.adjusted_by||'System'],
        'No adjustments recorded.');

    const s5 = pTable(movements,
        ['S/NO','Date/Time','Product','SKU','Type','Quantity','Ref #'],
        (r,i) => [i+1, formatDateTime(r.created_at), r.product_name, r.sku||'N/A', r.movement_type.replace(/_/g,' '), parseFloat(r.quantity).toFixed(3), r.reference_number||'N/A'],
        'No movement history.');

    // Dynamic timestamp for footer
    const now = new Date();
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const dateStr = `${String(now.getDate()).padStart(2,'0')} ${months[now.getMonth()]}, ${now.getFullYear()} at ${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')}`;

    const logoHtml = companyLogo
        ? `<img src="${APP_URL}/${companyLogo}" alt="Logo" style="max-height:70px;width:auto;display:block;margin:0 auto 8px;">`
        : '';

    const printEl = document.getElementById('whStockPrintContainer');
    printEl.innerHTML = `
        <div style="font-family:sans-serif;padding:10mm 12mm;">
            <!-- Header -->
            <div style="text-align:center;margin-bottom:20px;padding-bottom:12px;border-bottom:2px solid #0d6efd;">
                ${logoHtml}
                <div style="font-size:1.3rem;font-weight:800;text-transform:uppercase;color:#0d6efd;">${companyName}</div>
                <div style="font-size:1rem;font-weight:700;text-transform:uppercase;color:#212529;margin-top:4px;">WAREHOUSE STOCK &amp; HISTORY</div>
                <div style="font-size:0.85rem;color:#6c757d;margin-top:4px;">${wName}</div>
                <div style="width:60px;height:3px;background:#0d6efd;margin:8px auto 0;border-radius:2px;"></div>
            </div>
            <!-- Sections -->
            ${sec('1. Stock Summary', s1)}
            ${sec('2. Materials Received', s2)}
            ${sec('3. Materials Issued', s3)}
            ${sec('4. Adjustments', s4)}
            ${sec('5. Movement History', s5)}
            <!-- Footer -->
            <div style="margin-top:24px;padding-top:10px;border-top:1px solid #dee2e6;text-align:center;font-size:8.5pt;color:#495057;">
                <p style="margin:0 0 2px;">This document was <strong>Printed</strong> by <strong>${currentUserName} - ${currentUserRole}</strong> on <strong>${dateStr}</strong></p>
                <p style="margin:0;font-weight:700;color:#0d6efd;">Powered By BJP Technologies &copy; 2026, All Rights Reserved</p>
            </div>
        </div>`;

    // Move to body level — escapes .container-fluid which is hidden during warehouse-stock-print mode
    document.body.appendChild(printEl);

    document.body.classList.add('warehouse-stock-print');
    const restore = () => {
        document.body.classList.remove('warehouse-stock-print');
        printEl.style.display = 'none';
        window.removeEventListener('afterprint', restore);
    };
    window.addEventListener('afterprint', restore);
    window.print();
}

function manageWarehouseLocations(id) {
    window.location.href = APP_URL + '/locations?warehouse_id=' + id + '&project_id=' + projectId;
}

function transferWarehouseStock(id) {
    window.location.href = APP_URL + '/stock_transfers?warehouse_id=' + id + '&project_id=' + projectId;
}

function loadWarehouseEditData(id) {
    $.ajax({
        url: APP_URL + '/ajax_get_warehouse.php',
        type: 'GET',
        data: { id: id },
        success: function(response) {
            $('#edit_proj_warehouse_id').val(id);
            $('#editWarehouseFormContent').html(response);
            $('#editProjectWarehouseModal').modal('show');
        },
        error: () => Swal.fire('Error', 'Failed to load warehouse data', 'error')
    });
}


function getMovementTypeBadge(type) {
    const labels = {
        'purchase_in': 'Inbound',
        'sale_out': 'Outbound',
        'adjustment_in': 'Adjust In',
        'adjustment_out': 'Adjust Out',
        'found': 'Found Stock',
        'theft': 'Theft/Loss',
        'damaged': 'Damage',
        'expired': 'Expired'
    };
    const colors = {
        'purchase_in': 'success',
        'sale_out': 'danger',
        'adjustment_in': 'info',
        'adjustment_out': 'warning',
        'found': 'success',
        'theft': 'danger',
        'damaged': 'secondary',
        'expired': 'dark'
    };
    let color = colors[type] || 'primary';
    let label = labels[type] || type.replace('_', ' ');
    return `<span class="badge bg-${color}-soft text-${color} border border-${color} small py-1" style="font-size:0.65rem;">${label.toUpperCase()}</span>`;
}

function viewMovementDetails(id) {
    $.ajax({
        url: '<?= getUrl("api/get_adjustment") ?>',
        type: 'GET',
        data: { id: id },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const adj = response.data;
                const typeLabel = getMovementTypeBadge(adj.movement_type);
                
                const details = `
                    <div class="text-start">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Product:</strong> ${adj.product_name}</p>
                                <p><strong>SKU:</strong> ${adj.sku || 'N/A'}</p>
                                <p><strong>Barcode:</strong> ${adj.barcode || 'N/A'}</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Warehouse:</strong> ${adj.warehouse_name}</p>
                                <p><strong>Location:</strong> ${adj.location_name || 'N/A'}</p>
                                <p><strong>Project:</strong> ${adj.project_name || 'N/A'}</p>
                                <p><strong>Date:</strong> ${new Date(adj.created_at).toLocaleString()}</p>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <p><strong>Type:</strong> <span class="badge bg-light text-dark border">${adj.movement_type.replace('_', ' ').toUpperCase()}</span></p>
                                <p><strong>Quantity:</strong> <span class="${['adjustment_in', 'found'].includes(adj.movement_type) ? 'text-success' : 'text-danger'} fw-bold">${adj.quantity} ${adj.unit || 'pcs'}</span></p>
                                <p><strong>Unit Cost:</strong> ${formatMoney(adj.unit_cost)}</p>
                                <p><strong>Total Value:</strong> ${formatMoney(adj.quantity * adj.unit_cost)}</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Reason:</strong> ${adj.reason}</p>
                                <p><strong>Stock Before:</strong> ${adj.stock_before}</p>
                                <p><strong>Stock After:</strong> ${adj.stock_after}</p>
                                <p><strong>Adjusted By:</strong> ${adj.adjusted_by_name}</p>
                            </div>
                        </div>
                        ${adj.notes ? `<div class="row mt-3">
                            <div class="col-12">
                                <div class="p-2 bg-light border rounded">
                                    <small class="text-muted d-block fw-bold">NOTES:</small>
                                    ${adj.notes}
                                </div>
                            </div>
                        </div>` : ''}
                    </div>
                `;
                
                Swal.fire({
                    title: 'Adjustment Details',
                    html: details,
                    width: 700,
                    showCloseButton: true,
                    showConfirmButton: false,
                    footer: `<a href="<?= getUrl('stock_adjustments') ?>?edit=${id}&project_id=${projectId}" class="btn btn-warning btn-sm"><i class="bi bi-pencil"></i> Edit Adjustment</a>`
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: response.message, confirmButtonColor: '#3085d6', confirmButtonText: 'OK' });
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to fetch adjustment details.', confirmButtonColor: '#d33', confirmButtonText: 'OK' });
        }
    });
}

let _notesState = { offset: 0, limit: 20, total: 0 };

function renderNotes(description) {
    const html = `
        <div class="card border-0 shadow-sm mb-4 bg-white" style="border-radius:10px;">
            <div class="card-header bg-light border-0 py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold small text-uppercase text-primary"><i class="bi bi-pin-angle-fill me-1"></i> Pinned: Project Description</span>
                </div>
            </div>
            <div class="card-body">
                <p class="mb-0 text-muted" style="white-space:pre-wrap;">${safeOutput(description) || 'No detailed description provided for this project.'}</p>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3 d-print-none">
            <div class="card-body py-2 px-3">
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="noteSearch" class="form-control border-start-0 ps-0" placeholder="Search notes or author...">
                        </div>
                    </div>
                    <div class="col-6 col-md-auto">
                        <select id="noteAuthor" class="form-select form-select-sm" title="Filter by author"><option value="">All authors</option></select>
                    </div>
                    <div class="col-6 col-md-auto">
                        <input type="date" id="noteDateFrom" class="form-control form-control-sm" title="From date">
                    </div>
                    <div class="col-6 col-md-auto">
                        <input type="date" id="noteDateTo" class="form-control form-control-sm" title="To date">
                    </div>
                    <div class="col-6 col-md-auto">
                        <button class="btn btn-outline-secondary btn-sm w-100" onclick="clearNoteFilters()"><i class="bi bi-x-circle me-1"></i>Clear</button>
                    </div>
                </div>
            </div>
        </div>

        <h6 class="fw-bold mb-2"><i class="bi bi-chat-dots me-2 text-primary"></i>Notes <span class="badge bg-primary bg-opacity-10 text-primary ms-1" id="noteCount">0</span></h6>
        <div id="projectNotesTimeline"></div>
        <div class="text-center mt-3 d-print-none" id="noteLoadMoreWrap" style="display:none;">
            <button class="btn btn-outline-primary btn-sm" onclick="loadProjectNotes(false)"><i class="bi bi-arrow-down-circle me-1"></i>Load more</button>
        </div>
    `;
    $('#projectNotesList').html(html);

    let _nt;
    $('#noteSearch').on('input', function () { clearTimeout(_nt); _nt = setTimeout(() => loadProjectNotes(true), 300); });
    $('#noteAuthor, #noteDateFrom, #noteDateTo').on('change', function () { loadProjectNotes(true); });

    loadProjectNotes(true);
}

function noteCardHtml(n) {
    const initials = ((n.author || '?').trim().charAt(0) || '?').toUpperCase();
    return `<div class="border-start border-2 border-primary-soft ps-4 pb-4 position-relative">
        <span class="position-absolute start-0 translate-middle bg-primary rounded-circle" style="width:14px;height:14px;left:-1px !important;top:0px;"></span>
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-bold small text-dark d-flex align-items-center gap-2">
                <span class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:26px;height:26px;font-size:0.7rem;font-weight:700;">${safeOutput(initials)}</span>
                ${safeOutput(n.author)}
            </span>
            <span class="text-muted" style="font-size:0.7rem;"><i class="bi bi-clock me-1"></i>${formatDateTime(n.created_at)}</span>
        </div>
        <div class="bg-light p-3 rounded small border-start border-4 border-primary d-flex justify-content-between align-items-start gap-2">
            <p class="mb-0" style="white-space:pre-wrap;">${safeOutput(n.note)}</p>
            <button class="btn btn-sm btn-link text-danger p-0 flex-shrink-0 d-print-none" title="Delete note" onclick="deleteProjectNote(${n.note_id})"><i class="bi bi-trash"></i></button>
        </div>
    </div>`;
}

function loadProjectNotes(reset) {
    const $t = $('#projectNotesTimeline');
    if (!$t.length) return;
    const offset = reset ? 0 : _notesState.offset;
    if (reset) $t.html('<div class="text-center py-3"><span class="spinner-border spinner-border-sm text-primary"></span></div>');

    $.getJSON(APP_URL + '/api/operations/get_project_notes.php', {
        project_id: <?= $project_id ?>,
        search:    $('#noteSearch').val() || '',
        author:    $('#noteAuthor').val() || '',
        date_from: $('#noteDateFrom').val() || '',
        date_to:   $('#noteDateTo').val() || '',
        limit:     _notesState.limit,
        offset:    offset
    }, function (res) {
        if (!res || !res.success) { if (reset) $t.html('<div class="alert alert-danger">Failed to load notes.</div>'); return; }

        // Author dropdown (populate once; keep current selection)
        const $auth = $('#noteAuthor'), cur = $auth.val();
        $auth.find('option:not(:first)').remove();
        (res.authors || []).forEach(a => $auth.append(`<option value="${a.user_id}">${safeOutput(a.username)}</option>`));
        if (cur) $auth.val(cur);

        $('#noteCount').text(res.total);
        const rows = (res.data || []).map(noteCardHtml).join('');

        if (reset) {
            $t.html(res.total > 0
                ? '<div class="timeline-notes ps-3">' + rows + '</div>'
                : '<div class="text-center text-muted py-4 border rounded bg-light"><i class="bi bi-chat-dots fs-2 d-block mb-2 opacity-25"></i>No notes match your filters.</div>');
        } else {
            $t.find('.timeline-notes').append(rows);
        }
        _notesState.offset = offset + (res.data || []).length;
        _notesState.total  = res.total;
        $('#noteLoadMoreWrap').toggle(_notesState.offset < res.total);
    }).fail(function () { if (reset) $t.html('<div class="alert alert-danger">Failed to load notes. Please refresh.</div>'); });
}

function clearNoteFilters() {
    $('#noteSearch').val('');
    $('#noteAuthor').val('');
    $('#noteDateFrom').val('');
    $('#noteDateTo').val('');
    loadProjectNotes(true);
}

function deleteProjectNote(id) {
    Swal.fire({ title: 'Delete Note?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Yes, delete' })
        .then((r) => {
            if (!r.isConfirmed) return;
            $.post(APP_URL + '/api/operations/delete_project_note.php', { note_id: id }, function(res) {
                if (res && res.success) { Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false }); loadProjectNotes(true); }
                else Swal.fire('Error', (res && res.message) || 'Failed to delete note.', 'error');
            }, 'json').fail(function() { Swal.fire('Error', 'Server error. Please try again.', 'error'); });
        });
}

function renderReports(summary, progress) {
    $('#reportFinancialDesc').html(`Rev: <strong>${formatMoney(summary.total_revenue)}</strong> | Exp: <strong>${formatMoney(summary.total_expense)}</strong>`);
    $('#reportProgressDesc').html(`Completion: <strong>${Math.round(progress.calculated_progress)}%</strong> | Status: <strong>${progress.status.toUpperCase()}</strong>`);
    $('#reportBudgetDesc').html(`Budget: <strong>${formatMoney(summary.budget)}</strong> | Utilization: <strong>${progress.budget_utilization}%</strong>`);
}

function getStatusColorClass(s) {
    if (s === 'active') return 'bg-success text-white';
    if (s === 'completed') return 'bg-primary text-white';
    if (s === 'on_hold') return 'bg-warning text-dark';
    if (s === 'cancelled') return 'bg-danger text-white';
    return 'bg-secondary text-white';
}

function getStatusBadgeColor(s) {
    if (s === 'approved' || s === 'active' || s === 'paid' || s === 'completed') return 'success';
    if (s === 'pending') return 'warning';
    if (s === 'cancelled' || s === 'rejected') return 'danger';
    return 'secondary';
}

function getProgressColor(p) {
    if (p < 30) return 'bg-danger';
    if (p < 75) return 'bg-warning';
    return 'bg-success';
}

function safeOutput(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]);
}

function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    // Append T00:00:00 to force local time parsing for date-only strings
    const str = dateStr.includes(' ') || dateStr.includes('T') ? dateStr : dateStr + 'T00:00:00';
    return new Date(str).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(dateStr) {
    if (!dateStr) return 'N/A';
    return new Date(dateStr).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatMoney(amount) {
    return parseFloat(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatFileSize(bytes) {
    if (!bytes || bytes == 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// ===== PROJECT WORKSPACE ACTIONS =====

function createInvoice() {
    // Redirect to invoice create page with project pre-selected
    window.location.href = `<?= getUrl('invoice_create') ?>?project=${projectId}`;
}

function createSalesOrder() {
    // Redirect to sales order create page with project pre-selected
    window.location.href = `<?= getUrl('sales_order_create') ?>?project=${projectId}`;
}

function createPurchaseOrder() {
    let url = `<?= getUrl('purchase_order_create') ?>?project=${projectId}`;
    if (supplierMode && viewSupplierId) url += `&supplier=${viewSupplierId}`;
    window.location.href = url;
}

// ============================================================
// GOODS RETURN MODAL FUNCTIONS
// ============================================================
<?php
// Fetch items received via GRN (purchase_receipts) linked to this project
$purchased_items = [];
if ($project_id > 0) {
    // GRNs linked directly via PO project_id
    $grn_stmt = $pdo->prepare("
        SELECT 
            p.product_id,
            p.product_name,
            SUM(ri.quantity_received) AS total_qty_received,
            ri.unit
        FROM receipt_items ri
        JOIN purchase_receipts pr ON ri.receipt_id = pr.receipt_id
        JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        JOIN products p ON ri.product_id = p.product_id
        WHERE po.project_id = ? AND pr.status != 'cancelled'
        GROUP BY p.product_id, ri.unit
        ORDER BY p.product_name ASC
    ");
    $grn_stmt->execute([$project_id]);
    $purchased_items = $grn_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: GRNs not linked to PO but supplier is in project (direct GRNs)
    if (empty($purchased_items)) {
        $grn_stmt2 = $pdo->prepare("
            SELECT 
                p.product_id,
                p.product_name,
                SUM(ri.quantity_received) AS total_qty_received,
                ri.unit
            FROM receipt_items ri
            JOIN purchase_receipts pr ON ri.receipt_id = pr.receipt_id
            JOIN suppliers s ON pr.supplier_id = s.supplier_id
            JOIN products p ON ri.product_id = p.product_id
            WHERE s.project_id = ? AND pr.status != 'cancelled'
            GROUP BY p.product_id, ri.unit
            ORDER BY p.product_name ASC
        ");
        $grn_stmt2->execute([$project_id]);
        $purchased_items = $grn_stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
// Return Note Dynamic Loading
function loadReturnSuppliers(warehouseId) {
    const $sup = $('#returnSupplierId');
    const $grn = $('#returnReceiptId');
    
    if (!warehouseId) {
        $sup.html('<option value="">Select Warehouse First</option>').prop('disabled', true);
        $grn.html('<option value="">Select Supplier First</option>').prop('disabled', true);
        return;
    }

    $sup.html('<option value="">Loading...</option>').prop('disabled', true);
    
    $.get('<?= getUrl('api/operations/get_return_suppliers') ?>', { warehouse_id: warehouseId, project_id: '<?= $project_id ?>' }, function(res) {
        if (res.success && res.data.length > 0) {
            let html = '<option value="">Select Supplier</option>';
            res.data.forEach(s => {
                html += `<option value="${s.supplier_id}">${s.supplier_name}</option>`;
            });
            $sup.html(html).prop('disabled', false);
        } else if (res.success) {
            // Empty list read as "the dropdown is broken" before — say why it's empty.
            $sup.html('<option value="">No supplier has a GRN in this warehouse</option>');
        } else {
            $sup.html('<option value="">' + (res.message || 'No Suppliers found') + '</option>');
        }
    });
}

function loadReturnGRNs(supplierId) {
    const $grn = $('#returnReceiptId');
    const warehouseId = $('#returnWarehouseId').val();
    
    if (!supplierId || !warehouseId) {
        $grn.html('<option value="">Select Supplier First</option>').prop('disabled', true);
        return;
    }

    $grn.html('<option value="">Loading...</option>').prop('disabled', true);
    
    $.get('<?= getUrl('api/operations/get_return_grns') ?>', { warehouse_id: warehouseId, supplier_id: supplierId, project_id: '<?= $project_id ?>' }, function(res) {
        if (res.success) {
            let html = '<option value="">Select GRN</option>';
            res.data.forEach(g => {
                html += `<option value="${g.receipt_id}">${g.receipt_number} (${g.receipt_date})</option>`;
            });
            $grn.html(html).prop('disabled', false);
        } else {
            $grn.html('<option value="">No GRNs found</option>');
        }
    });
}

function loadGRNItems(receiptId) {
    const $body = $('#returnItemsBody');
    const warehouseId = $('#returnWarehouseId').val();

    if (!receiptId) {
        $body.html('<tr class="empty-row"><td colspan="7" class="text-center text-muted py-3">Select a GRN to populate items</td></tr>');
        return;
    }
    
    $body.html('<tr class="loading-row"><td colspan="7" class="text-center py-3"><span class="spinner-border spinner-border-sm text-primary"></span> Loading items...</td></tr>');
    
    $.get('<?= getUrl('api/operations/get_grn_items') ?>', { receipt_id: receiptId, warehouse_id: warehouseId }, function(res) {
        if (res.success && res.data.length > 0) {
            $body.empty();
            res.data.forEach((item, i) => {
                appendReturnRow(item, i);
            });
        } else {
            $body.html('<tr class="empty-row"><td colspan="7" class="text-center text-danger py-3">No items found in this GRN</td></tr>');
        }
    });
}

function updateReturnSerialNumbers() {
    $('#returnItemsBody tr:not(.empty-row, .loading-row)').each(function(index) {
        $(this).find('.row-sn').text(index + 1);
    });
}

function appendReturnRow(data = {}, i = null) {
    if (i === null) i = $('#returnItemsBody tr:not(.empty-row, .loading-row)').length;
    
    const rowId = `return-row-${i}`;
    const grnQty = parseFloat(data.qty || 0);
    const stockQty = parseFloat(data.current_stock || 0);
    const itemName = data.product_name || '';

    const html = `
        <tr id="${rowId}">
            <td class="row-sn text-center fw-bold text-muted">${$('#returnItemsBody tr:not(.empty-row, .loading-row)').length + 1}</td>
            <td>
                <input type="hidden" name="return_items[${i}][product_id]" value="${data.product_id || ''}">
                <div class="fw-bold small mb-1 text-truncate" style="max-width: 150px;" title="${itemName}">${itemName}</div>
                <div class="small">
                    <span class="badge bg-light text-dark border">GRN: ${grnQty}</span>
                    <span class="badge ${stockQty <= 0 ? 'bg-danger' : 'bg-info'} text-white">Stock: ${stockQty}</span>
                </div>
                <input type="hidden" name="return_items[${i}][item_name]" value="${itemName}">
            </td>
            <td><input type="text" class="form-control form-control-sm" name="return_items[${i}][sku]" value="${data.sku || data.barcode || ''}" readonly></td>
            <td>
                <input type="number" class="form-control form-control-sm return-qty-input" 
                    name="return_items[${i}][quantity]" value="${Math.min(grnQty, stockQty) || 0}" 
                    min="0" step="0.001" 
                    data-max-grn="${grnQty}" 
                    data-max-stock="${stockQty}" 
                    data-name="${itemName}"
                    onchange="validateReturnQty(this); updateReturnRowTotal(this)" required>
            </td>
            <td><input type="text" class="form-control form-control-sm return-unit-input" name="return_items[${i}][unit]" value="${data.unit || 'pcs'}" readonly></td>
            <td><input type="number" class="form-control form-control-sm return-price-input" name="return_items[${i}][unit_price]" value="${data.unit_price || 0}" step="0.01" onchange="updateReturnRowTotal(this)" required></td>
            <td><input type="number" class="form-control form-control-sm return-total-input" name="return_items[${i}][total]" value="${(Math.min(grnQty, stockQty) * (data.unit_price || 0)).toFixed(2)}" step="0.01" readonly style="background-color: #f8f9fa;"></td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeReturnRow(this)"><i class="bi bi-trash"></i></button>
            </td>
        </tr>
    `;
    $('#returnItemsBody').append(html);
    updateReturnSerialNumbers();
}

function removeReturnRow(btn) {
    $(btn).closest('tr').remove();
    updateReturnSerialNumbers();
    if ($('#returnItemsBody tr').length === 0) {
        $('#returnItemsBody').html('<tr class="empty-row"><td colspan="8" class="text-center text-muted py-3">Select a GRN to populate items</td></tr>');
    }
}

function updateReturnRowTotal(input) {
    const row = $(input).closest('tr');
    const qty = parseFloat(row.find('.return-qty-input').val()) || 0;
    const price = parseFloat(row.find('.return-price-input').val()) || 0;
    row.find('.return-total-input').val((qty * price).toFixed(2));
}

function validateReturnQty(qtyInput) {
    const maxGrn = parseFloat($(qtyInput).attr('data-max-grn')) || 0;
    const maxStock = parseFloat($(qtyInput).attr('data-max-stock')) || 0;
    const itemName = $(qtyInput).attr('data-name') || 'Product';
    const val = parseFloat($(qtyInput).val()) || 0;
    
    if (val > maxStock) {
        Swal.fire({
            icon: 'error',
            title: 'Insufficient Stock!',
            text: `You cannot return ${val} units of '${itemName}'. Only ${maxStock} units currently available in this warehouse.`,
            confirmButtonText: 'OK'
        });
        $(qtyInput).val(maxStock);
        updateReturnRowTotal(qtyInput);
    } else if (val > maxGrn) {
        Swal.fire({
            icon: 'warning',
            title: 'GRN Limit Exceeded!',
            text: `You only received ${maxGrn} units in this GRN for '${itemName}'. Defaulting to GRN quantity.`,
            confirmButtonText: 'OK'
        });
        $(qtyInput).val(maxGrn);
        updateReturnRowTotal(qtyInput);
    }
}

function addReturnItemRow() {
    appendReturnRow();
}

// Auto-generate return number when modal opens
document.getElementById('createReturnModal')?.addEventListener('show.bs.modal', function() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const rn = `RET-${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}-${Math.floor(100+Math.random()*900)}`;
    document.getElementById('returnNumber').value = rn;
    
    // Reset items to empty state
    $('#returnItemsBody').html('<tr class="empty-row"><td colspan="7" class="text-center text-muted py-3">Select a GRN to populate items</td></tr>');
    $('#returnWarehouseId').val('');
    $('#returnSupplierId').html('<option value="">Select Warehouse First</option>').prop('disabled', true);
    $('#returnReceiptId').html('<option value="">Select Supplier First</option>').prop('disabled', true);
    
    // Reset Other Reason
    $('#return_reason_select').val('');
    $('#other_return_reason_div').hide();
    $('#other_return_reason').val('');
});

function toggleReturnReasonOther(select) {
    if (select.value === 'other') {
        $('#other_return_reason_div').slideDown();
        $('#other_return_reason').attr('required', true);
    } else {
        $('#other_return_reason_div').slideUp();
        $('#other_return_reason').attr('required', false);
    }
}

// Handle Returns form submit — save and stay in project
$('#createReturnForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const items = [];
    let isValid = true;
    let errorMsg = '';

    $('#returnItemsBody tr:not(.empty-row, .loading-row)').each(function(idx) {
        const prodId = $(this).find('input[name$="[product_id]"]').val();
        const name = $(this).find('input[name$="[item_name]"]').val();
        const sku = $(this).find('input[name$="[sku]"]').val();
        const qtyInput = $(this).find('input[name$="[quantity]"]');
        const qty = parseFloat(qtyInput.val());
        const unit = $(this).find('input[name$="[unit]"]').val();
        const price = $(this).find('input[name$="[unit_price]"]').val();
        const total = $(this).find('input[name$="[total]"]').val();
        
        // Strict submission validation
        const maxGrn = parseFloat(qtyInput.attr('data-max-grn')) || 0;
        const maxStock = parseFloat(qtyInput.attr('data-max-stock')) || 0;
        
        if (qty > maxStock) {
            isValid = false;
            errorMsg = `Insufficient stock for '${name}'. You want to return ${qty}, but only ${maxStock} is available in the warehouse.`;
            return false;
        }

        if (qty > maxGrn) {
            isValid = false;
            errorMsg = `Cannot return more than received. For '${name}', max received is ${maxGrn}.`;
            return false;
        }

        if (name && qty > 0) {
            items.push({ 
                product_id: prodId,
                item_name: name, 
                sku: sku,
                quantity: qty, 
                unit: unit,
                unit_price: price,
                total: total
            });
        }
    });

    if (!isValid) {
        Swal.fire('Validation Error', errorMsg, 'error');
        return false;
    }

    if (items.length === 0) {
        Swal.fire('Error', 'Please include at least one item with a valid quantity.', 'error');
        return false;
    }

    formData.append('items_json', JSON.stringify(items));

    // Handle "Other" reason
    if (formData.get('return_reason') === 'other') {
        const otherVal = $('#other_return_reason').val();
        if (!otherVal) {
            Swal.fire('Error', 'Please specify the reason for return.', 'error');
            return;
        }
        formData.set('return_reason', otherVal);
    }

    const btn = $(this).find('[type="submit"]');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');

    $.ajax({
        url: '<?= getUrl('api/operations/save_goods_return') ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Return Note');
            if (res && res.success) {
                $('#createReturnModal').modal('hide');
                Swal.fire({ icon: 'success', title: 'Return Saved!', text: res.message || 'Goods return note created.', timer: 1800, showConfirmButton: false });
                // Refresh project specifics (this updates all tables including procurement returns)
                setTimeout(() => { loadProjectDetails(); }, 1900);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res?.message || 'Failed to save return note.' });
            }
        },
        error: function() {
            btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Return Note');
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' });
        }
    });
});

function createVoucher() {
    // Reset form
    $('#createVoucherForm')[0].reset();
    $('#vc_date').val(new Date().toISOString().slice(0, 10));
    $('#vc_amount_words').val('');
    $('#vc_balance_info_cont').hide();
    $('#vc_amount_validation').hide();

    // Populate Expense/Category dropdown
    const $expSel = $('#vc_expense_id');
    $expSel.html('<option value="">Select Specific Expense / Item to Pay</option>');

    if (projectData && ( (projectData.expenses && projectData.expenses.length > 0) || (projectData.budgets && projectData.budgets.length > 0) )) {
        // 1. Registered Expenses (Specific Items)
        if (projectData.expenses && projectData.expenses.length > 0) {
            const $grp = $('<optgroup label="── Registered Project Expenses ──"></optgroup>');
            projectData.expenses.forEach(ex => {
                $grp.append(`<option value="exp_${ex.expense_id}" data-type="expense" data-id="${ex.expense_id}" data-amount="${ex.amount}" data-cat="${ex.category_id || ''}" data-expaccount="${ex.expense_account_id || ''}">${ex.description || 'Unnamed Expense'} (${formatMoney(ex.amount)} TZS)</option>`);
            });
            $expSel.append($grp);
        }

        // 2. Budget Categories (Fallback)
        if (projectData.budgets && projectData.budgets.length > 0) {
            const $grp = $('<optgroup label="── General Budget Categories ──"></optgroup>');
            projectData.budgets.forEach(b => {
                if (b.category_id) {
                    $grp.append(`<option value="cat_${b.category_id}" data-type="category" data-id="${b.category_id}">${b.category_name}</option>`);
                }
            });
            $expSel.append($grp);
        }
    } else {
        $expSel.html('<option value="">⚠️ No expenses or budget found for this project</option>');
    }

    $('#createVoucherModal').modal('show');
}

function vcOnExpenseChange(val) {
    const $info = $('#vc_balance_info_cont');
    const $valErr = $('#vc_amount_validation');
    const $catHidden = $('#vc_category_id_hidden');
    $info.hide();
    $valErr.hide();
    $catHidden.val('');
    
    if (!val) return;

    const opt = $('#vc_expense_id option:selected');
    const type = opt.data('type');
    const id = opt.data('id');
    const totalAmount = parseFloat(opt.data('amount')) || 0;
    
    if (type === 'expense') {
        $catHidden.val(opt.data('cat'));

        // Pre-fill the Expense Account from the linked expense's own account —
        // still freely editable, same as the external Payment Vouchers form.
        const expAccount = opt.data('expaccount');
        if (expAccount) $('#vc_expense_account_id').val(String(expAccount));

        // Calculate paid so far from projectData.payment_vouchers
        const paidSoFar = (projectData.payment_vouchers || [])
            .filter(v => v.expense_id == id && ['approved', 'paid'].includes(v.status))
            .reduce((sum, v) => sum + parseFloat(v.amount), 0);
            
        const remaining = Math.max(0, totalAmount - paidSoFar);
        
        $('#vc_total_exp').text(formatMoney(totalAmount) + ' TZS');
        $('#vc_already_paid').text(formatMoney(paidSoFar) + ' TZS');
        $('#vc_remaining').text(formatMoney(remaining) + ' TZS');
        
        // Update amount field with remaining if it's 0 or empty
        if (!$('#vc_amount').val() || $('#vc_amount').val() == 0) {
            $('#vc_amount').val(remaining.toFixed(2));
            vcUpdateAmountWords(remaining);
        }
        
        $info.show();
    } else if (type === 'category') {
        $catHidden.val(id);
    }
}

function createExpense() {
    $('#addExpenseForm')[0].reset();
    // Reset breakdown
    $('#breakdown-body').empty();
    $('#breakdown-grand-total').text('0.00');
    $('#expense_items_json').val('');
    // Reset paid-to
    $('#proj_paid_to_id_block').addClass('d-none');
    const $ps = $('#proj_paid_to_id_select');
    if ($ps.data('select2')) $ps.select2('destroy');
    $ps.empty().append('<option value="">Select...</option>');
    // Reset category cascade
    $('#proj_add_cascade_container').find('.proj-cascade-sel').each(function() { if ($(this).data('select2')) $(this).select2('destroy'); });
    $('#proj_add_cascade_container').empty();
    $('#proj_add_selected_cat').val('');
    $('.add-expense-category-block').hide();
    $('#addExpenseModal').modal('show');
}

$(document).on('submit', '#addExpenseForm', function(e) {
    e.preventDefault();
    submitExpenseForm($(this));
});

// Build a clear message for a failed expense request (HTTP/server error, expired
// security token, permission, network, or a non-JSON response) so the user always
// gets a SweetAlert instead of a silent failure.
function expenseErrorMsg(xhr) {
    if (xhr) {
        if (xhr.status === 419) return 'Your security token expired. Please refresh the page and try again.';
        if (xhr.status === 403) return 'You do not have permission to record this expense.';
        if (xhr.status === 401) return 'Your session has ended. Please log in again.';
        if (xhr.status === 0)   return 'Network error — please check your connection and try again.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) return j.message; } catch (e) {}
    }
    return 'Could not save the expense (server error). Please try again.';
}

function submitExpenseForm($form) {


    const $btn = $('#btnSaveExpense');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing...');

    $.post('/api/account/add_expense.php', $form.serialize(), function(res) {
        if (res.success) {
            $('#addExpenseModal').modal('hide');
            Swal.fire({
                icon: 'success',
                title: 'Expense Recorded!',
                text: res.message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                loadProjectDetails();
            });
        } else {
            Swal.fire('Error', res.message || 'Failed to add expense', 'error');
        }
    }, 'json').fail(function(xhr) {
        Swal.fire('Error', expenseErrorMsg(xhr), 'error');
    }).always(() => {
        $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i> Record Expense');
    });
}

