/**
 * warehouse-batches.js
 *
 * "Batches" tab on warehouse_view.php (Simple POS only) — every batch/lot
 * ever received into this one warehouse: buying price, selling price,
 * wholesale price, manufacturing/expiry date, and received/remaining
 * quantity, exactly as recorded in product_batches (api/stock/
 * get_warehouse_batches.php does a plain SELECT against that table — no
 * parallel calculation, so this can never disagree with product_view.php's
 * own batch section, the FEFO consumption in core/pos_batch_consumption.php,
 * or the expiring-batch alerts on dashboard.php).
 *
 * A batch is created automatically, with no separate step, by:
 *   - Creating a product with opening stock (api/create_product.php) — its
 *     first batch.
 *   - Restocking at the POS (api/pos/quick_restock.php) — a new batch.
 *   - Approving a GRN with a batch_number/expiry_date on the line
 *     (api/approve_grn.php).
 * This page only ever shows what's really in product_batches; it never
 * writes a new one. Editing here corrects a batch's OWN recorded details
 * (see api/stock/update_warehouse_batch.php's docblock for exactly which
 * fields, and why quantity is deliberately never editable here).
 *
 * Same DataTable + mobile-card convention as assets/js/pos-credit-aging.js:
 * client-side (one fetch, the API is never paginated), explicit sort order
 * (dodges the non-orderable-first-column footgun), drawCallback +
 * resize-synced card/table toggle.
 */
