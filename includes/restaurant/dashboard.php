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
$rdFirst = trim((string)($_SESSION['first_name'] ?? '')) ?: $rdName;
$rdHour = (int)date('G');
$rdHello = $rdHour < 12 ? t('Good morning') : ($rdHour < 17 ? t('Good afternoon') : t('Good evening'));
?>
<div class="container-fluid mt-3 mt-md-4 rd-page">
    <div class="d-flex justify-content-between align-items-end mb-3 gap-3 flex-wrap">
        <div class="min-w-0">
            <h4 class="mb-0 rd-hello"><?= $rdHello ?><?= $rdFirst !== '' ? ', ' . safe_output($rdFirst) : '' ?>.</h4>
            <div class="rd-sub"><?= t('Here is how the restaurant is doing.') ?> · <?= date('d M Y') ?></div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <form method="get" class="d-flex align-items-center gap-2 mb-0">
                <label for="rdPeriodSel" class="rd-sub mb-0"><?= t('Showing') ?></label>
                <select id="rdPeriodSel" name="rd_period" class="form-select w-auto rd-sel" onchange="this.form.submit()">
                    <?php $rdPSel = (string)($_GET['rd_period'] ?? 'today');
                    foreach (['today' => t('Today'), 'week' => t('This week'), 'month' => t('This month'), 'quarter' => t('This quarter'), 'year' => t('This year')] as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $rdPSel === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if (canView('pos')): // same rule as the menu's "Take order" (the waiter takes orders too) ?>
            <a href="<?= getUrl('pos') ?>" class="rd-take"><i class="bi bi-plus-lg me-1"></i><?= t('Take order') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $rgsWarehouses = restaurantWarehousesForSelect($pdo);
    require ROOT_DIR . '/includes/restaurant/getting_started.php';
    ?>

    <!-- The restaurant dashboard panel: today vs yesterday, performance chart, where the money went,
         what earns money, busy hours, order types, needs attention, running low, recent orders — Layout 2, "Side actions"
         (includes/restaurant/dash_panel.php). -->
    <?php $rdScope = 'company'; include ROOT_DIR . '/includes/restaurant/dash_panel.php'; ?>

    <!-- Layout 2: the quick actions, the summary cards, and the Needs attention / Activities / Running low / Orders panel are all
         part of the panel above (they used to be separate rows here). -->
</div>


<style>
.rd-page .rd-hello { font-size:24px; font-weight:700; color:#0b3d91; }
.rd-page .rd-sub { font-size:14px; color:#5b6472; }
.rd-page .rd-sel { height:40px; font-size:14px; border-radius:10px; border-color:#cfdcf3; }
.rd-page .rd-take { display:inline-flex; align-items:center; height:44px; padding:0 18px; border-radius:10px; background:#0d6efd; color:#fff; font-size:14px; font-weight:600; text-decoration:none; white-space:nowrap; }
.rd-page .rd-take:hover { background:#0b5ed7; color:#fff; }
@media (max-width:575.98px) { .rd-page .rd-hello { font-size:20px; } .rd-page .rd-take { flex:1 1 100%; justify-content:center; height:48px; } }
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
