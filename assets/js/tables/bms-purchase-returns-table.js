/**
 * bms-purchase-returns-table.js
 * The Purchase Returns list table — ONE implementation, two hosts:
 *
 *   app/bms/purchase/purchase_returns.php  → full list, filter bar, add/edit modals
 *   app/bms/Suppliers/supplier_details.php → Purchase Returns tab, one supplier,
 *                                            Supplier column hidden
 *
 * Unlike the GRN table, the action dropdown for a return is rendered server-side
 * by api/get_purchase_returns.php (it already gates each workflow step by
 * permission there). That HTML calls back in here through act(), which resolves
 * which table the clicked row belongs to — so the same markup drives any number
 * of instances on a page.
 *
 * Rendered from includes/tables/purchase_returns_table.php.
 */
(function (window, $) {
    'use strict';

    var M = { _i: {} };

    function inst(id) { return M._i[id]; }

    var STATUS_BADGE = {
        pending: 'warning', approved: 'primary', completed: 'success',
        rejected: 'danger', cancelled: 'secondary'
    };

    function columns(cfg) {
        var all = [
            { key: 'sno', col: {
                data: null, orderable: false, searchable: false, width: '50px',
                className: 'text-muted small fw-bold',
                render: function (d, t, r, meta) { return meta.row + meta.settings._iDisplayStart + 1; }
            }},
            { key: 'return_number',  col: { data: 'return_number' } },
            { key: 'return_date',    col: { data: 'return_date' } },
            { key: 'supplier',       col: { data: 'supplier_name' } },
            { key: 'receipt_number', col: { data: 'receipt_number' } },
            { key: 'total_items',    col: { data: 'total_items' } },
            { key: 'total_amount',   col: { data: 'total_amount' } },
            { key: 'reason',         col: { data: 'reason' } },
            { key: 'status',         col: { data: 'status' } },
            { key: 'actions',        col: { data: 'actions', orderable: false, searchable: false } }
        ];
        return all.filter(function (c) { return cfg.hide.indexOf(c.key) === -1; });
    }

    function renderCards(cfg, data) {
        if (!cfg.cardContainer) return;
        var grid = document.querySelector(cfg.cardContainer);
        if (!grid) return;

        grid.innerHTML = '';
        if (!data || data.length === 0) {
            grid.innerHTML = '<div class="col-12 text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i> No records found</div>';
            return;
        }

        var showSupplier = cfg.hide.indexOf('supplier') === -1;

        data.each(function (row) {
            var badge = STATUS_BADGE[row.status_key] || 'secondary';
            var rid = row.id;
            grid.innerHTML +=
                '<div class="col-xl-3 col-lg-4 col-md-6">' +
                  '<div class="card h-100 border-0 shadow-sm rounded-3">' +
                    '<div class="card-header bg-white d-flex justify-content-between align-items-center py-2 px-3">' +
                      '<code class="small">' + (row.return_number || '') + '</code>' +
                      '<span class="badge bg-' + badge + '" style="font-size:0.65rem;">' + String(row.status_key || '').toUpperCase() + '</span>' +
                    '</div>' +
                    '<div class="card-body py-2 px-3">' +
                      (showSupplier ? '<div class="small text-muted mb-1">Supplier: <strong class="text-dark">' + (row.supplier_name || '') + '</strong></div>' : '') +
                      '<div class="small text-muted mb-1">Date: <span class="text-dark">' + (row.return_date || '') + '</span></div>' +
                      '<div class="small text-muted mb-1">GRN: <span class="text-dark">' + (row.receipt_number || 'N/A') + '</span></div>' +
                      '<div class="small text-muted mb-1">Items: <span class="text-dark">' + (row.total_items || 0) + '</span></div>' +
                      '<div class="small text-muted">Value: <strong class="text-dark">' + (row.total_amount || '') + '</strong></div>' +
                    '</div>' +
                    '<div class="card-footer bg-white" style="padding:6px 8px;">' +
                      '<div style="display:flex;flex-wrap:nowrap;gap:4px;">' +
                        '<button onclick="BMSReturnsTable.run(\'' + cfg.tableId + '\',\'view\',' + rid + ')" class="btn btn-outline-primary" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="View"><i class="bi bi-eye"></i></button>' +
                        '<button onclick="BMSReturnsTable.run(\'' + cfg.tableId + '\',\'edit\',' + rid + ')" class="btn btn-outline-secondary" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="Edit"><i class="bi bi-pencil"></i></button>' +
                        '<button onclick="BMSReturnsTable.run(\'' + cfg.tableId + '\',\'delete\',' + rid + ')" class="btn btn-outline-danger" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="Delete"><i class="bi bi-trash"></i></button>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                '</div>';
        });
    }

    /* ── Public API ──────────────────────────────────────────────────── */

    M.init = function (cfg) {
        cfg.hide = cfg.hide || [];
        cfg.fixed = cfg.fixed || {};

        var dt = $('#' + cfg.tableId).DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: cfg.apiUrl,
                type: 'GET',
                data: function (d) {
                    var extra = (typeof cfg.filters === 'function') ? cfg.filters() : {};
                    return $.extend(d, extra, cfg.fixed);   // fixed wins — the tab can't be un-filtered
                }
            },
            columns: columns(cfg).map(function (c) { return c.col; }),
            order: cfg.order || [[1, 'desc']],
            pageLength: cfg.pageLength || 25,
            lengthChange: false,
            dom: cfg.dom || 'rtip',
            language: { search: '_INPUT_', searchPlaceholder: 'Search returns...' },
            drawCallback: function () {
                var api = this.api();
                var count = api.page.info().recordsDisplay;
                if (typeof cfg.onCount === 'function') cfg.onCount(count);
                renderCards(cfg, api.rows({ page: 'current' }).data());
            }
        });

        M._i[cfg.tableId] = { dt: dt, cfg: cfg };
        return dt;
    };

    M.dt      = function (id) { var i = inst(id); return i && i.dt; };
    M.reload  = function (id) { var i = inst(id); if (i) { i.dt.ajax.reload(null, false); if (typeof i.cfg.onReload === 'function') i.cfg.onReload(); } };
    M.adjust  = function (id) { var i = inst(id); if (i) window.BMSTbl.adjust(i.dt); };

    /**
     * Entry point for the server-rendered action dropdown. `el` is the clicked
     * <a>; the owning table is whichever one contains it, so one block of HTML
     * works for every instance on the page.
     */
    M.act = function (el, action, id, arg) {
        var tableId = $(el).closest('table').attr('id');
        M.run(tableId, action, id, arg);
        return false;
    };

    M.run = function (tableId, action, id, arg) {
        var i = inst(tableId);
        if (!i) return false;
        var u = i.cfg.urls;
        var done = function () { M.reload(tableId); };

        switch (action) {
            case 'view':
                if (window.logReportAction) logReportAction('Viewed Purchase Return Details Link', 'User clicked to view details for purchase return #' + id);
                window.location.href = u.view + '?id=' + id;
                break;

            case 'edit':
                // On the module page the edit modal exists, so edit in place.
                // In a tab there is no modal — hand off to the module page,
                // which opens the same modal from ?edit=<id>.
                if (typeof window.editReturn === 'function' && $('#editReturnModal').length) {
                    window.editReturn(id);
                } else {
                    window.location.href = u.list + '?edit=' + id;
                }
                break;

            case 'review':
                window.BMSTbl.confirmPost({
                    title: 'Send for Review?',
                    text: 'This will mark the return as reviewed and capture your e-signature.',
                    color: '#ffc107', confirmText: 'Yes, send for review', successTitle: 'Reviewed',
                    url: u.review, data: { return_id: id },
                    onDone: function () {
                        if (window.logReportAction) logReportAction('Reviewed Purchase Return', 'User reviewed purchase return #' + id);
                        done();
                    }
                });
                break;

            case 'approve':
                window.BMSTbl.confirmPost({
                    title: 'Approve Purchase Return?',
                    text: 'This will deduct stock from the warehouse and capture your e-signature.',
                    icon: 'warning', color: '#198754', confirmText: 'Yes, approve', successTitle: 'Approved',
                    url: u.approve, data: { return_id: id },
                    onDone: function () {
                        if (window.logReportAction) logReportAction('Approved Purchase Return', 'User approved purchase return #' + id);
                        done();
                    }
                });
                break;

            case 'status':
                window.BMSTbl.confirmPost({
                    title: 'Confirm Update',
                    text: 'Are you sure you want to mark this return as ' + arg + '?',
                    icon: 'warning', confirmText: 'Yes, update it!', successTitle: 'Updated!',
                    url: u.status, data: { return_id: id, status: arg },
                    onDone: function () {
                        if (window.logReportAction) logReportAction('Updated Purchase Return Status', 'User updated purchase return #' + id + ' status to ' + arg);
                        done();
                    }
                });
                break;

            case 'delete':
                window.BMSTbl.confirmPost({
                    title: 'Are you sure?',
                    text: "You won't be able to revert this!",
                    icon: 'warning', color: '#d33', confirmText: 'Yes, delete it!', successTitle: 'Deleted!',
                    url: u.del, data: { return_id: id },
                    onDone: function () {
                        if (window.logReportAction) logReportAction('Deleted Purchase Return', 'User deleted purchase return #' + id);
                        done();
                    }
                });
                break;
        }
        return false;
    };

    window.BMSReturnsTable = M;

})(window, jQuery);
