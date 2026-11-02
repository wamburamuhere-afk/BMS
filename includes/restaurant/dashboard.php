<?php
/**
 * includes/restaurant/dashboard.php — the restaurant dashboard (restaurant_plan.md Phase 6; the numbers, charts and
 * profit story now live in includes/restaurant/dash_panel.php — restaurant dashboard pass, 2026-11-02).
 * Shown by app/dashboard.php INSTEAD of the shop dashboard only for a restaurant-only
 * company (restaurantOnlyCompany()). Expects $pdo and the header already rendered.
 */
require_once ROOT_DIR . '/core/restaurant_dashboard.php';
$rd = restaurantDashboardData($pdo);
$rdCur = getSetting('currency', 'TZS');
$rdFmt = fn($n) => number_format((float)$n, 0);
$rdTypes = ['dine_in' => t('Dine in'), 'take_away' => t('Take away'), 'delivery' => t('Delivery')];
$rdName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? '');
?>
<div class="container-fluid mt-3 mt-md-4 rd-page">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
        <div class="min-w-0">
            <h4 class="mb-0 text-truncate"><i class="bi bi-cup-hot text-primary me-2"></i><?= t('Dashboard') ?></h4>
            <div class="small text-muted"><?= $rdName !== '' ? sprintf(t('Welcome back, %s!'), safe_output($rdName)) . ' · ' : '' ?><?= date('d M Y') ?></div>
        </div>
    </div>

    <?php
    $rgsWarehouses = restaurantWarehousesForSelect($pdo);
    require ROOT_DIR . '/includes/restaurant/getting_started.php';
    ?>

    <!-- Quick actions -->
    <div class="rd-actions mb-3">
        <?php if (canCreate('pos')): ?>
        <a href="<?= getUrl('pos') ?>" class="rd-act rd-act-main"><i class="bi bi-cart-check"></i><span><?= t('Take order') ?></span></a>
        <a href="<?= getUrl('restaurant/tables-board') ?>" class="rd-act"><i class="bi bi-grid-3x3-gap"></i><span><?= t('Tables') ?></span></a>
        <?php endif; ?>
        <a href="<?= getUrl('restaurant/menu') ?>" class="rd-act"><i class="bi bi-journal-richtext"></i><span><?= t('Menu') ?></span></a>
        <?php if (canCreate('expenses')): ?>
        <button type="button" class="rd-act" onclick="rbiOpen()"><i class="bi bi-basket"></i><span><?= t('Buy Ingredients') ?></span></button>
        <?php endif; ?>
        <?php if (canView('expenses')): ?>
        <a href="<?= getUrl('expenses') ?>" class="rd-act"><i class="bi bi-receipt"></i><span><?= t('Expenses') ?></span></a>
        <?php endif; ?>
    </div>

    <!-- The restaurant dashboard panel: today vs yesterday, performance chart, where the money went,
         what earns money, busy hours, order types, needs attention (includes/restaurant/dash_panel.php). -->
    <?php $rdScope = 'company'; include ROOT_DIR . '/includes/restaurant/dash_panel.php'; ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-1"></i><?= t('Recent orders') ?></h6>
                    <?php if (canView('pos')): ?><a href="<?= getUrl('pos_dashboard') ?>" class="small"><?= t('See all') ?></a><?php endif; ?>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (!$rd['recent']): ?>
                    <div class="list-group-item text-muted text-center py-4"><?= t('No orders yet today. Tap "Take order" to start.') ?></div>
                    <?php endif; ?>
                    <?php foreach ($rd['recent'] as $o): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate"><?= safe_output($o['table_number'] !== null ? t('Table') . ' ' . $o['table_number'] : ($rdTypes[$o['sale_type']] ?? t('Order'))) ?></div>
                            <div class="small text-muted text-truncate"><?= safe_output($o['receipt_number']) ?> · <?= date('H:i', strtotime($o['sale_date'])) ?></div>
                        </div>
                        <div class="fw-bold text-nowrap"><?= $rdFmt($o['grand_total']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5 d-flex flex-column gap-3">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="bi bi-cup-straw text-primary me-1"></i><?= t('Drinks and ingredients running low') ?></h6></div>
                <div class="list-group list-group-flush">
                    <?php if (!$rd['low_drinks']): ?><div class="list-group-item text-muted small"><?= t('Everything has enough stock.') ?></div><?php endif; ?>
                    <?php foreach ($rd['low_drinks'] as $d): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-truncate"><?= safe_output($d['product_name']) ?></span>
                        <span class="badge" style="background:#dc3545;color:#fff;"><?= rtrim(rtrim(number_format((float)$d['stock'], 2), '0'), '.') ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($rd['low_drinks'] && canView('pos') && canView('pos_restock')): ?>
                    <a href="<?= getUrl('restaurant/purchases') ?>" class="list-group-item list-group-item-action text-primary small"><i class="bi bi-box-arrow-in-down me-1"></i><?= t('Buy stock & ingredients') ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require ROOT_DIR . '/includes/restaurant/buy_ingredients_modal.php'; ?>
<script>window.rbiSaved = function () { setTimeout(function () { location.reload(); }, 1600); };</script>

<style>
.rd-actions { display:grid; grid-template-columns:repeat(3, 1fr); grid-auto-rows:1fr; gap:8px; }
@media (min-width:768px) { .rd-actions { grid-template-columns:repeat(5, 1fr); gap:12px; } }
.rd-act { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; padding:12px 6px; border:1px solid #b6ccfe;
          border-radius:12px; background:#fff; color:#0d6efd; text-decoration:none; font-size:.85rem; font-weight:600; text-align:center; min-height:76px; }
.rd-act i { font-size:1.5rem; }
.rd-act:hover { background:#e7f0ff; color:#0a58ca; }
.rd-act-main { background:#0d6efd; color:#fff; border-color:#0d6efd; }
.rd-act-main:hover { background:#0b5ed7; color:#fff; }
.rd-stat { background:#e7f0ff; border:1px solid #b6ccfe; }
.rd-num { font-size:1.4rem; font-weight:700; line-height:1.2; overflow-wrap:anywhere; }
@media (min-width:768px) { .rd-num { font-size:1.75rem; } }
</style>
