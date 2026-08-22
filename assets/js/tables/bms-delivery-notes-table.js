/**
 * bms-delivery-notes-table.js
 * The Delivery Notes list table — ONE implementation, two hosts:
 *
 *   app/bms/grn/delivery_notes.php         → full list, inbound/outbound tabs,
 *                                            filter bar, stat cards
 *   app/bms/Suppliers/supplier_details.php → Delivery Notes tab, one supplier,
 *                                            inbound only, party column hidden
 *
 * Rendered from includes/tables/delivery_notes_table.php.
 */
(function (window, $) {
    'use strict';

    var M = { _i: {} };

    function inst(id) { return M._i[id]; }

    function statusClass(status) {
        switch (String(status).toLowerCase()) {
            case 'pending':              return 'warning';
            case 'reviewed':             return 'primary';
            case 'approved':             return 'info';
            case 'partially_delivered':  return 'warning';
            case 'dispatched':           return 'info';
            case 'delivered':            return 'success';
            case 'completed':            return 'success';
            case 'cancelled':            return 'danger';
            default:                     return 'secondary';
        }
    }

    function typeBadge(t) {
        return t === 'outbound'
            ? '<span class="badge bg-info-subtle text-info border border-info" style="font-size:0.62rem;"><i class="bi bi-box-arrow-up-right"></i> OUTBOUND</span>'
            : '<span class="badge bg-primary-subtle text-primary border border-primary" style="font-size:0.62rem;"><i class="bi bi-box-arrow-in-down"></i> INBOUND</span>';
    }

    /** Edit routes to a different form depending on the DN direction. */
    function editUrl(cfg, row) {
        var base = row.dn_type === 'outbound' ? cfg.urls.editOutbound : cfg.urls.editInbound;
        return base + '?edit=' + row.delivery_id;
    }

    function columns(cfg) {
        var esc = window.BMSTbl.esc, date = window.BMSTbl.date;
        var all = [
            { key: 'sno', col: {
                data: null, orderable: false, searchable: false, width: '50px',
                className: 'ps-3 text-muted small fw-bold text-center',
                render: function (d, t, r, meta) { return meta.row + meta.settings._iDisplayStart + 1; }
            }},
            { key: 'dn_number', col: {
                data: 'dn_number',
                render: function (data, type, row) {
                    var display = data || row.delivery_number || '';
                    var label = display ? '<strong>' + esc(display) + '</strong>' : '<span class="text-muted fst-italic">No DN #</span>';
                    var sub = (data && row.delivery_number)
                        ? '<small class="text-muted">Ref: ' + esc(row.delivery_number) + '</small>'
                        : '<small class="text-muted">ID: ' + row.delivery_id + '</small>';
                    return label + '<br>' + sub;
                }
            }},
            { key: 'dn_type', col: {
                data: 'dn_type', className: 'text-center d-print-none',
                render: function (data) { return typeBadge(data); }
            }},
            { key: 'delivery_date', col: {
                data: 'delivery_date',
                render: function (data) { return date(data); }
            }},
            { key: 'party', col: {
                data: 'supplier_name',
                render: function (data, type, row) {
                    var kindLabels = { subcontractor: 'Sub-Contractor', customer: 'Customer' };
                    var kind = '<small class="badge bg-light text-dark border">' + (kindLabels[row.party_type] || 'Supplier') + '</small>';
                    return '<span class="fw-bold">' + esc(data) + '</span> ' + kind +
                           (row.company_name ? '<br><small class="text-muted">' + esc(row.company_name) + '</small>' : '');
                }
            }},
            { key: 'project', col: {
                data: 'project_name',
                render: function (data) {
                    return data
                        ? '<span class="badge bg-info-soft text-info border border-info small p-1 text-wrap w-100 dn-project-badge" style="white-space: normal; word-break: break-word;">' + esc(data) + '</span>'
                        : '<span class="text-muted small">N/A</span>';
                }
            }},
            { key: 'total_items', col: {
                data: 'total_items',
                render: function (data) { return '<span class="badge bg-secondary p-1">' + data + '</span> <small>items</small>'; }
            }},
            { key: 'warehouse', col: { data: 'warehouse_name' } },
            { key: 'status', col: {
                data: 'status', className: 'text-center',
                render: function (data) {
                    return '<span class="badge bg-' + statusClass(data) + ' small" style="font-size: 0.7rem; padding: 4px 8px;">' + String(data).toUpperCase() + '</span>';
                }
            }},
            { key: 'actions', col: {
                data: null, orderable: false,
                render: function (d, t, row) { return rowActions(cfg, row); }
            }}
        ];
        return all.filter(function (c) { return cfg.hide.indexOf(c.key) === -1; });
    }

    function rowActions(cfg, row) {
        var T = window.BMSTbl, id = cfg.tableId, did = row.delivery_id, p = cfg.perms;

        var isPending  = (row.status === 'pending');
        var isReviewed = (row.status === 'reviewed');
        var inWorkflow = (isPending || isReviewed);
        var canEditNow = (inWorkflow || p.isAdmin);
        var canDeleteNow = (p.isAdmin || ['draft', 'pending', 'cancelled'].indexOf(row.status) !== -1);

        var items = [];
        items.push(T.item('View Details', 'bi-eye text-info', cfg.urls.view + '?id=' + did, '', true));

        if (canEditNow) {
            items.push(T.item('Edit Details', 'bi-pencil text-warning', editUrl(cfg, row), '', true));
        }

        // Parallel Review + Approve — one active, the other shown disabled.
        if (inWorkflow && p.canReview) {
            items.push(isPending
                ? T.item('Mark Reviewed', 'bi-check2', 'BMSDnTable.review(\'' + id + '\',' + did + ')', 'text-primary fw-bold')
                : '<li><a class="dropdown-item py-2 rounded text-muted disabled" href="#" tabindex="-1" aria-disabled="true" title="Already reviewed"><i class="bi bi-check2 me-2"></i>Mark Reviewed</a></li>');
        }
        if (inWorkflow && p.canApprove) {
            items.push(isReviewed
                ? T.item('Approve DN', 'bi-check-circle', 'BMSDnTable.approve(\'' + id + '\',' + did + ')', 'text-success fw-bold')
                : '<li><a class="dropdown-item py-2 rounded text-muted disabled" href="#" tabindex="-1" aria-disabled="true" title="Must be reviewed before approval"><i class="bi bi-check-circle me-2"></i>Approve DN</a></li>');
        }

        if (p.canCreateGrn && row.dn_type === 'inbound' && ['approved', 'partially_delivered'].indexOf(row.status) !== -1) {
            items.push(T.divider());
            items.push(T.item('Create GRN', 'bi-clipboard-plus', cfg.urls.grnCreate + '?dn=' + did, 'text-success fw-bold', true));
        }

        if (canDeleteNow && p.canDelete) {
            items.push(T.divider());
            items.push(T.item('Delete', 'bi-trash', 'BMSDnTable.remove(\'' + id + '\',' + did + ')', 'text-danger'));
        }

        return T.actions(items);
    }

    function renderCards(cfg, data) {
        if (!cfg.cardContainer) return;
        var $c = $(cfg.cardContainer);
        if (!$c.length) return;
        var esc = window.BMSTbl.esc, date = window.BMSTbl.date, p = cfg.perms;

        $c.empty();
        if (!data || data.length === 0) {
            $c.html('<div class="col-12 text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i> No records found</div>');
            return;
        }

        var showParty = cfg.hide.indexOf('party') === -1;
        var showProject = cfg.hide.indexOf('project') === -1;

        data.each(function (row) {
            var sc = statusClass(row.status);
            var canEditNow = (row.status === 'pending' || row.status === 'reviewed' || p.isAdmin);
            var canDeleteNow = (p.isAdmin || ['draft', 'pending', 'cancelled'].indexOf(row.status) !== -1);

            $c.append(
                '<div class="col-md-6 col-lg-4">' +
                  '<div class="card h-100 shadow-sm border-0 hover-shadow transition-all" style="border-radius: 12px; border: 1px solid #eef2f6 !important;">' +
                    '<div class="card-body">' +
                      '<div class="d-flex justify-content-between align-items-start mb-3"><div>' +
                        '<code class="small d-block mb-1">' + esc(row.dn_number || row.delivery_number || 'No DN #') + '</code>' +
                        '<div class="mb-1">' + typeBadge(row.dn_type) + '</div>' +
                        (showParty ? '<h6 class="fw-bold mb-0">' + esc(row.supplier_name) + '</h6><small class="text-muted">' + esc(row.company_name || '') + '</small>' : '') +
                      '</div><span class="badge bg-' + sc + ' small" style="font-size: 0.65rem;">' + String(row.status).toUpperCase() + '</span></div>' +
                      '<div class="row g-2 mb-3">' +
                        '<div class="col-6"><small class="text-muted d-block small">Date</small><span class="small fw-medium text-dark">' + date(row.delivery_date) + '</span></div>' +
                        '<div class="col-6"><small class="text-muted d-block small">Status</small><span class="badge bg-' + sc + ' small" style="font-size:0.65rem;">' + String(row.status).toUpperCase() + '</span></div>' +
                        '<div class="col-6"><small class="text-muted d-block small">Warehouse</small><span class="small text-dark text-truncate d-block" title="' + esc(row.warehouse_name) + '">' + esc(row.warehouse_name) + '</span></div>' +
                        '<div class="col-6"><small class="text-muted d-block small">Items</small><span class="small text-dark">' + row.total_items + ' items</span></div>' +
                        (showProject ? '<div class="col-12 mt-2"><small class="text-muted d-block small">Project</small><span class="badge bg-info-soft text-info border border-info small p-1 text-wrap d-inline-block w-100" style="white-space: normal; word-break: break-word;">' + esc(row.project_name || 'N/A') + '</span></div>' : '') +
                      '</div>' +
                      '<div class="dn-card-actions">' +
                        '<a href="' + cfg.urls.view + '?id=' + row.delivery_id + '" class="btn btn-sm btn-outline-primary dn-card-btn" title="View"><i class="bi bi-eye"></i></a>' +
                        (canEditNow ? '<a href="' + editUrl(cfg, row) + '" class="btn btn-sm btn-outline-warning dn-card-btn" title="Edit"><i class="bi bi-pencil"></i></a>' : '') +
                        ((row.status === 'pending' && p.canReview) ? '<button class="btn btn-sm btn-outline-primary dn-card-btn" onclick="BMSDnTable.review(\'' + cfg.tableId + '\',' + row.delivery_id + ')" title="Mark Reviewed"><i class="bi bi-check2"></i></button>' : '') +
                        ((row.status === 'reviewed' && p.canApprove) ? '<button class="btn btn-sm btn-outline-success dn-card-btn" onclick="BMSDnTable.approve(\'' + cfg.tableId + '\',' + row.delivery_id + ')" title="Approve"><i class="bi bi-check-circle"></i></button>' : '') +
                        ((p.canCreateGrn && row.dn_type === 'inbound' && ['approved', 'partially_delivered'].indexOf(row.status) !== -1) ? '<a href="' + cfg.urls.grnCreate + '?dn=' + row.delivery_id + '" class="btn btn-sm btn-success dn-card-btn" title="Create GRN"><i class="bi bi-clipboard-plus"></i></a>' : '') +
                        ((p.canDelete && canDeleteNow) ? '<button class="btn btn-sm btn-outline-danger dn-card-btn" onclick="BMSDnTable.remove(\'' + cfg.tableId + '\',' + row.delivery_id + ')" title="Delete"><i class="bi bi-trash"></i></button>' : '') +
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
            pageLength: cfg.pageLength || 25,
            order: cfg.order || [[1, 'desc']],
            dom: cfg.dom || 'rtp',
            ajax: {
                url: cfg.apiUrl,
                data: function (d) {
                    var extra = (typeof cfg.filters === 'function') ? cfg.filters() : {};
                    return $.extend({}, d, extra, cfg.fixed);
                },
                dataSrc: function (json) {
                    if (json.success) {
                        if (typeof cfg.onStats === 'function') cfg.onStats(json.stats);
                        if (typeof cfg.onTypeCounts === 'function' && json.type_counts) cfg.onTypeCounts(json.type_counts);
                        if (typeof cfg.onCount === 'function') cfg.onCount(json.recordsFiltered);
                        return json.data;
                    }
                    return [];
                }
            },
            drawCallback: function () {
                renderCards(cfg, this.api().rows({ page: 'current' }).data());
            },
            columns: columns(cfg).map(function (c) { return c.col; }),
            language: {
                processing: '<div class="spinner-border spinner-border-sm text-primary"></div> Loading...',
                zeroRecords: 'No delivery notes found match your filters'
            }
        });

        M._i[cfg.tableId] = { dt: dt, cfg: cfg };
        return dt;
    };

    M.dt     = function (id) { var i = inst(id); return i && i.dt; };
    M.reload = function (id) { var i = inst(id); if (i) i.dt.ajax.reload(null, false); };
    M.adjust = function (id) { var i = inst(id); if (i) window.BMSTbl.adjust(i.dt); };

    M.review = function (id, did) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: 'Mark as Reviewed?',
            text: 'DN will move to Reviewed and become approvable.',
            color: '#0d6efd', confirmText: 'Yes, mark reviewed', successTitle: 'Reviewed!',
            url: i.cfg.urls.review, data: { delivery_id: did },
            onDone: function () { M.reload(id); }
        });
    };

    M.approve = function (id, did) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: 'Approve Delivery Note?',
            text: 'Once approved, stock movements will fire.',
            color: '#198754', confirmText: 'Yes, approve', successTitle: 'Approved!',
            url: i.cfg.urls.approve, data: { delivery_id: did },
            onDone: function () { M.reload(id); }
        });
    };

    M.remove = function (id, did) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: 'Delete Delivery Note?',
            text: 'This action cannot be undone.',
            icon: 'warning', color: '#dc3545', confirmText: 'Yes, Delete', successTitle: 'Deleted!',
            url: i.cfg.urls.del, data: { delivery_id: did },
            onDone: function () { M.reload(id); }
        });
    };

    window.BMSDnTable = M;

})(window, jQuery);
