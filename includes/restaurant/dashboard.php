<?php
/**
 * includes/restaurant/dashboard.php — the restaurant dashboard (restaurant_plan.md Phase 6; redesigned 2026-11-02 to the
 * owner's chosen "Layout 2": white with blue, quick actions in a blue side bar, summary cards, notifications and activity).
 * Shown by app/dashboard.php INSTEAD of the shop dashboard only for a restaurant-only company (restaurantOnlyCompany()).
 * Expects $pdo and the header already rendered. Retail Smart POS and Smart BMS never reach this file.
 *
 *   side bar   quick actions, each with a live count (Take order, Tables, Kitchen, Menu, Buy stock, Expenses, Reservations, Sales report)
 *   cards      Today: Sales today · Orders today · Open tables · Kitchen waiting
 *              Money: Customers owe us · We owe suppliers · Expenses this month · Profit this month   (money: users who see profit)
 *              every card opens the page behind it
 *   below      Performance beside Notifications | Activities | Running low | Orders, then money, what earns, busy hours
 *              (includes/restaurant/dash_panel.php)
 *
 * Money figures come from the one ledger (glProfitLoss / journal), counts from the operational tables.
 */
require_once ROOT_DIR . '/core/restaurant_dashboard.php';
require_once ROOT_DIR . '/core/restaurant_insights.php';
require_once ROOT_DIR . '/core/restaurant_notifications.php';
require_once ROOT_DIR . '/core/activity_log_helpers.php';

$rd      = restaurantDashboardData($pdo);
$rdCur   = getSetting('currency', 'TZS');
$rdFmt   = fn($n) => number_format((float)$n, 0);
$rdTypes = ['dine_in' => t('Dine in'), 'take_away' => t('Take away'), 'delivery' => t('Delivery')];
$rdName  = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? '');
$rdMoney = hasReportsAccess() || canView('invoices') || canView('sales_report');   // same rule as the panel and the retail Performance card
$rdIds   = rdRestaurantIds($pdo);
$rdJe    = rdJeScope($rdIds, 'company');
$rdMonthFrom = date('Y-m-01');
$rdToday = date('Y-m-d');

// Worked out once here; the panel below reuses them.
$rdHero   = rdHero($pdo, $rdJe, $rdIds);
$rdStory  = rdProfitStory($pdo, $rdJe);
$rdDishes = rdDishEarnings($pdo, $rdIds, $rdMonthFrom, $rdToday);
$rdKitchen = restaurantKitchenNow($pdo, $rdIds);
$rdRes    = restaurantReservationsToday($pdo, $rdIds);
$rdLow    = restaurantLowStockCount($pdo, $rdIds);
$rdNoCost = count($rdDishes['needs_cost']);
$rdNotes  = restaurantNotifications($pdo, $rdIds, $rdMoney);   // the same list as the header bell
$rdTablesTotal = 0;
if ($rdIds) {
    try { $rdTablesTotal = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_tables WHERE warehouse_id IN (" . rdIn($rdIds) . ")")->fetchColumn(); }
    catch (Throwable $e) { error_log('restaurant dashboard tables: ' . $e->getMessage()); }
}
$rdPending = ['n' => 0, 'amount' => 0.0];
if (canView('expenses')) {
    try {
        $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS a FROM expenses e WHERE e.status IN ('pending','reviewed')"
            . scopeFilterSqlNullable('warehouse', 'e') . scopeFilterSqlNullable('project', 'e'))->fetch(PDO::FETCH_ASSOC) ?: [];
        $rdPending = ['n' => (int)($r['n'] ?? 0), 'amount' => (float)($r['a'] ?? 0)];
    } catch (Throwable $e) { error_log('restaurant dashboard pending expenses: ' . $e->getMessage()); }
}
$rdCredit = null;                                      // the same figures as "Who Owes Me" and the retail Credit card
if ($rdMoney && canView('pos')) {
    require_once ROOT_DIR . '/core/pos_credit_aging.php';
    $rdCredit = ['total' => posCreditTotalOutstanding($pdo, scopeFilterSqlNullable('warehouse', 's')), 'customers' => 0, 'overdue' => 0];
    try {
        $open = posCreditOpenSales($pdo, null, scopeFilterSqlNullable('warehouse', 's'));
        $rdCredit['customers'] = count(array_unique(array_column($open, 'customer_id')));
        $rdCredit['overdue'] = count(array_filter($open, fn($r) => !empty($r['is_overdue'])));
    } catch (Throwable $e) { /* the total is the headline */ }
}
$rdOweSup = $rdMoney ? restaurantOwedToSuppliers($pdo, $rdIds) : null;

