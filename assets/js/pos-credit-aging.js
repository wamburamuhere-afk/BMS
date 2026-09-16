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

    function statusBadge(cfg, row) {
        if (row.is_overdue) {
            return '<span class="badge bg-danger">' +
                sprintf(cfg.i18n.overdueBy, row.days_overdue) + '</span>';
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

    function sprintf(fmt, val) { return String(fmt).replace('%d', val); }

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

    function renderRow(cfg, row) {
        return '<tr>' +
            '<td><strong>' + esc(row.customer_name || '-') + '</strong></td>' +
            '<td>' + esc(row.customer_phone || '-') + '</td>' +
            '<td class="text-end"><strong class="text-danger">' + money(row.balance_due) + '</strong>' + paidBadge(cfg, row) + '</td>' +
            '<td>' + fmtDate(row.sale_date) + '</td>' +
            '<td>' + fmtDate(row.due_date) + '</td>' +
            '<td>' + statusBadge(cfg, row) + '</td>' +
            '<td class="text-center">' + rowActions(cfg, row) + '</td>' +
            '</tr>';
    }

    function load(id) {
        var i = inst(id); if (!i) return;
        var cfg = i.cfg;

        $(cfg.loadingEl).removeClass('d-none');
        $(cfg.listContainer).addClass('d-none');
        $(cfg.emptyEl).addClass('d-none');

        var params = {};
        if (cfg.customerId) params.customer_id = cfg.customerId;

        $.getJSON(cfg.urls.list, params, function (res) {
            $(cfg.loadingEl).addClass('d-none');
            if (!res.success) {
                if (typeof cfg.onError === 'function') cfg.onError(res.message);
                return;
            }

            i.rows = res.data;

            if (typeof cfg.onStats === 'function') {
                cfg.onStats({
                    totalOutstanding: res.total_outstanding,
                    count: res.count,
                    overdueCount: res.data.filter(function (r) { return r.is_overdue; }).length
                });
            }

            if (!res.data.length) {
                $(cfg.emptyEl).removeClass('d-none');
                return;
            }

            $(cfg.listContainer).removeClass('d-none');
            var body = $(cfg.bodyEl).empty();
            res.data.forEach(function (row) { body.append(renderRow(cfg, row)); });
        }).fail(function () {
            $(cfg.loadingEl).addClass('d-none');
            if (typeof cfg.onError === 'function') cfg.onError(cfg.i18n.error);
        });
    }

    /* ── Public API ──────────────────────────────────────────────────── */

    M.init = function (cfg) {
        var id = cfg.id || 'default';
        cfg._id = id;
        M._i[id] = { cfg: cfg, rows: [] };
        load(id);
        return id;
    };

    M.reload = function (id) { load(id); };

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
                '<thead class="table-light"><tr><th>' + esc(cfg.i18n.dueDate).replace(':', '') + '</th><th>Amount</th><th>Method</th><th>By</th></tr></thead>' +
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
                    load(id);
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
                load(id);
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
                load(id);
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
