/**
 * pos-credit-aging.js
 *
 * pos_credit_receivables_plan.md Phase 2a/2b — the ONE renderer for the
 * credit-receivables row list, shared by the dedicated "Who Owes Me" page
 * (app/bms/pos/pos_credit_customers.php, unfiltered) and the customer
 * detail page's "Madeni" tab (Phase 2b, filtered to one customer_id) — so
 * the list, its row actions, and their behaviour never drift apart between
 * the two hosts.
 *
 * 2026-09-16 rewrite (bug report: page scrolled sideways on mobile, no
 * sort/search on desktop) — was hand-built HTML rows off one non-paginated
 * $.getJSON; now a real client-side DataTable (data fetched once via
 * `ajax`, sorting/searching/paging all client-side — the API already
 * returns every open credit sale, never paginated) plus a mobile card view,
 * matching the convention in assets/js/tables/bms-expenses-table.js
 * (explicit-by-key sort column, drawCallback + resize-synced card/table
 * toggle). `cfg.deferPane` (optional) holds init until a Bootstrap tab is
 * actually shown — the Madeni tab is not the customer page's default tab,
 * and initialising a DataTable inside a display:none container measures
 * every column at zero width.
 *
 * Row actions reuse the same endpoints pos.php already ships:
 *   Repay  -> api/pos/receive_payment.php   (credit settlement, as-is)
 *   Delete -> api/pos/void_sale.php         (full reversal, as-is; "delete" = void)
 *   Edit   -> api/pos/update_credit_due_date.php (due_date/notes only)
 *   View   -> api/pos/get_credit_sale_detail.php (header + payment history)
 */