// Recent activities — the same source and rule as the retail dashboard (page views hidden; everyone else sees their own).
$rdActs = [];
try {
    $seeAll = isAdmin() || canView('audit_logs');
    $sql = "SELECT al.action, al.description, al.created_at, u.username FROM activity_logs al LEFT JOIN users u ON u.user_id = al.user_id
             WHERE NOT (al.action LIKE 'View%' OR al.action IN ('VIEW','view_list','FILTER') OR al.action LIKE 'Filter%' OR al.action LIKE 'Searched%'
                        OR COALESCE(al.description,'') LIKE 'User viewed%' OR COALESCE(al.description,'') LIKE '[LOG] User viewed%' OR COALESCE(al.description,'') LIKE '[VIEW]%')"
         . ($seeAll ? '' : ' AND al.user_id = ' . (int)$_SESSION['user_id']) . " ORDER BY al.created_at DESC LIMIT 12";
    $rdActs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('restaurant dashboard activity: ' . $e->getMessage()); }
/** Kind of an activity line, for its icon, colour and filter. */
$rdActKind = function (array $a): string {
    $s = strtolower(($a['action'] ?? '') . ' ' . ($a['description'] ?? ''));
    if (preg_match('/kitchen|ticket|ready|preparing/', $s)) return 'kitchen';
    if (preg_match('/sale|payment|paid|receipt|refund|order|invoice/', $s)) return 'sales';
    if (preg_match('/stock|purchase|product|menu|ingredient|supplier|recipe/', $s)) return 'stock';
    if (preg_match('/shift|login|logout|user|employee|role/', $s)) return 'staff';
    return 'other';
};
$rdActStyle = ['sales' => ['bi-cart-check', '#0B5ED7'], 'kitchen' => ['bi-fire', '#B45309'], 'stock' => ['bi-basket', '#0F766E'],
               'staff' => ['bi-person', '#7C3AED'], 'other' => ['bi-activity', '#475F8A']];
$rdAgo = function (string $ts): string {
    $m = max(0, (int)floor((time() - strtotime($ts)) / 60));
    if ($m < 1) return t('Just now');
    if ($m < 60) return sprintf(t('%d min ago'), $m);
    if ($m < 1440) return sprintf(t('%d hr ago'), (int)floor($m / 60));
    return date('d M H:i', strtotime($ts));
};
$rdNoteStyle = ['urgent' => ['#B45309', t('Urgent')], 'today' => ['#0B5ED7', t('Today')], 'stock' => ['#0F766E', t('Stock')],
                'money' => ['#4338CA', t('Money')], 'new' => ['#0369A1', t('New')]];

$rdCanBuy   = (canView('pos') && canView('pos_restock')) || hasPermission('adjust_stock') || isAdmin();
$rdCanSales = hasReportsAccess() || canView('sales_report') || canView('pos');
$rdPct = fn(?float $p) => $p === null ? '' : (($p >= 0 ? '▲ ' : '▼ ') . rtrim(rtrim(number_format(abs($p), 1), '0'), '.') . '%');

