<?php
/**
 * includes/restaurant/dash_panel.php — the restaurant dashboard panel (restaurant dashboard pass, 2026-11-02).
 *
 * Shared by the restaurant-only dashboard (includes/restaurant/dashboard.php) and the mixed-company
 * dashboard (loaded into includes/restaurant/today_strip.php by api/restaurant/dashboard_panel.php).
 * Never included for a retail-only company.
 *
 * Expects: $pdo, and $rdScope = 'company' (restaurant-only company) | 'restaurant' | 'combined' (mixed).
 * Money: the one ledger (core/restaurant_insights.php). Counts: operational tables.
 */
require_once ROOT_DIR . '/core/restaurant_insights.php';

// Profit and cost figures follow the same rule as the retail Performance card (reports access).
$rdMoney  = hasReportsAccess() || canView('invoices') || canView('sales_report');
$rdScope  = in_array($rdScope ?? '', ['company', 'restaurant', 'combined'], true) ? $rdScope : 'restaurant';
$rdIds    = rdRestaurantIds($pdo);
$rdCur    = getSetting('currency', 'TZS');
$rdFmt    = fn($n) => number_format((float)$n, 0);
$rdUid    = 'rdp' . substr(md5($rdScope . '|' . microtime(true)), 0, 6);   // unique ids: the panel can be re-loaded in place
$rdTypes  = ['dine_in' => t('Dine in'), 'take_away' => t('Take away'), 'delivery' => t('Delivery')];
$rdMonthFrom = date('Y-m-01');
$rdToday  = date('Y-m-d');

// Small chip: ▲ 12% / ▼ 8% (blue up, red down — the UI standard has no green). null = nothing to compare.
$rdDelta = function (?float $p, string $vs): string {
    if ($p === null) return '';
    $up = $p >= 0;
    return '<span class="rdp-chip ' . ($up ? 'rdp-up' : 'rdp-down') . '" title="' . htmlspecialchars($vs) . '">'
         . ($up ? '▲' : '▼') . ' ' . rtrim(rtrim(number_format(abs($p), 1), '0'), '.') . '%</span>';
};

if ($rdScope === 'combined') {
    $rdJe = rdJeScope($rdIds, 'company');
    $rdArea = rdAreaTotals($pdo, $rdIds, $rdMonthFrom, $rdToday);
    $rdTrend = rdTrend($pdo, 'daily', $rdJe, $rdIds);
    $rdTotalProfit = $rdArea['total']['profit'];
    // Only when both areas make a profit — a share of 110% (the other area losing money) would be nonsense.
    $rdShare = ($rdArea['restaurant']['profit'] > 0 && $rdArea['other']['profit'] >= 0 && $rdTotalProfit > 0) ? round($rdArea['restaurant']['profit'] / $rdTotalProfit * 100) : null;
} else {
    $rdJe = rdJeScope($rdIds, $rdScope);
    $rdHero = rdHero($pdo, $rdJe, $rdIds);
    $rdTrend = rdTrend($pdo, 'daily', $rdJe);
    $rdStory = rdProfitStory($pdo, $rdJe);
    $rdDishes = rdDishEarnings($pdo, $rdIds, $rdMonthFrom, $rdToday);
    $rdHours = rdOrdersByHour($pdo, $rdIds, 30);
    $rdTypeRows = restaurantSalesByOrderType($pdo, $rdMonthFrom, $rdToday, $rdIds);
    $rdData = restaurantDashboardData($pdo);
    $rdCheck = rdProfitCheck($pdo, $rdJe, $rdMonthFrom, $rdToday);
    $rdWarn = $rdMoney ? rdProfitWarnings($rdStory['cur'], $rdDishes['items'], $rdCheck['groceries']) : [];
    $rdOwed = null;
    if ($rdMoney && canView('pos')) {                      // the same figure as "Who Owes Me" and the retail Credit card
        require_once ROOT_DIR . '/core/pos_credit_aging.php';
        $rdOwed = posCreditTotalOutstanding($pdo, scopeFilterSqlNullable('warehouse', 's'));
    }
    // The cost warning now sits with the profit figure it concerns, so it is not repeated in "Needs your attention".
    $rdAttention = rdAttention($pdo, $rdIds, 0, count($rdData['low_drinks']));
}
$rdTrendNote = t('The last 7 days compared with the 7 days before'); // complete days only — today is never compared with a full day
$rdClassLabel = ['star' => t('Star'), 'gem' => t('Hidden gem'), 'popular' => t('Popular, small profit'), 'review' => t('Review')];
?>
<div class="rdp" id="<?= $rdUid ?>" data-scope="<?= htmlspecialchars($rdScope) ?>">

