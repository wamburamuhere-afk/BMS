<?php
/**
 * includes/restaurant/today_strip.php — small "Restaurant today" section on the normal
 * dashboard of a company with BOTH shops and restaurant/hybrid shops (restaurant_plan.md
 * Phase 6). The shop dashboard itself is unchanged; this only adds one row.
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
    </div>
</div>