// Quick actions — each says what is waiting there now.
$rdActions = [];
if (canCreate('pos')) {
    $rdActions[] = ['Take order', t('Take order'), t('Open the till'), 'bi-cart-check', '#0B5ED7', getUrl('pos')];
    $rdActions[] = ['Tables', t('Tables'), sprintf(t('%d with order'), (int)$rdHero['open']['tables']), 'bi-grid-3x3-gap', '#0369A1', getUrl('restaurant/tables-board')];
}
$rdActions[] = ['Kitchen', t('Kitchen'), $rdKitchen['on'] ? ($rdKitchen['late'] > 0 ? sprintf(t('%d waiting long'), $rdKitchen['late']) : sprintf(t('%d cooking'), $rdKitchen['in_kitchen'])) : t('By mouth'),
                'bi-fire', '#B45309', getUrl('restaurant/kitchen-dashboard')];
$rdActions[] = ['Menu', t('Menu'), ($rdMoney && $rdNoCost > 0) ? sprintf(t('%d need a cost'), $rdNoCost) : t('Dishes and drinks'), 'bi-journal-richtext', '#0F766E', getUrl('restaurant/menu')];
if ($rdCanBuy) $rdActions[] = ['Buy stock', t('Buy stock'), $rdLow > 0 ? sprintf(t('%d running low'), $rdLow) : t('Stock & ingredients'), 'bi-basket', '#A16207', getUrl('restaurant/purchases')];
if (canView('expenses')) $rdActions[] = ['Expenses', t('Expenses'), $rdPending['n'] > 0 ? sprintf(t('%d to approve'), $rdPending['n']) : t('Record a cost'), 'bi-receipt', '#4338CA', getUrl('expenses')];
$rdActions[] = ['Reservations', t('Reservations'), sprintf(t('%d today'), $rdRes['n']), 'bi-calendar-check', '#7C3AED', getUrl('restaurant/reservations')];
if ($rdCanSales) $rdActions[] = ['Sales report', t('Sales report'), t('Today and past'), 'bi-bar-chart', '#475F8A', getUrl('restaurant/sales-report')];

// Summary cards: [label, value, small line, link, accent]
$rdCardsToday = [
    [t('Sales today'), $rdFmt($rdHero['sales']['today']) . ' <small>' . htmlspecialchars($rdCur) . '</small>',
     sprintf(t('Yesterday %s'), $rdFmt($rdHero['sales']['yesterday'])) . ($rdHero['sales']['trend7'] !== null ? ' · ' . $rdPct($rdHero['sales']['trend7']) . ' ' . t('in 7 days') : ''),
     $rdCanSales ? getUrl('restaurant/sales-report') : '', 'bi-cash-stack'],
    [t('Orders today'), (string)(int)$rdHero['orders']['today'], sprintf(t('Average bill %s'), $rdFmt($rdHero['avg_bill']['today'])),
     $rdCanSales ? getUrl('restaurant/sales-report') : '', 'bi-receipt'],
    [t('Open tables'), (int)$rdHero['open']['tables'] . ($rdTablesTotal > 0 ? ' <small>' . sprintf(t('of %d'), $rdTablesTotal) . '</small>' : ''),
     sprintf(t('%s not paid yet'), $rdFmt($rdHero['open']['amount'])), getUrl('restaurant/tables-board'), 'bi-grid-3x3-gap'],
    [t('Kitchen waiting'), $rdKitchen['on'] ? (string)$rdKitchen['late'] : '—',
     $rdKitchen['on'] ? sprintf(t('over 20 min · %d cooking now'), $rdKitchen['in_kitchen']) : t('Kitchen screen is off (orders by mouth)'),
     getUrl('restaurant/kitchen-dashboard'), 'bi-fire'],
];
$rdCardsMoney = [];
if ($rdMoney) {
    if ($rdCredit !== null) $rdCardsMoney[] = [t('Customers owe us'), $rdFmt($rdCredit['total']),
        sprintf(t('%1$d customer(s) · %2$d overdue'), $rdCredit['customers'], $rdCredit['overdue']), getUrl('pos/credit-customers'), 'bi-person-check'];
    $rdCardsMoney[] = [t('We owe suppliers'), $rdFmt($rdOweSup['owed']),
        $rdOweSup['owed'] > 0 ? sprintf(t('%1$d supplier(s) · %2$d overdue'), $rdOweSup['suppliers'], $rdOweSup['overdue']) : t('Nothing owed'),
        $rdCanBuy ? getUrl('restaurant/purchases') . '#rpOwe' : '', 'bi-truck'];
    if (canView('expenses')) $rdCardsMoney[] = [t('Expenses this month'), $rdFmt($rd['month_expenses']),
        $rdPending['n'] > 0 ? sprintf(t('%d to approve'), $rdPending['n']) : t('from your books'),
        getUrl('expenses') . '?date_from=' . urlencode($rdMonthFrom) . '&date_to=' . urlencode(date('Y-m-t')), 'bi-wallet2'];
    $cur = $rdStory['cur'];
    $rdCardsMoney[] = [t('Profit this month'), '<span class="' . ($cur['profit'] < 0 ? 'rd2-neg' : '') . '">' . $rdFmt($cur['profit']) . '</span>',
        ($cur['sales'] > 0 ? rtrim(rtrim(number_format($rdStory['margin'], 1), '0'), '.') . '% ' . t('margin') : t('No sales yet')) . ($rdNoCost > 0 ? ' · ' . t('Estimated') : ''),
        $rdCanSales ? getUrl('restaurant/sales-report') : '', 'bi-graph-up-arrow'];
}