<?php if ($rdScope === 'combined'): ?>
    <!-- ───────── Combined: restaurant + shops in one profit picture ───────── -->
    <div class="row g-2 g-md-3 mb-3">
        <div class="col-12 col-lg-4"><div class="rdp-stat h-100">
            <div class="rdp-label"><?= t('Company profit this month') ?></div>
            <div class="rdp-num <?= $rdTotalProfit < 0 ? 'text-danger' : 'text-primary' ?>"><?= $rdFmt($rdTotalProfit) ?> <small><?= htmlspecialchars($rdCur) ?></small></div>
            <div class="rdp-sub"><?= sprintf(t('Sales %s'), $rdFmt($rdArea['total']['sales'])) ?></div>
            <?php if ($rdShare !== null): ?>
            <div class="rdp-split mt-2" title="<?= htmlspecialchars(sprintf(t('The restaurant earns %d%% of the profit'), $rdShare)) ?>">
                <span style="width:<?= $rdShare ?>%"></span>
            </div>
            <div class="rdp-sub mt-1"><?= sprintf(t('The restaurant earns %d%% of the profit'), $rdShare) ?></div>
            <?php endif; ?>
        </div></div>
        <div class="col-6 col-lg-4"><div class="rdp-stat h-100">
            <div class="rdp-label"><i class="bi bi-cup-hot me-1"></i><?= t('Restaurant') ?></div>
            <div class="rdp-num <?= $rdArea['restaurant']['profit'] < 0 ? 'text-danger' : 'text-primary' ?>"><?= $rdFmt($rdArea['restaurant']['profit']) ?></div>
            <div class="rdp-sub"><?= t('profit') ?> · <?= sprintf(t('Sales %s'), $rdFmt($rdArea['restaurant']['sales'])) ?></div>
        </div></div>
        <div class="col-6 col-lg-4"><div class="rdp-stat h-100">
            <div class="rdp-label"><i class="bi bi-shop me-1"></i><?= t('Shops & other') ?></div>
            <div class="rdp-num <?= $rdArea['other']['profit'] < 0 ? 'text-danger' : 'text-primary' ?>"><?= $rdFmt($rdArea['other']['profit']) ?></div>
            <div class="rdp-sub"><?= t('profit') ?> · <?= sprintf(t('Sales %s'), $rdFmt($rdArea['other']['sales'])) ?></div>
        </div></div>
    </div>
    <div class="rdp-card mb-2">
        <div class="rdp-card-h">
            <h6><i class="bi bi-graph-up-arrow me-1"></i><?= t('Profit: restaurant and shops') ?></h6>
            <div class="btn-group btn-group-sm rdp-period" role="group">
                <button type="button" class="btn btn-outline-primary active" data-p="daily"><?= t('Daily') ?></button>
                <button type="button" class="btn btn-outline-primary" data-p="weekly"><?= t('Weekly') ?></button>
                <button type="button" class="btn btn-outline-primary" data-p="monthly"><?= t('Monthly') ?></button>
            </div>
        </div>
        <div class="rdp-chart"><canvas id="<?= $rdUid ?>-trend"></canvas></div>
    </div>
    <div class="small text-muted"><i class="bi bi-info-circle me-1"></i><?= t('Costs that belong to the whole company (payroll, rent) are counted under "Shops & other".') ?></div>

