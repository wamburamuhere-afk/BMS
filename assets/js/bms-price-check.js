/**
 * assets/js/bms-price-check.js
 *
 * Price sanity checks for every form that takes prices (POS restock, product
 * create/edit, quick add, batch price correction). Fields are matched by name
 * inside the same form:
 *   buying  — name="buying_price" | "cost_price" | "unit_cost" (typed, not read-only)
 *   retail  — name="selling_price"
 *   wholesale — name="wholesale_price"
 *
 * Warns when, after a price is entered:
 *   - the retail price is below the buying price (selling at a loss),
 *   - the wholesale price is below the buying price,
 *   - the wholesale price is above the retail price.
 * It never blocks: "Yes" keeps the figures and the user carries on, "No" puts
 * them back in the field they just changed. Empty or zero fields are left
 * alone (an empty wholesale still means retail on the server). The same set of
 * figures is only asked about once.
 *
 * Labels come from window.BMS_PRICE_CHECK_T (set in header.php via t()).
 */
(function ($) {
    if (!$ || window.__bmsPriceCheck) return;
    window.__bmsPriceCheck = true;

    var T = $.extend({
        title:        'Wholesale price is higher than retail',
        text:         'Wholesale price ({w}) is higher than retail price ({r}). Is this correct?',
        buyTitle:     'Selling price is below the buying price',
        buyRetail:    'Retail price ({r}) is lower than the buying price ({b}) — you would sell at a loss. Is this correct?',
        buyWholesale: 'Wholesale price ({w}) is lower than the buying price ({b}) — you would sell at a loss. Is this correct?',
        multiTitle:   'Please check these prices',
        yes:          'Yes, keep it',
        no:           'No, change it'
    }, window.BMS_PRICE_CHECK_T || {});

    var BUY  = 'input[name="buying_price"], input[name="cost_price"], input[name="unit_cost"]';
    var ALL  = BUY + ', input[name="wholesale_price"], input[name="selling_price"]';

    function num(v) {
        var n = parseFloat(String(v == null ? '' : v).replace(/,/g, ''));
        return isNaN(n) ? null : n;
    }

    function fmt(n) {
        return Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // The field of a kind that belongs with the one just changed: same form,
    // enabled, typed by a person (not read-only), preferring a visible one
    // (some pages render a field per mode).
    function partner($el, selector) {
        var $scope = $el.closest('form');
        if (!$scope.length) $scope = $el.closest('.modal, .card, body');
        var $all = $scope.find(selector).not(':disabled').not('[readonly]').not('[type="hidden"]');
        var $vis = $all.filter(':visible');
        return ($vis.length ? $vis : $all).first();
    }

    $(document).on('change', ALL, function () {
        var $changed = $(this);
        if ($changed.is('[readonly]')) return;

        var $b = $changed.is(BUY) ? $changed : partner($changed, BUY);
        var $w = $changed.attr('name') === 'wholesale_price' ? $changed : partner($changed, 'input[name="wholesale_price"]');
        var $r = $changed.attr('name') === 'selling_price'   ? $changed : partner($changed, 'input[name="selling_price"]');

        var b = $b.length ? num($b.val()) : null;
        var w = $w.length ? num($w.val()) : null;
        var r = $r.length ? num($r.val()) : null;
        var pos = function (v) { return v !== null && v > 0; };

        var issues = [];
        if (pos(b) && pos(r) && r < b) issues.push({ title: T.buyTitle, text: T.buyRetail });
        if (pos(b) && pos(w) && w < b) issues.push({ title: T.buyTitle, text: T.buyWholesale });
        if (pos(w) && pos(r) && w > r) issues.push({ title: T.title, text: T.text });
        if (!issues.length) return;

        // Remembered on the form, so "Yes" isn't asked again for the same figures.
        var $form = $changed.closest('form');
        var $holder = $form.length ? $form : $changed;
        var key = [b, w, r].join('|');
        if ($holder.data('bmsPriceOk') === key) return;
        if (typeof Swal === 'undefined') return;

        var fill = function (s) {
            return s.replace('{b}', fmt(b)).replace('{w}', fmt(w)).replace('{r}', fmt(r));
        };
        var opts = {
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: T.yes,
            cancelButtonText: T.no,
            reverseButtons: true,
            // Keep focus handling ours so the field we point at actually gets it.
            returnFocus: false
        };
        if (issues.length === 1) {
            opts.title = issues[0].title;
            opts.text  = fill(issues[0].text);
        } else {
            opts.title = T.multiTitle;
            opts.html  = '<ul style="text-align:left;margin:0;padding-left:1.2em">'
                       + issues.map(function (i) { return '<li style="margin-bottom:6px">' + esc(fill(i.text)) + '</li>'; }).join('')
                       + '</ul>';
        }

        Swal.fire(opts).then(function (res) {
            if (res.isConfirmed) {
                $holder.data('bmsPriceOk', key);
            } else {
                $changed.trigger('focus').trigger('select');
            }
        });
    });
})(window.jQuery);
