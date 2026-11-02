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
 *
 * Layout 2 ("Side actions", arrangement A chosen by the owner, 2026-11-02) is for the restaurant-only company ('company'):
 * quick actions down the left, six coloured cards for the chosen period, performance beside a side panel (needs attention /
 * activities / running low / orders), then where the money went, what earns, how customers order. No black or red.
 * The mixed-company strip ('restaurant' / 'combined') keeps the layout it always had.
 */
require_once ROOT_DIR . '/core/restaurant_insights.php';

// Profit and cost figures follow the same rule as the retail Performance card (reports access).
$rdMoney  = hasReportsAccess() || canView('invoices') || canView('sales_report');
$rdScope  = in_array($rdScope ?? '', ['company', 'restaurant', 'combined'], true) ? $rdScope : 'restaurant';
$rdL2     = ($rdScope === 'company');
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
    // The warnings look at the whole month in every view: no expenses TODAY is normal, none this MONTH is not.
    $rdWarn = $rdMoney ? rdProfitWarnings($rdStory['cur'], $rdDishes['items'], $rdCheck['groceries']) : [];
    $rdUncMonth = count($rdDishes['needs_cost']);
    if ($rdL2) {                                          // the "Showing" choice drives the cards, the money story, what earns and how customers order
        $rdRange = rdPeriodRange((string)($_GET['rd_period'] ?? 'today'), $rdToday);
        $rdPLabel = ['today' => t('Today'), 'week' => t('This week'), 'month' => t('This month'), 'quarter' => t('This quarter'), 'year' => t('This year')][$rdRange['key']];
        $rdStory = rdPeriodStory($pdo, $rdJe, $rdIds, $rdRange);
        $rdDishes = rdDishEarnings($pdo, $rdIds, $rdRange['from'], $rdRange['to']);
        $rdTypeRows = restaurantSalesByOrderType($pdo, $rdRange['from'], $rdRange['to'], $rdIds);
    }
    $rdOwed = null;
    if ($rdMoney && canView('pos')) {                      // the same figure as "Who Owes Me" and the retail Credit card
        require_once ROOT_DIR . '/core/pos_credit_aging.php';
        $rdOwed = posCreditTotalOutstanding($pdo, scopeFilterSqlNullable('warehouse', 's'));
    }
    // The cost warning now sits with the profit figure it concerns, so it is not repeated in "Needs your attention".
    $rdAttention = rdAttention($pdo, $rdIds, 0, count($rdData['low_drinks']));
    if ($rdL2) {
        require_once ROOT_DIR . '/core/restaurant_scope.php';
        $rdX = rdDashExtras($pdo, $rdIds);
        $rdKitchenOn = (function_exists('restaurantKitchenMode') && restaurantKitchenMode() !== 'none');
        $rdActs = canView('audit_logs') ? rdRecentActivities($pdo, 8) : [];   // same permission as the retail dashboard's activity list
    }
}
$rdTrendNote = t('The last 7 days compared with the 7 days before'); // complete days only — today is never compared with a full day
$rdClassLabel = ['star' => t('Star'), 'gem' => t('Hidden gem'), 'popular' => t('Popular, small profit'), 'review' => t('Review')];
?>
<div class="rdp<?= $rdL2 ? ' rdp-l2' : '' ?>" id="<?= $rdUid ?>" data-scope="<?= htmlspecialchars($rdScope) ?>">

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