<?php else: ?>
    <!-- ───────── 1. Hero: how am I doing today? ───────── -->
    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-lg-3"><div class="rdp-stat h-100">
            <div class="rdp-label"><?= t('Sales today') ?></div>
            <div class="rdp-num text-primary"><?= $rdFmt($rdHero['sales']['today']) ?></div>
            <div class="rdp-sub"><?= htmlspecialchars($rdCur) ?> · <?= sprintf(t('Yesterday %s'), $rdFmt($rdHero['sales']['yesterday'])) ?></div>
            <?php if ($rdHero['sales']['trend7'] !== null): ?><div class="rdp-sub2"><?= $rdDelta($rdHero['sales']['trend7'], $rdTrendNote) ?> <?= t('last 7 days') ?></div><?php endif; ?>
        </div></div>
        <div class="col-6 col-lg-3"><div class="rdp-stat h-100">
            <div class="rdp-label"><?= t('Orders today') ?></div>
            <div class="rdp-num text-primary"><?= (int)$rdHero['orders']['today'] ?></div>
            <div class="rdp-sub"><?= t('paid orders') ?> · <?= sprintf(t('Yesterday %s'), (int)$rdHero['orders']['yesterday']) ?></div>
            <?php if ($rdHero['orders']['trend7'] !== null): ?><div class="rdp-sub2"><?= $rdDelta($rdHero['orders']['trend7'], $rdTrendNote) ?> <?= t('last 7 days') ?></div><?php endif; ?>
        </div></div>
        <div class="col-6 col-lg-3"><div class="rdp-stat h-100">
            <div class="rdp-label"><?= t('Average bill') ?></div>
            <div class="rdp-num text-primary"><?= $rdFmt($rdHero['avg_bill']['today']) ?></div>
            <div class="rdp-sub"><?= t('per order') ?> · <?= sprintf(t('Yesterday %s'), $rdFmt($rdHero['avg_bill']['yesterday'])) ?></div>
            <?php if ($rdHero['avg_bill']['trend7'] !== null): ?><div class="rdp-sub2"><?= $rdDelta($rdHero['avg_bill']['trend7'], $rdTrendNote) ?> <?= t('last 7 days') ?></div><?php endif; ?>
        </div></div>
        <div class="col-6 col-lg-3"><div class="rdp-stat h-100">
            <div class="rdp-label"><?= t('Tables with order') ?></div>
            <div class="rdp-num <?= $rdHero['open']['tables'] > 0 ? 'text-danger' : 'text-primary' ?>"><?= (int)$rdHero['open']['tables'] ?></div>
            <div class="rdp-sub"><?= sprintf(t('%s not paid yet'), $rdFmt($rdHero['open']['amount'])) ?></div>
        </div></div>
    </div>

    <!-- ───────── 2. Trend + the profit story ───────── -->
    <?php if ($rdMoney): ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="rdp-card h-100">
                <div class="rdp-card-h">
                    <h6><i class="bi bi-graph-up-arrow me-1"></i><?= t('Performance') ?></h6>
                    <div class="btn-group btn-group-sm rdp-period" role="group">
                        <button type="button" class="btn btn-outline-primary active" data-p="daily"><?= t('Daily') ?></button>
                        <button type="button" class="btn btn-outline-primary" data-p="weekly"><?= t('Weekly') ?></button>
                        <button type="button" class="btn btn-outline-primary" data-p="monthly"><?= t('Monthly') ?></button>
                    </div>
                </div>
                <div class="rdp-chart"><canvas id="<?= $rdUid ?>-trend"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <?php $cur = $rdStory['cur']; $hasSales = $cur['sales'] > 0; $unc = count($rdDishes['needs_cost']); ?>
            <div class="rdp-card h-100">
                <div class="rdp-card-h">
                    <h6><i class="bi bi-cash-coin me-1"></i><?= t('Where your money went') ?></h6>
                    <?php if ($unc > 0 || $rdWarn): ?>
                    <span class="rdp-pill" title="<?= htmlspecialchars(sprintf(t('%d item(s) sold have no recipe or purchase cost, so their cost is not counted and profit may look too high.'), $unc)) ?>"><?= t('Estimated') ?></span>
                    <?php else: ?>
                    <span class="rdp-pill rdp-pill-ok" title="<?= htmlspecialchars(t('Every item sold this month has a cost.')) ?>"><?= t('Complete') ?></span>
                    <?php endif; ?>
                </div>
                <div class="p-3">
                    <div class="rdp-sub"><?= t('Net Profit this month') ?></div>
                    <div class="rdp-num rdp-num-lg <?= $cur['profit'] < 0 ? 'text-danger' : 'text-primary' ?>"><?= $rdFmt($cur['profit']) ?> <small><?= htmlspecialchars($rdCur) ?></small></div>
                    <div class="rdp-sub mb-2">
                        <?php if ($hasSales): ?><strong><?= rtrim(rtrim(number_format($rdStory['margin'], 1), '0'), '.') ?>%</strong> <?= t('margin') ?><?php endif; ?>
                        <?= $rdDelta($rdStory['profit_vs_prev'], sprintf(t('compared with %1$s – %2$s last month'), date('d M', strtotime($rdStory['prev_from'])), date('d M', strtotime($rdStory['prev_to'])))) ?>
                    </div>
                    <?php if ($hasSales): $p100 = $rdStory['per100']; $lossy = $cur['profit'] < 0; ?>
                    <div class="rdp-bar" role="img" aria-label="<?= htmlspecialchars(t('Where your money went')) ?>">
                        <span class="rdp-bar-food" style="width:<?= max(0, min(100, $p100['cost'])) ?>%"></span>
                        <span class="rdp-bar-exp" style="width:<?= max(0, min(100 - min(100, $p100['cost']), $p100['expenses'])) ?>%"></span>
                        <?php if (!$lossy): ?><span class="rdp-bar-profit" style="width:<?= max(0, min(100, $p100['profit'])) ?>%"></span><?php endif; ?>
                    </div>
                    <ul class="rdp-legend">
                        <li><i class="rdp-dot rdp-bar-food"></i><?= t('Cost of Goods') ?><b><?= $rdFmt($cur['cost']) ?></b></li>
                        <li><i class="rdp-dot" style="background:#adb5bd"></i><?= t('Gross Profit') ?><b><?= $rdFmt($cur['sales'] - $cur['cost']) ?></b></li>
                        <li><i class="rdp-dot rdp-bar-exp"></i><?= t('Expenses') ?><b><?= $rdFmt($cur['expenses']) ?></b></li>
                        <li><i class="rdp-dot rdp-bar-profit"></i><?= t('Net Profit') ?><b class="<?= $lossy ? 'text-danger' : '' ?>"><?= $rdFmt($cur['profit']) ?></b></li>
                    </ul>
                    <?php foreach ($rdWarn as $w):
                        if ($w['type'] === 'no_cost')             { $wt = sprintf(t('%d item(s) sold have no cost.'), $w['n']); $wl = getUrl('restaurant/menu'); }
                        elseif ($w['type'] === 'no_food_bought')  { $wt = t('You sold dishes with no recipe, but no food purchase is recorded this month.'); $wl = getUrl('restaurant/purchases'); }
                        else                                      { $wt = t('No expenses are recorded this month — gas, wages, rent?'); $wl = getUrl('expenses'); } ?>
                    <a href="<?= $wl ?>" class="d-block small text-danger fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i><?= $wt ?></a>
                    <?php endforeach; ?>
                    <?php if ($rdWarn): ?><div class="small text-danger mb-2"><?= t('Until these are done, the real profit is lower than this figure.') ?></div><?php endif; ?>
                    <div class="rdp-story">
                        <?php if ($lossy): ?>
                            <i class="bi bi-exclamation-circle me-1"></i><?= t('You are spending more than you sell this month.') ?>
                        <?php else: ?>
                            <?= sprintf(t('For every 100 you sell: %1$s goes to food, %2$s to other costs, and %3$s is yours.'), '<b>' . rtrim(rtrim(number_format($p100['cost'], 1), '0'), '.') . '</b>', '<b>' . rtrim(rtrim(number_format($p100['expenses'], 1), '0'), '.') . '</b>', '<b class="text-primary">' . rtrim(rtrim(number_format($p100['profit'], 1), '0'), '.') . '</b>') ?>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="rdp-empty"><i class="bi bi-hourglass-split"></i><div><?= t('Your profit story appears after your first orders this month.') ?></div></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <!-- ───────── 3. What earns the money ───────── -->
    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="rdp-card h-100">
                <div class="rdp-card-h">
                    <h6><i class="bi bi-trophy me-1"></i><?= t('What earns you money') ?></h6>
                    <?php if ($rdMoney): ?>
                    <div class="btn-group btn-group-sm rdp-tabs" role="group">
                        <button type="button" class="btn btn-outline-primary active" data-t="earners"><?= t('Best earners') ?></button>
                        <button type="button" class="btn btn-outline-primary" data-t="sellers"><?= t('Best sellers') ?></button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="rdp-tab <?= $rdMoney ? '' : 'd-none' ?>" data-t="earners">
                    <?php if (!$rdDishes['best_earners']): ?>
                        <div class="rdp-empty"><i class="bi bi-journal-richtext"></i><div><?= $rdDishes['items'] ? t('Add a recipe to your dishes (and a purchase cost to your drinks) to see which items earn you the most.') : t('Nothing sold this month yet.') ?></div></div>
                    <?php else: ?>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0 rdp-table">
                        <thead><tr><th><?= t('Item') ?></th><th class="text-end"><?= t('Plates') ?></th><th class="text-end"><?= t('Profit per plate') ?></th><th class="text-end"><?= t('Margin') ?></th><th class="text-end"><?= t('Estimated profit') ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($rdDishes['best_earners'] as $d): ?>
                        <tr>
                            <td class="fw-semibold"><?= safe_output($d['name']) ?>
                                <?php if (!empty($d['class'])): ?><span class="rdp-class rdp-class-<?= $d['class'] ?>"><?= $rdClassLabel[$d['class']] ?></span><?php endif; ?></td>
                            <td class="text-end"><?= rtrim(rtrim(number_format($d['qty'], 2), '0'), '.') ?></td>
                            <td class="text-end"><?= $rdFmt($d['profit_per_plate']) ?></td>
                            <td class="text-end"><?= rtrim(rtrim(number_format((float)$d['margin'], 1), '0'), '.') ?>%</td>
                            <td class="text-end fw-bold <?= $d['est_profit'] < 0 ? 'text-danger' : 'text-primary' ?>"><?= $rdFmt($d['est_profit']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <div class="rdp-foot"><?= t('Profit per plate = what the customer paid minus the recipe or purchase cost. An estimate to guide your menu; the profit figure above comes from your books.') ?></div>
                    <?php endif; ?>
                </div>
                <div class="rdp-tab <?= $rdMoney ? 'd-none' : '' ?>" data-t="sellers">
                    <?php if (!$rdDishes['best_sellers']): ?>
                        <div class="rdp-empty"><i class="bi bi-journal-richtext"></i><div><?= t('Nothing sold this month yet.') ?></div></div>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($rdDishes['best_sellers'] as $i => $d): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="text-truncate"><span class="badge rounded-pill me-2" style="background:#cfe2ff;color:#084298;"><?= $i + 1 ?></span><?= safe_output($d['name']) ?></span>
                            <span class="fw-semibold text-nowrap"><?= rtrim(rtrim(number_format($d['qty'], 2), '0'), '.') ?> ×</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($rdMoney && $rdDishes['needs_cost']): $nc = $rdDishes['needs_cost']; ?>
                <a href="<?= getUrl('restaurant/menu') ?>" class="rdp-nudge" id="<?= $rdUid ?>-nudge">
                    <i class="bi bi-lightbulb"></i>
                    <span><strong><?= sprintf(t('%d item(s) have no cost yet'), count($nc)) ?></strong> —
                        <?= safe_output(implode(', ', array_map(fn($x) => $x['name'], array_slice($nc, 0, 3)))) ?><?= count($nc) > 3 ? '…' : '' ?>.
                        <?= t('Add a recipe or purchase cost to see their profit.') ?></span>
                    <i class="bi bi-chevron-right ms-auto"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-5 d-flex flex-column gap-3">
            <div class="rdp-card">
                <div class="rdp-card-h"><h6><i class="bi bi-clock me-1"></i><?= t('Busy hours') ?></h6>
                    <?php if ($rdHours['peak'] !== null): ?><span class="rdp-sub"><?= sprintf(t('Busiest: %s'), sprintf('%02d:00', $rdHours['peak'])) ?></span><?php endif; ?></div>
                <div class="rdp-chart rdp-chart-sm"><canvas id="<?= $rdUid ?>-hours"></canvas></div>
                <?php if ($rdHours['total'] === 0): ?><div class="rdp-foot"><?= t('Orders by hour of the day (last 30 days) appear here.') ?></div><?php endif; ?>
            </div>
            <div class="rdp-card">
                <div class="rdp-card-h"><h6><i class="bi bi-pie-chart me-1"></i><?= t('How customers order') ?></h6><span class="rdp-sub"><?= t('this month') ?></span></div>
                <?php if ($rdTypeRows): ?>
                <div class="d-flex align-items-center gap-3 p-3 flex-wrap">
                    <div class="rdp-donut"><canvas id="<?= $rdUid ?>-types"></canvas></div>
                    <ul class="rdp-legend flex-grow-1 mb-0" style="min-width:140px;">
                        <?php $tt = array_sum(array_column($rdTypeRows, 'sales')); foreach ($rdTypeRows as $i => $tr): ?>
                        <li><i class="rdp-dot" style="background:<?= ['#0d6efd', '#052c65', '#9ec5fe', '#6ea8fe'][$i % 4] ?>"></i><?= safe_output($rdTypes[$tr['sale_type']] ?? t('Order')) ?>
                            <b><?= $tt > 0 ? round($tr['sales'] / $tt * 100) : 0 ?>%</b></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <div class="rdp-empty"><i class="bi bi-pie-chart"></i><div><?= t('Dine in, take away and delivery split appears after your first orders.') ?></div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ───────── Bought → used → left ───────── -->
    <?php if ($rdMoney && $rdScope === 'company'): ?>
    <div class="rdp-card mb-3">
        <div class="rdp-card-h"><h6><i class="bi bi-arrow-left-right me-1"></i><?= t('Money check this month') ?></h6></div>
        <div class="p-3">
            <div class="row g-2">
                <div class="col-6 col-md"><div class="rdp-stat"><div class="rdp-label"><?= t('Added to stock') ?></div><div class="rdp-num text-primary"><?= $rdFmt($rdCheck['added']) ?></div></div></div>
                <div class="col-6 col-md"><div class="rdp-stat"><div class="rdp-label"><?= t('Used for food sold') ?></div><div class="rdp-num text-primary"><?= $rdFmt($rdCheck['used']) ?></div></div></div>
                <div class="col-6 col-md"><div class="rdp-stat"><div class="rdp-label"><?= t('In stock now') ?></div><div class="rdp-num <?= $rdCheck['in_stock'] < 0 ? 'text-danger' : 'text-primary' ?>"><?= $rdFmt($rdCheck['in_stock']) ?></div></div></div>
                <div class="col-6 col-md"><div class="rdp-stat"><div class="rdp-label"><?= t('Groceries (not counted)') ?></div><div class="rdp-num text-primary"><?= $rdFmt($rdCheck['groceries']) ?></div></div></div>
                <?php if ($rdOwed !== null): ?>
                <div class="col-12 col-md"><a href="<?= getUrl('pos/credit-customers') ?>" class="text-decoration-none"><div class="rdp-stat"><div class="rdp-label"><?= t('Owed to me') ?></div><div class="rdp-num <?= $rdOwed > 0 ? 'text-danger' : 'text-primary' ?>"><?= $rdFmt($rdOwed) ?></div></div></a></div>
                <?php endif; ?>
            </div>
            <div class="rdp-sub mt-2"><?= t('What you buy for stock is not a cost yet. It becomes a cost as the food is sold; the rest stays in stock.') ?></div>
            <?php if ($rdCheck['in_stock'] < 0): ?><a href="<?= getUrl('restaurant/purchases') ?>" class="d-block small text-danger fw-semibold mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= t('Stock is below zero: more was sold than was recorded as bought. Record your purchases.') ?></a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ───────── 4. Needs attention ───────── -->
    <?php if ($rdAttention): ?>
    <div class="rdp-card mb-3">
        <div class="rdp-card-h"><h6><i class="bi bi-bell me-1"></i><?= t('Needs your attention') ?></h6></div>
        <div class="list-group list-group-flush">
        <?php foreach ($rdAttention as $a):
            if ($a['type'] === 'old_bills')   { $ico = 'bi-receipt'; $txt = sprintf(t('%1$d bill(s) have been open for over 90 minutes (the longest %2$d min).'), $a['n'], $a['extra']); $lnk = getUrl('restaurant/tables-board'); }
            elseif ($a['type'] === 'kitchen_wait') { $ico = 'bi-egg-fried'; $txt = sprintf(t('%1$d kitchen order(s) have waited over 20 minutes (the longest %2$d min).'), $a['n'], $a['extra']); $lnk = getUrl('restaurant/kitchen-dashboard'); }
            elseif ($a['type'] === 'low_stock')    { $ico = 'bi-cup-straw'; $txt = sprintf(t('%d drink(s) or ingredient(s) are running low.'), $a['n']); $lnk = canView('pos_restock') ? getUrl('restaurant/purchases') : getUrl('restaurant/menu'); }
            else                                   { $ico = 'bi-lightbulb'; $txt = sprintf(t('%d item(s) sold have no cost, so profit may look too high.'), $a['n']); $lnk = getUrl('restaurant/menu'); } ?>
        <a href="<?= $lnk ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="bi <?= $ico ?> text-primary"></i><span class="small"><?= $txt ?></span><i class="bi bi-chevron-right ms-auto text-muted"></i></a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<style>
.rdp .rdp-stat { background:#e7f0ff; border:1px solid #b6ccfe; border-radius:12px; padding:12px 14px; }
.rdp .rdp-label { font-size:.78rem; color:#6c757d; font-weight:600; }
.rdp .rdp-num { font-size:1.55rem; font-weight:700; line-height:1.2; overflow-wrap:anywhere; }
.rdp .rdp-num small { font-size:.7rem; font-weight:600; color:#6c757d; }
.rdp .rdp-num-lg { font-size:2rem; }
.rdp .rdp-sub { font-size:.78rem; color:#6c757d; }
.rdp .rdp-sub2 { font-size:.72rem; color:#6c757d; margin-top:2px; }
.rdp .rdp-chip { display:inline-block; font-size:.7rem; font-weight:700; border-radius:20px; padding:1px 7px; margin-left:2px; }
.rdp .rdp-up { background:#cfe2ff; color:#084298; }
.rdp .rdp-down { background:#fde8ea; color:#b02a37; }
.rdp .rdp-card { background:#fff; border:1px solid #dbe6fb; border-radius:14px; box-shadow:0 1px 3px rgba(13,110,253,.06); overflow:hidden; }
.rdp .rdp-card-h { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:10px 14px; border-bottom:1px solid #eef3fd; flex-wrap:wrap; }
.rdp .rdp-card-h h6 { margin:0; font-weight:700; color:#0b3d91; }
.rdp .rdp-chart { position:relative; height:300px; padding:10px 12px 12px; }
.rdp .rdp-chart-sm { height:170px; }
.rdp .rdp-donut { position:relative; width:130px; height:130px; flex:0 0 130px; }
.rdp .rdp-pill { font-size:.68rem; font-weight:700; border-radius:20px; padding:2px 9px; background:#e9ecef; color:#495057; cursor:help; }
.rdp .rdp-pill-ok { background:#cfe2ff; color:#084298; }
.rdp .rdp-bar { display:flex; height:16px; border-radius:10px; overflow:hidden; background:#eef3fd; }
.rdp .rdp-bar span { display:block; height:100%; }
.rdp .rdp-bar-food { background:#052c65; } .rdp .rdp-bar-exp { background:#9ec5fe; } .rdp .rdp-bar-profit { background:#0d6efd; }
.rdp .rdp-legend { list-style:none; padding:0; margin:10px 0 0; font-size:.82rem; }
.rdp .rdp-legend li { display:flex; align-items:center; gap:7px; padding:3px 0; }
.rdp .rdp-legend li b { margin-left:auto; }
.rdp .rdp-dot { width:10px; height:10px; border-radius:50%; display:inline-block; flex:0 0 10px; }
.rdp .rdp-story { margin-top:10px; padding:9px 11px; background:#f4f8ff; border-radius:10px; font-size:.82rem; color:#1e3a8a; }
.rdp .rdp-split { height:8px; border-radius:6px; background:#9ec5fe; overflow:hidden; }
.rdp .rdp-split span { display:block; height:100%; background:#0d6efd; }
.rdp .rdp-empty { display:flex; flex-direction:column; align-items:center; gap:6px; padding:26px 16px; text-align:center; color:#6c757d; font-size:.85rem; }
.rdp .rdp-empty i { font-size:1.8rem; color:#9ec5fe; }
.rdp .rdp-foot { padding:8px 14px 10px; font-size:.72rem; color:#6c757d; }
.rdp .rdp-table th { font-size:.72rem; color:#6c757d; font-weight:600; white-space:nowrap; }
.rdp .rdp-class { display:inline-block; font-size:.62rem; font-weight:700; border-radius:20px; padding:1px 7px; margin-left:4px; vertical-align:middle; }
.rdp .rdp-class-star { background:#0d6efd; color:#fff; } .rdp .rdp-class-gem { background:#bfdbfe; color:#1e3a8a; }
.rdp .rdp-class-popular { background:#cfe2ff; color:#084298; } .rdp .rdp-class-review { background:#e9ecef; color:#495057; }
.rdp .rdp-nudge { display:flex; align-items:center; gap:10px; margin:8px 12px 12px; padding:10px 12px; border-radius:10px; background:#f4f8ff; border:1px dashed #9ec5fe; color:#1e3a8a; font-size:.82rem; text-decoration:none; }
.rdp .rdp-nudge:hover { background:#e7f0ff; }
.rdp .rdp-nudge .bi-lightbulb { font-size:1.2rem; color:#0d6efd; }
@media (max-width:575.98px) { .rdp .rdp-chart { height:240px; } .rdp .rdp-num { font-size:1.3rem; } .rdp .rdp-num-lg { font-size:1.7rem; } }
</style>

<script>
(function () {
    const UID = <?= json_encode($rdUid) ?>, SCOPE = <?= json_encode($rdScope) ?>, CUR = <?= json_encode($rdCur) ?>;
    const TREND = <?= json_encode($rdTrend) ?>;
    const HOURS = <?= json_encode($rdScope === 'combined' ? null : $rdHours['hours']) ?>;
    const TYPES = <?= json_encode($rdScope === 'combined' ? [] : array_map(fn($r) => ['label' => $rdTypes[$r['sale_type']] ?? t('Order'), 'sales' => $r['sales']], $rdTypeRows)) ?>;
    const L = { sales: <?= json_encode(t('Sales')) ?>, costs: <?= json_encode(t('Costs')) ?>, profit: <?= json_encode(t('Profit')) ?>,
                restaurant: <?= json_encode(t('Restaurant')) ?>, other: <?= json_encode(t('Shops & other')) ?>, orders: <?= json_encode(t('Orders')) ?> };
    const URL_API = <?= json_encode(buildUrl('api/restaurant/dashboard_panel.php')) ?>;
    const BLUE = '#0d6efd', NAVY = '#052c65', SKY = '#9ec5fe', RED = '#dc3545';
    const fmt = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
    let trendChart = null;

    function withChart(cb) {
        if (typeof Chart !== 'undefined') { cb(); return; }
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
        s.onload = cb; document.head.appendChild(s);
    }
    function el(id) { return document.getElementById(UID + '-' + id); }
    const tipMoney = { callbacks: { label: c => ' ' + c.dataset.label + ': ' + fmt(c.parsed.y) + ' ' + CUR } };

    function drawTrend(d) {
        const cv = el('trend'); if (!cv) return;
        if (trendChart) trendChart.destroy();
        const datasets = SCOPE === 'combined' ? [
            { type: 'bar', label: L.restaurant, data: d.profit_r, backgroundColor: BLUE, borderRadius: 4, stack: 'p' },
            { type: 'bar', label: L.other, data: d.profit_o, backgroundColor: SKY, borderRadius: 4, stack: 'p' },
            { type: 'line', label: L.profit, data: d.profit, borderColor: NAVY, backgroundColor: NAVY, tension: .3, pointRadius: 2, borderWidth: 2 }
        ] : [
            { type: 'bar', label: L.sales, data: d.sales, backgroundColor: SKY, borderRadius: 4, order: 3 },
            { type: 'bar', label: L.costs, data: d.cost.map((c, i) => c + d.expenses[i]), backgroundColor: NAVY, borderRadius: 4, order: 2 },
            { type: 'line', label: L.profit, data: d.profit, borderColor: BLUE, backgroundColor: BLUE, tension: .3, pointRadius: 2, borderWidth: 3, order: 1 }
        ];
        trendChart = new Chart(cv.getContext('2d'), {
            data: { labels: d.labels, datasets: datasets },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: tipMoney },
                scales: { x: { stacked: SCOPE === 'combined', grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                          y: { stacked: SCOPE === 'combined', grid: { color: '#eef3fd' }, ticks: { callback: v => fmt(v) } } } }
        });
    }
    function drawHours() {
        const cv = el('hours'); if (!cv || !HOURS) return;
        const nz = HOURS.map((v, i) => v > 0 ? i : -1).filter(i => i >= 0);
        const a = nz.length ? Math.min(nz[0], 8) : 8, b = nz.length ? Math.max(nz[nz.length - 1], 20) : 20;
        const labels = [], vals = [], cols = []; const mx = Math.max.apply(null, HOURS);
        for (let h = a; h <= b; h++) { labels.push(h); vals.push(HOURS[h]); cols.push(mx > 0 && HOURS[h] === mx ? BLUE : SKY); }
        new Chart(cv.getContext('2d'), { type: 'bar', data: { labels: labels, datasets: [{ label: L.orders, data: vals, backgroundColor: cols, borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false }, ticks: { maxRotation: 0 } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#eef3fd' } } } } });
    }
    function drawTypes() {
        const cv = el('types'); if (!cv || !TYPES.length) return;
        new Chart(cv.getContext('2d'), { type: 'doughnut', data: { labels: TYPES.map(x => x.label), datasets: [{ data: TYPES.map(x => x.sales), backgroundColor: [BLUE, NAVY, SKY, '#6ea8fe'], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + fmt(c.parsed) + ' ' + CUR } } } } });
    }
    withChart(function () { drawTrend(TREND); drawHours(); drawTypes(); });

    const root = document.getElementById(UID);
    root.querySelectorAll('.rdp-period button').forEach(function (b) {
        b.addEventListener('click', function () {
            root.querySelectorAll('.rdp-period button').forEach(x => x.classList.remove('active')); b.classList.add('active');
            fetch(URL_API + '?part=trend&scope=' + encodeURIComponent(SCOPE) + '&period=' + encodeURIComponent(b.dataset.p), { credentials: 'same-origin' })
                .then(r => r.json()).then(function (j) { if (j && j.success) drawTrend(j.trend); });
        });
    });
    root.querySelectorAll('.rdp-tabs button').forEach(function (b) {
        b.addEventListener('click', function () {
            root.querySelectorAll('.rdp-tabs button').forEach(x => x.classList.remove('active')); b.classList.add('active');
            root.querySelectorAll('.rdp-tab').forEach(t => t.classList.toggle('d-none', t.dataset.t !== b.dataset.t));
        });
    });
})();
</script>
</div>
