<?php
/**
 * core/restaurant_notifications.php — what a restaurant needs to know now (restaurant dashboard, 2026-11-02).
 *
 * ONE list, used by the header bell (restaurant-only company) and the dashboard's Notifications box, so the two
 * always agree. Each item says what happened and opens the page that fixes it.
 *
 *   urgent  bills open over 90 minutes · kitchen orders waiting over 20 minutes (when the kitchen screen is on)
 *   today   reservations today
 *   stock   drinks / ingredients running low
 *   money   customers past their credit days · expenses waiting for approval · supplier bills overdue or due soon ·
 *           items sold with no cost   (money items only for users who see profit — the retail Performance-card rule)
 *   new     the user's own unread notifications from the notification engine (core/notify.php)
 *
 * Counts read the operational tables; nothing here is a money figure from the books (those are on the cards).
 */
require_once __DIR__ . '/restaurant_scope.php';
require_once __DIR__ . '/restaurant_insights.php';
require_once __DIR__ . '/restaurant_supplier_credit.php';

if (!function_exists('restaurantLowStockCount')) {
    /** How many counted items (drinks, ingredients) are at or below their minimum (or 5). */
    function restaurantLowStockCount(PDO $pdo, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return 0;
        $in = implode(',', $ids);
        try {
            return (int)$pdo->query("SELECT COUNT(*) FROM (
                SELECT p.product_id FROM products p LEFT JOIN product_stocks ps ON ps.product_id = p.product_id AND ps.warehouse_id IN ($in)
                 WHERE p.status = 'active' AND p.is_service = 0
                   AND (p.is_menu_item = 1 OR p.product_id IN (SELECT ac.component_product_id FROM product_assembly_components ac
                                                               JOIN products d ON d.product_id = ac.parent_product_id WHERE d.is_menu_item = 1))
              GROUP BY p.product_id, p.min_stock_level
                HAVING COALESCE(SUM(ps.stock_quantity), 0) <= GREATEST(COALESCE(p.min_stock_level, 0), 5)) x")->fetchColumn();
        } catch (Throwable $e) { error_log('restaurantLowStockCount: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('restaurantReservationsToday')) {
    /** @return array{n:int,next:?string} booked reservations for today and the next time still to come */
    function restaurantReservationsToday(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return ['n' => 0, 'next' => null];
        $in = implode(',', $ids);
        try {
            $r = $pdo->query("SELECT COUNT(*) AS n, MIN(CASE WHEN reservation_time >= NOW() THEN reservation_time END) AS nx
                                FROM restaurant_reservations WHERE status = 'booked' AND DATE(reservation_time) = CURDATE() AND warehouse_id IN ($in)")
                     ->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['n' => (int)($r['n'] ?? 0), 'next' => !empty($r['nx']) ? date('H:i', strtotime($r['nx'])) : null];
        } catch (Throwable $e) { return ['n' => 0, 'next' => null]; }
    }
}

if (!function_exists('restaurantKitchenNow')) {
    /** @return array{on:bool,in_kitchen:int,late:int,longest:int} orders in the kitchen now and those waiting 20+ minutes */
    function restaurantKitchenNow(PDO $pdo, array $ids): array
    {
        $out = ['on' => (string)get_setting('restaurant_kitchen_mode', 'none') !== 'none', 'in_kitchen' => 0, 'late' => 0, 'longest' => 0];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !$out['on']) return $out;
        $in = implode(',', $ids);
        try {
            $r = $pdo->query("SELECT COUNT(*) AS n, SUM(TIMESTAMPDIFF(MINUTE, created_at, NOW()) >= 20) AS late,
                                     COALESCE(MAX(TIMESTAMPDIFF(MINUTE, created_at, NOW())), 0) AS m
                                FROM kitchen_tickets WHERE status IN ('queued','preparing') AND warehouse_id IN ($in)")->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['in_kitchen'] = (int)($r['n'] ?? 0); $out['late'] = (int)($r['late'] ?? 0); $out['longest'] = (int)($r['m'] ?? 0);
        } catch (Throwable $e) { /* kitchen tables are optional */ }
        return $out;
    }
}

if (!function_exists('restaurantNotifications')) {
    /**
     * @param int[] $ids     the user's restaurant shops (rdRestaurantIds)
     * @param bool  $money   the user sees money (profit) figures
     * @param ?int  $noCost  items sold this month with no cost (pass it when already known; null = not shown)
     * @return array<int,array{key:string,level:string,icon:string,text:string,url:string,when:string,id:?int}>
     */
    function restaurantNotifications(PDO $pdo, array $ids, bool $money, ?int $noCost = null): array
    {
        static $cache = [];
        $ck = implode(',', $ids) . '|' . (int)$money . '|' . (string)$noCost . '|' . (int)($_SESSION['user_id'] ?? 0);
        if (isset($cache[$ck])) return $cache[$ck];
        $list = [];
        $in = rdIn($ids);
        $now = t('Now');

        if ($ids) {
            try {
                $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(MAX(TIMESTAMPDIFF(MINUTE, h.held_at, NOW())),0) AS m FROM pos_held_sales h
                    JOIN restaurant_tables t ON t.table_id = h.table_id
                    WHERE h.status = 'held' AND t.warehouse_id IN ($in) AND TIMESTAMPDIFF(MINUTE, h.held_at, NOW()) >= 90")->fetch(PDO::FETCH_ASSOC) ?: [];
                if ((int)($r['n'] ?? 0) > 0) $list[] = ['key' => 'old_bills', 'level' => 'urgent', 'icon' => 'bi-receipt',
                    'text' => sprintf(t('%1$d bill(s) have been open for over 90 minutes (the longest %2$d min).'), (int)$r['n'], (int)$r['m']),
                    'url' => getUrl('restaurant/tables-board'), 'when' => $now, 'id' => null];
            } catch (Throwable $e) { error_log('restaurantNotifications bills: ' . $e->getMessage()); }

            $k = restaurantKitchenNow($pdo, $ids);
            if ($k['late'] > 0) $list[] = ['key' => 'kitchen_wait', 'level' => 'urgent', 'icon' => 'bi-fire',
                'text' => sprintf(t('%1$d kitchen order(s) have waited over 20 minutes (the longest %2$d min).'), $k['late'], $k['longest']),
                'url' => getUrl('restaurant/kitchen-dashboard'), 'when' => $now, 'id' => null];

            $rv = restaurantReservationsToday($pdo, $ids);
            if ($rv['n'] > 0) $list[] = ['key' => 'reservations', 'level' => 'today', 'icon' => 'bi-calendar-check',
                'text' => $rv['next'] ? sprintf(t('%1$d reservation(s) today. The next one is at %2$s.'), $rv['n'], $rv['next']) : sprintf(t('%d reservation(s) today.'), $rv['n']),
                'url' => getUrl('restaurant/reservations'), 'when' => t('Today'), 'id' => null];

            $low = restaurantLowStockCount($pdo, $ids);
            if ($low > 0) $list[] = ['key' => 'low_stock', 'level' => 'stock', 'icon' => 'bi-cup-straw',
                'text' => sprintf(t('%d drink(s) or ingredient(s) are running low.'), $low),
                'url' => getUrl('restaurant/purchases'), 'when' => t('Today'), 'id' => null];
        }

        if ($money) {
            if (canView('pos')) {
                try {
                    require_once __DIR__ . '/pos_credit_aging.php';
                    $open = posCreditOpenSales($pdo, null, scopeFilterSqlNullable('warehouse', 's'));
                    $late = array_filter($open, fn($r) => !empty($r['is_overdue']));
                    if ($late) {
                        $amt = array_sum(array_map(fn($r) => (float)($r['balance_due'] ?? 0), $late));
                        $list[] = ['key' => 'credit_overdue', 'level' => 'money', 'icon' => 'bi-cash-coin',
                            'text' => $amt > 0 ? sprintf(t('%1$d customer credit sale(s) are past their credit days (%2$s).'), count($late), number_format($amt, 0))
                                               : sprintf(t('%d customer credit sale(s) are past their credit days.'), count($late)),
                            'url' => getUrl('pos/credit-customers'), 'when' => t('Today'), 'id' => null];
                    }
                } catch (Throwable $e) { error_log('restaurantNotifications credit: ' . $e->getMessage()); }
            }
            if (canView('expenses')) {
                try {
                    $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS a FROM expenses e WHERE e.status IN ('pending','reviewed')"
                        . scopeFilterSqlNullable('warehouse', 'e') . scopeFilterSqlNullable('project', 'e'))->fetch(PDO::FETCH_ASSOC) ?: [];
                    if ((int)($r['n'] ?? 0) > 0) $list[] = ['key' => 'expenses_pending', 'level' => 'money', 'icon' => 'bi-receipt',
                        'text' => sprintf(t('%1$d expense(s) worth %2$s wait for approval.'), (int)$r['n'], number_format((float)$r['a'], 0)),
                        'url' => getUrl('expenses'), 'when' => t('Today'), 'id' => null];
                } catch (Throwable $e) { error_log('restaurantNotifications expenses: ' . $e->getMessage()); }
            }
            $bills = restaurantSupplierDebts($pdo, $ids);
            $over = array_filter($bills, fn($b) => (int)$b['overdue'] === 1);
            $soon = array_filter($bills, fn($b) => !(int)$b['overdue'] && !empty($b['due_date']) && $b['due_date'] <= date('Y-m-d', strtotime('+3 days')));
            if ($over) $list[] = ['key' => 'suppliers_overdue', 'level' => 'money', 'icon' => 'bi-truck',
                'text' => sprintf(t('You are late paying %1$d supplier bill(s) (%2$s).'), count($over), number_format(array_sum(array_column($over, 'owed')), 0)),
                'url' => getUrl('restaurant/purchases') . '#rpOwe', 'when' => t('Today'), 'id' => null];
            if ($soon) $list[] = ['key' => 'suppliers_due', 'level' => 'money', 'icon' => 'bi-truck',
                'text' => sprintf(t('%1$d supplier bill(s) are due in the next 3 days (%2$s).'), count($soon), number_format(array_sum(array_column($soon, 'owed')), 0)),
                'url' => getUrl('restaurant/purchases') . '#rpOwe', 'when' => t('Soon'), 'id' => null];
            if ($noCost !== null && $noCost > 0) $list[] = ['key' => 'no_cost', 'level' => 'money', 'icon' => 'bi-lightbulb',
                'text' => sprintf(t('%d item(s) sold have no cost, so profit may look too high.'), $noCost),
                'url' => getUrl('restaurant/menu'), 'when' => t('This month'), 'id' => null];
        }

        // The user's own unread notifications (the notification engine), newest first.
        try {
            $st = $pdo->prepare("SELECT notification_id, title, message, action_url, created_at FROM notifications
                                  WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
            $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $url = (string)($n['action_url'] ?? '');
                if ($url !== '' && !preg_match('#^(https?:)?//#i', $url)) $url = getUrl(ltrim($url, '/'));
                elseif (preg_match('#^(https?:)?//#i', $url)) $url = '';          // never send a user to another site from the bell
                $list[] = ['key' => 'engine', 'level' => 'new', 'icon' => 'bi-bell',
                    'text' => trim(($n['title'] ?? '') . (($n['message'] ?? '') !== '' ? ' — ' . $n['message'] : '')),
                    'url' => $url !== '' ? $url : (canView('notification_center') ? getUrl('notification_center') : ''),
                    'when' => date('d M H:i', strtotime((string)$n['created_at'])), 'id' => (int)$n['notification_id']];
            }
        } catch (Throwable $e) { /* engine table optional */ }

        return $cache[$ck] = $list;
    }
}