(function (window, $) {
    'use strict';

    var M = { _i: {} };

    function esc(t) { return $('<div>').text(t == null ? '' : t).html(); }
    function money(v) { return parseFloat(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }); }

    function fmtDate(d) {
        if (!d) return '-';
        return new Date((String(d).indexOf('T') !== -1 ? d : d + 'T00:00:00'))
            .toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function inst(id) { return M._i[id]; }

    function sprintf(fmt, val) { return String(fmt).replace('%d', val); }

    function statusBadge(cfg, row) {
        if (row.is_overdue) {
            return '<span class="badge bg-danger">' + sprintf(cfg.i18n.overdueBy, row.days_overdue) + '</span>';
        }
        if (!row.due_date) {
            return '<span class="badge bg-secondary">' + esc(cfg.i18n.noDueDate) + '</span>';
        }
        var today = new Date(); today.setHours(0, 0, 0, 0);
        var due = new Date(row.due_date + 'T00:00:00');
        var diffDays = Math.round((due - today) / 86400000);
        if (diffDays === 0) return '<span class="badge bg-warning text-dark">' + esc(cfg.i18n.dueToday) + '</span>';
        return '<span class="badge bg-info text-dark">' + sprintf(cfg.i18n.dueInDays, diffDays) + '</span>';
    }

    function paidBadge(cfg, row) {
        if (parseFloat(row.paid) > 0) return '<span class="badge bg-primary-soft text-primary border border-primary">' + esc(cfg.i18n.partial) + '</span>';
        return '<span class="badge bg-secondary-soft text-secondary border border-secondary">' + esc(cfg.i18n.unpaid) + '</span>';
    }

    function rowActions(cfg, row) {
        var id = cfg._id, sid = row.sale_id;
        var html = '<div class="dropdown action-dropdown">' +
            '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear"></i></button>' +
            '<ul class="dropdown-menu dropdown-menu-end shadow-sm">' +
            '<li><a class="dropdown-item" href="#" onclick="PosCreditAging.view(\'' + id + '\',' + sid + ');return false;"><i class="bi bi-eye text-info"></i> ' + esc(cfg.i18n.view) + '</a></li>';
        if (cfg.canEdit) {
            html += '<li><a class="dropdown-item" href="#" onclick="PosCreditAging.repay(\'' + id + '\',' + sid + ');return false;"><i class="bi bi-cash text-success"></i> ' + esc(cfg.i18n.repay) + '</a></li>' +
                    '<li><a class="dropdown-item" href="#" onclick="PosCreditAging.edit(\'' + id + '\',' + sid + ');return false;"><i class="bi bi-pencil text-warning"></i> ' + esc(cfg.i18n.edit) + '</a></li>';
        }
        if (cfg.canDelete) {
            html += '<li><hr class="dropdown-divider opacity-50"></li>' +
                    '<li><a class="dropdown-item text-danger" href="#" onclick="PosCreditAging.remove(\'' + id + '\',' + sid + ');return false;"><i class="bi bi-trash"></i> ' + esc(cfg.i18n.delete) + '</a></li>';
        }
        html += '</ul></div>';
        return html;
    }

    function columns(cfg) {
        var all = [
            { key: 'sno', col: {
                data: null, orderable: false, searchable: false, width: '50px',
                className: 'text-center text-muted small fw-bold',
                render: function (d, t, r, meta) { return meta.row + meta.settings._iDisplayStart + 1; }
            }},
            { key: 'customer', col: {
                data: 'customer_name',
                render: function (d) { return '<strong>' + esc(d || '-') + '</strong>'; }
            }},
            { key: 'phone', col: {
                data: 'customer_phone',
                render: function (d) { return esc(d || '-'); }
            }},
            { key: 'owed', col: {
                data: 'balance_due', className: 'text-end',
                render: function (d, t, row) { return '<strong class="text-danger">' + money(d) + '</strong> ' + paidBadge(cfg, row); }
            }},
            { key: 'sale_date', col: {
                data: 'sale_date',
                render: function (d) { return fmtDate(d); }
            }},
            { key: 'due_date', col: {
                data: 'due_date',
                render: function (d) { return fmtDate(d); }
            }},
            { key: 'status', col: {
                data: null, orderable: false,
                render: function (d, t, row) { return statusBadge(cfg, row); }
            }},
            { key: 'actions', col: {
                data: null, orderable: false, className: 'text-center',
                render: function (d, t, row) { return rowActions(cfg, row); }
            }}
        ];
        return all.filter(function (c) { return (cfg.hide || []).indexOf(c.key) === -1; });
    }

    function renderCards(cfg, dt) {
        if (!cfg.cardContainer) return;
        // dataSrc() already put the page into its "empty" state (emptyEl
        // shown, both listContainer and cardContainer hidden) before this
        // drawCallback fires — DataTables calls drawCallback even for a
        // zero-row draw. Leave that state alone rather than re-showing an
        // empty table/card list on top of the "nobody owes you" message.
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
            container.append(
                '<div class="credit-aging-mobile-card mb-2">' +
                  '<div class="d-flex justify-content-between align-items-start mb-1"><div>' +
                    '<strong class="d-block" style="font-size:0.85rem">' + esc(row.customer_name || '-') + '</strong>' +
                    '<small class="text-muted">' + esc(row.customer_phone || '-') + '</small>' +
                  '</div><div class="d-flex align-items-center gap-2">' +
                    statusBadge(cfg, row) +
                    rowActions(cfg, row) +
                  '</div></div>' +
                  '<div class="d-flex flex-wrap align-items-center gap-2" style="font-size:0.78rem">' +
                    '<span class="text-danger fw-bold">' + money(row.balance_due) + '</span>' +
                    paidBadge(cfg, row) +
                    '<span class="text-muted"><i class="bi bi-calendar3 me-1"></i>' + esc(cfg.i18n.saleDate).replace(':', '') + ': ' + fmtDate(row.sale_date) + '</span>' +
                    '<span class="text-muted"><i class="bi bi-calendar-event me-1"></i>' + esc(cfg.i18n.dueDate).replace(':', '') + ': ' + fmtDate(row.due_date) + '</span>' +
                  '</div>' +
                '</div>'
            );
        });
    }

    function buildTable(cfg) {
        var visibleCols = columns(cfg);

        var dt = $(cfg.tableSel).DataTable({
            responsive: false,
            processing: true,
            ajax: {
                url: cfg.urls.list,
                data: function (d) { if (cfg.customerId) d.customer_id = cfg.customerId; return d; },
                dataSrc: function (json) {
                    if (!json.success) {
                        if (typeof cfg.onError === 'function') cfg.onError(json.message);
                        $(cfg.loadingEl).addClass('d-none');
                        return [];
                    }

                    var i = inst(cfg._id);
                    if (i) i.rows = json.data;

                    if (typeof cfg.onStats === 'function') {
                        cfg.onStats({
                            totalOutstanding: json.total_outstanding,
                            count: json.count,
                            overdueCount: json.data.filter(function (r) { return r.is_overdue; }).length
                        });
                    }

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
            // Server already orders by urgency (overdue/soonest-due first,
            // core/pos_credit_aging.php::posCreditOpenSales()) — no client
            // re-sort on load. Explicit empty array (not just omitted) so a
            // non-orderable column 0 never becomes DataTables' implicit
            // default sort target (see bms-expenses-table.js's own fix for
            // the same footgun).
            order: [],
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

    M.view = function (id, saleId) {
        var i = inst(id); if (!i) return;
        var cfg = i.cfg;
        var $modal = $('#creditViewModal');
        var $body = $('#creditViewBody');
        if (!$modal.length) return;

        $body.html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
        new bootstrap.Modal($modal[0]).show();

        $.getJSON(cfg.urls.detail, { sale_id: saleId }, function (res) {
            if (!res.success) {
                $body.html('<div class="alert alert-danger mb-0">' + esc(res.message) + '</div>');
                return;
            }
            var s = res.sale;
            var rowsHtml = res.payments.length
                ? res.payments.map(function (p) {
                    return '<tr>' +
                        '<td>' + fmtDate(p.created_at) + '</td>' +
                        '<td>' + money(p.amount) + '</td>' +
                        '<td>' + esc(p.payment_method || '-') + '</td>' +
                        '<td>' + esc(p.received_by_name || '-') + '</td>' +
                        '</tr>';
                }).join('')
                : '<tr><td colspan="4" class="text-center text-muted py-3">' + esc(cfg.i18n.noPaymentsYet) + '</td></tr>';

            $body.html(
                '<div class="row g-2 mb-3">' +
                '<div class="col-6"><small class="text-muted d-block">' + esc(cfg.i18n.saleAmount) + '</small><strong>' + money(s.grand_total) + '</strong></div>' +
                '<div class="col-6"><small class="text-muted d-block">' + esc(cfg.i18n.balanceDue) + '</small><strong class="text-danger">' + money(res.balance_due) + '</strong></div>' +
                '<div class="col-6"><small class="text-muted d-block">' + esc(cfg.i18n.saleDate) + '</small>' + fmtDate(s.sale_date) + '</div>' +
                '<div class="col-6"><small class="text-muted d-block">' + esc(cfg.i18n.dueDate) + '</small>' + fmtDate(s.due_date) + '</div>' +
                '</div>' +
                '<h6 class="mb-2">' + esc(cfg.i18n.paymentHistory) + '</h6>' +
                '<div class="table-responsive"><table class="table table-sm table-bordered mb-0">' +
                '<thead class="table-light"><tr><th>' + esc(cfg.i18n.dueDate).replace(':', '') + '</th><th>' + esc(cfg.i18n.amountHeader) + '</th><th>' + esc(cfg.i18n.methodHeader) + '</th><th>' + esc(cfg.i18n.byHeader) + '</th></tr></thead>' +
                '<tbody>' + rowsHtml + '</tbody></table></div>'
            );
        }).fail(function () {
            $body.html('<div class="alert alert-danger mb-0">' + esc(cfg.i18n.error) + '</div>');
        });
    };

    M.repay = function (id, saleId) {
        var i = inst(id); if (!i) return;
        var row = (i.rows || []).find(function (r) { return String(r.sale_id) === String(saleId); });
        if (!row) return;

        $('#repay_sale_id').val(saleId);
        $('#repay_customer_name').text(row.customer_name || '-');
        $('#repay_balance_due').text(money(row.balance_due));
        $('#repay_amount').val('').attr('max', row.balance_due);
        $('#repay_reference').val('');

        new bootstrap.Modal(document.getElementById('creditRepayModal')).show();
    };

    M.edit = function (id, saleId) {
        var i = inst(id); if (!i) return;
        var row = (i.rows || []).find(function (r) { return String(r.sale_id) === String(saleId); });
        if (!row) return;

        $('#edit_sale_id').val(saleId);
        $('#edit_due_date').val(row.due_date || '');
        $('#edit_notes').val('');

        new bootstrap.Modal(document.getElementById('creditEditModal')).show();
    };

    M.remove = function (id, saleId) {
        var i = inst(id); if (!i) return;
        var cfg = i.cfg;

        Swal.fire({
            icon: 'warning',
            title: cfg.i18n.confirmVoidTitle,
            text: cfg.i18n.confirmVoidText,
            input: 'text',
            inputPlaceholder: cfg.i18n.voidReasonPlaceholder,
            inputValidator: function (value) { if (!value) return cfg.i18n.voidReasonPlaceholder; },
            showCancelButton: true,
            confirmButtonText: cfg.i18n.yesVoid,
            cancelButtonText: cfg.i18n.cancel,
            confirmButtonColor: '#d33'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            $.post(cfg.urls.void, { sale_id: saleId, reason: result.value }, function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: cfg.i18n.success, text: res.message, timer: 2000, showConfirmButton: false });
                    M.reload(id);
                } else {
                    Swal.fire({ icon: 'error', title: cfg.i18n.error, text: res.message });
                }
            }, 'json').fail(function () {
                Swal.fire({ icon: 'error', title: cfg.i18n.error, text: cfg.i18n.error });
            });
        });
    };

    $(document).on('submit', '#creditRepayForm', function (e) {
        e.preventDefault();
        var $form = $(this);
        var id; for (var k in M._i) { if (M._i.hasOwnProperty(k)) { id = k; break; } }
        var i = inst(id); if (!i) return;
        var cfg = i.cfg;

        var btn = $form.find('[type="submit"]');
        var orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.post(cfg.urls.repay, $form.serialize(), function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: cfg.i18n.success, text: res.message, timer: 2000, showConfirmButton: false });
                bootstrap.Modal.getInstance(document.getElementById('creditRepayModal'))?.hide();
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

    $(document).on('submit', '#creditEditForm', function (e) {
        e.preventDefault();
        var $form = $(this);
        var id; for (var k in M._i) { if (M._i.hasOwnProperty(k)) { id = k; break; } }
        var i = inst(id); if (!i) return;
        var cfg = i.cfg;

        var btn = $form.find('[type="submit"]');
        var orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.post(cfg.urls.edit, $form.serialize(), function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: cfg.i18n.success, text: res.message, timer: 2000, showConfirmButton: false });
                bootstrap.Modal.getInstance(document.getElementById('creditEditModal'))?.hide();
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

    window.PosCreditAging = M;

})(window, jQuery);
