/**
 * Price terms on document lines (Quotation / Sales Order / Invoice).
 * customer_subscription_plan.md Phase 2 — server side: core/service_price_terms.php.
 *
 * When a line's service has price terms, a "Term" select appears under the item
 * name. Choosing a term sets the line price and a "Term: <label>" line in the
 * description (so existing prints show it). The server re-checks the term
 * against the database; nothing here is trusted.
 *
 * A service that sells a Superadmin plan offers plan options ("p12") and extras
 * ("a3-12") grouped by plan (service_plans_plan.md Phase 5). An extra follows
 * its plan's term: changing the plan line's term re-points the extra lines.
 *
 *   BmsLineTerms.attach($row, product, {priceSel, descSel, onChange, savedTermId, keepPrice, selectName})
 *   BmsLineTerms.value($row)         → term id or ''
 *   BmsLineTerms.missing($container) → Superadmin-plan rows with no plan/term chosen (these block Save)
 *
 * A service's own terms (an outside system invoiced by hand) never block Save: the
 * term is a suggestion, the price stays editable, and an empty term only shows a hint.
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
        // Platform plans come grouped ("Pro", "Pro — extras"); local terms have no group.
        const groups = {};
        terms.forEach(t => {
            const o = new Option((t.display_label || t.term_label) + ' — ' + Number(t.price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}), String(t.term_id));
            if (!t.group) { $sel.append(o); return; }
            if (!groups[t.group]) groups[t.group] = $('<optgroup></optgroup>').attr('label', t.group).appendTo($sel);
            groups[t.group].append(o);
        });
        $box.append($sel);
        const isPlatform = terms.some(t => t.kind === 'plan' || t.kind === 'addon');
        const $hint = $('<div class="lpt-hint form-text text-warning d-none"></div>')
            .text(window.BMS_LINE_TERMS_I18N && window.BMS_LINE_TERMS_I18N.no_term
                  ? window.BMS_LINE_TERMS_I18N.no_term : 'No term chosen — the line is saved at the price typed.');
        if (!isPlatform) $box.append($hint);
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
            // A second line of the same plan service defaults to an extra for the plan already chosen.
            const pp = currentPlanPrice($row);
            const extra = pp ? terms.find(t => t.kind === 'addon' && t.plan_price_id === pp) : null;
            const def = extra || terms.find(t => t.is_default && t.kind !== 'addon') || terms.find(t => t.kind !== 'addon') || terms[0];
            selected = String(def.term_id);
        }
        $sel.val(selected);

        const apply = function (fromUser) {
            const id = $sel.val();
            const t = terms.find(x => String(x.term_id) === String(id));
            if (t && (fromUser || !opts.keepPrice)) $price.val(t.price);
            setDescription($desc, t ? (t.display_label || t.term_label) : (id ? (opts.savedTermLabel || '') : ''));
            $hint.toggleClass('d-none', !!id);
            if (typeof opts.onChange === 'function') opts.onChange($row);
            if (fromUser && t && t.kind === 'plan') repointExtras($row, t.plan_price_id);
        };
        $sel.on('change', () => apply(true));
        apply(false);

        if ($.fn.select2) {
            $sel.select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: Infinity,
                           dropdownParent: $(document.body) });
        }
    }

    function selectedTerm($sel) {
        const terms = $sel.closest('tr').data('lptTerms') || [];
        return terms.find(x => String(x.term_id) === String($sel.val())) || null;
    }

    /** The plan price chosen on another line of the same document, or null. */
    function currentPlanPrice($row) {
        let pp = null;
        $row.closest('tbody').find('.item-term').each(function () {
            if ($(this).closest('tr')[0] === $row[0]) return;
            const t = selectedTerm($(this));
            if (t && t.kind === 'plan') pp = t.plan_price_id;
        });
        return pp;
    }

    /** The plan's term changed: its extras follow it (same extra, new term) — they are locked to the plan's term. */
    function repointExtras($row, planPriceId) {
        $row.closest('tbody').find('.item-term').each(function () {
            const $s = $(this), t = selectedTerm($s);
            if (!t || t.kind !== 'addon' || t.plan_price_id === planPriceId) return;
            const terms = $s.closest('tr').data('lptTerms') || [];
            const next = terms.find(x => x.kind === 'addon' && x.addon_key === t.addon_key && x.plan_price_id === planPriceId);
            $s.val(next ? String(next.term_id) : '').trigger('change');
        });
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
            // A saved platform-plan line: its "term" is the plan/extra token (core/service_price_terms.php).
            if (!opts.savedTermId && Number(product.plan_price_id) > 0) {
                opts = Object.assign({}, opts, {
                    savedTermId: Number(product.plan_addon_id) > 0 ? ('a' + product.plan_addon_id + '-' + product.plan_price_id) : ('p' + product.plan_price_id),
                    savedTermLabel: product.price_term_label || '', keepPrice: true,
                });
            }
            // A saved line: fetch the service's terms only if the line recorded one.
            if (!opts.savedTermId) { detach($row); return; }
            $.getJSON(cfgUrl(), { product_id: product.product_id })
                .done(res => { if (res && res.success) render($row, res.line_terms || res.data || [], Object.assign({ keepPrice: true }, opts)); })
                .fail(() => {});
        },
        detach: detach,
        value: function ($row) {
            const $s = $row.find('.item-term');
            return $s.length ? ($s.val() || '') : '';
        },
        missing: function ($container) {
            return $container.find('.item-term').filter(function () {
                if ($(this).val()) return false;
                const terms = $(this).closest('tr').data('lptTerms') || [];
                return terms.some(t => t.kind === 'plan' || t.kind === 'addon');
            }).closest('tr');
        },
        stripTermLine: function (s) { return String(s || '').replace(TERM_RE, '').trim(); }
    };
})(window, window.jQuery);