// The side box beside Performance: Notifications | Activities | Running low | Orders.
ob_start(); ?>
<div class="rdp-card h-100 rd2-box" id="rd2Box">
    <div class="rdp-card-h">
        <div class="rd2-seg" role="tablist">
            <button type="button" class="active" data-box="notes"><?= t('Notifications') ?> <span class="rd2-count"><?= count($rdNotes) ?></span></button>
            <button type="button" data-box="acts"><?= t('Activities') ?></button>
            <button type="button" data-box="low"><?= t('Running low') ?></button>
            <button type="button" data-box="orders"><?= t('Orders') ?></button>
        </div>
    </div>
    <div class="rd2-pane" data-box="notes">
        <div class="rd2-filter">
            <?php foreach (['all' => t('All'), 'urgent' => t('Urgent'), 'today' => t('Today'), 'stock' => t('Stock'), 'money' => t('Money')] as $k => $lbl):
                if ($k === 'money' && !$rdMoney) continue; ?>
            <button type="button" class="<?= $k === 'all' ? 'active' : '' ?>" data-f="<?= $k ?>"><?= $lbl ?></button>
            <?php endforeach; ?>
        </div>
        <div class="rd2-list">
            <?php if (!$rdNotes): ?><div class="rd2-empty"><i class="bi bi-check2-circle"></i><?= t('All good — nothing needs you right now.') ?></div><?php endif; ?>
            <?php foreach ($rdNotes as $n): [$col, $lab] = $rdNoteStyle[$n['level']] ?? $rdNoteStyle['new']; ?>
            <a class="rd2-row" data-level="<?= htmlspecialchars($n['level']) ?>" href="<?= htmlspecialchars($n['url'] ?: '#') ?>"<?= $n['id'] ? ' data-nid="' . (int)$n['id'] . '"' : '' ?>>
                <span class="rd2-ico" style="background:<?= $col ?>"><i class="bi <?= $n['icon'] ?>"></i></span>
                <span class="min-w-0"><span class="rd2-t"><span class="rd2-lab<?= $n['level'] === 'urgent' ? ' rd2-lab-u' : '' ?>"><?= $lab ?></span><?= safe_output($n['text']) ?></span>
                <span class="rd2-m"><?= safe_output($n['when']) ?></span></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="rd2-pane d-none" data-box="acts">
        <div class="rd2-filter">
            <?php foreach (['all' => t('All'), 'sales' => t('Sales'), 'kitchen' => t('Kitchen'), 'stock' => t('Stock'), 'staff' => t('Staff')] as $k => $lbl): ?>
            <button type="button" class="<?= $k === 'all' ? 'active' : '' ?>" data-f="<?= $k ?>"><?= $lbl ?></button>
            <?php endforeach; ?>
        </div>
        <div class="rd2-list">
            <?php if (!$rdActs): ?><div class="rd2-empty"><i class="bi bi-activity"></i><?= t('No activity yet.') ?></div><?php endif; ?>
            <?php foreach ($rdActs as $a): $k = $rdActKind($a); [$ico, $col] = $rdActStyle[$k]; ?>
            <div class="rd2-row" data-level="<?= $k ?>">
                <span class="rd2-ico" style="background:<?= $col ?>"><i class="bi <?= $ico ?>"></i></span>
                <span class="min-w-0"><span class="rd2-t rd2-plain"><?= safe_output(activityDisplayText((string)($a['description'] ?: $a['action']))) ?></span>
                <span class="rd2-m"><?= safe_output((string)($a['username'] ?? '')) ?> · <?= $rdAgo((string)$a['created_at']) ?></span></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (canView('audit_logs')): ?><a class="rd2-more" href="<?= getUrl('activity_log') ?>"><?= t('View all') ?> <i class="bi bi-chevron-right"></i></a><?php endif; ?>
    </div>
    <div class="rd2-pane d-none" data-box="low">
        <div class="rd2-sub"><?= t('Drinks and ingredients running low') ?></div>
        <div class="rd2-list">
            <?php if (!$rd['low_drinks']): ?><div class="rd2-empty"><i class="bi bi-check2-circle"></i><?= t('Everything has enough stock.') ?></div><?php endif; ?>
            <?php foreach ($rd['low_drinks'] as $d): ?>
            <div class="rd2-li"><span class="text-truncate"><?= safe_output($d['product_name']) ?></span><b><?= rtrim(rtrim(number_format((float)$d['stock'], 2), '0'), '.') ?> <?= t('left') ?></b></div>
            <?php endforeach; ?>
        </div>
        <?php if ($rd['low_drinks'] && $rdCanBuy): ?><a class="rd2-more" href="<?= getUrl('restaurant/purchases') ?>"><i class="bi bi-box-arrow-in-down me-1"></i><?= t('Buy stock & ingredients') ?></a><?php endif; ?>
    </div>
    <div class="rd2-pane d-none" data-box="orders">
        <div class="rd2-sub"><?= t('Recent orders') ?></div>
        <div class="rd2-list">
            <?php if (!$rd['recent']): ?><div class="rd2-empty"><i class="bi bi-receipt"></i><?= t('No orders yet today. Tap "Take order" to start.') ?></div><?php endif; ?>
            <?php foreach ($rd['recent'] as $o): ?>
            <div class="rd2-li"><span class="min-w-0"><b class="d-block text-truncate"><?= safe_output($o['table_number'] !== null ? t('Table') . ' ' . $o['table_number'] : ($rdTypes[$o['sale_type']] ?? t('Order'))) ?></b>
                <span class="rd2-m"><?= safe_output($o['receipt_number']) ?> · <?= date('H:i', strtotime($o['sale_date'])) ?></span></span><b><?= $rdFmt($o['grand_total']) ?></b></div>
            <?php endforeach; ?>
        </div>
        <?php if (canView('pos')): ?><a class="rd2-more" href="<?= getUrl('pos_dashboard') ?>"><?= t('See all') ?> <i class="bi bi-chevron-right"></i></a><?php endif; ?>
    </div>
