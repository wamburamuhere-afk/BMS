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
 * Layout 2 ("Side actions", arrangement A chosen by the owner, 2026-11-02) is for the restaurant-only company ('company').
 * Redesign 2026-11-03 (owner approved the mock-up: Preline look + two Medesk parts): quick actions down the left in one blue
 * (amber when one needs you); 4 tiles — Sales (split by how customers order), Orders, Profit, Tables busy right now; a
 * Sales | Orders chart that follows the "Showing" choice against the same stretch before; Kitchen right now (per kitchen
 * area) with Needs your attention; Top dishes; Money for the period with Customers owe us / We owe suppliers; Latest orders
 * (tables not paid yet, then the last paid); Sales by staff. White cards, BMS blue, amber warnings. No black or red.
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
$rdTypes  = ['dine_in' => t('Dine in'), 'take_away' => t('Take away'), 'delivery' => t('Delivery'), 'walk_in' => t('Walk in')];
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
        // redesign 2026-11-03: the chart follows the "Showing" choice; kitchen load; open table orders; sales by staff; supplier debt
        $rdSeries  = rdCompareSeries($pdo, $rdJe, $rdIds, $rdRange);
        $rdKitchen = $rdKitchenOn ? rdKitchenLoad($pdo, $rdIds) : ['total' => 0, 'areas' => []];
        $rdOpen    = rdOpenOrders($pdo, $rdIds, 5);
        $rdStaff   = rdStaffSales($pdo, $rdJe, $rdIds, $rdRange['from'], $rdRange['to'], 5);
        $rdSupp    = null;
        if ($rdMoney && is_file(ROOT_DIR . '/core/restaurant_supplier_credit.php')) {
            require_once ROOT_DIR . '/core/restaurant_supplier_credit.php';
            if (function_exists('restaurantOwedToSuppliers')) $rdSupp = restaurantOwedToSuppliers($pdo, $rdIds);
        }
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
                        <thead><tr><th><?= t('Item') ?></th><th class="text-end"><?= t('Plates') ?></th><th class="text-end"><?= t('Gross profit per plate') ?></th><th class="text-end"><?= t('Margin') ?></th><th class="text-end"><?= t('Estimated gross profit') ?></th></tr></thead>
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
                    <div class="rdp-foot"><?= t('Gross profit per plate = what the customer paid minus the recipe or purchase cost (before gas, staff and rent). An estimate to guide your menu; the profit figure above comes from your books.') ?></div>
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
        elseif ($a['type'] === 'kitchen_wait') { $ico = 'ri ri-chef-hat'; $txt = sprintf(t('%1$d kitchen order(s) have waited over 20 minutes (the longest %2$d min).'), $a['n'], $a['extra']); $lnk = getUrl('restaurant/kitchen-dashboard'); }
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
        <a href="<?= $lnk ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2"><i class="<?= strpos($ico, 'ri ') === 0 ? $ico : 'bi ' . $ico ?> text-primary"></i><span class="small"><?= $txt ?></span><i class="bi bi-chevron-right ms-auto text-muted"></i></a>
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
        // ───────── Layout 2 — "Side actions" (redesign 2026-11-03: Preline look + two Medesk parts; blue only, amber = needs you) ─────────
        $nWith = (int)$rdHero['open']['tables'];
        // Quick actions: name, a short live line, icon, link, tone (main | blue | amber when it needs you). Same permissions as the menu.
        $acts = [];
        if (canCreate('pos')) {
            $acts[] = [t('Take order'), t('Open the till'), 'ri ri-hand-platter', getUrl('pos'), 'main'];
            $acts[] = [t('Tables'), sprintf(t('%d with order'), $nWith), 'ri ri-armchair', getUrl('restaurant/tables-board'), 'blue'];
        }
        if ($rdKitchenOn) $acts[] = [t('Kitchen'), sprintf(t('%d waiting long'), $rdX['kitchen_waiting']), 'ri ri-chef-hat', getUrl('restaurant/kitchen-dashboard'), $rdX['kitchen_waiting'] > 0 ? 'amber' : 'blue'];
        $acts[] = [t('Menu'), $rdMoney ? sprintf(t('%d need a cost'), $unc) : t('Dishes and drinks'), 'ri ri-book-open-text', getUrl('restaurant/menu'), ($rdMoney && $unc > 0) ? 'amber' : 'blue'];
        if ((canView('pos') && canView('pos_restock')) || canCreate('expenses')) $acts[] = [t('Purchases'), sprintf(t('%d running low'), count($rdData['low_drinks'])), 'ri ri-shopping-basket', getUrl('restaurant/purchases'), 'blue'];
        if (canView('expenses')) $acts[] = [t('Expenses'), sprintf(t('%d to approve'), $rdX['expenses_pending']), 'ri ri-wallet', getUrl('expenses'), 'blue'];
        $acts[] = [t('Reservations'), sprintf(t('%d today'), $rdX['reservations_today']), 'ri ri-calendar-check', getUrl('restaurant/reservations'), 'blue'];
        $acts[] = [t('Sales report'), t('Today and past'), 'ri ri-chart-column', getUrl('restaurant/sales-report'), 'blue'];

        $rdCurTag = ' <small>' . htmlspecialchars($rdCur) . '</small>';
        $k = $rdRange['key'];
        $prevLbl = ['today' => t('Yesterday %s'), 'week' => t('Last week so far %s'), 'month' => t('Last month so far %s'), 'quarter' => t('Last quarter so far %s'), 'year' => t('Last year so far %s')][$k];
        $nowWord = ['today' => t('Today'), 'week' => t('This week'), 'month' => t('This month'), 'quarter' => t('This quarter'), 'year' => t('This year')][$k];
        $prevWord = ['today' => t('Yesterday'), 'week' => t('Last week'), 'month' => t('Last month'), 'quarter' => t('Last quarter'), 'year' => t('Last year')][$k];
        $unitWord = ['today' => t('By hour'), 'week' => t('By day'), 'month' => t('By day'), 'quarter' => t('By week'), 'year' => t('By month')][$k];
        $pct = fn($v) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.');
        $blues = ['#0d6efd', '#6ea2f7', '#b8cff5', '#dbe6fb'];

        // Sales split by how customers order (dine in / take away / delivery) — the ledger figures, as shares
        $typeTotal = array_sum(array_column($rdTypeRows, 'sales'));
        // the chart: one scale for both series of each tab
        $S = $rdSeries; $mxS = max(1, max(array_merge($S['sales'], $S['sales_prev'], [0]))); $mxO = max(1, max(array_merge($S['orders'], $S['orders_prev'], [0])));
        $every = count($S['labels']) > 16 ? (int)ceil(count($S['labels']) / 12) : 1;   // a label every few bars on a month
        // Profit view (2026-11-03): sales up, each slot's costs down in three parts (drinks & stock SOLD, food BOUGHT,
        // expenses), and profit SO FAR in the period as a line — red while still at a loss. Same slots as Sales/Orders;
        // ends at the Profit tile's figure. Not by hour: food is bought in the morning and sold all day.
        // Restaurant costing plan, Phase 3: stock taken for cooking counts with stock ("used"), and spoiled stock /
        // kitchen waste is its own line — the three still add up to the Cost of goods figure.
        $PT = ['stock' => (float)($S['p_tot']['stock'] ?? 0), 'waste' => (float)($S['p_tot']['waste'] ?? 0), 'food' => 0.0, 'exp' => (float)($cur['expenses'] ?? 0)];
        $PT['food'] = round((float)$cur['cost'] - $PT['stock'] - $PT['waste'], 2);
        $pSvg = '';
        if ($rdMoney && $k !== 'today' && !empty($S['labels'])) {
            $pn = count($S['labels']);
            $pc = array_map(fn($i) => ($S['p_stock'][$i] ?? 0) + ($S['p_food'][$i] ?? 0) + ($S['p_waste'][$i] ?? 0) + ($S['p_exp'][$i] ?? 0), range(0, $pn - 1));
            $up = max(1, max(array_merge($S['sales'], $S['p_cum'], [0]))); $down = max(0, max(array_merge($pc, array_map(fn($v) => -$v, $S['p_cum']), [0])));
            $W = 1000; $H = 220; $top = 8; $span = ($up + $down) * 1.06; $z = $top + $up / $span * ($H - 2 * $top);
            $Y = fn($v) => round($z - $v / $span * ($H - 2 * $top), 2);
            $slot = $W / $pn; $bw = round($slot * 0.46, 2);
            $o = ['<line x1="0" y1="' . $z . '" x2="' . $W . '" y2="' . $z . '" stroke="#9ca3af" stroke-width="1" vector-effect="non-scaling-stroke"/>'];
            $pts = [];
            foreach ($S['labels'] as $i => $lb) {
                $x = round($i * $slot + ($slot - $bw) / 2, 2); $sv = (float)($S['sales'][$i] ?? 0);
                if ($sv > 0) $o[] = '<rect x="' . $x . '" y="' . $Y($sv) . '" width="' . $bw . '" height="' . round($z - $Y($sv), 2) . '" fill="#9ec5fe"/>';
                $base = 0.0;
                foreach ([['p_stock', '#f59e0b'], ['p_food', '#fdba74'], ['p_waste', '#e57373'], ['p_exp', '#94a3b8']] as [$key, $col]) {
                    $v = max(0, (float)($S[$key][$i] ?? 0));
                    if ($v > 0) { $o[] = '<rect x="' . $x . '" y="' . $Y(-$base) . '" width="' . $bw . '" height="' . round($Y(-$base - $v) - $Y(-$base), 2) . '" fill="' . $col . '"/>'; $base += $v; }
                }
                $tip = $lb . ' — ' . t('Sales') . ': ' . $rdFmt($sv) . ' · ' . t('Drinks & stock used') . ': ' . $rdFmt($S['p_stock'][$i] ?? 0) . ' · ' . t('Food bought') . ': ' . $rdFmt($S['p_food'][$i] ?? 0)
                     . (($S['p_waste'][$i] ?? 0) > 0 ? ' · ' . t('Spoiled & waste') . ': ' . $rdFmt($S['p_waste'][$i]) : '')
                     . ' · ' . t('Expenses') . ': ' . $rdFmt($S['p_exp'][$i] ?? 0) . ' · ' . (($S['p_cum'][$i] ?? 0) < 0 ? t('Loss so far') : t('Profit so far')) . ': ' . $rdFmt($S['p_cum'][$i] ?? 0);
                $o[] = '<rect x="' . round($i * $slot, 2) . '" y="0" width="' . round($slot, 2) . '" height="' . $H . '" fill="transparent"><title>' . htmlspecialchars($tip) . '</title></rect>';
                $pts[] = [round($i * $slot + $slot / 2, 2), (float)($S['p_cum'][$i] ?? 0)];
            }
            for ($i = 1; $i < count($pts); $i++) {           // the line, split where it crosses zero so each part has its colour
                [$x1, $v1] = $pts[$i - 1]; [$x2, $v2] = $pts[$i];
                $segs = (($v1 < 0) === ($v2 < 0)) ? [[$x1, $v1, $x2, $v2]] : (function () use ($x1, $v1, $x2, $v2) { $xm = $x1 + ($x2 - $x1) * ($v1 / ($v1 - $v2)); return [[$x1, $v1, $xm, 0.0], [$xm, 0.0, $x2, $v2]]; })();
                foreach ($segs as [$a, $va, $b, $vb]) {
                    $neg = ($va + $vb) / 2 < 0;
                    $o[] = '<line x1="' . $a . '" y1="' . $Y($va) . '" x2="' . $b . '" y2="' . $Y($vb) . '" stroke="' . ($neg ? '#dc3545' : '#052c65') . '" stroke-width="3" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
                }
            }
            if (count($pts) === 1) $o[] = '<line x1="' . ($pts[0][0] - 6) . '" y1="' . $Y($pts[0][1]) . '" x2="' . ($pts[0][0] + 6) . '" y2="' . $Y($pts[0][1]) . '" stroke="' . ($pts[0][1] < 0 ? '#dc3545' : '#052c65') . '" stroke-width="3" vector-effect="non-scaling-stroke"/>';
            $pSvg = '<svg class="rd2-psvg" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="' . htmlspecialchars($unitWord . ': ' . t('Profit so far')) . '">' . implode('', $o) . '</svg>';
        }
        // "Needs your attention": warnings first (amber), then the rest (blue); low stock names the items
        $att = [];
        foreach ($rdAttention as $a) {
            if ($a['type'] === 'old_bills')        $att[] = ['amber', 'bi bi-receipt', sprintf(t('%1$d bill(s) have been open for over 90 minutes (the longest %2$d min).'), $a['n'], $a['extra']), getUrl('restaurant/tables-board')];
            elseif ($a['type'] === 'kitchen_wait') $att[] = ['amber', 'ri ri-chef-hat', sprintf(t('%1$d kitchen order(s) have waited over 20 minutes (the longest %2$d min).'), $a['n'], $a['extra']), getUrl('restaurant/kitchen-dashboard')];
            elseif ($a['type'] === 'low_stock')    $att[] = ['blue', 'bi bi-cup-straw', sprintf(t('%d drink(s) or ingredient(s) are running low.'), $a['n']) . ' ' . safe_output(implode(', ', array_map(fn($d) => $d['product_name'], array_slice($rdData['low_drinks'], 0, 3)))) . (count($rdData['low_drinks']) > 3 ? '…' : ''), canView('pos_restock') ? getUrl('restaurant/purchases') : getUrl('restaurant/menu')];
            else                                   $att[] = ['blue', 'bi bi-lightbulb', sprintf(t('%d item(s) sold have no cost, so profit may look too high.'), $a['n']), getUrl('restaurant/menu')];
        }
        if ($rdX['expenses_pending'] > 0 && canView('expenses')) $att[] = ['blue', 'ri ri-wallet', sprintf(t('%1$d expense(s) worth %2$s wait for approval.'), $rdX['expenses_pending'], $rdFmt($rdX['expenses_pending_amount'])), getUrl('expenses')];
        // top dishes by sales in the period
        $top = $rdDishes['items']; usort($top, fn($a, $b) => ($b['price'] * $b['qty']) <=> ($a['price'] * $a['qty'])); $top = array_slice($top, 0, 5);
        $initials = function (string $n): string {           // "Pilau beef" → PB, "Chips" → CH
            $w = preg_split('/\s+/', trim($n)) ?: [''];
            return mb_strtoupper(mb_substr($w[0], 0, 1) . (isset($w[1]) ? mb_substr($w[1], 0, 1) : mb_substr($w[0], 1, 1)));
        };
        // latest orders: tables not paid yet first, then the last paid ones
        $orderRows = [];
        foreach ($rdOpen as $h) $orderRows[] = ['tag' => mb_substr((string)$h['table_number'], 0, 4), 'title' => t('Table') . ' ' . $h['table_number'],
            'meta' => sprintf(t('%d items'), (int)$h['item_count']) . ' · ' . date('H:i', strtotime($h['held_at'])) . ($h['waiter'] !== '' ? ' · ' . $h['waiter'] : ''),
            'amount' => (float)$h['total_amount'], 'open' => true];
        foreach ($rdData['recent'] as $o) { if (count($orderRows) >= 6) break; $orderRows[] = [
            'tag' => $o['table_number'] !== null ? mb_substr((string)$o['table_number'], 0, 4) : ['take_away' => 'TA', 'delivery' => 'DL', 'walk_in' => 'WI', 'dine_in' => 'DI'][$o['sale_type']] ?? '#',
            'title' => $o['table_number'] !== null ? t('Table') . ' ' . $o['table_number'] : ($rdTypes[$o['sale_type']] ?? t('Order')),
            'meta' => $o['receipt_number'] . ' · ' . date(date('Y-m-d', strtotime($o['sale_date'])) === $rdToday ? 'H:i' : 'd M H:i', strtotime($o['sale_date'])),
            'amount' => (float)$o['grand_total'], 'open' => false]; }
        $staffMax = $rdStaff ? max(1, max(array_column($rdStaff, $rdMoney ? 'sales' : 'orders'))) : 1;
        ?>
    <div class="rd2 <?= $rdMoney ? '' : 'rd2-flat' ?>">
        <nav class="rd2-acts" aria-label="<?= htmlspecialchars(t('Quick actions')) ?>">
            <?php foreach ($acts as [$an, $as, $ai, $ah, $tone]): ?>
            <a href="<?= $ah ?>" class="rd2-act rd2-t-<?= $tone ?>"><span class="rd2-sq"><i class="<?= $ai ?>"></i></span><span class="rd2-at"><b><?= $an ?></b><small><?= $as ?></small></span></a>
            <?php endforeach; ?>
        </nav>

        <!-- 4 tiles: Sales (with how customers order), Orders, Profit, Tables busy right now -->
        <section class="rd2-hero">
            <div class="rd2-sum">
                <a href="<?= getUrl('restaurant/sales-report') ?>" class="rd2-tile">
                    <span class="rd2-tl"><?= t('Sales') ?><i class="ri ri-banknote"></i></span>
                    <span class="rd2-tv"><?= $rdFmt($cur['sales']) . $rdCurTag ?></span>
                    <?php if ($typeTotal > 0): ?>
                    <span class="rd2-split" aria-hidden="true"><?php foreach ($rdTypeRows as $i => $tr): ?><span style="width:<?= max(0, round($tr['sales'] / $typeTotal * 100, 1)) ?>%;background:<?= $blues[min($i, 3)] ?>"></span><?php endforeach; ?></span>
                    <span class="rd2-ts"><?= implode(' · ', array_map(fn($tr) => safe_output($rdTypes[$tr['sale_type']] ?? t('Order')) . ' ' . $rdFmt($tr['sales']), array_slice($rdTypeRows, 0, 2))) ?></span>
                    <?php endif; ?>
                    <span class="rd2-ts"><?= sprintf($prevLbl, $rdFmt($rdStory['prev']['sales'])) ?></span>
                </a>
                <a href="<?= getUrl('restaurant/sales-report') ?>" class="rd2-tile">
                    <span class="rd2-tl"><?= t('Orders') ?><i class="ri ri-receipt"></i></span>
                    <span class="rd2-tv"><?= (int)$rdStory['orders'] ?></span>
                    <span class="rd2-ts"><?= sprintf(t('Average bill %s'), $rdFmt($rdStory['avg_bill'])) ?></span>
                </a>
                <?php if ($rdMoney): ?>
                <div class="rd2-tile">
                    <span class="rd2-tl"><?= t('Profit') ?><i class="ri ri-trending-up"></i></span>
                    <span class="rd2-tv <?= $cur['profit'] < 0 ? 'text-danger' : '' ?>"><?= $rdFmt($cur['profit']) . $rdCurTag ?></span>
                    <?php if ($hasSales): ?><span class="rd2-ts"><?= $pct($rdStory['margin']) ?>% <?= t('margin') ?></span><?php endif; ?>
                    <?php if ($unc > 0 || $rdWarn): ?><span class="rd2-ts rd2-warn"><?= t('Estimated') ?><?= $unc > 0 ? ' — ' . sprintf(t('%d need a cost'), $unc) : '' ?></span>
                    <?php else: ?><span class="rd2-ts"><?= t('Complete') ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <a href="<?= getUrl('restaurant/tables-board') ?>" class="rd2-tile rd2-tile-main">
                    <span class="rd2-tl"><?= t('Tables busy') ?><span class="rd2-now"><?= t('Right now') ?></span></span>
                    <span class="rd2-tv"><?= $nWith ?><?php if ($rdX['tables_total'] > 0): ?> <small><?= sprintf(t('of %d'), $rdX['tables_total']) ?></small><?php endif; ?></span>
                    <?php if ($rdX['tables_total'] > 0 && $rdX['tables_total'] <= 16): ?>
                    <span class="rd2-seats" aria-hidden="true"><?php for ($i = 0; $i < $rdX['tables_total']; $i++): ?><span class="<?= $i < $nWith ? 'on' : '' ?>"></span><?php endfor; ?></span>
                    <?php elseif ($rdX['tables_total'] > 16): ?>
                    <span class="rd2-split rd2-split-w" aria-hidden="true"><span style="width:<?= round(min(1, $nWith / $rdX['tables_total']) * 100) ?>%;background:#fff"></span></span>
                    <?php endif; ?>
                    <span class="rd2-ts"><?= sprintf(t('%s not paid yet'), $rdFmt($rdHero['open']['amount'])) ?></span>
                </a>
            </div>
        </section>

        <!-- Sales | Orders, this period against the same stretch before -->
        <section class="rd2-chart rdp-card">
            <div class="rdp-card-h">
                <div>
                    <h6 class="rd2-ct-title" data-sales="<?= htmlspecialchars(t('Sales')) ?>" data-orders="<?= htmlspecialchars(t('Orders')) ?>" data-profit="<?= htmlspecialchars(t('Profit')) ?>"><?= t('Sales') ?></h6>
                    <div class="rd2-legend" data-ctv="sales orders"><?= $unitWord ?> · <span class="rd2-lc">■ <?= $nowWord ?></span> · <span class="rd2-lp">■ <?= $prevWord ?></span></div>
                    <?php if ($rdMoney): ?><div class="rd2-legend rd2-plegend d-none" data-ctv="profit"><?= $k === 'today' ? $nowWord : $unitWord . ' · ' . $nowWord ?></div><?php endif; ?>
                </div>
                <div class="btn-group btn-group-sm rd2-ct" role="group" aria-label="<?= htmlspecialchars(t('Chart')) ?>">
                    <button type="button" class="btn btn-outline-primary active" data-ct="sales"><?= t('Sales') ?></button>
                    <button type="button" class="btn btn-outline-primary" data-ct="orders"><?= t('Orders') ?></button>
                    <?php if ($rdMoney): ?><button type="button" class="btn btn-outline-primary" data-ct="profit"><?= t('Profit') ?></button><?php endif; ?>
                </div>
            </div>
            <div class="rd2-plot">
                <?php foreach (['sales' => [$S['sales'], $S['sales_prev'], $mxS], 'orders' => [$S['orders'], $S['orders_prev'], $mxO]] as $ct => [$a, $b, $mx]): ?>
                <div class="rd2-bars <?= $ct === 'orders' ? 'd-none' : '' ?>" data-ct="<?= $ct ?>" role="img" aria-label="<?= htmlspecialchars($unitWord . ': ' . $nowWord . ' / ' . $prevWord) ?>">
                    <?php foreach ($S['labels'] as $i => $lb): $va = $a[$i] ?? 0; $vb = $b[$i] ?? 0; ?>
                    <div class="rd2-b" title="<?= htmlspecialchars($lb . ' — ' . $nowWord . ': ' . ($ct === 'sales' ? $rdFmt($va) : (int)$va) . ' · ' . $prevWord . ': ' . ($ct === 'sales' ? $rdFmt($vb) : (int)$vb)) ?>">
                        <span class="rd2-bc" style="height:<?= max(0, round($va / $mx * 100, 1)) ?>%"></span><span class="rd2-bp" style="height:<?= max(0, round($vb / $mx * 100, 1)) ?>%"></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <?php if ($rdMoney):
                    $pNow = (float)$cur['profit']; $pVs = $rdStory['profit_vs_prev'] ?? null; ?>
                <div class="rd2-bars rd2-pv d-none" data-ct="profit">
                    <div class="rd2-pv-h">
                        <div>
                            <div class="rd2-pv-k"><?= htmlspecialchars(($pNow < 0 ? t('Loss so far') : t('Profit so far')) . ' · ' . $nowWord) ?></div>
                            <div class="rd2-pv-v <?= $pNow < 0 ? 'text-danger' : '' ?>"><?= $rdFmt(abs($pNow)) . $rdCurTag ?></div>
                        </div>
                        <?php if ($pVs !== null && $hasSales): ?><span class="rd2-pv-vs <?= $pVs < 0 ? 'rd2-pv-down' : '' ?>"><?= ($pVs >= 0 ? '+' : '') . $pct($pVs) ?>% <?= htmlspecialchars(sprintf(t('vs %s'), $prevWord)) ?></span><?php endif; ?>
                        <?php if ($unc > 0 || $rdWarn): ?><span class="rd2-pv-est"><?= t('Estimated') ?></span><?php endif; ?>
                    </div>
                    <?php if ($pSvg !== ''): ?><div class="rd2-pv-legend">
                        <span><i style="background:#9ec5fe"></i><?= t('Sales') ?></span>
                        <span><i style="background:#f59e0b"></i><?= t('Drinks & stock used') ?></span>
                        <span><i style="background:#fdba74"></i><?= t('Food bought') ?></span>
                        <?php if ($PT['waste'] > 0): ?><span><i style="background:#e57373"></i><?= t('Spoiled & waste') ?></span><?php endif; ?>
                        <span><i style="background:#94a3b8"></i><?= t('Expenses') ?></span>
                        <span><i class="rd2-pv-ln" style="background:#052c65"></i><?= t('Profit so far') ?></span>
                        <span><i class="rd2-pv-ln" style="background:#dc3545"></i><?= t('Loss so far') ?></span>
                    </div><?php endif; ?>
                    <?php if ($pSvg !== ''): ?>
                    <?= $pSvg ?>
                    <?php else: ?>
                    <div class="rd2-pv-today">
                        <div class="rd2-pv-row"><span><?= t('Sales') ?></span><b><?= $rdFmt($cur['sales']) ?></b></div>
                        <div class="rd2-pv-row"><span>− <?= t('Drinks & stock used') ?></span><b><?= $rdFmt($PT['stock']) ?></b></div>
                        <div class="rd2-pv-row"><span>− <?= t('Food bought') ?></span><b><?= $rdFmt($PT['food']) ?></b></div>
                        <?php if ($PT['waste'] > 0): ?><div class="rd2-pv-row"><span>− <?= t('Spoiled & waste') ?></span><b><?= $rdFmt($PT['waste']) ?></b></div><?php endif; ?>
                        <div class="rd2-pv-row"><span>− <?= t('Expenses') ?></span><b><?= $rdFmt($PT['exp']) ?></b></div>
                        <div class="rd2-muted small mt-2"><?= t('Profit is not shown by hour: food is bought in the morning and sold all day, so hours would mislead. Choose This week or longer to see profit day by day.') ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="rd2-bl" data-today="<?= $k === 'today' ? '1' : '0' ?>"><?php foreach ($S['labels'] as $i => $lb): ?><span><?= $i % $every === 0 ? htmlspecialchars($lb) : '' ?></span><?php endforeach; ?></div>
            </div>
            <?php if ($rdMoney): ?><div class="rdp-foot d-none" data-ctv="profit"><?= t('Drinks and stock items count when they are sold or taken for cooking; other food counts the day it is bought; spoiled stock and waste the day they are recorded; expenses the day they are recorded.') ?><?= $pSvg !== '' ? ' ' . t('The line ends at the Profit figure above.') : '' ?></div><?php endif; ?>
            <div class="rdp-foot" data-ctv="sales orders">
                <?php if ($S['peak'] !== null): ?><?= t('Busiest') ?>: <b><?= htmlspecialchars($k === 'today' ? $S['peak'] . ':00' : $S['peak']) ?></b> · <?php endif; ?>
                <?= $nowWord ?> <b><?= $rdFmt(array_sum($S['sales'])) ?></b> · <?= $prevWord ?> <?= $rdFmt(array_sum($S['sales_prev'])) ?>
            </div>
        </section>

        <!-- Kitchen right now (Medesk "department load") + Needs your attention -->
        <section class="rd2-panel rdp-card">
            <?php if ($rdKitchenOn): ?>
            <div class="rd2-kit">
                <div class="d-flex justify-content-between align-items-baseline"><h6><?= t('Kitchen right now') ?></h6><span class="rd2-muted"><b><?= (int)$rdKitchen['total'] ?></b> <?= t('orders') ?></span></div>
                <?php if ($rdKitchen['total'] > 0): ?>
                <div class="rd2-split rd2-split-k" aria-hidden="true"><?php foreach ($rdKitchen['areas'] as $i => $ka): ?><span style="width:<?= round($ka['n'] / $rdKitchen['total'] * 100, 1) ?>%;background:<?= $blues[min($i, 3)] ?>"></span><?php endforeach; ?></div>
                <?php foreach ($rdKitchen['areas'] as $i => $ka): ?>
                <div class="rd2-kr"><span><i style="background:<?= $blues[min($i, 3)] ?>"></i><?= safe_output($ka['name']) ?></span><span><b><?= $ka['n'] ?></b> · <?= round($ka['n'] / $rdKitchen['total'] * 100) ?>%</span></div>
                <?php endforeach; ?>
                <?php else: ?><div class="rd2-muted small mt-1"><?= t('Nothing waiting in the kitchen.') ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="rd2-att">
                <?php if ($rdActs): ?>
                <div class="btn-group btn-group-sm rdp-ptabs w-100 mb-2" role="group" aria-label="<?= htmlspecialchars(t('Needs your attention')) ?>">
                    <button type="button" class="btn btn-outline-primary active" data-pt="att"><?= t('Needs attention') ?><?php if ($att): ?> <span class="rd2-cnt"><?= count($att) ?></span><?php endif; ?></button>
                    <button type="button" class="btn btn-outline-primary" data-pt="act"><?= t('Activities') ?></button>
                </div>
                <?php else: ?>
                <h6 class="mb-2"><?= t('Needs your attention') ?><?php if ($att): ?> <span class="rd2-cnt"><?= count($att) ?></span><?php endif; ?></h6>
                <?php endif; ?>
                <div class="rd2-pt" data-pt="att">
                    <?php if (!$att): ?><div class="rdp-empty"><i class="bi bi-check2-circle"></i><div><?= t('Nothing needs your attention.') ?></div></div><?php endif; ?>
                    <?php foreach ($att as [$tone, $ico, $txt, $lnk]): ?>
                    <a href="<?= $lnk ?>" class="rd2-row rd2-row-<?= $tone ?>"><i class="<?= $ico ?>"></i><span><?= $txt ?></span></a>
                    <?php endforeach; ?>
                </div>
                <?php if ($rdActs): ?>
                <div class="rd2-pt d-none" data-pt="act">
                    <?php foreach ($rdActs as $a): ?>
                    <div class="rd2-row rd2-row-plain"><i class="bi bi-clock-history"></i><span><?= safe_output($a['description'] ?: $a['action']) ?><small class="d-block text-muted"><?= safe_output($a['username'] ?? '') ?> · <?= date('d M H:i', strtotime($a['created_at'])) ?></small></span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Top dishes for the period -->
        <section class="rd2-dishes rdp-card">
            <div class="rdp-card-h"><h6><?= t('Top dishes') ?> · <?= $nowWord ?></h6><a href="<?= getUrl('restaurant/sales-report') ?>" class="small fw-semibold"><?= t('All dishes') ?> →</a></div>
            <?php if (!$top): ?>
            <div class="rdp-empty"><i class="ri ri-utensils"></i><div><?= t('Nothing sold in this period yet.') ?></div></div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0 rd2-table">
                <thead><tr><th><?= t('Dish') ?></th><?php if ($rdMoney): ?><th class="text-end"><?= t('Price') ?></th><?php endif; ?><th class="text-end"><?= t('Sold') ?></th><?php if ($rdMoney): ?><th class="text-end"><?= t('Sales') ?></th><th class="text-end"><?= t('Profit') ?></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($top as $d): ?>
                <tr>
                    <td><span class="d-flex align-items-center gap-2"><span class="rd2-ini"><?= safe_output($initials($d['name'])) ?></span><span class="fw-semibold"><?= safe_output($d['name']) ?></span></span></td>
                    <?php if ($rdMoney): ?><td class="text-end"><?= $rdFmt($d['price']) ?></td><?php endif; ?>
                    <td class="text-end"><?= rtrim(rtrim(number_format($d['qty'], 2), '0'), '.') ?></td>
                    <?php if ($rdMoney): ?>
                    <td class="text-end fw-bold"><?= $rdFmt($d['price'] * $d['qty']) ?></td>
                    <td class="text-end"><?php if ($d['margin'] === null): ?><a href="<?= getUrl('restaurant/menu') ?>" class="rd2-need"><?= t('Needs a cost') ?></a>
                        <?php else: ?><span class="rd2-mg"><span><span style="width:<?= max(0, min(100, (float)$d['margin'])) ?>%"></span></span><b><?= $pct($d['margin']) ?>%</b></span><?php endif; ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </section>

        <?php if ($rdMoney): ?>
        <!-- Money for the period, from the books -->
        <section class="rd2-money rdp-card">
            <div class="rdp-card-h"><h6><?= t('Money') ?> · <?= $nowWord ?></h6>
                <?php if ($unc > 0 || $rdWarn): ?><span class="rdp-pill rd2-pill-warn"><?= t('Estimated') ?></span><?php else: ?><span class="rdp-pill rdp-pill-ok"><?= t('Complete') ?></span><?php endif; ?></div>
            <div class="p-3">
                <div class="rd2-ml"><span><?= t('Sales') ?></span><b><?= $rdFmt($cur['sales']) ?></b></div>
                <div class="rd2-ml"><span><?= t('Drinks & stock used') ?></span><b>− <?= $rdFmt($PT['stock']) ?></b></div>
                <div class="rd2-ml"><span><?= t('Food bought') ?></span><b>− <?= $rdFmt($PT['food']) ?></b></div>
                <?php if ($PT['waste'] > 0): ?><div class="rd2-ml"><span><?= t('Spoiled & waste') ?></span><b>− <?= $rdFmt($PT['waste']) ?></b></div><?php endif; ?>
                <div class="rd2-ml"><span><?= t('Expenses') ?></span><b>− <?= $rdFmt($cur['expenses']) ?></b></div>
                <div class="rd2-ml rd2-ml-total"><span><?= t('Net Profit') ?></span><b class="<?= $cur['profit'] < 0 ? 'text-danger' : '' ?>"><?= $rdFmt($cur['profit']) ?> <small><?= htmlspecialchars($rdCur) ?></small></b></div>
                <?php if ($hasSales && $cur['profit'] >= 0): ?><div class="rd2-muted small mt-1"><?= sprintf(t('Of every 100 you sell, %s is yours.'), '<b>' . $pct($rdStory['per100']['profit']) . '</b>') ?></div>
                <?php elseif ($hasSales): ?><div class="small mt-1 text-danger"><?= t('You are spending more than you sell in this period.') ?></div><?php endif; ?>
                <?php foreach ($rdWarn as $w):
                    if ($w['type'] === 'no_cost')             { $wt = sprintf(t('%d item(s) sold have no cost.'), $w['n']); $wl = getUrl('restaurant/menu'); }
                    elseif ($w['type'] === 'no_food_bought')  { $wt = t('You sold dishes with no recipe, but no food purchase is recorded this month.'); $wl = getUrl('restaurant/purchases'); }
                    else                                      { $wt = t('No expenses are recorded this month — gas, wages, rent?'); $wl = getUrl('expenses'); } ?>
                <a href="<?= $wl ?>" class="d-block small rd2-warn mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= $wt ?></a>
                <?php endforeach; ?>
                <div class="rd2-owe">
                    <?php if ($rdOwed !== null): ?><a href="<?= getUrl('pos/credit-customers') ?>" class="rd2-owe-in"><span><?= t('Customers owe us') ?></span><b><?= $rdFmt($rdOwed) ?></b></a><?php endif; ?>
                    <?php if ($rdSupp !== null): ?><a href="<?= getUrl('restaurant/purchases') ?>#rpOwe" class="rd2-owe-out"><span><?= t('We owe suppliers') ?></span><b><?= $rdFmt($rdSupp['owed']) ?></b></a><?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Latest orders: tables not paid yet, then the last paid -->
        <section class="rd2-orders rdp-card">
            <div class="rdp-card-h"><h6><?= t('Latest orders') ?></h6><?php if (canView('pos')): ?><a href="<?= getUrl('pos_dashboard') ?>" class="small fw-semibold"><?= t('See all') ?> →</a><?php endif; ?></div>
            <?php if (!$orderRows): ?><div class="rdp-empty"><i class="bi bi-receipt"></i><div><?= t('No orders yet today. Tap "Take order" to start.') ?></div></div><?php endif; ?>
            <?php foreach ($orderRows as $o): ?>
            <div class="rd2-or">
                <span class="rd2-ini"><?= safe_output($o['tag']) ?></span>
                <span class="flex-grow-1 min-w-0"><b><?= safe_output($o['title']) ?></b><small class="d-block rd2-muted"><?= safe_output($o['meta']) ?></small></span>
                <b class="text-nowrap"><?= $rdFmt($o['amount']) ?></b>
                <span class="rd2-st <?= $o['open'] ? 'rd2-st-open' : 'rd2-st-paid' ?>"><?= $o['open'] ? t('Open') : t('Paid') ?></span>
            </div>
            <?php endforeach; ?>
        </section>

        <!-- Sales by staff for the period -->
        <section class="rd2-staff rdp-card">
            <div class="rdp-card-h"><h6><?= t('Sales by staff') ?> · <?= $nowWord ?></h6></div>
            <div class="p-3">
                <?php if (!$rdStaff): ?><div class="rd2-muted small"><?= t('Nothing sold in this period yet.') ?></div><?php endif; ?>
                <?php foreach ($rdStaff as $s): $sv = $rdMoney ? $s['sales'] : $s['orders']; ?>
                <div class="rd2-sf">
                    <div class="d-flex justify-content-between"><span><b><?= safe_output($s['name']) ?></b> <span class="rd2-muted">· <?= sprintf(t('%d orders'), $s['orders']) ?></span></span><?php if ($rdMoney): ?><b><?= $rdFmt($s['sales']) ?></b><?php endif; ?></div>
                    <div class="rd2-sfb"><span style="width:<?= round($sv / $staffMax * 100) ?>%"></span></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
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

