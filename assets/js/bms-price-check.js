/**
 * assets/js/bms-price-check.js
 *
 * Wholesale-above-retail check for every form that takes both prices (POS
 * restock, product create/edit, quick add, batch price correction). Fields are
 * matched by name inside the same form: name="wholesale_price" and
 * name="selling_price" (retail).
 *
 * When a price is entered and wholesale ends up higher than retail, the user is
 * asked to confirm. It never blocks: "Yes" keeps the figure and they carry on,
 * "No" puts them back in the wholesale field. An empty wholesale is left alone
 * (the server keeps treating it as retail, as before). The same pair of figures
 * is only asked about once.
 *
 * Labels come from window.BMS_PRICE_CHECK_T (set in header.php via t()).
 */
(function ($) {
    if (!$ || window.__bmsPriceCheck) return;
    window.__bmsPriceCheck = true;

    var T = $.extend({
        title:   'Wholesale price is higher than retail',
        text:    'Wholesale price ({w}) is higher than retail price ({r}). Is this correct?',
        yes:     'Yes, keep it',
        no:      'No, change it'
    }, window.BMS_PRICE_CHECK_T || {});

    function num(v) {
        var n = parseFloat(String(v == null ? '' : v).replace(/,/g, ''));
        return isNaN(n) ? null : n;
    }

    function fmt(n) {
        return Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    // The retail field that belongs with this wholesale field: same form,
    // enabled, preferring a visible one (some pages render a field per mode).
    function partner($el, name) {
        var $scope = $el.closest('form');
        if (!$scope.length) $scope = $el.closest('.modal, .card, body');
        var $all = $scope.find('input[name="' + name + '"]').not(':disabled').not('[type="hidden"]');
        var $vis = $all.filter(':visible');
        return ($vis.length ? $vis : $all).first();
    }

    $(document).on('change', 'input[name="wholesale_price"], input[name="selling_price"]', function () {
        var $changed = $(this);
        var $w = $changed.attr('name') === 'wholesale_price' ? $changed : partner($changed, 'wholesale_price');
        var $r = $changed.attr('name') === 'selling_price'   ? $changed : partner($changed, 'selling_price');
        if (!$w.length || !$r.length) return;

        var w = num($w.val()), r = num($r.val());
        if (w === null || r === null || w <= 0 || r <= 0 || w <= r) return;

        var key = w + '|' + r;
        if ($w.data('bmsPriceOk') === key) return;
        if (typeof Swal === 'undefined') return;

        Swal.fire({
            icon: 'warning',
            title: T.title,
            text: T.text.replace('{w}', fmt(w)).replace('{r}', fmt(r)),
            showCancelButton: true,
            confirmButtonText: T.yes,
            cancelButtonText: T.no,
            reverseButtons: true,
            // Keep focus handling ours so the field we point at actually gets it.
            returnFocus: false
        }).then(function (res) {
            if (res.isConfirmed) {
                $w.data('bmsPriceOk', key);
            } else {
                $w.trigger('focus').trigger('select');
            }
        });
    });
})(window.jQuery);
