/**
 * bms-rfq-table.js
 * The RFQ list table — ONE implementation, two hosts:
 *
 *   app/bms/purchase/rfq.php               → full list, filter bar, stat cards
 *   app/bms/Suppliers/supplier_details.php → RFQs tab, one supplier,
 *                                            Supplier column hidden
 *
 * Rendered from includes/tables/rfq_table.php.
 */
(function (window, $) {
    'use strict';

    var M = { _i: {} };

    function inst(id) { return M._i[id]; }

    var STATUS = {
        pending:   { c: 'text-warning', l: 'Pending' },
        draft:     { c: 'text-muted',   l: 'Draft' },
        review:    { c: 'text-primary', l: 'In Review' },
        approved:  { c: 'text-success', l: 'Approved' },
        sent:      { c: 'text-info',    l: 'Sent' },
        received:  { c: 'text-primary', l: 'Quote Received' },
        evaluated: { c: 'text-primary', l: 'Evaluated' },
        awarded:   { c: 'text-success', l: 'Awarded' },
        partially: { c: 'text-warning', l: 'Partially Ordered' },
        completed: { c: 'text-primary', l: 'Completed' },
        cancelled: { c: 'text-danger',  l: 'Cancelled' }
    };

    function columns(cfg) {
        var esc = window.BMSTbl.esc;
        var all = [
            { key: 'sno', col: {
                data: null, orderable: false, searchable: false,
                className: 'ps-3 text-center text-muted small fw-bold', responsivePriority: 1,
                render: function (d, t, r, m) { return m.row + m.settings._iDisplayStart + 1; }
            }},
            { key: 'rfq_number', col: {
                data: 'rfq_number', responsivePriority: 1,
                render: function (d) { return '<span class="rfq-code">' + (esc(d) || '—') + '</span>'; }
            }},
            { key: 'rfq_date', col: {
                data: 'rfq_date', responsivePriority: 5,
                render: function (d) { return esc(d) || '—'; }
            }},
            { key: 'supplier', col: {
                data: 'supplier_name', responsivePriority: 2,
                render: function (d) {
                    return d ? '<span style="white-space:normal;word-break:break-word;">' + esc(d) + '</span>'
                             : '<span class="text-muted">—</span>';
                }
            }},
            { key: 'project', col: {
                data: 'project_name', defaultContent: '—', responsivePriority: 6,
                render: function (d) {
                    return d ? '<span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 rfq-project-badge" style="white-space:normal;">' + esc(d) + '</span>' : '—';
                }
            }},
            { key: 'warehouse', col: {
                data: 'warehouse_name', responsivePriority: 4,
                render: function (d) {
                    return d ? '<span style="white-space:normal;word-break:break-word;">' + esc(d) + '</span>'
                             : '<span class="text-muted">—</span>';
                }
            }},
            { key: 'status', col: {
                data: 'status', responsivePriority: 3,
                render: function (d) {
                    var s = STATUS[d] || { c: 'text-dark', l: d };
                    return '<span class="' + s.c + ' text-uppercase fw-bold" style="font-size:.8rem;letter-spacing:.4px;">' + esc(s.l) + '</span>';
                }
            }},
            { key: 'actions', col: {
                data: null, orderable: false, className: 'text-end pe-3 d-print-none', responsivePriority: 1,
                render: function (d, t, row) { return rowActions(cfg, row); }
            }}
        ];
        return all.filter(function (c) { return cfg.hide.indexOf(c.key) === -1; });
    }

    function rowActions(cfg, row) {
        var id = cfg.tableId, rid = row.rfq_id;
        var num = String(row.rfq_number || '').replace(/'/g, "\\'");

        var createPO = (row.status === 'approved' && row.supplier_id)
            ? '<li><hr class="dropdown-divider opacity-50"></li>' +
              '<li><a class="dropdown-item py-2 text-primary fw-semibold" href="' + cfg.urls.poCreate + '?supplier=' + row.supplier_id + '&rfq_ref=' + rid + '">' +
              '<i class="bi bi-cart-plus me-2"></i>Create Purchase Order</a></li>'
            : '';

        return '<div class="dropdown">' +
            '<button class="btn btn-sm btn-white border dropdown-toggle" style="background:#fff;" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>' +
            '<ul class="dropdown-menu dropdown-menu-end shadow-sm">' +
                '<li><a class="dropdown-item py-2" href="' + cfg.urls.view + '?id=' + rid + '"><i class="bi bi-eye text-primary me-2"></i>View</a></li>' +
                (row.status === 'draft'
                    ? '<li><a class="dropdown-item py-2 text-primary fw-semibold" href="' + cfg.urls.view + '?id=' + rid + '"><i class="bi bi-eye-fill me-2"></i>Review</a></li>'
                    : '') +
                (row.status === 'review'
                    ? '<li><a class="dropdown-item py-2 text-success fw-semibold" href="#" onclick="BMSRfqTable.approve(\'' + id + '\',' + rid + ',\'' + num + '\');return false;"><i class="bi bi-check-circle me-2"></i>Approve</a></li>'
                    : '') +
                '<li>' +
                    '<div class="d-flex align-items-center dropdown-item py-0 pe-1">' +
                        '<a class="flex-grow-1 py-2 text-decoration-none text-dark" href="#" onclick="BMSRfqTable.print(\'' + id + '\',' + rid + ');return false;"><i class="bi bi-printer text-dark me-2"></i>Print</a>' +
                        '<button type="button" class="btn btn-sm border-0 p-1 text-muted" title="Choose a different template" onclick="event.stopPropagation(); $(\'#rfqTplSub' + rid + '\').toggleClass(\'d-none\'); $(this).find(\'i\').toggleClass(\'bi-chevron-down bi-chevron-up\');"><i class="bi bi-chevron-down"></i></button>' +
                    '</div>' +
                    '<ul class="list-unstyled ms-4 mb-1 d-none" id="rfqTplSub' + rid + '">' +
                        '<li><a class="dropdown-item py-1 small text-muted" href="#" onclick="BMSRfqTable.print(\'' + id + '\',' + rid + ',\'navy\'); return false;"><i class="bi bi-file-earmark-text me-2"></i>Striped Template</a></li>' +
                        '<li><a class="dropdown-item py-1 small text-muted" href="#" onclick="BMSRfqTable.print(\'' + id + '\',' + rid + ',\'corporate\'); return false;"><i class="bi bi-file-earmark-text me-2"></i>Minimal Template</a></li>' +
                        '<li><a class="dropdown-item py-1 small text-muted" href="#" onclick="BMSRfqTable.print(\'' + id + '\',' + rid + ',\'banded\'); return false;"><i class="bi bi-file-earmark-text me-2"></i>Radiant Template</a></li>' +
                    '</ul>' +
                '</li>' +
                (row.status === 'draft'
                    ? '<li><a class="dropdown-item py-2" href="' + cfg.urls.edit + '?edit=' + rid + '"><i class="bi bi-pencil text-info me-2"></i>Edit</a></li>'
                    : '') +
                createPO +
                '<li><hr class="dropdown-divider opacity-50"></li>' +
                '<li><a class="dropdown-item py-2 text-danger" href="#" onclick="BMSRfqTable.remove(\'' + id + '\',' + rid + ',\'' + num + '\');return false;"><i class="bi bi-trash me-2"></i>Delete</a></li>' +
            '</ul></div>';
    }

    /* ── Public API ──────────────────────────────────────────────────── */

    M.init = function (cfg) {
        cfg.hide = cfg.hide || [];
        cfg.fixed = cfg.fixed || {};

        var dt = $('#' + cfg.tableId).DataTable({
            dom: cfg.dom || 'rtip',
            responsive: true,
            scrollX: false,
            pageLength: cfg.pageLength || 10,
            order: cfg.order || [[0, 'desc']],
            ajax: {
                url: cfg.apiUrl,
                data: function (d) {
                    var extra = (typeof cfg.filters === 'function') ? cfg.filters() : {};
                    return $.extend(d, extra, cfg.fixed);
                },
                dataSrc: function (json) {
                    if (json.stats && typeof cfg.onStats === 'function') cfg.onStats(json.stats);
                    var rows = json.data || [];
                    if (typeof cfg.onCount === 'function') cfg.onCount(rows.length);
                    return rows;
                },
                error: function () {}
            },
            columns: columns(cfg).map(function (c) { return c.col; }),
            language: {
                emptyTable: '<div class="text-center py-5 text-muted"><i class="bi bi-file-earmark-text fs-1 d-block mb-2 opacity-25"></i>No RFQ records found</div>',
                zeroRecords: '<div class="text-center py-4 text-muted">No records match your filters</div>'
            }
        });

        M._i[cfg.tableId] = { dt: dt, cfg: cfg };
        return dt;
    };

    M.dt     = function (id) { var i = inst(id); return i && i.dt; };
    M.reload = function (id) { var i = inst(id); if (i) i.dt.ajax.reload(null, false); };
    M.adjust = function (id) { var i = inst(id); if (i) window.BMSTbl.adjust(i.dt); };

    M.print = function (id, rid, template) {
        var i = inst(id); if (!i) return;
        var base = i.cfg.urls.print[template] || i.cfg.urls.print.standard;
        window.open(base + '?id=' + rid, '_blank');
    };

    M.approve = function (id, rid, number) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: 'Approve RFQ?',
            text: 'RFQ #' + number + ' will be marked as approved.',
            color: '#198754', confirmText: 'Yes, Approve It', successTitle: 'Approved!',
            url: i.cfg.urls.approve, data: { rfq_id: rid },
            onDone: function () { M.reload(id); }
        });
    };

    M.remove = function (id, rid, number) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: 'Delete RFQ?',
            text: 'RFQ #' + number + ' will be permanently deleted and cannot be recovered.',
            icon: 'warning', color: '#dc3545', confirmText: 'Yes, Delete It', successTitle: 'Deleted!',
            url: i.cfg.urls.del, data: { rfq_id: rid },
            onDone: function () { M.reload(id); }
        });
    };

    window.BMSRfqTable = M;

})(window, jQuery);
