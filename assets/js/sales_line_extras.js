/**
 * Quotation / Sales Order / Invoice lines: discount as % or a fixed amount, and
 * the line description a user can see and change on service lines.
 * Server side: core/sales_line_discount.php (salesLineMath re-computes every line;
 * nothing here is trusted).
 *
 *   BmsLineDiscount.enhance($row, {type, typeName, valueName})  adds the %/amount switch
 *   BmsLineDiscount.amount($row, gross)  → the money discount of that line (2 decimals)
 *   BmsLineDiscount.payload($row)        → {discount_type, discount_value, discount_percent}
 *   BmsLineDesc.show($row, isService)    → description box visible on service lines only
 *
 * window.BMS_SALES_DISCOUNT = {type: 'percent'|'amount', allowSwitch: bool, currency: 'TZS'}
 * comes from includes/sales_line_extras_js.php.
 */
(function (window, $) {
    'use strict';
    if (!$) return;

    const cfg = function () { return window.BMS_SALES_DISCOUNT || { type: 'percent', allowSwitch: true, currency: 'TZS' }; };
    // Rounds like PHP's round($n, 2) (it pre-rounds to 15 digits): 3333.335 → 3333.34, as the server saves it.
    const round2 = n => Math.round(parseFloat(((Number(n) || 0) * 100).toPrecision(15))) / 100;

    function currencyLabel() {
        const c = $('#currency').val();
        return c || cfg().currency || 'TZS';
    }

    function applyType($row, type) {
        const $in = $row.find('.item-discount').first();
        if (type === 'amount') {
            $in.removeAttr('max').attr('step', '0.01').attr('title', 'Discount amount for this line');
        } else {
            $in.attr('max', '100').attr('step', '0.01').attr('title', 'Discount % for this line');
        }
    }

    window.BmsLineDiscount = {
        round2: round2,
        /**
         * opts.type       the line's saved type (default: the System Settings choice)
         * opts.typeName   form field name for the type (form-posted pages, e.g. invoices)
         * opts.valueName  form field name for the value (replaces the input's discount_percent name)
         */
        enhance: function ($row, opts) {
            opts = opts || {};
            const $in = $row.find('.item-discount').first();
            if (!$in.length || $row.find('.item-discount-type').length) return;
            const type = (opts.type === 'amount' || opts.type === 'percent') ? opts.type : cfg().type;
            const $sel = $('<select class="form-select form-select-sm item-discount-type" style="flex:0 0 5rem;width:5rem;min-width:5rem;"></select>')
                .append(new Option('%', 'percent'))
                .append(new Option(currencyLabel(), 'amount'))
                .val(type);
            if (opts.typeName) $sel.attr('name', opts.typeName);
            if (opts.valueName) $in.attr('name', opts.valueName);
            // A saved line keeps its own type even when switching is turned off.
            if (!cfg().allowSwitch) $sel.addClass('pe-none bg-light').attr('tabindex', '-1');

            const $group = $in.closest('.input-group');
            if ($group.length) {
                $group.find('.input-group-text').filter(function () { return $(this).text().trim() === '%'; }).remove();
                $in.after($sel);
            } else {
                $in.wrap('<div class="input-group input-group-sm flex-nowrap"></div>').after($sel);
            }
            if ($in.prop('readonly')) $sel.addClass('pe-none bg-light').attr('tabindex', '-1');
            applyType($row, type);
            $sel.on('change', function () {
                applyType($row, $sel.val());
                $in.trigger('input').trigger('change');
            });
        },
        type: function ($row) {
            const $s = $row.find('.item-discount-type');
            return $s.length ? ($s.val() === 'amount' ? 'amount' : 'percent') : 'percent';
        },
        /** The line's discount in money. A % is rounded to cents, the same way the server does. */
        amount: function ($row, gross) {
            const v = Math.max(parseFloat($row.find('.item-discount').first().val()) || 0, 0);
            if (this.type($row) === 'amount') return round2(Math.min(v, Math.max(gross, 0)));
            return round2(gross * Math.min(v, 100) / 100);
        },
        payload: function ($row) {
            const v = $row.find('.item-discount').first().val() || 0;
            const type = this.type($row);
            return { discount_type: type, discount_value: v, discount_percent: type === 'percent' ? v : 0 };
        },
        /** Too much off a line: the amount is more than the line itself. */
        overLimit: function ($row, gross) {
            const v = parseFloat($row.find('.item-discount').first().val()) || 0;
            return this.type($row) === 'amount' ? v > round2(gross) + 0.004 : v > 100;
        }
    };

    window.BmsLineDesc = {
        /** The description box shows on service lines; product lines keep their hidden description. */
        show: function ($row, isService) {
            const $d = $row.find('.item-description').first();
            if (!$d.is('textarea')) return;
            $d.toggleClass('d-none', !isService);
            if (isService) autoGrow($d[0]);
        }
    };

    function autoGrow(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight + 2, 220) + 'px';
    }
    $(document).on('input', 'textarea.item-description', function () { autoGrow(this); });
})(window, window.jQuery);