</div>
<?php $rdPanelHtml = ob_get_clean(); ?>

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

    <div class="rd2">
        <!-- Quick actions (blue side bar on a computer, a grid on a phone) -->
        <nav class="rd-actions rd2-side" aria-label="<?= htmlspecialchars(t('Quick Actions')) ?>">
            <?php foreach ($rdActions as $a): ?>
            <a href="<?= $a[5] ?>" class="rd2-act">
                <span class="rd2-sq" style="background:<?= $a[4] ?>"><i class="bi <?= $a[3] ?>"></i></span>
                <span class="min-w-0"><span class="rd2-an"><?= $a[1] ?></span><span class="rd2-ab"><?= safe_output($a[2]) ?></span></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="rd2-main">
            <!-- Summary cards — each opens the page behind it -->
            <section class="rdp-card rd2-cards mb-3">
                <div class="rd2-row-lbl"><?= t('Today') ?></div>
                <div class="rd2-grid">
                    <?php foreach ($rdCardsToday as $c): $tag = $c[3] ? 'a' : 'div'; ?>
                    <<?= $tag ?> class="rd2-card"<?= $c[3] ? ' href="' . htmlspecialchars($c[3]) . '"' : '' ?>>
                        <span class="rd2-cl"><i class="bi <?= $c[4] ?> me-1"></i><?= $c[0] ?></span>
                        <span class="rd2-cv"><?= $c[1] ?></span>
                        <span class="rd2-cs"><?= $c[2] ?></span>
                    </<?= $tag ?>>
                    <?php endforeach; ?>
                </div>
                <?php if ($rdCardsMoney): ?>
                <div class="rd2-row-lbl mt-2"><?= t('Money') ?></div>
                <div class="rd2-grid">
                    <?php foreach ($rdCardsMoney as $c): $tag = $c[3] ? 'a' : 'div'; ?>
                    <<?= $tag ?> class="rd2-card"<?= $c[3] ? ' href="' . htmlspecialchars($c[3]) . '"' : '' ?>>
                        <span class="rd2-cl"><i class="bi <?= $c[4] ?> me-1"></i><?= $c[0] ?></span>
                        <span class="rd2-cv"><?= $c[1] ?> <small><?= htmlspecialchars($rdCur) ?></small></span>
                        <span class="rd2-cs"><?= $c[2] ?></span>
                    </<?= $tag ?>>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <!-- Performance + the side box, then money, what earns, busy hours (includes/restaurant/dash_panel.php) -->
            <?php $rdScope = 'company'; include ROOT_DIR . '/includes/restaurant/dash_panel.php'; ?>
        </div>
    </div>
