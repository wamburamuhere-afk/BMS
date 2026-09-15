/**
 * bms-expenses-table.js
 * The Expenses list table — ONE implementation, two hosts:
 *
 *   app/constant/accounts/expenses.php     → full list, category tree, filters,
 *                                            stat cards, add/edit modals, exports
 *   app/bms/Suppliers/supplier_details.php → Expenses tab, filtered to what was
 *                                            paid to that supplier
 *
 * Fed by api/account/get_expenses.php (which now accepts paid_to_type +
 * paid_to_id). Rendered from includes/tables/expenses_table.php.
 */
(function (window, $) {
    'use strict';

    var M = { _i: {} };

    function inst(id) { return M._i[id]; }

    function esc(t) { return $('<div>').text(t == null ? '' : t).html(); }
    function money(v) { return parseFloat(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }); }

    function statusClass(s) {
        return s === 'approved' ? 'success'
             : s === 'reviewed' ? 'primary'
             : s === 'pending'  ? 'warning'
             : s === 'rejected' ? 'danger'
             : s === 'paid'     ? 'info' : 'secondary';
    }

    function fmtDate(d) {
        if (!d) return '-';
        return new Date(d.indexOf('T') !== -1 ? d : d + 'T00:00:00')
            .toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function categoryCell(row) {
        if (row.categories && Array.isArray(row.categories) && row.categories.length > 0) {
            return row.categories.map(function (cat) {
                var path = cat.category_path || cat.category_name || cat.name;
                var leaf = path.indexOf(' › ') !== -1 ? path.split(' › ').pop() : path;
                return '<span class="small text-dark">' + esc(leaf) + '</span>';
            }).join('<br>');
        }
        if (row.category_name) return '<span class="small text-dark">' + esc(row.category_name) + '</span>';
        return '<span class="text-muted small">—</span>';
    }

    function columns(cfg) {
        var all = [
            { key: 'sno', col: {
                data: null, orderable: false, searchable: false, width: '50px',
                className: 'text-center text-muted small fw-bold',
                render: function (d, t, r, meta) { return meta.row + meta.settings._iDisplayStart + 1; }
            }},
            { key: 'expense_date', col: {
                data: 'expense_date', width: '110px',
                render: function (d) { return fmtDate(d); }
            }},
            { key: 'description', col: {
                data: 'description', width: '20%',
                render: function (d, t, row) {
                    return '<div><strong>' + esc(d) + '</strong>' +
                        (row.notes ? '<br><small class="text-muted text-truncate d-inline-block" style="max-width:200px">' + esc(row.notes) + '</small>' : '') +
                        '</div>';
                }
            }},
            { key: 'categories', col: {
                data: 'categories', width: '15%', orderable: false,
                render: function (d, t, row) { return categoryCell(row); }
            }},
            { key: 'project', col: {
                data: 'project_name', width: '12%',
                render: function (d) { return d ? '<span class="small text-dark">' + esc(d) + '</span>' : '<span class="text-muted small">—</span>'; }
            }},
            { key: 'amount', col: {
                data: 'amount', width: '110px',
                render: function (d, t, row) {
                    var html = '<strong class="text-danger">' + money(d) + '</strong>';
                    if (row.daily_category_total && parseFloat(row.daily_category_total) !== parseFloat(d)) {
                        html += '<br><small class="text-muted" style="font-size:0.65rem" title="Daily total for this category">' + cfg.i18n.dayPrefix + ' ' + money(row.daily_category_total) + '</small>';
                    }
                    return html;
                }
            }},
            { key: 'paid_to', col: {
                data: 'paid_to_name', width: '12%',
                render: function (d, t, row) {
                    var name = esc(d || row.vendor || cfg.i18n.na);
                    if (row.paid_to_type === 'supplier') {
                        return '<div><span class="badge bg-primary-soft text-primary border border-primary small mb-1">' + cfg.i18n.supplier + '</span><br><strong>' + name + '</strong></div>';
                    }
                    if (row.paid_to_type === 'staff') {
                        return '<div><span class="badge bg-info-soft text-info border border-info small mb-1">' + cfg.i18n.staff + '</span><br><strong>' + name + '</strong></div>';
                    }
                    if (row.paid_to_type === 'other') {
                        var roleLabel = esc(row.payee_manual_role || cfg.i18n.na);
                        return '<div><span class="badge bg-secondary-soft text-secondary border border-secondary small mb-1">' + roleLabel + '</span><br><strong>' + name + '</strong></div>';
                    }
                    return '<strong>' + name + '</strong>';
                }
            }},
            { key: 'status', col: {
                data: 'status', width: '80px',
                render: function (d) {
                    if (!d) return '<span class="badge bg-secondary">' + cfg.i18n.unknown + '</span>';
                    return '<span class="badge bg-' + statusClass(d) + '">' + (cfg.i18n.statusLabels[d] || (d.charAt(0).toUpperCase() + d.slice(1))) + '</span>';
                }
            }},
            { key: 'actions', col: {
                data: null, width: '50px', orderable: false, className: 'text-end',
                render: function (d, t, row) { return rowActions(cfg, row); }
            }}
        ];
        return all.filter(function (c) { return cfg.hide.indexOf(c.key) === -1; });
    }

    /** Workflow items shared by the table dropdown and the mobile card dropdown. */
    function workflowItems(cfg, row) {
        var id = cfg.tableId, eid = row.expense_id, p = cfg.perms, html = '';

        if (p.canEdit && (row.status === 'pending' || row.status === 'reviewed')) {
            html += '<li><hr class="dropdown-divider opacity-50"></li>' +
                    '<li><a class="dropdown-item" href="#" onclick="BMSExpensesTable.edit(\'' + id + '\',' + eid + ');return false;"><i class="bi bi-pencil text-primary"></i> ' + cfg.i18n.editExpense + '</a></li>';
        }
        if (p.canEdit) {
            if (row.status === 'pending') {
                html += '<li><a class="dropdown-item" href="#" onclick="BMSExpensesTable.setStatus(\'' + id + '\',' + eid + ',\'reviewed\');return false;"><i class="bi bi-search text-info"></i> ' + cfg.i18n.markAsReviewed + '</a></li>';
            } else if (row.status === 'reviewed') {
                html += '<li><a class="dropdown-item" href="#" onclick="BMSExpensesTable.setStatus(\'' + id + '\',' + eid + ',\'approved\');return false;"><i class="bi bi-check-circle text-success"></i> ' + cfg.i18n.approve + '</a></li>';
                html += '<li><a class="dropdown-item" href="#" onclick="BMSExpensesTable.setStatus(\'' + id + '\',' + eid + ',\'rejected\');return false;"><i class="bi bi-x-circle text-danger"></i> ' + cfg.i18n.reject + '</a></li>';
            } else if (row.status === 'approved') {
                html += '<li><a class="dropdown-item" href="#" onclick="BMSExpensesTable.setStatus(\'' + id + '\',' + eid + ',\'paid\');return false;"><i class="bi bi-cash text-success"></i> ' + cfg.i18n.markAsPaid + '</a></li>';
            }
        }
        if (p.canDelete) {
            html += '<li><hr class="dropdown-divider opacity-50"></li>' +
                    '<li><a class="dropdown-item text-danger" href="#" onclick="BMSExpensesTable.remove(\'' + id + '\',' + eid + ');return false;"><i class="bi bi-trash"></i> ' + cfg.i18n.delete + '</a></li>';
        }
        return html;
    }

    function rowActions(cfg, row) {
        var id = cfg.tableId, eid = row.expense_id;
        return '<div class="dropdown action-dropdown">' +
            '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear"></i></button>' +
            '<ul class="dropdown-menu dropdown-menu-end shadow-sm">' +
                '<li><a class="dropdown-item" href="' + cfg.urls.view + '?id=' + eid + '"><i class="bi bi-eye text-info"></i> ' + cfg.i18n.viewDetails + '</a></li>' +
                '<li><a class="dropdown-item" href="#" onclick="BMSExpensesTable.printVoucher(\'' + id + '\',' + eid + ');return false;"><i class="bi bi-printer text-secondary"></i> ' + cfg.i18n.printVoucher + '</a></li>' +
                workflowItems(cfg, row) +
            '</ul></div>';
    }

    function renderCards(cfg, api) {
        if (!cfg.cardContainer) return;
        var $c = $(cfg.cardContainer);
        if (!$c.length) return;

        if ($(window).width() > 768) {
            $c.hide();
            $('#' + cfg.tableId + '_wrapper').show();
            return;
        }
        $('#' + cfg.tableId + '_wrapper').hide();
        var container = $c.empty().show();
        var showPaidTo = cfg.hide.indexOf('paid_to') === -1;

        api.rows({ page: 'current' }).every(function () {
            var d = this.data();
            var actions = '<div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end shadow-sm">' +
                '<li><a class="dropdown-item" href="' + cfg.urls.view + '?id=' + d.expense_id + '"><i class="bi bi-eye text-info"></i> ' + cfg.i18n.viewDetails + '</a></li>' +
                workflowItems(cfg, d) + '</ul></div>';

            var statusKey = String(d.status || '');
            container.append(
                '<div class="expense-mobile-card mb-2">' +
                  '<div class="d-flex justify-content-between align-items-start mb-1"><div>' +
                    '<strong class="d-block" style="font-size:0.85rem">' + esc(d.description || '-') + '</strong>' +
                    '<small class="text-muted">' + fmtDate(d.expense_date) + '</small>' +
                  '</div><div class="d-flex align-items-center gap-2">' +
                    '<span class="badge bg-' + statusClass(d.status) + '">' + (cfg.i18n.statusLabels[statusKey] || (statusKey.charAt(0).toUpperCase() + statusKey.slice(1))) + '</span>' +
                    actions +
                  '</div></div>' +
                  '<div class="d-flex flex-wrap gap-1" style="font-size:0.78rem">' +
                    categoryCell(d) +
                    '<span class="text-danger fw-bold">' + money(d.amount) + '</span>' +
                    ((d.daily_category_total && parseFloat(d.daily_category_total) !== parseFloat(d.amount))
                        ? '<span class="text-muted ms-1" style="font-size:0.7rem">' + cfg.i18n.dayPrefix + ' ' + money(d.daily_category_total) + '</span>' : '') +
                    ((showPaidTo && d.paid_to_name) ? '<span class="text-muted"><i class="bi bi-person"></i> ' + esc(d.paid_to_name) + '</span>' : '') +
                  '</div>' +
                '</div>'
            );
        });
    }

    /* ── Payment voucher print (moved verbatim from expenses.php) ─────── */

function buildVoucher(cfg, id) {
    logReportAction('Print Voucher', 'User printed payment voucher for expense #' + id);

    $.get(cfg.urls.get, { id: id }, function(response) {
        if (!response.success) { Swal.fire(cfg.i18n.error, response.message || cfg.i18n.couldNotLoadExpense, 'error'); return; }
        const d = response.data;

        // ── Amount in words helper ──────────────────────────────────────
        function numToWords(n) {
            const a = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                       'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                       'Seventeen','Eighteen','Nineteen'];
            const b = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
            n = Math.floor(parseFloat(n) || 0);
            if (n === 0) return 'Zero';
            if (n < 20)  return a[n];
            if (n < 100) return b[Math.floor(n/10)] + (n%10 ? ' ' + a[n%10] : '');
            if (n < 1000) return a[Math.floor(n/100)] + ' Hundred' + (n%100 ? ' ' + numToWords(n%100) : '');
            if (n < 1000000) return numToWords(Math.floor(n/1000)) + ' Thousand' + (n%1000 ? ' ' + numToWords(n%1000) : '');
            return numToWords(Math.floor(n/1000000)) + ' Million' + (n%1000000 ? ' ' + numToWords(n%1000000) : '');
        }

        const amount   = parseFloat(d.amount) || 0;
        const amtWords = numToWords(amount) + ' Only';
        const fmtAmt   = amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const voucherNo = 'PV-' + String(d.expense_id).padStart(5, '0');
        const date = d.expense_date ? new Date(d.expense_date.includes('T') ? d.expense_date : d.expense_date + 'T00:00:00').toLocaleDateString('en-US', { day:'2-digit', month:'long', year:'numeric' }) : '-';
        const paidTo = d.paid_to_name || d.vendor || '-';
        const paidTypeLabel = d.paid_to_type === 'other' ? (d.payee_manual_role || d.paid_to_type) : d.paid_to_type;
        const printedBy = cfg.voucher.printedBy;
        const printedRole = cfg.voucher.printedRole;
        const now = new Date();
        const printDate = now.getDate().toString().padStart(2,'0') + ' ' +
            ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][now.getMonth()] +
            ' ' + now.getFullYear() + ' at ' +
            now.getHours().toString().padStart(2,'0') + ':' +
            now.getMinutes().toString().padStart(2,'0') + ':' +
            now.getSeconds().toString().padStart(2,'0');

        const logoHtml = cfg.voucher.logoHtml;
        const cName    = cfg.voucher.companyName;

        // ── Build Voucher HTML ──────────────────────────────────────────
        const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>${cfg.i18n.paymentVoucher} - ${voucherNo}</title>
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body { font-family: Arial, sans-serif; font-size: 10pt; color: #222; background:#fff; padding:15mm 15mm 20mm 15mm; }

            /* Header */
            .pv-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #0d6efd; padding-bottom:10px; margin-bottom:14px; }
            .pv-logo-area { display:flex; flex-direction:column; gap:4px; }
            .pv-company  { font-size:16pt; font-weight:800; color:#0d6efd; text-transform:uppercase; }
            .pv-title-area { text-align:right; }
            .pv-title    { font-size:14pt; font-weight:800; text-transform:uppercase; color:#333; letter-spacing:2px; }
            .pv-voucher-no { font-size:9pt; color:#666; margin-top:4px; }
            .pv-date     { font-size:9pt; color:#333; font-weight:600; margin-top:2px; }

            /* Amount box */
            .pv-amount-box { background:#f0f7ff; border:2px solid #0d6efd; border-radius:6px; padding:10px 16px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; }
            .pv-amount-label { font-size:8pt; text-transform:uppercase; color:#555; }
            .pv-amount-value { font-size:20pt; font-weight:900; color:#0d6efd; }
            .pv-amount-words { font-size:8.5pt; color:#333; font-style:italic; text-align:right; }

            /* Details table */
            .pv-table { width:100%; border-collapse:collapse; margin-bottom:14px; }
            .pv-table tr { border-bottom:1px solid #eee; }
            .pv-table td { padding:6px 8px; vertical-align:top; font-size:9.5pt; }
            .pv-table td:first-child { width:35%; font-weight:700; color:#555; text-transform:uppercase; font-size:8.5pt; }
            .pv-table td:last-child  { color:#222; }

            /* Status badge */
            .pv-status { display:inline-block; padding:2px 10px; border-radius:20px; font-size:8pt; font-weight:700; text-transform:uppercase; }
            .pv-status-pending  { background:#fff3cd; color:#856404; border:1px solid #ffc107; }
            .pv-status-approved { background:#d1e7dd; color:#0f5132; border:1px solid #198754; }
            .pv-status-paid     { background:#cfe2ff; color:#084298; border:1px solid #0d6efd; }
            .pv-status-rejected { background:#f8d7da; color:#842029; border:1px solid #dc3545; }

            /* Signature section */
            .pv-signatures { display:flex; justify-content:space-between; margin-top:24px; gap:20px; }
            .pv-sig-block  { flex:1; text-align:center; }
            .pv-sig-line   { border-top:1px solid #333; margin-bottom:4px; margin-top:30px; }
            .pv-sig-label  { font-size:8pt; text-transform:uppercase; color:#555; font-weight:700; }
            .pv-sig-name   { font-size:8pt; color:#333; margin-top:2px; }

            /* Footer */
            .pv-footer { position:fixed; bottom:0; left:0; right:0; padding:3mm 15mm; border-top:1px solid #ccc; background:#fff; text-align:center; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .pv-footer p { font-size:7pt; margin:0; line-height:1.4; }
            .pv-footer .pv-powered { color:#0d6efd; font-weight:700; }
            .pv-spacer { height:15mm; }

            /* Note box */
            .pv-note { background:#fffbee; border-left:3px solid #ffc107; padding:6px 10px; font-size:8.5pt; color:#555; margin-bottom:14px; border-radius:0 4px 4px 0; }

            @media print {
                body { padding:10mm 12mm 18mm 12mm; }
                .pv-footer { position:fixed; bottom:0; }
            }
        </style></head><body>

        <!-- HEADER -->
        <div class="pv-header">
            <div class="pv-logo-area">
                ${logoHtml}
                <span class="pv-company">${cName}</span>
            </div>
            <div class="pv-title-area">
                <div class="pv-title">${cfg.i18n.paymentVoucher}</div>
                <div class="pv-voucher-no">${cfg.i18n.voucherNo} <strong>${voucherNo}</strong></div>
                <div class="pv-date">${cfg.i18n.dateLabel} <strong>${date}</strong></div>
            </div>
        </div>

        <!-- AMOUNT BOX -->
        <div class="pv-amount-box">
            <div>
                <div class="pv-amount-label">${cfg.i18n.amountPaid}</div>
                <div class="pv-amount-value">${fmtAmt}</div>
            </div>
            <div class="pv-amount-words">
                <div style="font-size:7.5pt; color:#888; margin-bottom:2px;">${cfg.i18n.inWords}</div>
                <strong>${amtWords}</strong>
            </div>
        </div>

        <!-- DETAILS TABLE -->
        <table class="pv-table">
            <tr><td>${cfg.i18n.paidTo}</td><td><strong>${d.paid_to_name || d.vendor || '-'}</strong>${paidTypeLabel ? ' <span style="font-size:8pt;color:#888;">('+paidTypeLabel+')</span>' : ''}</td></tr>
            <tr><td>${cfg.i18n.description}</td><td>${d.description || '-'}</td></tr>
            <tr><td>${cfg.i18n.expenseAccount}</td><td>${d.expense_account_name ? ((d.expense_account_code ? d.expense_account_code + ' — ' : '') + d.expense_account_name) : '-'}</td></tr>
            <tr><td>${cfg.i18n.paidFromBank}</td><td>${d.bank_account_name ? ((d.bank_account_code ? d.bank_account_code + ' — ' : '') + d.bank_account_name) : '-'}</td></tr>
            <tr><td>${cfg.i18n.referenceNo}</td><td>${d.reference_number || '-'}</td></tr>
            ${d.notes ? `<tr><td>${cfg.i18n.notes}</td><td>${d.notes}</td></tr>` : ''}
            <tr><td>${cfg.i18n.status}</td><td><span class="pv-status pv-status-${d.status||'pending'}">${cfg.i18n.statusLabels[d.status||'pending'] || ((d.status||'pending').charAt(0).toUpperCase()+(d.status||'pending').slice(1))}</span></td></tr>
            <tr><td>${cfg.i18n.preparedBy}</td><td>${d.created_by_name || '-'}</td></tr>
        </table>

        <!-- NOTE -->
        <div class="pv-note">
            <strong>${cfg.i18n.voucherNoteLabel}</strong> ${cfg.i18n.voucherNoteText}
        </div>

        <!-- SIGNATURES -->
        <div class="pv-signatures">
            <div class="pv-sig-block">
                <div class="pv-sig-line"></div>
                <div class="pv-sig-label">${cfg.i18n.preparedBy}</div>
                <div class="pv-sig-name">${d.created_by_name || ''}</div>
            </div>
            <div class="pv-sig-block">
                <div class="pv-sig-line"></div>
                <div class="pv-sig-label">${cfg.i18n.approvedBy}</div>
                <div class="pv-sig-name">&nbsp;</div>
            </div>
            <div class="pv-sig-block">
                <div class="pv-sig-line"></div>
                <div class="pv-sig-label">${cfg.i18n.receivedBy}</div>
                <div class="pv-sig-name">${paidTo}</div>
            </div>
        </div>

        <!-- BUFFER -->
        <div class="pv-spacer"></div>

        <!-- FOOTER -->
        <div class="pv-footer">
            <p>This document was <strong>Printed</strong> by <strong>${printedBy} - ${printedRole}</strong> on ${printDate}</p>
            <p class="pv-powered">Powered by BJP Technologies &copy; ${now.getFullYear()}, All Rights Reserved.</p>
        </div>

        <script>window.onload = function() { window.print(); };<\/script>
        </body></html>`;

        const win = window.open('', '_blank', 'width=850,height=650');
        win.document.write(html);
        win.document.close();

    }, 'json');
}


    /* ── Public API ──────────────────────────────────────────────────── */

    M.init = function (cfg) {
        cfg.hide = cfg.hide || [];
        cfg.fixed = cfg.fixed || {};

        var opts = {
            responsive: false,
            serverSide: true,
            processing: true,
            ajax: {
                url: cfg.apiUrl,
                data: function (d) {
                    var extra = (typeof cfg.filters === 'function') ? cfg.filters() : {};
                    return $.extend(d, extra, cfg.fixed);
                },
                dataSrc: function (json) {
                    if (typeof cfg.onStats === 'function') cfg.onStats(json);
                    var recCount = (json.filteredCount != null) ? json.filteredCount : json.recordsTotal;
                    if (typeof cfg.onCount === 'function') cfg.onCount(recCount);
                    return json.data;
                }
            },
            columns: columns(cfg).map(function (c) { return c.col; }),
            dom: cfg.dom || 'rtip',
            pageLength: cfg.pageLength || 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            drawCallback: function () { renderCards(cfg, this.api()); }
        };
        if (cfg.buttons) opts.buttons = cfg.buttons;

        var dt = $('#' + cfg.tableId).DataTable(opts);

        M._i[cfg.tableId] = { dt: dt, cfg: cfg };
        return dt;
    };

    M.dt     = function (id) { var i = inst(id); return i && i.dt; };
    M.reload = function (id) { var i = inst(id); if (i) i.dt.ajax.reload(null, false); };
    M.adjust = function (id) { var i = inst(id); if (i) window.BMSTbl.adjust(i.dt); };

    M.printVoucher = function (id, eid) {
        var i = inst(id); if (!i) return;
        buildVoucher(i.cfg, eid);
    };

    /**
     * On the Expenses page the edit modal exists, so edit in place. In a tab
     * there is no modal — hand off to the Expenses page, which opens the same
     * modal from ?edit=<id>.
     */
    M.edit = function (id, eid) {
        var i = inst(id); if (!i) return;
        if (typeof window.editExpense === 'function' && $('#addExpenseModal').length) {
            window.editExpense(eid);
        } else {
            window.location.href = i.cfg.urls.list + '?edit=' + eid;
        }
    };

    M.setStatus = function (id, eid, status) {
        var i = inst(id); if (!i) return;
        var statusLabel = i.cfg.i18n.statusLabels[status] || status;
        window.BMSTbl.confirmPost({
            title: i.cfg.i18n.updateStatusTitle,
            text: i.cfg.i18n.markAsConfirm + ' ' + statusLabel + '?',
            confirmText: i.cfg.i18n.yesProceed, successTitle: i.cfg.i18n.updated,
            url: i.cfg.urls.status, data: { expense_id: eid, status: status },
            onDone: function () {
                if (window.logReportAction) logReportAction('Updated Expense Status', 'User updated status of expense record #' + eid + ' to ' + status);
                M.reload(id);
            }
        });
    };

    M.remove = function (id, eid) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: i.cfg.i18n.deleteExpenseTitle,
            text: i.cfg.i18n.deleteExpenseText,
            icon: 'warning', color: '#d33', confirmText: i.cfg.i18n.yesDelete, successTitle: i.cfg.i18n.deleted,
            url: i.cfg.urls.del, data: { expense_id: eid },
            onDone: function () {
                if (window.logReportAction) logReportAction('Deleted Expense Record', 'User deleted expense record #' + eid);
                M.reload(id);
            }
        });
    };

    window.BMSExpensesTable = M;

})(window, jQuery);
