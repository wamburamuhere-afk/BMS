/**
 * bms-grn-table.js
 * The Goods Received Notes list table — ONE implementation, two hosts:
 *
 *   app/bms/grn/grn.php                  → full list, all columns, filter bar
 *   app/bms/Suppliers/supplier_details.php → GRN tab, forced to one supplier,
 *                                            Supplier column hidden (redundant there)
 *
 * Rendering, action dropdown, workflow transitions, print and delete are the same
 * code in both places; only the config differs. Rendered from
 * includes/tables/grn_table.php — do not duplicate this logic in a page.
 */
(function (window, $) {
    'use strict';

    var M = { _i: {} };

    /** grn.php's original status→badge mapping, unchanged. */
    function badge(status) {
        switch (status) {
            case 'active':
            case 'approved':
            case 'completed':
            case 'success':  return 'success';
            case 'pending':
            case 'waiting':  return 'warning';
            case 'draft':    return 'secondary';
            case 'cancelled':
            case 'deleted':
            case 'void':     return 'danger';
            default:         return 'secondary';
        }
    }

    function inst(id) { return M._i[id]; }

    /* ── Column catalogue ────────────────────────────────────────────────
       Each entry is { key, th, col }. A host hides columns by listing their
       key in cfg.hide, which drops the <th> (in the PHP partial) and the
       column object here in lockstep, so headers never drift from data.    */
    function columns(cfg) {
        var esc = window.BMSTbl.esc, date = window.BMSTbl.date, money = window.BMSTbl.money;
        var all = [
            { key: 'sno', col: {
                data: null, orderable: false, searchable: false, width: '50px',
                className: 'ps-4 text-center text-muted small fw-bold',
                render: function (d, t, r, meta) { return meta.row + meta.settings._iDisplayStart + 1; }
            }},
            { key: 'receipt_number', col: {
                data: 'receipt_number',
                render: function (data, type, row) {
                    var html = '<code class="small text-wrap-cell">' + esc(data) + '</code>';
                    if (row.notes) {
                        html += '<small class="text-muted d-block text-wrap-cell" title="' + esc(row.notes) + '">' + esc(row.notes) + '</small>';
                    }
                    return html;
                }
            }},
            { key: 'receipt_date', col: {
                data: 'receipt_date',
                render: function (data) { return '<span class="text-wrap-cell">' + date(data) + '</span>'; }
            }},
            { key: 'supplier', col: {
                data: 'supplier_name',
                render: function (data, type, row) {
                    var html = '<div class="text-wrap-cell fw-bold" title="' + esc(data) + '">' + esc(data) + '</div>';
                    if (row.company_name) {
                        html += '<div class="text-wrap-cell text-muted small" title="' + esc(row.company_name) + '">' + esc(row.company_name) + '</div>';
                    }
                    return html;
                }
            }},
            { key: 'order_number', col: {
                data: 'order_number', width: '100px',
                render: function (data, type, row) {
                    if (data) {
                        return '<a href="' + cfg.urls.po + '?id=' + row.purchase_order_id + '" class="text-decoration-none small">' + esc(data) + '</a>';
                    }
                    return '<span class="text-muted small">N/A</span>';
                }
            }},
            { key: 'project', col: {
                data: 'project_name',
                render: function (data) {
                    return data
                        ? '<div class="text-wrap-cell"><span class="badge bg-info-soft text-info border border-info small p-1 text-wrap w-100 grn-project-badge" style="white-space: normal; word-break: break-word;">' + esc(data) + '</span></div>'
                        : '<span class="text-muted small">N/A</span>';
                }
            }},
            { key: 'warehouse', col: {
                data: 'warehouse_name',
                render: function (data) { return '<div class="text-truncate small" style="max-width: 100px;" title="' + esc(data) + '">' + esc(data) + '</div>'; }
            }},
            { key: 'total_items', col: {
                data: 'total_items', width: '80px',
                render: function (data) { return '<span class="badge bg-secondary p-1">' + data + '</span> <small>items</small>'; }
            }},
            { key: 'total_value', col: {
                data: 'total_value', width: '110px',
                render: function (data) { return '<strong class="small">' + money(data) + '</strong>'; }
            }},
            { key: 'received_by', col: {
                data: 'received_by_name', width: '100px',
                render: function (data) { return '<div class="text-truncate small" style="max-width: 80px;" title="' + esc(data) + '">' + esc(data) + '</div>'; }
            }},
            { key: 'status', col: {
                data: 'status', className: 'text-center', width: '90px',
                render: function (data) {
                    return '<span class="badge bg-' + badge(data) + ' small grn-status-badge" style="font-size: 0.7rem; padding: 4px 8px;">' + String(data).toUpperCase() + '</span>';
                }
            }},
            { key: 'actions', col: {
                data: null, orderable: false, className: 'd-print-none',
                render: function (d, t, row) { return rowActions(cfg, row); }
            }}
        ];

        return all.filter(function (c) { return cfg.hide.indexOf(c.key) === -1; });
    }

    /** The gear dropdown — same items, same permission gates, in both hosts. */
    function rowActions(cfg, row) {
        var T = window.BMSTbl, id = cfg.tableId, rid = row.receipt_id;
        var p = cfg.perms;

        var isPending  = (row.status === 'pending');
        var isReviewed = (row.status === 'reviewed');
        var inWorkflow = (isPending || isReviewed);
        var canEditNow = (inWorkflow || p.isAdmin);

        var items = [];

        items.push(T.item('View GRN', 'bi-eye text-info', cfg.urls.view + '?id=' + rid, '', true));

        if (canEditNow) {
            items.push(T.item('Edit GRN', 'bi-pencil text-primary', cfg.urls.edit + '?id=' + rid, 'text-primary', true));
        }

        // Parallel Review + Approve — one active, the other shown disabled so the
        // workflow stays visible to users who can only do one half of it.
        if (inWorkflow && p.canReview) {
            items.push(isPending
                ? T.item('Mark Reviewed', 'bi-check2', 'BMSGrnTable.review(\'' + id + '\',' + rid + ')', 'text-primary fw-bold')
                : '<li><a class="dropdown-item py-2 rounded text-muted disabled" href="#" tabindex="-1" aria-disabled="true" title="Already reviewed"><i class="bi bi-check2 me-2"></i>Mark Reviewed</a></li>');
        }
        if (inWorkflow && p.canApprove) {
            items.push(isReviewed
                ? T.item('Approve GRN', 'bi-check-circle', 'BMSGrnTable.approve(\'' + id + '\',' + rid + ')', 'text-success fw-bold')
                : '<li><a class="dropdown-item py-2 rounded text-muted disabled" href="#" tabindex="-1" aria-disabled="true" title="Must be reviewed before approval"><i class="bi bi-check-circle me-2"></i>Approve GRN</a></li>');
        }

        if (canEditNow) {
            items.push(T.item('Cancel GRN', 'bi-x-octagon', 'BMSGrnTable.setStatus(\'' + id + '\',' + rid + ',\'cancelled\')', 'text-warning'));
        }

        items.push(T.item('Print GRN', 'bi-printer text-dark', 'BMSGrnTable.print(\'' + id + '\',' + rid + ')'));

        if (p.canDelete && (isPending || p.isAdmin)) {
            items.push(T.divider());
            items.push(T.item('Delete GRN', 'bi-trash', 'BMSGrnTable.remove(\'' + id + '\',' + rid + ')', 'text-danger'));
        }

        return T.actions(items);
    }

    /** Mobile card view — only rendered when the host supplies a container. */
    function renderCards(cfg, data) {
        if (!cfg.cardContainer) return;
        var $c = $(cfg.cardContainer);
        if (!$c.length) return;
        var esc = window.BMSTbl.esc, date = window.BMSTbl.date, money = window.BMSTbl.money;

        $c.empty();
        if (!data || data.length === 0) {
            $c.html('<div class="col-12 text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i> No records found</div>');
            return;
        }

        data.each(function (row) {
            var projectBlock = (cfg.hide.indexOf('project') === -1)
                ? '<div class="col-12 mt-2"><small class="text-muted d-block small">Project</small>' +
                  '<span class="badge bg-info-soft text-info border border-info small p-1 text-wrap d-inline-block" style="max-width: 100%;">' + esc(row.project_name || 'N/A') + '</span></div>'
                : '';
            var supplierBlock = (cfg.hide.indexOf('supplier') === -1)
                ? '<h6 class="fw-bold mb-0">' + esc(row.supplier_name) + '</h6>' +
                  '<small class="text-muted">' + esc(row.company_name || '') + '</small>'
                : '';

            $c.append(
                '<div class="col-md-6 col-lg-4">' +
                  '<div class="card h-100 shadow-sm border-0 hover-shadow transition-all" style="border-radius: 12px; border: 1px solid #eef2f6 !important;">' +
                    '<div class="card-body">' +
                      '<div class="d-flex justify-content-between align-items-start mb-3"><div>' +
                        '<code class="small d-block mb-1">' + esc(row.receipt_number) + '</code>' + supplierBlock +
                      '</div><span class="badge bg-' + badge(row.status) + ' small" style="font-size: 0.65rem;">' + String(row.status).toUpperCase() + '</span></div>' +
                      '<div class="row g-2 mb-3">' +
                        '<div class="col-6"><small class="text-muted d-block small">Date</small><span class="small fw-medium text-dark">' + date(row.receipt_date) + '</span></div>' +
                        '<div class="col-6"><small class="text-muted d-block small">Total Value</small><span class="small fw-bold text-dark">' + money(row.total_value) + '</span></div>' +
                        '<div class="col-6"><small class="text-muted d-block small">Warehouse</small><span class="small text-dark text-truncate d-block" title="' + esc(row.warehouse_name) + '">' + esc(row.warehouse_name) + '</span></div>' +
                        '<div class="col-6"><small class="text-muted d-block small">Items</small><span class="small text-dark">' + row.total_items + ' items</span></div>' +
                        projectBlock +
                      '</div>' +
                      '<div class="border-top mt-2 pt-1">' +
                        '<div class="small text-muted mb-1"><i class="bi bi-person me-1"></i> ' + esc(row.received_by_name) + '</div>' +
                        '<div style="display:flex; flex-wrap:nowrap; gap:4px;">' +
                          '<a href="' + cfg.urls.view + '?id=' + row.receipt_id + '" class="btn btn-outline-primary" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="View"><i class="bi bi-eye"></i></a>' +
                          '<a href="' + cfg.urls.edit + '?id=' + row.receipt_id + '" class="btn btn-outline-secondary" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" title="Edit"><i class="bi bi-pencil"></i></a>' +
                          '<button type="button" class="btn btn-outline-danger" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" onclick="BMSGrnTable.remove(\'' + cfg.tableId + '\',' + row.receipt_id + ')" title="Delete"><i class="bi bi-trash"></i></button>' +
                        '</div>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                '</div>'
            );
        });
    }

    /* ── Public API ──────────────────────────────────────────────────── */

    M.init = function (cfg) {
        cfg.hide = cfg.hide || [];
        cfg.fixed = cfg.fixed || {};

        var dt = $('#' + cfg.tableId).DataTable({
            processing: true,
            serverSide: true,
            responsive: false,
            autoWidth: false,
            pageLength: cfg.pageLength || 10,
            order: cfg.order || [[2, 'desc']],
            columnDefs: [{ className: 'text-center', targets: '_all' }],
            drawCallback: function () {
                renderCards(cfg, this.api().rows({ page: 'current' }).data());
            },
            ajax: {
                url: cfg.apiUrl,
                data: function (d) {
                    var extra = (typeof cfg.filters === 'function') ? cfg.filters() : {};
                    return $.extend({}, d, extra, cfg.fixed);   // fixed wins — the tab can't be un-filtered
                },
                dataSrc: function (json) {
                    if (json.success) {
                        if (json.stats && typeof cfg.onStats === 'function') cfg.onStats(json.stats);
                        if (typeof cfg.onCount === 'function') cfg.onCount(json.recordsFiltered);
                        return json.data;
                    }
                    console.error('GRN API error:', json.message);
                    window.Swal.fire({ icon: 'error', title: 'Data Load Error', text: 'API Error: ' + (json.message || 'Unknown error') });
                    return [];
                },
                error: function (xhr) {
                    console.error('GRN DataTables AJAX error:', xhr.responseText);
                    window.Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Failed to load data. Status: ' + xhr.status + ' ' + xhr.statusText });
                }
            },
            columns: columns(cfg).map(function (c) { return c.col; }),
            language: {
                processing: '<div class="spinner-border text-primary" role="status"><span></span></div>',
                emptyTable: '<div class="text-center my-3"><i class="bi bi-clipboard-check display-4 text-muted"></i><p class="mt-2">No Goods Received Notes Found</p></div>',
                lengthMenu: 'Show _MENU_ entries'
            },
            lengthChange: false,
            dom: cfg.dom || 'rtip'
        });

        M._i[cfg.tableId] = { dt: dt, cfg: cfg };
        return dt;
    };

    M.dt     = function (id) { var i = inst(id); return i && i.dt; };
    M.reload = function (id) { var i = inst(id); if (i) i.dt.ajax.reload(null, false); };
    M.adjust = function (id) { var i = inst(id); if (i) window.BMSTbl.adjust(i.dt); };

    M.review = function (id, rid) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: 'Mark as Reviewed?',
            text: 'GRN will move to Reviewed and become approvable.',
            color: '#0d6efd', confirmText: 'Yes, mark reviewed', successTitle: 'Reviewed!',
            url: i.cfg.urls.review, data: { receipt_id: rid },
            onDone: function () { M.reload(id); }
        });
    };

    M.approve = function (id, rid) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: 'Approve GRN?',
            text: 'Stock will be updated on approval.',
            color: '#198754', confirmText: 'Yes, approve', successTitle: 'Approved!',
            url: i.cfg.urls.approve, data: { receipt_id: rid },
            onDone: function () { M.reload(id); }
        });
    };

    M.setStatus = function (id, rid, status) {
        var i = inst(id); if (!i) return;
        var verb = ({ completed: 'complete', cancelled: 'cancel' })[status] || 'update';
        window.BMSTbl.confirmPost({
            title: 'Are you sure?',
            text: 'Do you want to ' + verb + ' this GRN?',
            icon: status === 'completed' ? 'success' : 'warning',
            confirmText: 'Yes, ' + status.charAt(0).toUpperCase() + status.slice(1),
            successTitle: 'Success!',
            url: i.cfg.urls.updateStatus, data: { receipt_id: rid, status: status },
            onDone: function () {
                if (window.logReportAction) logReportAction('Updated GRN Status', 'User updated GRN #' + rid + ' status to ' + status);
                M.reload(id);
            }
        });
    };

    M.remove = function (id, rid) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: 'Delete GRN',
            text: 'Are you sure you want to delete this GRN? This action cannot be undone.',
            icon: 'warning', color: '#dc3545', confirmText: 'Yes, Delete', successTitle: 'Deleted!',
            url: i.cfg.urls.del, data: { receipt_id: rid },
            onDone: function () {
                if (window.logReportAction) logReportAction('Deleted GRN', 'User deleted GRN ID #' + rid);
                M.reload(id);
            }
        });
    };

    M.print = function (id, rid) {
        var i = inst(id); if (!i) return;
        if (window.logReportAction) logReportAction('Printed GRN', 'User generated a printed GRN for ID #' + rid);
        window.location.href = i.cfg.urls.print + '?id=' + rid;
    };

    window.BMSGrnTable = M;

})(window, jQuery);
