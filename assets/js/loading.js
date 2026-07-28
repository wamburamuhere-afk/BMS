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

    // ---- Guaranteed-resolve section loader (opt-in) ----
    // Usage:
    //   BMSSkeleton.load({
    //       loading:  '#loading',             // container to show the skeleton in
    //       content:  '#content',             // optional — container to reveal on success
    //       skeleton: { cards: 4, rows: 6 },   // passed straight to BMSSkeleton.render
    //       ajax:     { url: '...', data: {...}, dataType: 'json' },
    //       onData:   function (res) { ... }   // render the real content
    //   })
    // Always resolves: on success `content` fades in; on failure (network error,
    // or a throw inside onData) the loading container is replaced with an inline
    // error + Retry button instead of spinning forever. onData always receives
    // the raw response — including a `success: false` payload — so pages that
    // need custom handling (e.g. redirect away on "not found") can do so inline;
    // pages happy with the generic retry state can just `throw` on that branch.
    function loadSection(opts) {
        var $loading = opts.loading ? $(opts.loading) : null;
        var $content = opts.content ? $(opts.content) : null;

        if ($loading && $loading.length) {
            $loading.show().html(buildSkeleton(opts.skeleton));
        }
        if ($content && $content.length) $content.hide();

        function showError(message) {
            if (!$loading || !$loading.length) return;
            $loading.html(
                '<div class="text-center py-4 text-muted">' +
                '<i class="bi bi-exclamation-triangle text-warning" style="font-size:1.5rem;"></i>' +
                '<p class="mt-2 mb-2">' + (message || 'Failed to load this section.') + '</p>' +
                '<button type="button" class="btn btn-sm btn-outline-primary bms-retry-btn">' +
                '<i class="bi bi-arrow-clockwise"></i> Retry</button></div>'
            );
            $loading.find('.bms-retry-btn').on('click', function () { loadSection(opts); });
        }

        $.ajax(opts.ajax).done(function (res) {
            try {
                if (typeof opts.onData === 'function') opts.onData(res);
                if ($loading && $loading.length) $loading.hide();
                if ($content && $content.length) $content.fadeIn();
            } catch (e) {
                console.error('BMSSkeleton.load onData error:', e);
                showError((e && e.message) || 'Something went wrong while displaying this section.');
            }
        }).fail(function () {
            showError('Network error. Please try again.');
        });
    }

    window.BMSSkeleton = {
        render: function (selector, opts) {
            var el = document.querySelector(selector);
            if (el) el.innerHTML = buildSkeleton(opts);
        },
        html: buildSkeleton,
        load: loadSection
    };
})();