</div>

<?php require ROOT_DIR . '/includes/restaurant/buy_ingredients_modal.php'; ?>
<script>
window.rbiSaved = function () { setTimeout(function () { location.reload(); }, 1600); };
(function () {
    const box = document.getElementById('rd2Box'); if (!box) return;
    box.querySelectorAll('.rd2-seg button').forEach(function (b) {
        b.addEventListener('click', function () {
            box.querySelectorAll('.rd2-seg button').forEach(x => x.classList.toggle('active', x === b));
            box.querySelectorAll('.rd2-pane').forEach(p => p.classList.toggle('d-none', p.dataset.box !== b.dataset.box));
        });
    });
    box.querySelectorAll('.rd2-pane').forEach(function (pane) {
        pane.querySelectorAll('.rd2-filter button').forEach(function (b) {
            b.addEventListener('click', function () {
                pane.querySelectorAll('.rd2-filter button').forEach(x => x.classList.toggle('active', x === b));
                pane.querySelectorAll('.rd2-row').forEach(r => r.classList.toggle('d-none', b.dataset.f !== 'all' && r.dataset.level !== b.dataset.f));
            });
        });
    });
    // An engine notification is marked read when it is opened (the same endpoint as the notification centre).
    box.querySelectorAll('.rd2-row[data-nid]').forEach(function (a) {
        a.addEventListener('click', function () {
            try { navigator.sendBeacon(<?= json_encode(buildUrl('api/mark_notification_read.php')) ?>, new URLSearchParams({ notification_id: a.dataset.nid })); } catch (e) {}
        });
    });
})();
</script>

