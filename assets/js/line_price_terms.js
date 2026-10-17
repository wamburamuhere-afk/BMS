/**
 * Price terms on document lines (Quotation / Sales Order / Invoice).
 * customer_subscription_plan.md Phase 2 — server side: core/service_price_terms.php.
 *
 * When a line's service has price terms, a "Term" select appears under the item
 * name. Choosing a term sets the line price and a "Term: <label>" line in the
 * description (so existing prints show it). The server re-checks the term
 * against the database; nothing here is trusted.
 *
 *   BmsLineTerms.attach($row, product, {priceSel, descSel, onChange, savedTermId, keepPrice, selectName})
 *   BmsLineTerms.value($row)         → term id or ''
 *   BmsLineTerms.missing($container) → rows that have terms but none chosen
 */
(function (window, $) {
    'use strict';
    if (!$) return;

    const TERM_RE = /(^|\r?\n)Term: [^\r\n]*/;

    function setDescription($desc, label) {
        if (!$desc || !$desc.length) return;
        let d = String($desc.val() || '').replace(TERM_RE, '').trim();
        if (label) d = (d ? d + '\n' : '') + 'Term: ' + label;
        $desc.val(d);
    }

    function cfgUrl() {
        return (window.BMS_LINE_TERMS_URL || '') + '';
    }

    function detach($row) {
        const $box = $row.find('.lpt-box');
        const $sel = $box.find('select');
        if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
        $box.remove();
        $row.removeData('lptTerms');
    }

    function render($row, terms, opts) {
        detach($row);
        if (!terms || !terms.length) return;
        const $name = $row.find(opts.nameSel || '.item-name').first();
        const $box = $('<div class="lpt-box mt-1"></div>');
        const $sel = $('<select class="form-select form-select-sm item-term"></select>');
        if (opts.selectName) $sel.attr('name', opts.selectName);   // form-posted documents (invoices)
        $sel.append(new Option(window.BMS_LINE_TERMS_I18N ? window.BMS_LINE_TERMS_I18N.choose : 'Choose term…', ''));
        terms.forEach(t => {
            const o = new Option(t.term_label + ' — ' + Number(t.price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}), String(t.term_id));
            $sel.append(o);
        });
        $box.append($sel);
        const $anchor = $name.closest('.input-group').length ? $name.closest('.input-group') : $name;
        $anchor.after($box);
        $row.data('lptTerms', terms);

        const $price = $row.find(opts.priceSel || '.item-price').first();
        const $desc = $row.find(opts.descSel || '.item-description').first();

        let selected = opts.savedTermId ? String(opts.savedTermId) : '';
        if (selected && !terms.some(t => String(t.term_id) === selected)) {
            // A saved term that is no longer offered (made inactive) stays selectable on this document.
            $sel.append(new Option(opts.savedTermLabel || ('#' + selected), selected));
        }
        if (!selected) {
            const def = terms.find(t => t.is_default) || terms[0];
            selected = String(def.term_id);
        }
        $sel.val(selected);

        const apply = function (fromUser) {
            const id = $sel.val();
            const t = terms.find(x => String(x.term_id) === String(id));
            if (t && (fromUser || !opts.keepPrice)) $price.val(t.price);
            setDescription($desc, t ? t.term_label : (id ? (opts.savedTermLabel || '') : ''));
            if (typeof opts.onChange === 'function') opts.onChange($row);
        };
        $sel.on('change', () => apply(true));
        apply(false);

        if ($.fn.select2) {
            $sel.select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: Infinity,
                           dropdownParent: $(document.body) });
        }
    }

    window.BmsLineTerms = {
        /** product: an item from api/account/get_products.php, or a saved line ({product_id, price_term_id…}). */
        attach: function ($row, product, opts) {
            opts = opts || {};
            if (!product || !product.product_id) { detach($row); return; }
            if (Array.isArray(product.price_terms)) {
                render($row, product.price_terms, opts);
                return;
            }
            // A saved line: fetch the service's terms only if the line recorded one.
            if (!opts.savedTermId) { detach($row); return; }
            $.getJSON(cfgUrl(), { product_id: product.product_id })
                .done(res => { if (res && res.success) render($row, res.data || [], Object.assign({ keepPrice: true }, opts)); })
                .fail(() => {});
        },
        detach: detach,
        value: function ($row) {
            const $s = $row.find('.item-term');
            return $s.length ? ($s.val() || '') : '';
        },
        missing: function ($container) {
            return $container.find('.item-term').filter(function () { return !$(this).val(); }).closest('tr');
        },
        stripTermLine: function (s) { return String(s || '').replace(TERM_RE, '').trim(); }
    };
})(window, window.jQuery);