/* ── Layout 2 — "Side actions" (restaurant-only company). Redesign 2026-11-03: white cards, BMS blue only, amber = it needs you;
      no black, no red. Sizes in px: the page's root font is 12px, and style.css makes every .btn 16px / 85px wide. ── */
.rdp-l2 .text-danger { color:#b45309 !important; }
.rdp-l2 .rdp-card-h h6 { font-size:15px; }
.rdp-l2 .rdp-foot { font-size:12px; }
.rdp-l2 .rdp-empty { font-size:13px; }
.rd2 { display:grid; gap:16px; grid-template-columns:220px minmax(0, 2fr) minmax(0, 1fr);
       grid-template-areas:"acts hero hero" "acts chart panel" "acts dishes money" "acts orders staff"; }
.rd2 > * { min-width:0; }
.rd2-acts { grid-area:acts; display:flex; flex-direction:column; gap:4px; align-self:start; background:#fff; border:1px solid #dbe6fb; border-radius:14px; padding:8px; }
.rd2-hero { grid-area:hero; } .rd2-chart { grid-area:chart; } .rd2-panel { grid-area:panel; }
.rd2-dishes { grid-area:dishes; } .rd2-money { grid-area:money; } .rd2-orders { grid-area:orders; } .rd2-staff { grid-area:staff; }
.rd2-flat { grid-template-areas:"acts hero hero" "acts chart panel" "acts dishes dishes" "acts orders staff"; }
/* quick actions */
.rd2-act { display:flex; align-items:center; gap:11px; border-radius:10px; padding:7px 8px; text-decoration:none; color:#1f2937; min-height:48px; }
.rd2-act:hover { background:#f4f8ff; color:#0a58ca; }
.rd2-sq { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex:none; font-size:18px; background:#e7f0ff; color:#0d6efd; }
.rd2-t-main .rd2-sq { background:#0d6efd; color:#fff; }
.rd2-t-amber .rd2-sq { background:#fff1d6; color:#b45309; }
.rd2-at b { display:block; font-size:14px; font-weight:600; line-height:1.2; } .rd2-at small { display:block; font-size:12px; color:#5b6472; line-height:1.2; }
.rd2-t-amber .rd2-at small { color:#92400e; font-weight:600; }
/* tiles */
.rd2-sum { display:grid; gap:16px; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); }
.rd2-tile { display:flex; flex-direction:column; gap:7px; background:#fff; border:1px solid #dbe6fb; border-radius:14px; padding:16px 18px; text-decoration:none; color:#1f2937; min-width:0; }
a.rd2-tile:hover { border-color:#9ec5fe; box-shadow:0 4px 14px rgba(13,110,253,.10); color:#1f2937; }
.rd2-tl { display:flex; justify-content:space-between; align-items:center; gap:6px; font-size:13px; font-weight:600; color:#5b6472; }
.rd2-tl i { font-size:20px; color:#0d6efd; }
.rd2-tv { font-size:26px; font-weight:800; line-height:1.1; color:#0b3d91; overflow-wrap:anywhere; font-variant-numeric:tabular-nums; }
.rd2-tv small { font-size:13px; font-weight:600; color:#5b6472; }
.rd2-ts { font-size:12px; color:#5b6472; }
.rd2-warn { color:#92400e !important; font-weight:600; }
.rd2-split { display:flex; height:8px; border-radius:6px; overflow:hidden; background:#e7f0ff; gap:2px; }
.rd2-split span { display:block; height:100%; }
.rd2-tile-main { background:#0d6efd; border-color:#0d6efd; color:#fff; }
a.rd2-tile-main:hover { background:#0b5ed7; border-color:#0b5ed7; color:#fff; }
.rd2-tile-main .rd2-tl, .rd2-tile-main .rd2-ts { color:#e7f0ff; } .rd2-tile-main .rd2-tv { color:#fff; } .rd2-tile-main .rd2-tv small { color:#dbe6fb; }
.rd2-now { font-size:11px; font-weight:700; border-radius:20px; padding:2px 8px; background:rgba(255,255,255,.22); color:#fff; white-space:nowrap; }
.rd2-seats { display:flex; gap:4px; } .rd2-seats span { flex:1; height:8px; border-radius:4px; background:rgba(255,255,255,.3); } .rd2-seats span.on { background:#fff; }
.rd2-split-w { background:rgba(255,255,255,.3); }
/* chart */
.rd2-legend { font-size:12px; color:#5b6472; margin-top:2px; } .rd2-lc { color:#0d6efd; font-weight:600; } .rd2-lp { color:#8a94a6; font-weight:600; }
.rd2-ct .btn, .rdp-ptabs .btn { min-width:0 !important; font-size:12px !important; padding:4px 12px !important; line-height:1.4; font-weight:600; }
.rd2-plot { padding:12px 16px 4px; }
.rd2-bars.rd2-pv { display:block; height:auto; border-bottom:0; }
.rd2-pv-h { display:flex; flex-wrap:wrap; align-items:flex-end; gap:8px 12px; margin-bottom:6px; }
.rd2-pv-k { font-size:12px; color:#5b6472; font-weight:600; }
.rd2-pv-v { font-size:24px; font-weight:800; color:#052c65; line-height:1.15; } .rd2-pv-v small { font-size:12px; color:#5b6472; font-weight:600; }
.rd2-pv-vs { font-size:12px; font-weight:700; color:#047857; background:#ecfdf5; border-radius:999px; padding:3px 9px; }
.rd2-pv-vs.rd2-pv-down { color:#b42318; background:#fef3f2; }
.rd2-pv-est { font-size:11px; font-weight:700; color:#92400e; background:#fef3c7; border-radius:999px; padding:3px 9px; }
.rd2-pv-legend { display:flex; flex-wrap:wrap; gap:6px 14px; font-size:12px; color:#374151; margin-bottom:6px; }
.rd2-pv-legend i { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:5px; vertical-align:-1px; }
.rd2-pv-legend i.rd2-pv-ln { width:14px; height:3px; border-radius:2px; vertical-align:2px; }
.rd2-psvg { display:block; width:100%; height:220px; border-bottom:1px solid #e3eaf7; }
.rd2-pv-today { max-width:420px; padding:6px 0 8px; } .rd2-pv-row { display:flex; justify-content:space-between; font-size:14px; padding:4px 0; border-bottom:1px dashed #eef0f3; }
.rd2-bars { display:flex; align-items:flex-end; gap:6px; height:210px; border-bottom:1px solid #e3eaf7; }
.rd2-b { flex:1; display:flex; align-items:flex-end; justify-content:center; gap:2px; height:100%; min-width:0; }
.rd2-b span { width:44%; max-width:14px; border-radius:4px 4px 0 0; min-height:1px; }
.rd2-bc { background:#0d6efd; } .rd2-bp { background:#c7d3e6; }
.rd2-bl { display:flex; gap:6px; padding-top:5px; } .rd2-bl span { flex:1; min-width:0; text-align:center; font-size:11px; color:#5b6472; white-space:nowrap; overflow:visible; }
/* kitchen + attention */
.rd2-panel h6 { margin:0; font-size:15px; font-weight:700; color:#0b3d91; }
.rd2-kit { padding:14px 16px; border-bottom:1px solid #eef3fd; }
.rd2-split-k { height:12px; margin:12px 0 10px; }
.rd2-kr { display:flex; justify-content:space-between; font-size:13px; padding:2px 0; } .rd2-kr i { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:7px; }
.rd2-att { padding:14px 16px; }
.rd2-muted { color:#5b6472; font-size:13px; }
.rd2-cnt { display:inline-block; min-width:18px; padding:0 6px; border-radius:20px; background:#fff1d6; color:#7a4b00; font-size:11px; line-height:18px; text-align:center; vertical-align:middle; }
.rd2-row { display:flex; align-items:flex-start; gap:9px; padding:9px 10px; border-radius:10px; margin-bottom:8px; text-decoration:none; font-size:13px; color:#1f2937; }
.rd2-row i { margin-top:1px; }
.rd2-row-amber { background:#fff7e6; } .rd2-row-amber i { color:#b45309; }
.rd2-row-blue { background:#f4f8ff; } .rd2-row-blue i { color:#0d6efd; }
.rd2-row-plain { border-bottom:1px solid #eef3fd; border-radius:0; margin:0; } .rd2-row-plain i { color:#0d6efd; }
a.rd2-row:hover { filter:brightness(.98); color:#0a58ca; }
.rd2-row small { font-size:12px; }
/* dishes */
.rd2-table th { font-size:12px; color:#0b3d91; font-weight:700; background:#f4f8ff !important; white-space:nowrap; padding:9px 12px !important; }
.rd2-table td { font-size:13px; padding:9px 12px !important; }
.rd2-ini { width:36px; height:36px; border-radius:10px; background:#e7f0ff; color:#0b5ed7; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; flex:none; }
.rd2-mg { display:inline-flex; align-items:center; gap:8px; } .rd2-mg > span { width:60px; height:6px; border-radius:4px; background:#e7f0ff; overflow:hidden; display:inline-block; }
.rd2-mg > span > span { display:block; height:6px; background:#0d6efd; } .rd2-mg b { color:#0b3d91; }
.rd2-need { font-size:11px; font-weight:700; border-radius:20px; padding:2px 8px; background:#fff1d6; color:#7a4b00; text-decoration:none; white-space:nowrap; }
/* money */
.rd2-ml { display:flex; justify-content:space-between; font-size:14px; padding:4px 0; } .rd2-ml span { color:#5b6472; }
.rd2-ml-total { border-top:1px dashed #cfdcf3; margin-top:6px; padding-top:10px; } .rd2-ml-total span { color:#0b3d91; font-weight:700; } .rd2-ml-total b { font-size:18px; color:#0b3d91; }
.rd2-ml-total b small { font-size:12px; color:#5b6472; }
.rd2-pill-warn { background:#fff1d6; color:#7a4b00; }
.rd2-owe { display:grid; grid-template-columns:repeat(auto-fit, minmax(120px, 1fr)); gap:10px; margin-top:14px; }
.rd2-owe a { border-radius:12px; padding:10px 12px; text-decoration:none; } .rd2-owe span { display:block; font-size:12px; } .rd2-owe b { font-size:16px; }
.rd2-owe-in { background:#f4f8ff; border:1px solid #dbe6fb; } .rd2-owe-in span { color:#5b6472; } .rd2-owe-in b { color:#0b3d91; }
.rd2-owe-out { background:#fff7e6; border:1px solid #f5d38f; } .rd2-owe-out span, .rd2-owe-out b { color:#7a4b00; }
/* latest orders + staff */
.rd2-or { display:flex; align-items:center; gap:12px; padding:10px 16px; border-bottom:1px solid #eef3fd; font-size:13px; }
.rd2-or b { font-weight:600; }
.rd2-st { font-size:11px; font-weight:700; border-radius:20px; padding:3px 10px; white-space:nowrap; }
.rd2-st-open { background:#cfe2ff; color:#084298; } .rd2-st-paid { background:#052c65; color:#fff; }
.rd2-sf { font-size:13px; margin-bottom:12px; } .rd2-sf b { font-weight:600; }
.rd2-sfb { height:6px; border-radius:4px; background:#e7f0ff; overflow:hidden; margin-top:5px; } .rd2-sfb span { display:block; height:6px; background:#0d6efd; }
@media (max-width:1199.98px) {
    .rd2, .rd2-flat { grid-template-columns:minmax(0, 1fr); grid-template-areas:none; }
    .rd2 > * { grid-area:auto !important; }
    .rd2-acts { flex-direction:row; flex-wrap:wrap; }
    .rd2-act { flex:1 1 calc(25% - 8px); flex-direction:column; text-align:center; gap:6px; padding:8px 4px; min-width:72px; border:1px solid #dbe6fb; }
}
@media (max-width:575.98px) {
    .rd2-acts { padding:0; border:0; background:transparent; gap:8px; }
    .rd2-act { flex-basis:calc(25% - 6px); min-width:0; background:#fff; padding:10px 2px; min-height:74px; }
    .rd2-at b { font-size:11px; } .rd2-at small { display:none; }
    .rd2-sum { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
    .rd2-tile { padding:12px; } .rd2-tv { font-size:19px; }
    .rd2-bars { height:140px; gap:3px; } .rd2-b span { width:46%; }
    .rd2-psvg { height:170px; } .rd2-pv-v { font-size:20px; }
}
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
    // Layout 2 draws its chart in plain HTML (no Chart.js); the mixed-company strip still uses the canvases.
    if (el('trend') || el('hours') || el('types')) withChart(function () { drawTrend(TREND); drawHours(); drawTypes(); });

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
    // Layout 2 chart: Sales | Orders
    root.querySelectorAll('.rd2-ct button').forEach(function (b) {
        b.addEventListener('click', function () {
            root.querySelectorAll('.rd2-ct button').forEach(x => x.classList.remove('active')); b.classList.add('active');
            root.querySelectorAll('.rd2-bars').forEach(x => x.classList.toggle('d-none', x.dataset.ct !== b.dataset.ct));
            root.querySelectorAll('[data-ctv]').forEach(x => x.classList.toggle('d-none', (' ' + x.dataset.ctv + ' ').indexOf(' ' + b.dataset.ct + ' ') < 0));
            const bl = root.querySelector('.rd2-chart .rd2-bl'); if (bl) bl.classList.toggle('d-none', b.dataset.ct === 'profit' && bl.dataset.today === '1');   // Today: no hourly profit, so no hour labels
            const h = root.querySelector('.rd2-ct-title'); if (h) h.textContent = h.dataset[b.dataset.ct] || h.textContent;
        });
    });
    // Side panel tabs (Layout 2): needs attention / activities
    root.querySelectorAll('.rdp-ptabs button').forEach(function (b) {
        b.addEventListener('click', function () {
            root.querySelectorAll('.rdp-ptabs button').forEach(x => x.classList.remove('active')); b.classList.add('active');
            root.querySelectorAll('.rd2-pt').forEach(t => t.classList.toggle('d-none', t.dataset.pt !== b.dataset.pt));
        });
    });
})();
</script>
</div>