<style>
/* Layout 2 — white with blue: quick actions in a blue side bar, summary cards, then the panel. No red or black; urgent is amber. */
.rd2 { display:grid; grid-template-columns:200px minmax(0,1fr); gap:14px; align-items:start; }
.rd2-side { background:#0B5ED7; border-radius:14px; padding:10px; display:grid; gap:8px; position:sticky; top:12px; }
.rd2-act { display:flex; gap:9px; align-items:center; background:#fff; border-radius:10px; padding:8px; text-decoration:none; color:#12305A; min-height:54px; }
.rd2-act:hover { background:#eef4ff; color:#12305A; }
.rd2-sq { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; flex:none; font-size:1.05rem; }
.rd2-an { display:block; font-weight:600; font-size:.86rem; line-height:1.15; }
.rd2-ab { display:block; font-size:.74rem; color:#4F6381; line-height:1.15; }
.rd2-main { min-width:0; }
.rd2-cards { padding:12px 14px; }
.rd2-row-lbl { font-size:.72rem; font-weight:700; color:#4F6381; text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px; }
.rd2-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:8px; }
.rd2-card { display:block; background:#eef4ff; border:1px solid transparent; border-radius:10px; padding:10px 12px; text-decoration:none; color:#12305A; min-width:0; }
a.rd2-card:hover { border-color:#0d6efd; background:#fff; color:#12305A; }
.rd2-cl { display:block; font-size:.78rem; font-weight:600; color:#4F6381; }
.rd2-cv { display:block; font-size:1.45rem; font-weight:700; line-height:1.2; overflow-wrap:anywhere; color:#0B3D91; }
.rd2-cv small { font-size:.7rem; font-weight:600; color:#4F6381; }
.rd2-cs { display:block; font-size:.74rem; color:#4F6381; }
.rd2-neg { color:#B45309; }
.rd2-box .rdp-card-h { padding:8px 10px; }
.rd2-seg, .rd2-filter { display:flex; flex-wrap:wrap; gap:4px; }
.rd2-seg button, .rd2-filter button { border:1px solid #dbe6fb; background:#fff; color:#0B5ED7; border-radius:7px; padding:4px 9px; font-size:.78rem; font-weight:600; min-height:32px; }
.rd2-seg button.active, .rd2-filter button.active { background:#0B5ED7; border-color:#0B5ED7; color:#fff; }
.rd2-count { display:inline-block; min-width:18px; padding:0 5px; border-radius:9px; background:#fff; color:#0B5ED7; font-size:.7rem; }
.rd2-seg button:not(.active) .rd2-count { background:#eef4ff; }
.rd2-filter { padding:6px 10px; border-bottom:1px solid #eef3fd; }
.rd2-list { max-height:310px; overflow:auto; }
.rd2-row { display:flex; gap:9px; padding:8px 12px; border-bottom:1px solid #eef3fd; text-decoration:none; color:#12305A; align-items:flex-start; }
a.rd2-row:hover { background:#eef4ff; color:#12305A; }
.rd2-ico { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; color:#fff; flex:none; font-size:.85rem; }
.rd2-t { display:block; font-size:.82rem; font-weight:600; }
.rd2-plain { font-weight:500; }
.rd2-m { display:block; font-size:.72rem; color:#4F6381; }
.rd2-lab { display:inline-block; font-size:.66rem; font-weight:700; padding:0 5px; border-radius:4px; margin-right:5px; background:#eef4ff; color:#0B5ED7; }
.rd2-lab-u { background:#B45309; color:#fff; }
.rd2-li { display:flex; justify-content:space-between; gap:8px; padding:8px 12px; border-bottom:1px solid #eef3fd; font-size:.85rem; }
.rd2-sub { padding:8px 12px 2px; font-size:.75rem; font-weight:700; color:#4F6381; }
.rd2-empty { display:flex; flex-direction:column; align-items:center; gap:4px; padding:22px 12px; color:#4F6381; font-size:.85rem; text-align:center; }
.rd2-empty i { font-size:1.5rem; color:#9ec5fe; }
.rd2-more { display:block; padding:8px 12px; font-size:.82rem; font-weight:600; text-decoration:none; }
@media (max-width:1199.98px) { .rd2-grid { grid-template-columns:repeat(2, minmax(0,1fr)); } }
@media (max-width:991.98px) {
    .rd2 { grid-template-columns:minmax(0,1fr); }
    .rd2-side { position:static; grid-template-columns:repeat(4, minmax(0,1fr)); background:none; padding:0; }
    .rd2-act { flex-direction:column; text-align:center; gap:4px; padding:8px 4px; border:1px solid #dbe6fb; }
}
@media (max-width:575.98px) { .rd2-cv { font-size:1.15rem; } .rd2-an { font-size:.76rem; } .rd2-ab { font-size:.68rem; } }
</style>
