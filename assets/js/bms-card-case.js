/**
 * bms-card-case.js — makes every summary ("stat") card label follow the company's
 * Text Display Case setting (Settings → General → Display Text Format).
 *
 * Why: the card labels were typed in the page source (and many forced to CAPITALS by a
 * `text-uppercase` class or a CSS rule), so they ignored the setting that data already
 * respects. Doing it once, here, covers every page that uses these card styles instead of
 * editing hundreds of call sites.
 *
 * What it touches: only the LABEL elements inside a summary card (p / small / .small / h6 /
 * .stat-label …). Never the headline number (h1–h5), never anything with a digit in it
 * (a figure, a date, "TSh 12,000"), never table or form text.
 * Read-only display formatting — nothing is stored and nothing is written back.
 *
 * Mode comes from the global TEXT_CASE_MODE set in header.php:
 *   as_typed → text shown exactly as written (a forced CAPS style is switched off)
 *   title    → Each Word Capitalised      (the default)
 *   sentence → Only the first letter capitalised
 *   lower / upper / toggle → as named
 */
(function (window, document) {
    'use strict';

    var CARD = [
        '.custom-stat-card', '.stat-card', '.stat-mini-card', '.dn-stat-card', '.cn-stat-card',
        '.rm-stat', '.rd-stat', '.tb-stat', '.summary-card', '.planning-summary-card',
        '.svc-stat', '.wh-stat', '.stat-card-green'
    ].join(',');
    var LABEL = 'p, small, .small, h6, .stat-label, .stat-title, .stat-scope-label';
    var FLAG = 'bmsCardCased';

    function mode() {
        return (typeof TEXT_CASE_MODE !== 'undefined' && TEXT_CASE_MODE) ? TEXT_CASE_MODE : 'title';
    }

    function capFirst(str) {
        // Capitalise the first letter of every word; a letter right after an apostrophe stays lower.
        return str.replace(/(^|[^\p{L}\p{N}'’])(\p{L})/gu, function (m, a, b) { return a + b.toUpperCase(); });
    }

    /**
     * Case transform for a label. Same modes as core/text_display_case.php, with one label-only
     * refinement: in a label written in mixed case, an all-capitals word of 2–3 letters
     * (VAT, TIN, POS, WHT) is an acronym and is kept — "Total VAT" must not become "Total Vat".
     */
    function labelCase(str, m) {
        if (!str) return str;
        var allCaps = str === str.toUpperCase();
        var keep = [];
        if (!allCaps && (m === 'title' || m === 'sentence')) {
            str.replace(/\p{L}+/gu, function (w, idx) {
                if (w.length >= 2 && w.length <= 3 && w === w.toUpperCase()) keep.push([idx, w]);
                return w;
            });
        }
        var out;
        switch (m) {
            case 'lower': out = str.toLowerCase(); break;
            case 'upper': out = str.toUpperCase(); break;
            case 'title': out = capFirst(str.toLowerCase()); break;
            case 'sentence':
                out = str.toLowerCase().replace(/(^\s*\p{L}|[.!?]\s+\p{L})/gu, function (c) { return c.toUpperCase(); });
                break;
            case 'toggle':
                out = str.split('').map(function (ch) { return ch === ch.toUpperCase() ? ch.toLowerCase() : ch.toUpperCase(); }).join('');
                break;
            default: return str;   // as_typed
        }
        keep.forEach(function (k) { out = out.slice(0, k[0]) + k[1] + out.slice(k[0] + k[1].length); });
        return out;
    }

    function isLabel(el) {
        if (el.dataset && el.dataset[FLAG]) return false;
        if (el.querySelector('h1,h2,h3,h4,h5')) return false;          // a container holding a headline figure
        var t = el.textContent || '';
        if (!/\p{L}/u.test(t) || /\d/.test(t)) return false;           // no words, or it carries a figure
        return true;
    }

    function run(root) {
        var m = mode();
        (root || document).querySelectorAll(CARD).forEach(function (card) {
            card.querySelectorAll(LABEL).forEach(function (el) {
                if (!isLabel(el)) return;
                for (var n = el.firstChild; n; n = n.nextSibling) {
                    if (n.nodeType === 3 && n.nodeValue.trim() !== '') n.nodeValue = labelCase(n.nodeValue, m);
                }
                // Switch off any forced CAPITALS (Bootstrap .text-uppercase or a page CSS rule) —
                // the setting decides now; "upper" mode simply produces capitals itself.
                el.style.setProperty('text-transform', 'none', 'important');
                el.dataset[FLAG] = '1';
            });
        });
    }

    window.BMSCardCase = { run: run, labelCase: labelCase };

    function start() { run(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    window.addEventListener('load', start);   // late-rendered cards
})(window, document);