<?php else:
    $cur = $rdStory['cur']; $hasSales = $cur['sales'] > 0; $unc = $rdUncMonth;

    // ───── the blocks, each built once and placed by the layout below ─────
    ob_start(); ?>
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
    <?php $b_hero_old = ob_get_clean();

    ob_start(); ?>
            <div class="rdp-card h-100">
                <div class="rdp-card-h">
                    <h6><i class="bi bi-graph-up-arrow me-1"></i><?= t('Performance') ?></h6>
                    <?php if ($rdL2): ?>
                    <select class="form-select form-select-sm w-auto rdp-period-sel" aria-label="<?= htmlspecialchars(t('Performance')) ?>">
                        <option value="daily" selected><?= t('Daily') ?></option>
                        <option value="weekly"><?= t('Weekly') ?></option>
                        <option value="monthly"><?= t('Monthly') ?></option>
                        <option value="quarterly"><?= t('Quarterly') ?></option>
                        <option value="yearly"><?= t('Yearly') ?></option>
                    </select>
                    <?php else: ?>
                    <div class="btn-group btn-group-sm rdp-period" role="group">
                        <button type="button" class="btn btn-outline-primary active" data-p="daily"><?= t('Daily') ?></button>
                        <button type="button" class="btn btn-outline-primary" data-p="weekly"><?= t('Weekly') ?></button>
                        <button type="button" class="btn btn-outline-primary" data-p="monthly"><?= t('Monthly') ?></button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="rdp-chart"><canvas id="<?= $rdUid ?>-trend"></canvas></div>
            </div>
    <?php $b_perf = ob_get_clean();

    ob_start(); ?>
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
                    <div class="rdp-sub"><?= $rdL2 ? t('Net Profit') . ' · ' . htmlspecialchars($rdPLabel) : t('Net Profit this month') ?></div>
                    <div class="rdp-num rdp-num-lg <?= $cur['profit'] < 0 ? 'text-danger' : 'text-primary' ?>"><?= $rdFmt($cur['profit']) ?> <small><?= htmlspecialchars($rdCur) ?></small></div>
                    <div class="rdp-sub mb-2">
                        <?php if ($hasSales): ?><strong><?= rtrim(rtrim(number_format($rdStory['margin'], 1), '0'), '.') ?>%</strong> <?= t('margin') ?><?php endif; ?>
                        <?= $rdDelta($rdStory['profit_vs_prev'], $rdL2 ? sprintf(t('compared with %1$s – %2$s'), date('d M', strtotime($rdStory['prev_from'])), date('d M', strtotime($rdStory['prev_to']))) : sprintf(t('compared with %1$s – %2$s last month'), date('d M', strtotime($rdStory['prev_from'])), date('d M', strtotime($rdStory['prev_to'])))) ?>
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
                    <div class="rdp-empty"><i class="bi bi-hourglass-split"></i><div><?= $rdL2 ? t('Nothing sold in this period yet.') : t('Your profit story appears after your first orders this month.') ?></div></div>
                    <?php endif; ?>
                </div>
            </div>
    <?php $b_money = ob_get_clean();

    ob_start(); ?>
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
                        <div class="rdp-empty"><i class="bi bi-journal-richtext"></i><div><?= $rdDishes['items'] ? t('Add a recipe to your dishes (and a purchase cost to your drinks) to see which items earn you the most.') : ($rdL2 ? t('Nothing sold in this period yet.') : t('Nothing sold this month yet.')) ?></div></div>
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
                        <div class="rdp-empty"><i class="bi bi-journal-richtext"></i><div><?= $rdL2 ? t('Nothing sold in this period yet.') : t('Nothing sold this month yet.') ?></div></div>
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
    <?php $b_earn = ob_get_clean();

    ob_start(); ?>
            <div class="rdp-card">
                <div class="rdp-card-h"><h6><i class="bi bi-clock me-1"></i><?= t('Busy hours') ?></h6>
                    <?php if ($rdHours['peak'] !== null): ?><span class="rdp-sub"><?= sprintf(t('Busiest: %s'), sprintf('%02d:00', $rdHours['peak'])) ?></span><?php endif; ?></div>
                <div class="rdp-chart rdp-chart-sm"><canvas id="<?= $rdUid ?>-hours"></canvas></div>
                <?php if ($rdHours['total'] === 0): ?><div class="rdp-foot"><?= t('Orders by hour of the day (last 30 days) appear here.') ?></div><?php endif; ?>
            </div>
    <?php $b_busy = ob_get_clean();

    ob_start(); ?>
            <div class="rdp-card">
                <div class="rdp-card-h"><h6><i class="bi bi-pie-chart me-1"></i><?= t('How customers order') ?></h6><span class="rdp-sub"><?= $rdL2 ? htmlspecialchars($rdPLabel) : t('this month') ?></span></div>
                <?php if ($rdTypeRows): ?>
                <div class="d-flex align-items-center gap-3 p-3 flex-wrap">
                    <div class="rdp-donut"><canvas id="<?= $rdUid ?>-types"></canvas></div>
                    <ul class="rdp-legend flex-grow-1 mb-0" style="min-width:140px;">
                        <?php $tt = array_sum(array_column($rdTypeRows, 'sales')); $rdTypeCols = $rdL2 ? ['#0d6efd', '#d97706', '#7c93b5', '#a9c8f8'] : ['#0d6efd', '#052c65', '#9ec5fe', '#6ea8fe'];
                        foreach ($rdTypeRows as $i => $tr): ?>
                        <li><i class="rdp-dot" style="background:<?= $rdTypeCols[$i % 4] ?>"></i><?= safe_output($rdTypes[$tr['sale_type']] ?? t('Order')) ?>
                            <b><?= $tt > 0 ? round($tr['sales'] / $tt * 100) : 0 ?>%</b></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <div class="rdp-empty"><i class="bi bi-pie-chart"></i><div><?= t('Dine in, take away and delivery split appears after your first orders.') ?></div></div>
                <?php endif; ?>
            </div>
    <?php $b_types = ob_get_clean();

    // "Needs attention" rows (bills open too long, kitchen orders waiting, running low)
    $rdAttRows = [];
    foreach ($rdAttention as $a) {
        if ($a['type'] === 'old_bills')        { $ico = 'bi-receipt'; $txt = sprintf(t('%1$d bill(s) have been open for over 90 minutes (the longest %2$d min).'), $a['n'], $a['extra']); $lnk = getUrl('restaurant/tables-board'); }
        elseif ($a['type'] === 'kitchen_wait') { $ico = 'bi-egg-fried'; $txt = sprintf(t('%1$d kitchen order(s) have waited over 20 minutes (the longest %2$d min).'), $a['n'], $a['extra']); $lnk = getUrl('restaurant/kitchen-dashboard'); }
        elseif ($a['type'] === 'low_stock')    { $ico = 'bi-cup-straw'; $txt = sprintf(t('%d drink(s) or ingredient(s) are running low.'), $a['n']); $lnk = canView('pos_restock') ? getUrl('restaurant/purchases') : getUrl('restaurant/menu'); }
        else                                   { $ico = 'bi-lightbulb'; $txt = sprintf(t('%d item(s) sold have no cost, so profit may look too high.'), $a['n']); $lnk = getUrl('restaurant/menu'); }
        $rdAttRows[] = [$ico, $txt, $lnk];
    }
    ob_start();
    if ($rdAttRows): ?>
    <div class="rdp-card mb-3">
        <div class="rdp-card-h"><h6><i class="bi bi-bell me-1"></i><?= t('Needs your attention') ?></h6></div>
        <div class="list-group list-group-flush">
        <?php foreach ($rdAttRows as [$ico, $txt, $lnk]): ?>
        <a href="<?= $lnk ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="bi <?= $ico ?> text-primary"></i><span class="small"><?= $txt ?></span><i class="bi bi-chevron-right ms-auto text-muted"></i></a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif;
    $b_attn = ob_get_clean();

    if (!$rdL2):
        // ───── the layout the mixed-company strip has always had ───── ?>
    <!-- ───────── 1. Hero: how am I doing today? ───────── -->
    <?= $b_hero_old ?>

    <!-- ───────── 2. Trend + the profit story ───────── -->
    <?php if ($rdMoney): ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-8"><?= $b_perf ?></div>
        <div class="col-lg-4"><?= $b_money ?></div>
    </div>
    <?php endif; ?>

    <!-- ───────── 3. What earns the money ───────── -->
    <div class="row g-3 mb-3">
        <div class="col-lg-7"><?= $b_earn ?></div>
        <div class="col-lg-5 d-flex flex-column gap-3"><?= $b_busy ?><?= $b_types ?></div>
    </div>

    <!-- ───────── 4. Needs attention ───────── -->
    <?= $b_attn ?>

    <?php else:
        // ───────── Layout 2 — "Side actions" ─────────
        $nWith = (int)$rdHero['open']['tables'];
        // Quick actions: name, a short live line, icon, colour. Same permissions as the menu.
        $acts = [];
        if (canCreate('pos')) {
            $acts[] = [t('Take order'), t('Open the till'), 'bi-cart-check', '#0b5ed7', getUrl('pos')];
            $acts[] = [t('Tables'), sprintf(t('%d with order'), $nWith), 'bi-grid-3x3-gap', '#0369a1', getUrl('restaurant/tables-board')];
        }
        if ($rdKitchenOn) $acts[] = [t('Kitchen'), sprintf(t('%d waiting long'), $rdX['kitchen_waiting']), 'bi-fire', '#b45309', getUrl('restaurant/kitchen-dashboard')];
        $acts[] = [t('Menu'), $rdMoney ? sprintf(t('%d need a cost'), $unc) : t('Dishes and drinks'), 'bi-journal-richtext', '#0f766e', getUrl('restaurant/menu')];
        if ((canView('pos') && canView('pos_restock')) || canCreate('expenses')) $acts[] = [t('Purchases'), sprintf(t('%d running low'), count($rdData['low_drinks'])), 'bi-basket', '#a16207', getUrl('restaurant/purchases')];
        if (canView('expenses')) $acts[] = [t('Expenses'), sprintf(t('%d to approve'), $rdX['expenses_pending']), 'bi-receipt', '#4338ca', getUrl('expenses')];
        $acts[] = [t('Reservations'), sprintf(t('%d today'), $rdX['reservations_today']), 'bi-calendar-check', '#7c3aed', getUrl('restaurant/reservations')];
        $acts[] = [t('Sales report'), t('Today and past'), 'bi-bar-chart', '#475f8a', getUrl('restaurant/sales-report')];

        $card = function (string $colour, string $icon, string $label, string $value, string $sub, string $href = '', bool $now = false) {
            $tag = $href !== '' ? 'a href="' . htmlspecialchars($href) . '"' : 'div';
            $end = $href !== '' ? 'a' : 'div';
            return '<' . $tag . ' class="rd2-sc rd2-c-' . $colour . '"><span class="rd2-l"><i class="bi ' . $icon . '"></i>' . $label . ($now ? '<span class="rd2-now">' . t('Right now') . '</span>' : '') . '</span><span class="rd2-kv">' . $value . '</span><span class="rd2-ks">' . $sub . '</span></' . $end . '>';
        };
        $flat = !$rdMoney;                       // without profit access there is no chart to put the side panel next to
        $pTabs = [['att', t('Needs attention')]];
        if ($rdActs) $pTabs[] = ['act', t('Activities')];
        $pTabs[] = ['low', t('Running low')];
        $pTabs[] = ['ord', t('Orders')];
        ?>
    <div class="rd2 <?= $flat ? 'rd2-flat' : '' ?>">
        <nav class="rd2-acts" aria-label="<?= htmlspecialchars(t('Quick actions')) ?>">
            <?php foreach ($acts as $i => [$an, $as, $ai, $ac, $ah]): ?>
            <a href="<?= $ah ?>" class="rd2-act"><span class="rd2-sq" style="background:<?= $ac ?>"><i class="bi <?= $ai ?>"></i></span><span class="rd2-at"><b><?= $an ?></b><small><?= $as ?></small></span></a>
            <?php endforeach; ?>
        </nav>

        <section class="rd2-hero">
            <?php
            $rdCurTag = ' <small>' . htmlspecialchars($rdCur) . '</small>';
            $prevLbl = ['today' => t('Yesterday %s'), 'week' => t('Last week so far %s'), 'month' => t('Last month so far %s'), 'quarter' => t('Last quarter so far %s'), 'year' => t('Last year so far %s')][$rdRange['key']];
            ?>
            <div class="rd2-sum">
                <?= $card('sales', 'bi-cart-check', t('Sales'), $rdFmt($cur['sales']) . $rdCurTag, sprintf($prevLbl, $rdFmt($rdStory['prev']['sales'])), getUrl('restaurant/sales-report')) ?>
                <?= $card('orders', 'bi-receipt', t('Orders'), (string)$rdStory['orders'], sprintf(t('Average bill %s'), $rdFmt($rdStory['avg_bill'])), getUrl('restaurant/sales-report')) ?>
                <?php if ($rdMoney): ?>
                <?= $card('profit', 'bi-graph-up-arrow', t('Profit'), $rdFmt($cur['profit']) . $rdCurTag, ($hasSales ? rtrim(rtrim(number_format($rdStory['margin'], 1), '0'), '.') . '% ' . t('margin') . ' · ' : '') . (($unc > 0 || $rdWarn) ? t('Estimated') : t('Complete'))) ?>
                <?= $card('exp', 'bi-basket', t('Expenses'), $rdFmt($cur['expenses']) . $rdCurTag, sprintf(t('%d to approve'), $rdX['expenses_pending']), canView('expenses') ? getUrl('expenses') : '') ?>
                <?php endif; ?>
                <?= $card('tables', 'bi-grid-3x3-gap', t('Open tables'), $rdX['tables_total'] > 0 ? sprintf(t('%1$d of %2$d'), $nWith, $rdX['tables_total']) : (string)$nWith, sprintf(t('%s not paid yet'), $rdFmt($rdHero['open']['amount'])), getUrl('restaurant/tables-board'), true) ?>
                <?php if ($rdOwed !== null): ?><?= $card('owed', 'bi-cash-coin', t('Owed to me'), $rdFmt($rdOwed) . $rdCurTag, t('customers who bought on credit'), getUrl('pos/credit-customers'), true) ?><?php endif; ?>
            </div>
        </section>

        <?php if ($rdMoney): ?><section class="rd2-perf"><?= $b_perf ?></section><?php endif; ?>

        <section class="rd2-panel">
            <div class="rdp-card h-100">
                <div class="rdp-card-h"><div class="btn-group btn-group-sm rdp-ptabs" role="group">
                    <?php foreach ($pTabs as $i => [$pk, $pl]): ?><button type="button" class="btn btn-outline-primary <?= $i === 0 ? 'active' : '' ?>" data-pt="<?= $pk ?>"><?= $pl ?><?= $pk === 'att' && $rdAttRows ? ' <span class="rd2-cnt">' . count($rdAttRows) . '</span>' : '' ?></button><?php endforeach; ?>
                </div></div>
                <div class="rd2-pbody">
                    <div class="rd2-pt" data-pt="att">
                        <?php if (!$rdAttRows): ?><div class="rdp-empty"><i class="bi bi-check2-circle"></i><div><?= t('Nothing needs your attention.') ?></div></div><?php endif; ?>
                        <?php foreach ($rdAttRows as [$ico, $txt, $lnk]): ?>
                        <a href="<?= $lnk ?>" class="rd2-row"><i class="bi <?= $ico ?> text-primary"></i><span><?= $txt ?></span></a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($rdActs): ?>
                    <div class="rd2-pt d-none" data-pt="act">
                        <?php foreach ($rdActs as $a): ?>
                        <div class="rd2-row"><i class="bi bi-clock-history text-primary"></i><span><?= safe_output($a['description'] ?: $a['action']) ?><small class="d-block text-muted"><?= safe_output($a['username'] ?? '') ?> · <?= date('d M H:i', strtotime($a['created_at'])) ?></small></span></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="rd2-pt d-none" data-pt="low">
                        <?php if (!$rdData['low_drinks']): ?><div class="rdp-empty"><i class="bi bi-check2-circle"></i><div><?= t('Everything has enough stock.') ?></div></div><?php endif; ?>
                        <?php foreach ($rdData['low_drinks'] as $d): ?>
                        <div class="rd2-row justify-content-between"><span><?= safe_output($d['product_name']) ?></span><span class="badge rd2-low"><?= rtrim(rtrim(number_format((float)$d['stock'], 2), '0'), '.') ?></span></div>
                        <?php endforeach; ?>
                        <?php if ($rdData['low_drinks'] && canView('pos') && canView('pos_restock')): ?>
                        <a href="<?= getUrl('restaurant/purchases') ?>" class="rd2-row text-primary small"><i class="bi bi-box-arrow-in-down"></i><span><?= t('Buy stock & ingredients') ?></span></a>
                        <?php endif; ?>
                    </div>
                    <div class="rd2-pt d-none" data-pt="ord">
                        <?php if (!$rdData['recent']): ?><div class="rdp-empty"><i class="bi bi-receipt"></i><div><?= t('No orders yet today. Tap "Take order" to start.') ?></div></div><?php endif; ?>
                        <?php foreach ($rdData['recent'] as $o): ?>
                        <div class="rd2-row justify-content-between"><span><b><?= safe_output($o['table_number'] !== null ? t('Table') . ' ' . $o['table_number'] : ($rdTypes[$o['sale_type']] ?? t('Order'))) ?></b><small class="d-block text-muted"><?= safe_output($o['receipt_number']) ?> · <?= date('H:i', strtotime($o['sale_date'])) ?></small></span><b><?= $rdFmt($o['grand_total']) ?></b></div>
                        <?php endforeach; ?>
                        <?php if (canView('pos')): ?><a href="<?= getUrl('pos_dashboard') ?>" class="rd2-row text-primary small"><span><?= t('See all') ?></span></a><?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($rdMoney): ?><section class="rd2-money"><?= $b_money ?></section><?php endif; ?>
        <section class="rd2-earn"><?= $b_earn ?></section>
        <section class="rd2-types"><?= $b_types ?></section>
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

