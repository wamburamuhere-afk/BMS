/**
 * bms-mobile-cards.js
 * Global mobile card view system for BMS DataTable pages.
 *
 * Mobile  (< 768px) : always card view — search bar + filter pills shown; no toggle.
 * Desktop (≥ 768px) : toggle buttons (table ↔ card) in card-header; preference saved.
 * Print              : always table view — handled entirely by responsive.css @media print.
 */
(function ($) {
    'use strict';

    var MOBILE_BREAK   = 768;
    var STORAGE_PREFIX = 'bms_view_';

    // ── Helpers ──────────────────────────────────────────────────────

    function isMobile() {
        return window.innerWidth < MOBILE_BREAK;
    }

    function storeView(key, view) {
        try { localStorage.setItem(STORAGE_PREFIX + key, view); } catch (e) {}
    }

    function getSavedView(key) {
        try { return localStorage.getItem(STORAGE_PREFIX + key); } catch (e) { return null; }
    }

    function hasBuiltinCardView() {
        return (
            $('#cardView').length > 0 ||
            $('#card-view-container').length > 0
        );
    }

    // ── Column detection ─────────────────────────────────────────────

    var SKIP_HEADERS = ['s/no', '#', 'sno', 'no.', 'no', 'sl', 'sr', 'serial', 'sr no'];

    function detectTitleCol(headers) {
        for (var i = 0; i < headers.length; i++) {
            if (SKIP_HEADERS.indexOf(headers[i].toLowerCase().trim()) === -1) return i;
        }
        return 0;
    }

    function detectStatusCol(headers) {
        for (var i = 0; i < headers.length; i++) {
            if (headers[i].toLowerCase().trim() === 'status') return i;
        }
        return -1;
    }

    function detectActionsCol(headers) {
        var actionWords = ['actions', 'action', 'manage', 'options', 'operations', 'operation', 'option'];
        for (var i = headers.length - 1; i >= 0; i--) {
            if (actionWords.indexOf(headers[i].toLowerCase().trim()) !== -1) return i;
        }
        return -1;
    }

    // ── Action button builder ─────────────────────────────────────────

    function getButtonColor(text, iconClass) {
        var t  = text.toLowerCase();
        var ic = (iconClass || '').toLowerCase();
        if (t.indexOf('delete')     !== -1 || t.indexOf('trash')      !== -1 || t.indexOf('remove')  !== -1 || ic.indexOf('trash')  !== -1) return 'danger';
        if (t.indexOf('edit')       !== -1 || t.indexOf('pencil')     !== -1 || ic.indexOf('pencil')  !== -1)                               return 'warning';
        if (t.indexOf('view')       !== -1 || t.indexOf('detail')     !== -1 || ic.indexOf('eye')     !== -1)                               return 'primary';
        if (t.indexOf('approve')    !== -1 || t.indexOf('order')      !== -1 || t.indexOf('payment')  !== -1 ||
            ic.indexOf('check')     !== -1 || ic.indexOf('cart')      !== -1)                                                               return 'success';
        if (t.indexOf('new')        !== -1 || t.indexOf('create')     !== -1 || t.indexOf('add')      !== -1 || ic.indexOf('plus')   !== -1) return 'info';
        if (t.indexOf('suspend')    !== -1 || t.indexOf('deactivate') !== -1 || t.indexOf('pause')    !== -1)                               return 'warning';
        if (t.indexOf('activate')   !== -1 || t.indexOf('play')       !== -1 || t.indexOf('enable')   !== -1)                               return 'success';
        if (t.indexOf('blacklist')  !== -1 || t.indexOf('ban')        !== -1 || ic.indexOf('ban')     !== -1)                               return 'danger';
        if (t.indexOf('block')      !== -1 || t.indexOf('lock')       !== -1)                                                               return 'danger';
        return 'secondary';
    }

    var BTN_STYLE = 'style="flex:1;min-width:0;padding-left:0;padding-right:0;text-align:center;min-height:36px;"';

    function itemToButton(itemEl) {
        var href      = itemEl.getAttribute('href') || '#';
        var onclick   = itemEl.getAttribute('onclick') || '';
        var iconEl    = itemEl.querySelector('i');
        var iconClass = iconEl ? iconEl.className.trim() : 'bi bi-gear';
        var text      = itemEl.textContent.trim();
        var color     = getButtonColor(text, iconClass);
        var safeTitle = text.replace(/"/g, '&quot;');

        var isRealHref = href && href !== '#' && href.indexOf('javascript:') === -1;
        if (isRealHref) {
            return '<a href="' + href + '" class="btn btn-sm btn-outline-' + color + '" title="' + safeTitle + '" ' + BTN_STYLE + '><i class="' + iconClass + '"></i></a>';
        }
        var safeClick = onclick.replace(/"/g, '&quot;');
        return '<button type="button" class="btn btn-sm btn-outline-' + color + '" onclick="' + safeClick + '" title="' + safeTitle + '" ' + BTN_STYLE + '><i class="' + iconClass + '"></i></button>';
    }

    function buildActionButtons(actionCell) {
        if (!actionCell) return '';

        var items = Array.prototype.slice.call(
            actionCell.querySelectorAll('.dropdown-item')
        ).filter(function (el) {
            return el.textContent.trim() && el.tagName !== 'HR';
        });

        if (items.length === 0) return actionCell.innerHTML;

        // Max 3 on mobile for a clean footer; 4 on desktop
        var maxBtns = isMobile() ? 3 : 4;
        var btns = '';
        items.slice(0, maxBtns).forEach(function (item) { btns += itemToButton(item); });
        return btns;
    }

    // ── Card builder ──────────────────────────────────────────────────

    function buildCard(trEl, headers, titleCol, statusCol, actionsCol) {
        // Exclude print-only hidden cells (d-none) so indices match visible headers
        var cells = Array.prototype.slice.call(trEl.querySelectorAll('td')).filter(function (td) {
            return !td.classList.contains('d-none');
        });
        if (cells.length === 0) return '';

        var tc         = titleCol < cells.length ? titleCol : 0;
        var titleHtml  = cells[tc] ? cells[tc].innerHTML : '';
        var statusHtml = (statusCol >= 0 && statusCol < cells.length) ? cells[statusCol].innerHTML : '';
        var actionCell = (actionsCol >= 0 && actionsCol < cells.length) ? cells[actionsCol] : null;

        var skipSet = {};
        skipSet[0]  = true;
        skipSet[tc] = true;
        if (statusCol  >= 0) skipSet[statusCol]  = true;
        if (actionsCol >= 0) skipSet[actionsCol] = true;

        var bodyHtml = '';
        for (var i = 0; i < cells.length && i < headers.length; i++) {
            if (skipSet[i]) continue;
            var label = headers[i];
            if (!label || SKIP_HEADERS.indexOf(label.toLowerCase()) !== -1) continue;
            var val = cells[i].innerHTML.trim();
            if (!val || val === '-') continue;
            bodyHtml += '<div class="bms-card-field">' +
                '<span class="bms-card-lbl">' + label + '</span>' +
                '<span class="bms-card-val">' + val + '</span>' +
                '</div>';
        }

        var actionBtns = buildActionButtons(actionCell);

        // Raw status text stored as data-status for pill filtering
        var statusText = (statusCol >= 0 && statusCol < cells.length)
            ? cells[statusCol].textContent.trim().toLowerCase()
            : '';

        return '<div class="col-xl-3 col-lg-4 col-md-6 col-12 bms-auto-card-item" data-status="' + statusText + '">' +
            '<div class="card h-100 border-0 shadow-sm" style="border-radius:10px;overflow:hidden;">' +
            '<div class="card-header d-flex justify-content-between align-items-start py-2 px-3 bms-card-hdr">' +
            '<div class="fw-bold" style="font-size:.88rem;max-width:72%;line-height:1.3;">' + titleHtml + '</div>' +
            '<div class="text-end flex-shrink-0">' + statusHtml + '</div>' +
            '</div>' +
            '<div class="card-body p-3" style="font-size:.84rem;">' +
            (bodyHtml || '<span class="text-muted small">—</span>') +
            '</div>' +
            '<div class="card-footer bg-white border-top d-flex align-items-center flex-nowrap gap-1 px-3 py-2">' +
            actionBtns +
            '</div>' +
            '</div>' +
            '</div>';
    }

    // ── Client-side filtering (mobile search + pills) ─────────────────

    function filterCards(tableId) {
        var container = document.getElementById(tableId + '-bms-cards');
        if (!container) return;

        var searchInput  = document.getElementById(tableId + '-mobile-search');
        var searchTerm   = searchInput ? searchInput.value.toLowerCase().trim() : '';

        var activePill   = document.querySelector('#' + tableId + '-filter-pills .bms-filter-pill.active');
        var statusFilter = activePill ? activePill.getAttribute('data-filter') : 'all';

        var items        = container.querySelectorAll('.bms-auto-card-item');
        var visibleCount = 0;

        items.forEach(function (item) {
            var cardStatus  = (item.getAttribute('data-status') || '').toLowerCase();
            var cardText    = item.textContent.toLowerCase();
            var statusMatch = (statusFilter === 'all') || (cardStatus.indexOf(statusFilter) !== -1);
            var searchMatch = !searchTerm || (cardText.indexOf(searchTerm) !== -1);

            if (statusMatch && searchMatch) {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        // Show / hide no-results message
        var noResultsId  = tableId + '-no-results';
        var existingMsg  = document.getElementById(noResultsId);
        if (visibleCount === 0 && items.length > 0) {
            if (!existingMsg) {
                var div       = document.createElement('div');
                div.id        = noResultsId;
                div.className = 'col-12 text-center py-5 text-muted';
                div.innerHTML = '<i class="bi bi-search" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.4;"></i>No matching records.';
                container.appendChild(div);
            }
        } else if (existingMsg) {
            existingMsg.remove();
        }
    }

    // ── Collect unique status values from rendered cards ──────────────

    function collectStatusValues(tableId) {
        var container = document.getElementById(tableId + '-bms-cards');
        if (!container) return [];
        var seen = {}, values = [];
        container.querySelectorAll('.bms-auto-card-item').forEach(function (item) {
            var s = (item.getAttribute('data-status') || '').trim();
            if (s && !seen[s]) { seen[s] = true; values.push(s); }
        });
        return values;
    }

    // ── Rebuild filter pills after each card render ───────────────────

    function updateFilterPills(tableId) {
        var pillsContainer = document.getElementById(tableId + '-filter-pills');
        if (!pillsContainer) return;

        var statuses = collectStatusValues(tableId);
        if (statuses.length === 0) {
            pillsContainer.style.display = 'none';
            return;
        }
        pillsContainer.style.display = '';

        var activePill    = pillsContainer.querySelector('.bms-filter-pill.active');
        var currentFilter = activePill ? activePill.getAttribute('data-filter') : 'all';

        var html = '<button class="bms-filter-pill' + (currentFilter === 'all' ? ' active' : '') + '" data-filter="all">All</button>';
        statuses.forEach(function (s) {
            var label    = s.charAt(0).toUpperCase() + s.slice(1);
            var isActive = (currentFilter === s);
            html += '<button class="bms-filter-pill' + (isActive ? ' active' : '') + '" data-filter="' + s + '">' + label + '</button>';
        });
        pillsContainer.innerHTML = html;

        pillsContainer.querySelectorAll('.bms-filter-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                pillsContainer.querySelectorAll('.bms-filter-pill').forEach(function (p) { p.classList.remove('active'); });
                pill.classList.add('active');
                filterCards(tableId);
            });
        });
    }

    // ── Inject mobile controls: search bar + filter pills ────────────
    // d-print-none ensures this is NEVER visible in print.
    // CSS Section 8d hides it on desktop (≥ 768px).

    function injectMobileControls(tableEl, tableId) {
        if (document.getElementById(tableId + '-mobile-controls')) return;

        var cardContainer = document.getElementById(tableId + '-bms-cards');
        if (!cardContainer) return;

        var html =
            '<div class="bms-mobile-controls d-print-none" id="' + tableId + '-mobile-controls">' +
                '<div class="bms-mobile-search-bar">' +
                    '<div class="input-group">' +
                        '<span class="input-group-text bg-white border-end-0 pe-1">' +
                            '<i class="bi bi-search text-muted" style="font-size:.85rem;"></i>' +
                        '</span>' +
                        '<input type="search" class="form-control border-start-0 ps-1" ' +
                               'placeholder="Search records..." ' +
                               'id="' + tableId + '-mobile-search" autocomplete="off">' +
                    '</div>' +
                '</div>' +
                '<div class="bms-filter-pills" id="' + tableId + '-filter-pills" style="display:none;">' +
                    '<button class="bms-filter-pill active" data-filter="all">All</button>' +
                '</div>' +
            '</div>';

        cardContainer.insertAdjacentHTML('beforebegin', html);

        // Live search binding
        var searchInput = document.getElementById(tableId + '-mobile-search');
        if (searchInput) {
            searchInput.addEventListener('input', function () { filterCards(tableId); });
        }

        // Initial pill binding
        var pillsContainer = document.getElementById(tableId + '-filter-pills');
        if (pillsContainer) {
            pillsContainer.querySelectorAll('.bms-filter-pill').forEach(function (pill) {
                pill.addEventListener('click', function () {
                    pillsContainer.querySelectorAll('.bms-filter-pill').forEach(function (p) { p.classList.remove('active'); });
                    pill.classList.add('active');
                    filterCards(tableId);
                });
            });
        }
    }

    // ── Card rendering ────────────────────────────────────────────────

    function renderCards(tableEl, tableId, headers, titleCol, statusCol, actionsCol) {
        var container = document.getElementById(tableId + '-bms-cards');
        if (!container) return;

        container.innerHTML = '';

        var rows    = tableEl.querySelectorAll('tbody tr');
        var hasRows = false;

        rows.forEach(function (tr) {
            if (tr.classList.contains('dt-empty') || tr.querySelector('td.dataTables_empty')) return;
            var html = buildCard(tr, headers, titleCol, statusCol, actionsCol);
            if (html) { container.insertAdjacentHTML('beforeend', html); hasRows = true; }
        });

        if (!hasRows) {
            container.innerHTML =
                '<div class="col-12 text-center py-5 text-muted">' +
                '<i class="bi bi-inbox" style="font-size:3rem;display:block;margin-bottom:.5rem;opacity:.4;"></i>' +
                'No records found.</div>';
        }

        // Re-init Bootstrap dropdowns inside freshly rendered cards
        if (window.bootstrap && bootstrap.Dropdown) {
            container.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
                new bootstrap.Dropdown(el);
            });
        }

        // Refresh status pills then re-apply any active filter/search
        updateFilterPills(tableId);
        filterCards(tableId);
    }

    // ── Toggle buttons — desktop / tablet only (≥ 768px) ─────────────
    // d-print-none keeps these off print. CSS Section 8d hides on mobile.

    function injectToggleButtons(tableEl, tableId) {
        if (document.getElementById(tableId + '-btn-tbl')) return;

        var parentCard = tableEl.closest('.card') || tableEl.closest('.main-card');
        if (!parentCard) return;
        if (parentCard.querySelector('.bms-view-toggle')) return;

        // Skip cards whose header contains tab navigation — toggle buttons don't belong there
        var hdr = parentCard.querySelector('.card-header');
        if (hdr && hdr.querySelector('.nav-tabs, ul.nav')) return;

        var html =
            '<div class="btn-group bms-view-toggle shadow-sm d-print-none" role="group" style="margin-left:auto;flex-shrink:0;">' +
                '<button type="button" class="btn btn-light btn-sm border" id="' + tableId + '-btn-tbl" ' +
                    'style="min-width:36px;min-height:36px;" ' +
                    'onclick="window.bmsMobileCards.toggleAuto(\'' + tableId + '\',\'table\')" title="Table View">' +
                    '<i class="bi bi-table"></i>' +
                '</button>' +
                '<button type="button" class="btn btn-light btn-sm border" id="' + tableId + '-btn-crd" ' +
                    'style="min-width:36px;min-height:36px;" ' +
                    'onclick="window.bmsMobileCards.toggleAuto(\'' + tableId + '\',\'card\')" title="Card View">' +
                    '<i class="bi bi-grid"></i>' +
                '</button>' +
            '</div>';

        var cardHeader = parentCard.querySelector('.card-header');
        if (cardHeader) {
            if (!cardHeader.style.display) {
                cardHeader.style.display    = 'flex';
                cardHeader.style.alignItems = 'center';
                cardHeader.style.flexWrap   = 'wrap';
            }
            cardHeader.insertAdjacentHTML('beforeend', html);
        } else {
            parentCard.insertAdjacentHTML('beforebegin',
                '<div class="d-flex justify-content-end mb-2 d-print-none">' + html + '</div>');
        }
    }

    // ── View switcher (used by desktop toggle buttons) ────────────────

    function toggleAutoView(tableId, viewType, noStore) {
        var table = document.getElementById(tableId);
        if (!table) return;

        var wrapper        = table.closest('.dataTables_wrapper') || table.closest('.table-responsive') || table.parentElement;
        var cardContainer  = document.getElementById(tableId + '-bms-cards');
        var mobileControls = document.getElementById(tableId + '-mobile-controls');
        var btnTbl         = document.getElementById(tableId + '-btn-tbl');
        var btnCrd         = document.getElementById(tableId + '-btn-crd');

        if (viewType === 'card') {
            if (wrapper)        wrapper.classList.add('d-none');
            if (cardContainer)  cardContainer.classList.remove('d-none');
            if (mobileControls) mobileControls.classList.remove('d-none'); // CSS still hides it on desktop
            if (btnTbl) { btnTbl.classList.remove('btn-primary', 'text-white'); btnTbl.classList.add('btn-light'); }
            if (btnCrd) { btnCrd.classList.add('btn-primary', 'text-white');    btnCrd.classList.remove('btn-light'); }
        } else {
            if (wrapper)        wrapper.classList.remove('d-none');
            if (cardContainer)  cardContainer.classList.add('d-none');
            if (mobileControls) mobileControls.classList.add('d-none');
            if (btnCrd) { btnCrd.classList.remove('btn-primary', 'text-white'); btnCrd.classList.add('btn-light'); }
            if (btnTbl) { btnTbl.classList.add('btn-primary', 'text-white');    btnTbl.classList.remove('btn-light'); }
        }

        if (!noStore) storeView(tableId, viewType); // Only save explicit user toggle choices
    }

    // ── Enhance one DataTable ─────────────────────────────────────────

    function enhanceTable(dt, tableEl) {
        var tableId = tableEl.id;
        if (!tableId) return;

        var headerCells = tableEl.querySelectorAll('thead tr:last-child th');
        var headers = [];
        headerCells.forEach(function (th) { headers.push(th.textContent.trim()); });

        var titleCol   = detectTitleCol(headers);
        var statusCol  = detectStatusCol(headers);
        var actionsCol = detectActionsCol(headers);

        // Create card container after the table wrapper
        if (!document.getElementById(tableId + '-bms-cards')) {
            var wrapper       = tableEl.closest('.dataTables_wrapper') || tableEl.closest('.table-responsive') || tableEl.parentElement;
            var cardContainer = document.createElement('div');
            cardContainer.id        = tableId + '-bms-cards';
            cardContainer.className = 'row g-3 bms-auto-cards d-none d-print-none';
            if (wrapper && wrapper.parentNode) {
                wrapper.parentNode.insertBefore(cardContainer, wrapper.nextSibling);
            }
        }

        // Desktop/tablet: toggle buttons (CSS hides on mobile)
        injectToggleButtons(tableEl, tableId);

        // Mobile: search bar + filter pills (CSS hides on desktop)
        injectMobileControls(tableEl, tableId);

        // Re-render cards on every DataTable draw (pagination / search / sort)
        dt.on('draw.bmsCards', function () {
            renderCards(tableEl, tableId, headers, titleCol, statusCol, actionsCol);
        });

        // Initial render
        renderCards(tableEl, tableId, headers, titleCol, statusCol, actionsCol);

        // Set initial view
        if (isMobile()) {
            toggleAutoView(tableId, 'card', true); // true = don't save; mobile auto-switch only
        } else {
            var saved = getSavedView(tableId);
            toggleAutoView(tableId, saved || 'table');
        }
    }

    // ── Handle pages with built-in card view ──────────────────────────

    function handleBuiltinCardView() {
        // Per-page modifications handle built-in card views (suppliers, customers, etc.)
    }

    // ── Plain-table enhancer (non-DataTable / custom-AJAX pages) ─────

    function enhancePlainTable(tableEl) {
        var tableId = tableEl.id;
        if (!tableId) return;

        // Use first header row; exclude print-only hidden headers (d-none) to handle
        // multi-row thead structures (e.g. performanceTable non-daily view)
        var headerCells = Array.prototype.slice.call(
            tableEl.querySelectorAll('thead tr:first-child th')
        ).filter(function (th) { return !th.classList.contains('d-none'); });
        var headers = [];
        headerCells.forEach(function (th) { headers.push(th.textContent.trim()); });

        var titleCol   = detectTitleCol(headers);
        var statusCol  = detectStatusCol(headers);
        var actionsCol = detectActionsCol(headers);

        if (!document.getElementById(tableId + '-bms-cards')) {
            var wrapper       = tableEl.closest('.table-responsive') || tableEl.parentElement;
            var cardContainer = document.createElement('div');
            cardContainer.id        = tableId + '-bms-cards';
            cardContainer.className = 'row g-3 bms-auto-cards d-none d-print-none';
            if (wrapper && wrapper.parentNode) {
                wrapper.parentNode.insertBefore(cardContainer, wrapper.nextSibling);
            }
        }

        injectToggleButtons(tableEl, tableId);
        injectMobileControls(tableEl, tableId);

        if (!tableEl.getAttribute('data-bms-plain')) {
            tableEl.setAttribute('data-bms-plain', '1');
            if (isMobile()) {
                toggleAutoView(tableId, 'card', true); // true = don't save; mobile auto-switch only
            } else {
                var saved = getSavedView(tableId);
                toggleAutoView(tableId, saved || 'table');
            }
        }

        renderCards(tableEl, tableId, headers, titleCol, statusCol, actionsCol);
    }

    // ── Public API ────────────────────────────────────────────────────

    window.bmsMobileCards = {
        toggleAuto:     toggleAutoView,
        enhance:        enhanceTable,
        renderForTable: function (tableId) {
            var el = document.getElementById(tableId);
            if (el) enhancePlainTable(el);
        }
    };

    // ── Bootstrap ─────────────────────────────────────────────────────

    $(document).ready(function () {
        handleBuiltinCardView();

        if (!hasBuiltinCardView()) {
            $(document).on('init.dt', function (e, settings) {
                var tableEl = settings.nTable;
                if (!tableEl.id) return;

                setTimeout(function () {
                    var dt = $(tableEl).DataTable();
                    enhanceTable(dt, tableEl);
                }, 50);
            });
        }

        // Window resize: adapt view when crossing the mobile breakpoint
        var resizeTimer;
        $(window).on('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                $('.bms-auto-cards').each(function () {
                    var tableId = this.id.replace('-bms-cards', '');
                    if (isMobile()) {
                        toggleAutoView(tableId, 'card', true); // true = don't save; mobile auto-switch only
                    } else {
                        var saved = getSavedView(tableId);
                        toggleAutoView(tableId, saved || 'table'); // desktop: restore preference
                    }
                });
            }, 300);
        });
    });

})(jQuery);
