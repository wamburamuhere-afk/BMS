/* Sitewide loading UX: top progress bar (fully automatic) + a shared
   skeleton-screen helper (opt-in per page). Loaded once from footer.php
   so it is available on every page without any other page needing to
   change anything to get the progress bar. */
(function () {
    // ---- Top progress bar: fires for every $.ajax call, on every page ----
    var $bar = null;
    var hideTimer = null;

    function ensureBar() {
        if (!$bar) {
            $bar = $('<div id="bms-topbar"></div>').appendTo('body');
        }
        return $bar;
    }

    function startBar() {
        clearTimeout(hideTimer);
        var bar = ensureBar();
        bar.stop(true).css({ width: '0%' }).addClass('bms-topbar-active');
        // Animate to 80% and hold — the remaining 20% completes on ajaxStop.
        requestAnimationFrame(function () {
            bar.css({ width: '80%', transition: 'width 3s ease' });
        });
    }

    function finishBar() {
        if (!$bar) return;
        $bar.css({ transition: 'width 0.2s ease', width: '100%' });
        hideTimer = setTimeout(function () {
            $bar.removeClass('bms-topbar-active');
            hideTimer = setTimeout(function () { $bar.css({ width: '0%' }); }, 250);
        }, 200);
    }

    if (window.jQuery) {
        $(document).ajaxStart(startBar);
        $(document).ajaxStop(finishBar);
    }

    // Flash the bar immediately on internal navigation clicks so there is
    // no blank moment between "click" and the browser's own page load.
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
        if (a.target === '_blank' || a.hasAttribute('download')) return;
        if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey) return;
        startBar();
    }, true);

    // ---- Shared skeleton-screen helper (opt-in per page) ----
    // Usage: BMSSkeleton.render('#loading', { cards: 3, rows: 4 })
    //        BMSSkeleton.clear('#loading')   // optional — most pages just hide/replace the container
    function buildSkeleton(opts) {
        opts = opts || {};
        var cards = opts.cards || 0;
        var rows = opts.rows || 0;
        var html = '<div class="bms-skeleton-wrap">';

        if (cards > 0) {
            html += '<div class="row g-3 mb-3">';
            for (var c = 0; c < cards; c++) {
                html += '<div class="col-6 col-md-' + Math.max(2, Math.floor(12 / cards)) + '">' +
                    '<div class="skel-card">' +
                    '<div class="skel-line" style="width:50%;height:10px;"></div>' +
                    '<div class="skel-line" style="width:70%;height:20px;margin-top:8px;"></div>' +
                    '</div></div>';
            }
            html += '</div>';
        }

        if (rows > 0) {
            html += '<div class="skel-card">';
            for (var r = 0; r < rows; r++) {
                html += '<div class="skel-line" style="width:' + (95 - (r % 3) * 15) + '%;"></div>';
            }
            html += '</div>';
        }

        if (!cards && !rows) {
            // Generic fallback: a title line + a few body lines.
            html += '<div class="skel-card">' +
                '<div class="skel-line" style="width:40%;height:16px;"></div>' +
                '<div class="skel-line" style="width:90%;"></div>' +
                '<div class="skel-line" style="width:80%;"></div>' +
                '<div class="skel-line" style="width:60%;"></div>' +
                '</div>';
        }

        html += '</div>';
        return html;
    }

    window.BMSSkeleton = {
        render: function (selector, opts) {
            var el = document.querySelector(selector);
            if (el) el.innerHTML = buildSkeleton(opts);
        },
        html: buildSkeleton
    };
})();