/* ── Layout 2 — "Side actions" (restaurant-only company). Each card its own colour; sales blue, costs orange, profit green; no black or red. ── */
.rdp-l2 .rdp-bar-food { background:#d97706; } .rdp-l2 .rdp-bar-exp { background:#7c93b5; } .rdp-l2 .rdp-bar-profit { background:#15803d; }
.rdp-l2 .text-danger { color:#b45309 !important; } .rdp-l2 .rdp-num.text-primary { color:#15803d !important; }
.rdp-l2 .rdp-down { background:#fef3e2; color:#b45309; }
.rdp-l2 .rd2-low { background:#fef3e2; color:#b45309; }
.rd2 { display:grid; gap:12px; grid-template-columns:200px repeat(3, minmax(0, 1fr));
       grid-template-areas:"acts hero hero hero" "acts perf perf panel" "acts money earn types"; }
.rd2 > * { min-width:0; }
.rd2-acts { grid-area:acts; display:flex; flex-direction:column; gap:8px; align-content:start; align-self:start; background:#0d6efd; padding:10px; border-radius:14px; }
.rd2-hero { grid-area:hero; }
.rd2-perf { grid-area:perf; } .rd2-panel { grid-area:panel; } .rd2-money { grid-area:money; } .rd2-earn { grid-area:earn; }
.rd2-types { grid-area:types; }
.rd2-act { display:flex; align-items:center; gap:9px; background:#fff; border-radius:10px; padding:8px; text-decoration:none; color:#12305a; min-height:54px; }
.rd2-act:hover { background:#e7f0ff; color:#0a58ca; }
.rd2-sq { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; flex:none; font-size:1.05rem; }
.rd2-at b { display:block; font-size:.85rem; line-height:1.15; } .rd2-at small { display:block; font-size:.72rem; color:#4f6381; line-height:1.15; }
.rd2-sum { display:grid; gap:8px; grid-template-columns:repeat(3, minmax(0, 1fr)); }
.rd2-sc { display:flex; flex-direction:column; gap:1px; border-radius:12px; padding:11px 13px; text-decoration:none; color:#fff; min-width:0; }
a.rd2-sc:hover { color:#fff; filter:brightness(1.08); }
.rd2-c-sales { background:#0b5ed7; } .rd2-c-orders { background:#0f766e; } .rd2-c-profit { background:#15803d; }
.rd2-c-exp { background:#c2610c; } .rd2-c-tables { background:#7e22ce; } .rd2-c-owed { background:#3f3fb8; }
.rd2-l { display:flex; align-items:center; gap:6px; font-size:.8rem; font-weight:600; }
.rd2-now { margin-left:auto; font-size:.62rem; font-weight:700; border-radius:8px; padding:0 6px; background:rgba(255,255,255,.25); white-space:nowrap; }
.rd2-kv { font-size:1.45rem; font-weight:700; line-height:1.15; overflow-wrap:anywhere; font-variant-numeric:tabular-nums; }
.rd2-kv small { font-size:.7rem; font-weight:600; }
.rd2-ks { font-size:.72rem; }
.rdp-ptabs { display:flex; flex-wrap:wrap; gap:3px; }
.rdp-ptabs .btn { flex:none; margin:0 !important; font-size:.72rem !important; padding:3px 7px !important; border-radius:7px !important; line-height:1.3; min-height:0; }
.rd2-pbody { overflow:auto; max-height:290px; }
.rd2-row { display:flex; align-items:flex-start; gap:9px; padding:8px 12px; border-bottom:1px solid #eef3fd; color:#12305a; text-decoration:none; font-size:.82rem; }
a.rd2-row:hover { background:#f4f8ff; color:#0a58ca; }
.rd2-row small { font-size:.72rem; }
.rd2-cnt { display:inline-block; min-width:16px; padding:0 4px; border-radius:8px; background:#b45309; color:#fff; font-size:.68rem; line-height:16px; text-align:center; }
.rd2-ptabs .btn.active .rd2-cnt { background:#fff; color:#b45309; }
@media (max-width:1199.98px) {
    .rd2 { grid-template-columns:minmax(0, 1fr); grid-template-areas:none; }
    .rd2-sum { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    .rd2 > * { grid-area:auto !important; }
    .rd2-acts { flex-direction:row; flex-wrap:wrap; }
    .rd2-act { flex:1 1 calc(25% - 8px); flex-direction:column; text-align:center; padding:6px 2px; min-width:72px; }
}
@media (max-width:575.98px) { .rd2-act { flex-basis:calc(50% - 8px); flex-direction:row; text-align:left; } }
.rd2-flat { grid-template-columns:minmax(0, 1fr); grid-template-areas:none; }
.rd2-flat > * { grid-area:auto !important; }
.rd2-flat .rd2-acts { flex-direction:row; flex-wrap:wrap; }
.rd2-flat .rd2-act { flex:1 1 180px; }
</style>

<script>
(function () {
    const UID = <?= json_encode($rdUid) ?>, SCOPE = <?= json_encode($rdScope) ?>, CUR = <?= json_encode($rdCur) ?>;
    const L2 = <?= json_encode($rdL2) ?>;
    const TREND = <?= json_encode($rdTrend) ?>;
    const HOURS = <?= json_encode($rdScope === 'combined' ? null : $rdHours['hours']) ?>;
    const TYPES = <?= json_encode($rdScope === 'combined' ? [] : array_map(fn($r) => ['label' => $rdTypes[$r['sale_type']] ?? t('Order'), 'sales' => $r['sales']], $rdTypeRows)) ?>;
    const L = { sales: <?= json_encode(t('Sales')) ?>, costs: <?= json_encode(t('Costs')) ?>, profit: <?= json_encode(t('Profit')) ?>,
                restaurant: <?= json_encode(t('Restaurant')) ?>, other: <?= json_encode(t('Shops & other')) ?>, orders: <?= json_encode(t('Orders')) ?> };
    const URL_API = <?= json_encode(buildUrl('api/restaurant/dashboard_panel.php')) ?>;
    // Layout 2 uses the chosen design's colours: sales blue, costs orange, profit a dark line.
    const BLUE = '#0d6efd', NAVY = '#052c65', SKY = '#9ec5fe', RED = '#dc3545', AMBER = '#d97706', GREEN = '#15803d', SLATE = '#7c93b5', PALE = '#a9c8f8';
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
            { type: 'bar', label: L.sales, data: d.sales, backgroundColor: L2 ? BLUE : SKY, borderRadius: 4, order: 3 },
            { type: 'bar', label: L.costs, data: d.cost.map((c, i) => c + d.expenses[i]), backgroundColor: L2 ? AMBER : NAVY, borderRadius: 4, order: 2 },
            { type: 'line', label: L.profit, data: d.profit, borderColor: L2 ? GREEN : BLUE, backgroundColor: L2 ? GREEN : BLUE, tension: .3, pointRadius: 2, borderWidth: 3, order: 1 }
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
        for (let h = a; h <= b; h++) { labels.push(h); vals.push(HOURS[h]); cols.push(mx > 0 && HOURS[h] === mx ? BLUE : (L2 ? PALE : SKY)); }
        new Chart(cv.getContext('2d'), { type: 'bar', data: { labels: labels, datasets: [{ label: L.orders, data: vals, backgroundColor: cols, borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false }, ticks: { maxRotation: 0 } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#eef3fd' } } } } });
    }
    function drawTypes() {
        const cv = el('types'); if (!cv || !TYPES.length) return;
        new Chart(cv.getContext('2d'), { type: 'doughnut', data: { labels: TYPES.map(x => x.label), datasets: [{ data: TYPES.map(x => x.sales), backgroundColor: L2 ? [BLUE, AMBER, SLATE, PALE] : [BLUE, NAVY, SKY, '#6ea8fe'], borderWidth: 0 }] },
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
    root.querySelectorAll('.rdp-period-sel').forEach(function (sel) {
        sel.addEventListener('change', function () {
            fetch(URL_API + '?part=trend&scope=' + encodeURIComponent(SCOPE) + '&period=' + encodeURIComponent(sel.value), { credentials: 'same-origin' })
                .then(r => r.json()).then(function (j) { if (j && j.success) drawTrend(j.trend); });
        });
    });
    root.querySelectorAll('.rdp-tabs button').forEach(function (b) {
        b.addEventListener('click', function () {
            root.querySelectorAll('.rdp-tabs button').forEach(x => x.classList.remove('active')); b.classList.add('active');
            root.querySelectorAll('.rdp-tab').forEach(t => t.classList.toggle('d-none', t.dataset.t !== b.dataset.t));
        });
    });
    // Side panel tabs (Layout 2): needs attention / activities / running low / orders
    root.querySelectorAll('.rdp-ptabs button').forEach(function (b) {
        b.addEventListener('click', function () {
            root.querySelectorAll('.rdp-ptabs button').forEach(x => x.classList.remove('active')); b.classList.add('active');
            root.querySelectorAll('.rd2-pt').forEach(t => t.classList.toggle('d-none', t.dataset.pt !== b.dataset.pt));
        });
    });
})();
</script>
</div>