(function (window, $) {
    'use strict';

    var M = { _i: {} };

    function esc(t) { return $('<div>').text(t == null ? '' : t).html(); }
    function money(v) { return v === null || v === undefined ? null : parseFloat(v).toLocaleString('en-US', { minimumFractionDigits: 2 }); }

    function fmtDate(d) {
        if (!d) return '-';
        var s = String(d);
        var iso = s.indexOf(' ') !== -1 ? s.replace(' ', 'T') : (s.indexOf('T') !== -1 ? s : s + 'T00:00:00');
        var dt = new Date(iso);
        if (isNaN(dt.getTime())) return '-';
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function inst(id) { return M._i[id]; }

    function statusBadge(cfg, row) {
        if (row.status === 'exhausted') return '<span class="badge bg-secondary">' + esc(cfg.i18n.exhausted) + '</span>';
        if (row.status === 'expired') return '<span class="badge bg-danger">' + esc(cfg.i18n.expired) + '</span>';
        if (row.days_remaining !== null && row.days_remaining <= 30) {
            return '<span class="badge bg-warning text-dark">' + esc(cfg.i18n.active) + ' &middot; ' + row.days_remaining + 'd</span>';
        }
        return '<span class="badge bg-success">' + esc(cfg.i18n.active) + '</span>';
    }

    function moneyOrDash(v) { var m = money(v); return m === null ? '-' : m; }

    function columns(cfg) {
        var all = [
            { key: 'sno', col: {
                data: null, orderable: false, searchable: false, width: '40px',
                className: 'text-center text-muted small fw-bold',
                render: function (d, t, r, meta) { return meta.row + meta.settings._iDisplayStart + 1; }
            }},
            { key: 'product', col: {
                data: 'product_name',
                render: function (d, t, row) {
                    return '<strong>' + esc(d || '-') + '</strong>' + (row.sku ? '<br><small class="text-muted">' + esc(row.sku) + '</small>' : '');
                }
            }},
            { key: 'batch_number', col: {
                data: 'batch_number',
                render: function (d) { return d ? '<code>' + esc(d) + '</code>' : '<span class="text-muted">-</span>'; }
            }},
            { key: 'unit_cost', col: {
                data: 'unit_cost', className: 'text-end',
                render: function (d) { return moneyOrDash(d); }
            }},
            { key: 'selling_price', col: {
                data: 'selling_price', className: 'text-end',
                render: function (d) { return moneyOrDash(d); }
            }},
            { key: 'wholesale_price', col: {
                data: 'wholesale_price', className: 'text-end',
                render: function (d) { return moneyOrDash(d); }
            }},
            { key: 'manufacturing_date', col: {
                data: 'manufacturing_date',
                render: function (d) { return fmtDate(d); }
            }},
            { key: 'expiry_date', col: {
                data: 'expiry_date',
                render: function (d) { return fmtDate(d); }
            }},
            { key: 'quantity_received', col: {
                data: 'quantity_received', className: 'text-end',
                render: function (d) { return parseFloat(d || 0).toLocaleString('en-US'); }
            }},
            { key: 'quantity_remaining', col: {
                data: 'quantity_remaining', className: 'text-end fw-bold',
                render: function (d) { return parseFloat(d || 0).toLocaleString('en-US'); }
            }},
            { key: 'status', col: {
                data: null, orderable: false,
                render: function (d, t, row) { return statusBadge(cfg, row); }
            }},
            { key: 'actions', col: {
                data: null, orderable: false, className: 'text-center',
                render: function (d, t, row) {
                    if (!cfg.canEdit) return '';
                    return '<button type="button" class="btn btn-sm btn-outline-warning" title="' + esc(cfg.i18n.edit) + '" onclick="WarehouseBatches.edit(\'' + cfg._id + '\',' + row.batch_id + ')"><i class="bi bi-pencil"></i></button>';
                }
            }}
        ];
        return all.filter(function (c) { return (cfg.hide || []).indexOf(c.key) === -1; });
    }

    function renderCards(cfg, dt) {
        if (!cfg.cardContainer) return;
        if (dt.rows().count() === 0) return;

        var $c = $(cfg.cardContainer);
        if (!$c.length) return;

        if ($(window).width() > 768) {
            $c.addClass('d-none').empty();
            $(cfg.listContainer).removeClass('d-none');
            return;
        }
        $(cfg.listContainer).addClass('d-none');
        var container = $c.removeClass('d-none').empty();

        dt.rows({ page: 'current' }).every(function () {
            var row = this.data();
            var editBtn = cfg.canEdit
                ? '<button type="button" class="btn btn-sm btn-outline-warning w-100" onclick="WarehouseBatches.edit(\'' + cfg._id + '\',' + row.batch_id + ')"><i class="bi bi-pencil me-1"></i>' + esc(cfg.i18n.edit) + '</button>'
                : '';
            container.append(
                '<div class="wb-mobile-card mb-2">' +
                  '<div class="wb-head">' +
                    '<div class="wb-icon"><i class="bi bi-box-seam"></i></div>' +
                    '<div class="flex-grow-1" style="min-width:0;">' +
                      '<div class="wb-name">' + esc(row.product_name || '-') + '</div>' +
                      '<div class="mt-1">' + statusBadge(cfg, row) + (row.batch_number ? ' <code class="small">' + esc(row.batch_number) + '</code>' : '') + '</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="wb-row"><span class="wb-label">' + esc(cfg.i18n.buyingPrice) + '</span><span class="wb-value">' + moneyOrDash(row.unit_cost) + '</span></div>' +
                  '<div class="wb-row"><span class="wb-label">' + esc(cfg.i18n.sellingPrice) + '</span><span class="wb-value">' + moneyOrDash(row.selling_price) + '</span></div>' +
                  '<div class="wb-row"><span class="wb-label">' + esc(cfg.i18n.wholesalePrice) + '</span><span class="wb-value">' + moneyOrDash(row.wholesale_price) + '</span></div>' +
                  '<div class="wb-row"><span class="wb-label">' + esc(cfg.i18n.manufacturingDate) + '</span><span class="wb-value">' + fmtDate(row.manufacturing_date) + '</span></div>' +
                  '<div class="wb-row"><span class="wb-label">' + esc(cfg.i18n.expiryDate) + '</span><span class="wb-value">' + fmtDate(row.expiry_date) + '</span></div>' +
                  '<div class="wb-row"><span class="wb-label">' + esc(cfg.i18n.received) + '</span><span class="wb-value">' + parseFloat(row.quantity_received || 0).toLocaleString('en-US') + '</span></div>' +
                  '<div class="wb-row"><span class="wb-label">' + esc(cfg.i18n.remaining) + '</span><span class="wb-value fw-bold">' + parseFloat(row.quantity_remaining || 0).toLocaleString('en-US') + '</span></div>' +
                  (editBtn ? '<div class="wb-actions">' + editBtn + '</div>' : '') +
                '</div>'
            );
        });
    }

    function buildTable(cfg) {
        var visibleCols = columns(cfg);
        var dateColIdx = visibleCols.findIndex(function (c) { return c.key === 'expiry_date'; });

        var dt = $(cfg.tableSel).DataTable({
            responsive: false,
            processing: true,
            ajax: {
                url: cfg.urls.list,
                data: function (d) { d.warehouse_id = cfg.warehouseId; return d; },
                dataSrc: function (json) {
                    if (!json.success) {
                        if (typeof cfg.onError === 'function') cfg.onError(json.message);
                        $(cfg.loadingEl).addClass('d-none');
                        return [];
                    }
                    var i = inst(cfg._id);
                    if (i) i.rows = json.data;

                    $(cfg.loadingEl).addClass('d-none');
                    if (!json.data.length) {
                        $(cfg.listContainer).addClass('d-none');
                        $(cfg.cardContainer || '').addClass('d-none');
                        $(cfg.emptyEl).removeClass('d-none');
                    } else {
                        $(cfg.emptyEl).addClass('d-none');
                    }
                    return json.data;
                },
                error: function () {
                    $(cfg.loadingEl).addClass('d-none');
                    if (typeof cfg.onError === 'function') cfg.onError(cfg.i18n.error);
                }
            },
            columns: visibleCols.map(function (c) { return c.col; }),
            // Same reasoning as pos-credit-aging.js: explicit order (not
            // omitted) so a non-orderable column 0 never silently becomes
            // DataTables' implicit default sort target. Soonest-to-expire
            // first matches the server's own ORDER BY.
            order: (dateColIdx !== -1) ? [[dateColIdx, 'asc']] : [],
            dom: cfg.dom || 'rtip',
            pageLength: cfg.pageLength || 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            drawCallback: function () { renderCards(cfg, this.api()); }
        });

        if (cfg.cardContainer) {
            $(window).on('resize.' + cfg._id, function () { renderCards(cfg, dt); });
        }

        return dt;
    }

    /* ── Public API ──────────────────────────────────────────────────── */

    M.init = function (cfg) {
        var id = cfg.id || 'default';
        cfg._id = id;
        cfg.hide = cfg.hide || [];
        M._i[id] = { cfg: cfg, rows: [] };

        function start() {
            $(cfg.loadingEl).removeClass('d-none');
            M._i[id].dt = buildTable(cfg);
        }

        if (cfg.deferPane && window.BMSTbl && typeof window.BMSTbl.defer === 'function') {
            window.BMSTbl.defer(cfg.deferPane, function () { start(); return M._i[id].dt; });
        } else {
            start();
        }
        return id;
    };

    M.reload = function (id) {
        var i = inst(id);
        if (i && i.dt) i.dt.ajax.reload(null, false);
    };

    M.edit = function (id, batchId) {
        var i = inst(id); if (!i) return;
        var row = (i.rows || []).find(function (r) { return String(r.batch_id) === String(batchId); });
        if (!row) return;

        $('#wb_batch_id').val(row.batch_id);
        $('#wb_warehouse_id').val(i.cfg.warehouseId);
        $('#wb_product_name').text(row.product_name || '-');
        $('#wb_batch_number').val(row.batch_number || '');
        $('#wb_manufacturing_date').val(row.manufacturing_date ? String(row.manufacturing_date).substring(0, 10) : '');
        $('#wb_expiry_date').val(row.expiry_date ? String(row.expiry_date).substring(0, 10) : '');
        $('#wb_unit_cost').val(row.unit_cost);
        $('#wb_wholesale_price').val(row.wholesale_price !== null ? row.wholesale_price : '');
        $('#wb_selling_price').val(row.selling_price !== null ? row.selling_price : '');

        new bootstrap.Modal(document.getElementById('warehouseBatchEditModal')).show();
    };

    $(document).on('submit', '#warehouseBatchEditForm', function (e) {
        e.preventDefault();
        var $form = $(this);
        var id; for (var k in M._i) { if (M._i.hasOwnProperty(k)) { id = k; break; } }
        var i = inst(id); if (!i) return;
        var cfg = i.cfg;

        var btn = $form.find('[type="submit"]');
        var orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.post(cfg.urls.update, $form.serialize(), function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: cfg.i18n.success, text: res.message, timer: 2000, showConfirmButton: false });
                bootstrap.Modal.getInstance(document.getElementById('warehouseBatchEditModal'))?.hide();
                M.reload(id);
            } else {
                Swal.fire({ icon: 'error', title: cfg.i18n.error, text: res.message });
            }
        }, 'json').fail(function () {
            Swal.fire({ icon: 'error', title: cfg.i18n.error, text: cfg.i18n.error });
        }).always(function () {
            btn.prop('disabled', false).html(orig);
        });
    });

    window.WarehouseBatches = M;

})(window, jQuery);
