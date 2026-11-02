<?php
/**
 * includes/restaurant/today_strip.php — small "Restaurant today" section on the normal
 * dashboard of a company with BOTH shops and restaurant/hybrid shops (restaurant_plan.md
 * Phase 6). The shop dashboard itself is unchanged; this only adds one row — plus, for people with reports
 * access, the All | Restaurant | Shop switch that loads the restaurant panel (restaurant dashboard pass, 2026-11-02).
 * Expects $pdo. Renders nothing unless the restaurant module is on.
 */
if (!restaurantModuleOn($pdo) || !restaurantWarehousesForSelect($pdo)) return;
require_once ROOT_DIR . '/core/restaurant_dashboard.php';
$rts = restaurantDashboardData($pdo);
?>
<div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #0d6efd !important;">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-bold"><i class="bi bi-cup-hot text-primary me-1"></i><?= t('Restaurant today') ?></h6>
            <div class="d-flex gap-2">
                <?php if (canCreate('pos')): ?>
                <a href="<?= getUrl('restaurant/tables-board') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-grid-3x3-gap me-1"></i><?= t('Tables') ?></a>
                <a href="<?= getUrl('pos') ?>" class="btn btn-sm btn-primary"><i class="bi bi-cart-check me-1"></i><?= t('Take order') ?></a>
                <?php endif; ?>
            </div>
        </div>
        <div class="row g-2 mt-1 text-center">
            <div class="col-4"><div class="fs-5 fw-bold text-primary"><?= (int)$rts['orders_today'] ?></div><div class="small text-muted"><?= t('Orders today') ?></div></div>
            <div class="col-4"><div class="fs-5 fw-bold text-danger"><?= (int)$rts['open_tables'] ?></div><div class="small text-muted"><?= t('Tables with order') ?></div></div>
            <div class="col-4"><div class="fs-5 fw-bold text-primary"><?= number_format($rts['open_amount'], 0) ?></div><div class="small text-muted"><?= t('Not paid yet') ?></div></div>
        </div>
        <?php if (hasReportsAccess() || canView('invoices') || canView('sales_report')): ?>
        <!-- Restaurant dashboard pass — one switch for a mixed company: All (combined profit) | Restaurant | Shop
             ("Shop" folds this panel away and leaves the shop dashboard below exactly as it is). -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3" id="rdMixedBar">
            <div class="btn-group btn-group-sm" role="group" id="rdMixedSwitch" aria-label="<?= htmlspecialchars(t('Dashboard view')) ?>">
                <button type="button" class="btn btn-outline-primary" data-v="combined"><i class="bi bi-layers me-1"></i><?= t('All') ?></button>
                <button type="button" class="btn btn-outline-primary" data-v="restaurant"><i class="bi bi-cup-hot me-1"></i><?= t('Restaurant') ?></button>
                <button type="button" class="btn btn-outline-primary" data-v="shop"><i class="bi bi-shop me-1"></i><?= t('Shop') ?></button>
            </div>
            <span class="small text-muted d-none d-md-inline" id="rdMixedHint"></span>
        </div>
        <div id="rdMixedBody" class="mt-3"></div>
        <script>
        (function () {
            const API = <?= json_encode(buildUrl('api/restaurant/dashboard_panel.php')) ?>;
            const KEY = 'bms_rd_mixed_view';
            const body = document.getElementById('rdMixedBody'), sw = document.getElementById('rdMixedSwitch'), hint = document.getElementById('rdMixedHint');
            const HINTS = { combined: <?= json_encode(t('Restaurant and shops together')) ?>, restaurant: <?= json_encode(t('Restaurant only')) ?>, shop: <?= json_encode(t('Your shop dashboard is below')) ?> };
            const cache = {}; let current = null;
            // innerHTML does not run scripts — re-create them so the panel's charts start (no jQuery needed here).
            function setHtml(el, html) {
                el.innerHTML = html;
                el.querySelectorAll('script').forEach(function (old) { const s = document.createElement('script'); s.text = old.textContent; old.parentNode.replaceChild(s, old); });
            }
            function get() { try { const v = localStorage.getItem(KEY); return ['combined', 'restaurant', 'shop'].includes(v) ? v : 'combined'; } catch (e) { return 'combined'; } }
            function show(v) {
                current = v;
                try { localStorage.setItem(KEY, v); } catch (e) {}
                sw.querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.v === v));
                hint.textContent = HINTS[v] || '';
                if (v === 'shop') { body.classList.add('d-none'); return; }
                body.classList.remove('d-none');
                if (cache[v]) { setHtml(body, cache[v]); return; }
                body.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm text-primary me-2"></div><?= htmlspecialchars(t('Loading...'), ENT_QUOTES) ?></div>';
                fetch(API + '?part=panel&scope=' + encodeURIComponent(v), { credentials: 'same-origin' }).then(r => r.json()).then(function (j) {
                    if (current !== v) return;
                    if (j && j.success) { cache[v] = j.html; setHtml(body, j.html); }
                    else body.innerHTML = '<div class="alert alert-light border small mb-0">' + ((j && j.message) || '') + '</div>';
                }).catch(function () { if (current === v) body.innerHTML = ''; });
            }
            sw.querySelectorAll('button').forEach(b => b.addEventListener('click', () => show(b.dataset.v)));
            show(get());
        })();
        </script>
        <?php endif; ?>
    </div>
</div>
