/**
 * Location cascade component — the ONE way forms offer
 * Country → Region → District → Ward → Street/Village.
 *
 * Backed by api/location/options.php (which reads the local reference
 * tables maintained by the OOP location engine). Behaviour is data-driven:
 *  - a country whose subdivisions exist locally (has_regions=1, Tanzania
 *    today) gets locked cascading dropdowns — nothing free-typed;
 *  - any other country swaps the four lower fields to plain text inputs.
 * When a new country's regions are imported later, it becomes a dropdown
 * country automatically — no changes here.
 *
 * Selected option VALUES are the location NAMES, so forms keep posting
 * the same text fields (country/state/city/ward/village) they always did.
 *
 * Usage (any page, add & edit modals alike):
 *   initLocationCascade({
 *       endpoint: BMS_LOCATION_OPTIONS_URL,          // buildUrl('api/location/options.php')
 *       fields: { country: '#country', region: '#state', district: '#city',
 *                 ward: '#ward', village: '#village' },
 *       dropdownParent: '#addModal',                 // optional, for Select2 in modals
 *       values: { country: 'Tanzania', region: 'Dar es Salaam', ... }  // optional prefill (edit)
 *   });
 */
(function (window, $) {
    'use strict';

    var LEVELS = ['region', 'district', 'ward', 'village'];
    var LEVEL_API = { region: 'regions', district: 'districts', ward: 'wards', village: 'villages' };
    var PLACEHOLDERS = {
        country: 'Select Country',
        region: 'Select Region',
        district: 'Select District',
        ward: 'Select Ward',
        village: 'Select Street/Village'
    };

    function initLocationCascade(cfg) {
        if (!cfg || !cfg.endpoint || !cfg.fields || !cfg.fields.country) {
            throw new Error('initLocationCascade: endpoint and fields.country are required');
        }
        var values = cfg.values || {};
        var state = { ids: {} }; // numeric ids per level, drives child loads

        // ── element helpers: swap a field between <select> and <input> in place ──
        function el(key) { return $(cfg.fields[key]); }

        function ensureTag(key, tag) {
            var $cur = el(key);
            if (!$cur.length || $cur.prop('tagName').toLowerCase() === tag) return $cur;
            var $next = $('<' + tag + '>')
                .attr('id', $cur.attr('id') || null)
                .attr('name', $cur.attr('name') || null)
                .addClass(tag === 'select' ? 'form-select' : 'form-control');
            if (tag === 'input') {
                $next.attr('type', 'text').attr('placeholder', PLACEHOLDERS[key].replace('Select ', ''));
            }
            if ($cur.data('select2')) $cur.select2('destroy');
            $cur.replaceWith($next);
            return $next;
        }

        function select2ify($sel) {
            if ($.fn.select2 && !$sel.hasClass('select2-hidden-accessible')) {
                var opts = { theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: $sel.find('option').first().text() };
                if (cfg.dropdownParent) opts.dropdownParent = $(cfg.dropdownParent);
                $sel.select2(opts);
            }
        }

        function resetBelow(key) {
            var start = key === 'country' ? 0 : LEVELS.indexOf(key) + 1;
            for (var i = start; i < LEVELS.length; i++) {
                var k = LEVELS[i];
                state.ids[k] = null;
                var $f = el(k);
                if ($f.length && $f.prop('tagName').toLowerCase() === 'select') {
                    $f.html('<option value=""></option>').trigger('change.select2');
                }
            }
        }

        function fetchOptions(level, parentId, done) {
            $.getJSON(cfg.endpoint, { level: LEVEL_API[level] || level, parent_id: parentId || '' })
                .done(function (res) { done(res && res.success ? res.results : []); })
                .fail(function () { done([]); });
        }

        function populate(key, results, preselectName) {
            var $sel = ensureTag(key, 'select');
            var html = '<option value=""></option>';
            var matchId = null, matchName = null;
            var want = (preselectName || '').trim().toLowerCase();
            results.forEach(function (r) {
                var isMatch = want && r.text.trim().toLowerCase() === want;
                if (isMatch) { matchId = r.id; matchName = r.text; }
                html += '<option value="' + $('<i>').text(r.text).html() + '" data-id="' + r.id + '"' +
                        (r.has_regions !== undefined ? ' data-has-regions="' + r.has_regions + '"' : '') +
                        (isMatch ? ' selected' : '') + '>' + $('<i>').text(r.text).html() + '</option>';
            });
            $sel.html(html);
            select2ify($sel);
            if (matchId !== null) $sel.val(matchName).trigger('change.select2');
            return matchId;
        }

        // ── cascade wiring ──────────────────────────────────────────────
        function selectedId(key) {
            var opt = el(key).find('option:selected');
            return opt.length && opt.data('id') !== undefined ? parseInt(opt.data('id'), 10) : null;
        }

        function onCountryChange(prefill) {
            var $c = el('country');
            var opt = $c.find('option:selected');
            var hasRegions = parseInt(opt.data('has-regions') || '0', 10) === 1;
            resetBelow('country');
            if (!$c.val()) return;

            if (hasRegions) {
                // Defined country (Tanzania): locked dropdown cascade.
                LEVELS.forEach(function (k) { if (cfg.fields[k]) ensureTag(k, 'select'); });
                state.ids.country = selectedId('country');
                if (cfg.fields.region) {
                    fetchOptions('region', state.ids.country, function (results) {
                        var id = populate('region', results, prefill ? values.region : null);
                        if (id !== null) { state.ids.region = id; onLevelChange('region', prefill); }
                    });
                }
            } else {
                // Undefined country: free-text entry for all lower levels.
                LEVELS.forEach(function (k) {
                    if (!cfg.fields[k]) return;
                    var $f = ensureTag(k, 'input');
                    if (prefill && values[k]) $f.val(values[k]);
                });
            }
        }

        function onLevelChange(key, prefill) {
            var idx = LEVELS.indexOf(key);
            var childKey = LEVELS[idx + 1];
            resetBelow(key);
            state.ids[key] = selectedId(key);
            if (!childKey || !cfg.fields[childKey] || state.ids[key] === null) return;
            fetchOptions(childKey, state.ids[key], function (results) {
                var id = populate(childKey, results, prefill ? values[childKey] : null);
                if (id !== null) { state.ids[childKey] = id; onLevelChange(childKey, prefill); }
            });
        }

        // ── boot: load countries, bind events, apply prefill ───────────
        var $country = ensureTag('country', 'select');
        fetchOptions('countries', null, function (results) {
            populate('country', results, values.country || 'Tanzania');
            onCountryChange(true);
        });

        $(document)
            .on('change', cfg.fields.country, function () { onCountryChange(false); })
            .on('change', cfg.fields.region, function () {
                if (el('region').prop('tagName').toLowerCase() === 'select') onLevelChange('region', false);
            })
            .on('change', cfg.fields.district, function () {
                if (el('district').prop('tagName').toLowerCase() === 'select') onLevelChange('district', false);
            })
            .on('change', cfg.fields.ward, function () {
                if (el('ward').prop('tagName').toLowerCase() === 'select') onLevelChange('ward', false);
            });

        return {
            /** Re-apply a fresh set of values (e.g. loading a record into an edit modal). */
            setValues: function (newValues) {
                values = newValues || {};
                var want = (values.country || 'Tanzania').trim().toLowerCase();
                el('country').find('option').each(function () {
                    if ($(this).text().trim().toLowerCase() === want) {
                        el('country').val($(this).val()).trigger('change.select2');
                    }
                });
                onCountryChange(true);
            }
        };
    }

    window.initLocationCascade = initLocationCascade;
})(window, jQuery);
