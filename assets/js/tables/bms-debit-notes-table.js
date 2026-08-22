/**
 * bms-debit-notes-table.js
 * The Debit Notes list table — ONE implementation, two hosts:
 *
 *   app/bms/purchase/debit_notes/debit_notes.php → full list, filters, stat cards
 *   app/bms/Suppliers/supplier_details.php       → Debit Notes tab, one supplier,
 *                                                  Supplier column hidden
 *
 * Fed by api/purchase/get_debit_notes.php. Rendered from
 * includes/tables/debit_notes_table.php.
 */
(function (window, $) {
    'use strict';

    var M = { _i: {} };

    function inst(id) { return M._i[id]; }

    var BADGE = {
        pending:   ['#e9ecef', '#495057'],
        reviewed:  ['#bfdbfe', '#1e3a8a'],
        approved:  ['#0d6efd', '#fff'],
        paid:      ['#052c65', '#fff'],
        rejected:  ['#dc3545', '#fff'],
        cancelled: ['#6c757d', '#fff']
    };

    function badge(s) {
        var c = BADGE[s] || ['#e9ecef', '#495057'];
        var l = String(s).charAt(0).toUpperCase() + String(s).slice(1);
        return '<span class="badge-status" style="background:' + c[0] + ';color:' + c[1] + ';">' + l + '</span>';
    }

    function money(v) {
        return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function columns(cfg) {
        var esc = window.BMSTbl.esc;
        var all = [
            { key: 'sno',      col: { data: null, orderable: false, render: function (d, t, r, m) { return m.row + 1; } } },
            { key: 'number',   col: { data: 'number' } },
            { key: 'date',     col: { data: 'date' } },
            { key: 'supplier', col: {
                data: 'supplier',
                render: function (d, t, r) { return t === 'display' ? '<div class="fw-semibold">' + esc(r.supplier) + '</div>' : r.supplier; }
            }},
            { key: 'origin',   col: { data: 'origin' } },
            { key: 'amount',   col: {
                data: 'amount', className: 'text-end',
                render: function (d, t) { return t === 'display' ? money(d) : d; }
            }},
            { key: 'status',   col: {
                data: 'status', className: 'text-center',
                render: function (d, t) { return t === 'display' ? badge(d) : d; }
            }},
            { key: 'actions',  col: {
                data: null, orderable: false, searchable: false, className: 'text-end',
                render: function (d, t, r) { return rowActions(cfg, r); }
            }}
        ];
        return all.filter(function (c) { return cfg.hide.indexOf(c.key) === -1; });
    }

    function rowActions(cfg, row) {
        var u = cfg.urls, p = cfg.perms, id = cfg.tableId;

        var items = '<li><a class="dropdown-item py-2 rounded" href="' + u.view + '?id=' + row.id + '"><i class="bi bi-eye text-primary me-2"></i> View</a></li>';

        items += '<li>' +
            '<div class="d-flex align-items-center dropdown-item py-0 pe-1 rounded">' +
                '<a class="flex-grow-1 py-2 text-decoration-none text-dark" href="' + u.print.standard + '?id=' + row.id + '" target="_blank"><i class="bi bi-printer text-primary me-2"></i> Print</a>' +
                '<button type="button" class="btn btn-sm border-0 p-1 text-muted" title="Choose a different template" onclick="event.stopPropagation(); $(\'#dnTplSub' + row.id + '\').toggleClass(\'d-none\'); $(this).find(\'i\').toggleClass(\'bi-chevron-down bi-chevron-up\');">' +
                    '<i class="bi bi-chevron-down"></i>' +
                '</button>' +
            '</div>' +
            '<ul class="list-unstyled ms-4 mb-1 d-none" id="dnTplSub' + row.id + '">' +
                '<li><a class="dropdown-item py-1 small text-muted" href="' + u.print.navy + '?id=' + row.id + '" target="_blank"><i class="bi bi-file-earmark-text me-2"></i>Navy Template</a></li>' +
                '<li><a class="dropdown-item py-1 small text-muted" href="' + u.print.corporate + '?id=' + row.id + '" target="_blank"><i class="bi bi-file-earmark-text me-2"></i>Corporate Template</a></li>' +
                '<li><a class="dropdown-item py-1 small text-muted" href="' + u.print.banded + '?id=' + row.id + '" target="_blank"><i class="bi bi-file-earmark-text me-2"></i>Banded Template</a></li>' +
            '</ul>' +
        '</li>';

        if (p.edit && row.status === 'pending') {
            items += '<li><a class="dropdown-item py-2 rounded" href="' + u.edit + '?id=' + row.id + '"><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>';
        }
        if (p.del && row.status !== 'paid') {
            items += '<li><hr class="dropdown-divider"></li>' +
                     '<li><button class="dropdown-item py-2 rounded text-danger" onclick="BMSDebitNotesTable.remove(\'' + id + '\',' + row.id + ')"><i class="bi bi-trash text-danger me-2"></i> Delete</button></li>';
        }

        return '<div class="dropdown d-flex justify-content-end">' +
               '<button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button>' +
               '<ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">' + items + '</ul></div>';
    }

    function renderCards(cfg, rows) {
        if (!cfg.cardContainer) return;
        var $cv = $(cfg.cardContainer);
        if (!$cv.length) return;

        if (!rows.length) {
            $cv.html('<div class="col-12 text-center py-5 text-muted">No debit notes found.</div>');
            return;
        }

        var esc = window.BMSTbl.esc, u = cfg.urls, p = cfg.perms;
        var showSupplier = cfg.hide.indexOf('supplier') === -1;
        var html = '';

        rows.forEach(function (r) {
            html += '<div class="col-12"><div class="card dn-card shadow-sm"><div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-start"><div>' +
                    '<div class="fw-bold text-primary">' + esc(r.number) + '</div>' +
                    '<small class="text-muted">' + esc(r.date) + ' · ' + esc(r.origin) + '</small>' +
                '</div>' + badge(r.status) + '</div>' +
                (showSupplier ? '<div class="mt-2 fw-semibold">' + esc(r.supplier) + '</div>' : '') +
                '<div class="fw-bold">TZS ' + money(r.amount) + '</div></div>' +
                '<div class="card-footer bg-white border-top p-0"><div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">' +
                    '<a class="btn btn-sm btn-outline-primary" style="flex:1" href="' + u.view + '?id=' + r.id + '"><i class="bi bi-eye"></i></a>' +
                    '<a class="btn btn-sm btn-outline-primary" style="flex:1" href="' + u.print.standard + '?id=' + r.id + '" target="_blank"><i class="bi bi-printer"></i></a>' +
                    ((p.edit && r.status === 'pending') ? '<a class="btn btn-sm btn-outline-primary" style="flex:1" href="' + u.edit + '?id=' + r.id + '"><i class="bi bi-pencil"></i></a>' : '') +
                    ((p.del && r.status !== 'paid') ? '<button class="btn btn-sm btn-outline-danger" style="flex:1" onclick="BMSDebitNotesTable.remove(\'' + cfg.tableId + '\',' + r.id + ')"><i class="bi bi-trash"></i></button>' : '') +
                '</div></div></div></div>';
        });

        $cv.html(html);
    }

    /* ── Public API ──────────────────────────────────────────────────── */

    M.init = function (cfg) {
        cfg.hide = cfg.hide || [];

        var dt = $('#' + cfg.tableId).DataTable({
            responsive: false,
            scrollX: true,
            pageLength: cfg.pageLength || 25,
            order: cfg.order || [[1, 'desc']],
            dom: cfg.dom || 'rtip',
            ajax: {
                url: cfg.apiUrl,
                data: cfg.supplierId ? { supplier_id: cfg.supplierId } : {},
                dataSrc: function (json) {
                    if (!json.success) return [];
                    if (json.stats && typeof cfg.onStats === 'function') cfg.onStats(json.stats);
                    if (json.suppliers && typeof cfg.onSuppliers === 'function') cfg.onSuppliers(json.suppliers);
                    if (typeof cfg.onCount === 'function') cfg.onCount((json.data || []).length);
                    return json.data || [];
                }
            },
            language: { emptyTable: 'No debit notes found.', zeroRecords: 'No matching debit notes.' },
            columns: columns(cfg).map(function (c) { return c.col; }),
            drawCallback: function () {
                renderCards(cfg, this.api().rows({ page: 'current', search: 'applied' }).data().toArray());
            }
        });

        M._i[cfg.tableId] = { dt: dt, cfg: cfg };
        return dt;
    };

    M.dt     = function (id) { var i = inst(id); return i && i.dt; };
    M.reload = function (id) { var i = inst(id); if (i) i.dt.ajax.reload(null, false); };
    M.adjust = function (id) { var i = inst(id); if (i) window.BMSTbl.adjust(i.dt); };

    /** Column index of a key in THIS instance, so filters survive hidden columns. */
    M.colIndex = function (id, key) {
        var i = inst(id); if (!i) return -1;
        var visible = columns(i.cfg);
        for (var n = 0; n < visible.length; n++) {
            if (visible[n].key === key) return n;
        }
        return -1;
    };

    M.remove = function (id, rid) {
        var i = inst(id); if (!i) return;
        window.BMSTbl.confirmPost({
            title: 'Delete Debit Note?',
            text: 'This cannot be undone.',
            icon: 'warning', color: '#dc3545', confirmText: 'Yes, Delete', successTitle: 'Deleted!',
            url: i.cfg.urls.del,
            data: { debit_note_id: rid, _csrf: (typeof window.CSRF_TOKEN !== 'undefined' ? window.CSRF_TOKEN : '') },
            onDone: function () { M.reload(id); }
        });
    };

    window.BMSDebitNotesTable = M;

})(window, jQuery);
