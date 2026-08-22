/**
 * bms-table-utils.js
 * Shared helpers for the reusable BMS list-table modules in assets/js/tables/.
 *
 * These tables are rendered by ONE code path and hosted in two places:
 *   1. the module's own list page (e.g. grn.php)          — unfiltered
 *   2. a tab inside a parent record (e.g. supplier_details) — filtered to that record
 *
 * Because a parent page can host several of these tables at once, everything is
 * namespaced under window.BMSTbl instead of leaking globals like formatDate().
 */
(function (window, $) {
    'use strict';

    if (window.BMSTbl) return;   // already loaded

    var BMSTbl = {};

    /** HTML-escape a value for use inside a template literal. Mirrors safe_output(). */
    BMSTbl.esc = function (str) {
        if (str === 0 || str === '0') return '0';
        if (str === null || str === undefined || str === '') return '';
        return $('<div>').text(str).html();
    };

    /** 12 Aug 2026 */
    BMSTbl.date = function (value) {
        if (!value) return '';
        var d = new Date(value);
        if (isNaN(d.getTime())) return BMSTbl.esc(value);
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    /** TZS 1,250,000.00 */
    BMSTbl.money = function (amount, currency) {
        var num = parseFloat(amount) || 0;
        return (currency || 'TZS') + ' ' + num.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    /**
     * Build a gear dropdown from a list of item HTML strings.
     * Falsy entries are skipped so callers can inline conditionals.
     */
    BMSTbl.actions = function (items) {
        var body = (items || []).filter(Boolean).join('');
        if (!body) return '';
        return '<div class="dropdown">' +
               '<button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" ' +
               'data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false">' +
               '<i class="bi bi-gear-fill"></i></button>' +
               '<ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">' + body + '</ul></div>';
    };

    /** One dropdown row. `cls` adds e.g. 'text-danger'. */
    BMSTbl.item = function (label, icon, onclickOrHref, cls, isHref) {
        var attr = isHref ? 'href="' + onclickOrHref + '"'
                          : 'href="#" onclick="' + onclickOrHref + ';return false;"';
        return '<li><a class="dropdown-item py-2 rounded ' + (cls || '') + '" ' + attr + '>' +
               '<i class="bi ' + icon + ' me-2"></i>' + label + '</a></li>';
    };

    BMSTbl.divider = function () { return '<li><hr class="dropdown-divider"></li>'; };

    /**
     * Standard SweetAlert confirm → POST → reload-the-table cycle used by every
     * status transition and delete across these tables.
     */
    BMSTbl.confirmPost = function (opts) {
        window.Swal.fire({
            title: opts.title,
            html: opts.text,
            icon: opts.icon || 'question',
            showCancelButton: true,
            confirmButtonColor: opts.color || '#0d6efd',
            confirmButtonText: opts.confirmText || 'Yes, continue'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            $.post(opts.url, opts.data, function (res) {
                if (res && res.success) {
                    window.Swal.fire({
                        icon: 'success',
                        title: opts.successTitle || 'Done!',
                        text: res.message,
                        timer: 1800,
                        showConfirmButton: false
                    });
                    if (typeof opts.onDone === 'function') opts.onDone();
                } else {
                    window.Swal.fire('Error', (res && res.message) || 'Request failed.', 'error');
                }
            }, 'json').fail(function () {
                window.Swal.fire('Error', 'Server error. Please try again.', 'error');
            });
        });
    };

    /**
     * A table living inside a Bootstrap tab-pane is display:none at init time, so
     * DataTables measures every column as zero-width. Call this on shown.bs.tab.
     */
    BMSTbl.adjust = function (dt) {
        if (dt) { try { dt.columns.adjust().draw(false); } catch (e) {} }
    };

    /**
     * Hold a table's init until its tab is actually opened.
     *
     * A record page hosting seven of these tables would otherwise fire seven
     * server-side AJAX requests on page load for panes nobody has looked at, and
     * every hidden pane would measure its columns at zero width. This runs the
     * init on the first shown.bs.tab for the pane (or immediately if that pane is
     * already the visible one), then re-measures on every later show.
     *
     * @param {string}   paneSel  e.g. '#pane-grn'
     * @param {function} initFn   returns the DataTable API instance
     */
    BMSTbl.defer = function (paneSel, initFn) {
        var started = false;
        var dt = null;

        function start() {
            if (started) return;
            started = true;
            dt = initFn();
        }

        // The tab button is the element Bootstrap fires shown.bs.tab on.
        var $btn = $('[data-bs-target="' + paneSel + '"]');

        $btn.on('shown.bs.tab', function () {
            if (!started) { start(); } else { BMSTbl.adjust(dt); }
        });

        // Already the open pane (e.g. it is the default tab) — init right away.
        if ($(paneSel).hasClass('active') || $(paneSel).is(':visible')) {
            start();
        }
    };

    window.BMSTbl = BMSTbl;

})(window, jQuery);
