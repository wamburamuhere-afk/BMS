<?php
/**
 * core/restaurant_notify.php — restaurant notifications (the bell + email).
 *
 * Twelve alerts, all through the ONE notification engine (core/notify.php →
 * dispatchEvent): RBAC on restaurant_pos ∩ the shop's warehouse scope − mutes,
 * then the rules set on Settings → Notification rules (notification_rules.php).
 * The migration 2026_11_03_restaurant_notifications seeds every event with the
 * rule "All admins — in-app + email"; other staff are added per event there.
 *
 *   Instant   restaurant.online_order_new      a customer sent an order (online menu / table QR)
 *             restaurant.kitchen_ready         the kitchen marked an order ready (owner = the waiter)
 *             restaurant.reservation_new       a table was booked
 *   Frequent  restaurant.online_order_waiting  not accepted after RN_ORDER_WAIT_MIN minutes
 *   (≈2 min)  restaurant.kitchen_waiting       a kitchen order waiting over RN_KITCHEN_WAIT_MIN
 *             restaurant.reservation_upcoming  (existing event) — now also ~1 hour before
 *             restaurant.table_open_long       a table's bill open over RN_TABLE_OPEN_HOURS
 *   Daily     restaurant.stock_low             a drink / ingredient low or out of stock
 *             restaurant.supplier_payment_due  a supplier bill from Purchases due tomorrow / today / overdue
 *             pos_credit.due_soon / .overdue   (existing, customer credit) — unchanged
 *             restaurant.daily_summary         yesterday's sales, orders and profit (from the ledger)
 *             restaurant.dishes_no_cost        dishes sold with no cost (weekly)
 *
 * Every notification carries an action_url to the exact record (that order,
 * ticket, table, booking, item or bill); notification_open?id=N marks it read
 * and goes there. Each fires once per record (dedupe), never on every page load.
 * Everything here is fail-silent: a notification problem never breaks a sale.
 */

require_once __DIR__ . '/restaurant_notify_events.php';   // rnEvents() — dependency-free, migrations read it
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/restaurant_scope.php';
require_once __DIR__ . '/pos_nav.php';

const RN_ORDER_WAIT_MIN    = 5;
const RN_KITCHEN_WAIT_MIN  = 20;
const RN_RESERVATION_AHEAD = 60;   // minutes
const RN_TABLE_OPEN_HOURS  = 3;
const RN_UNCOLLECTED_HOURS = 3;    // accepted pickup order not paid at the till
const RN_FREQUENT_EVERY    = 120;  // seconds between frequent checks

if (!function_exists('rnModuleOn')) {
    /** Restaurant on for this tenant — WITHOUT a logged-in user (background jobs, the public menu). */
    function rnModuleOn(PDO $pdo): bool
    {
        static $on = null;
        if ($on !== null) return $on;
        try {
            $on = (!function_exists('tenantFeatureEnabled') || tenantFeatureEnabled('restaurant_pos'))
                && posSimpleModeEnabled() && restaurantSchemaReady($pdo) && restaurantMenuSchemaReady($pdo)
                && (bool)$pdo->query("SHOW TABLES LIKE 'notification_events'")->fetch();
        } catch (Throwable $e) {
            $on = false;
        }
        return $on;
    }
}

if (!function_exists('rnShopIds')) {
    /** @return int[] every active Restaurant / Hybrid shop (company-wide; recipients are narrowed per shop by the engine) */
    function rnShopIds(PDO $pdo): array
    {
        try {
            return array_map('intval', $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' AND pos_mode IN ('restaurant','hybrid')")->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('rnMoney')) {
    function rnMoney(float $v): string
    {
        $sym = function_exists('bmsCurrencySymbol') ? trim(bmsCurrencySymbol()) : 'TSh';
        return $sym . ' ' . number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2);
    }
}

if (!function_exists('rnDispatch')) {
    /** dispatchEvent with the restaurant defaults; never throws. */
    function rnDispatch(PDO $pdo, string $key, array $ctx): array
    {
        try {
            $ctx['category'] = $ctx['category'] ?? 'Restaurant';
            return dispatchEvent($pdo, $key, $ctx);
        } catch (Throwable $e) {
            error_log("rnDispatch $key: " . $e->getMessage());
            return ['dispatched' => false, 'created' => 0, 'emailed' => 0];
        }
    }
}

// ─── Instant ────────────────────────────────────────────────────────────────

if (!function_exists('rnNotifyOnlineOrder')) {
    function rnNotifyOnlineOrder(PDO $pdo, int $orderId): void
    {
        if ($orderId <= 0 || !rnModuleOn($pdo)) return;
        try {
            $st = $pdo->prepare("SELECT o.order_id, o.order_code, o.order_type, o.subtotal, o.warehouse_id, o.customer_name, t.table_number
                                   FROM restaurant_online_orders o LEFT JOIN restaurant_tables t ON t.table_id = o.table_id WHERE o.order_id = ?");
            $st->execute([$orderId]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o) return;
            $where = $o['table_number'] !== null ? 'Table ' . $o['table_number'] : 'Pickup' . ($o['customer_name'] ? ' · ' . $o['customer_name'] : '');
            rnDispatch($pdo, 'restaurant.online_order_new', [
                'entity_type'   => 'online_order', 'entity_id' => (int)$o['order_id'],
                'warehouse_id'  => (int)$o['warehouse_id'],
                'title'         => 'New online order ' . $o['order_code'],
                'message'       => "{$o['order_code']} · $where · " . rnMoney((float)$o['subtotal']) . '. Accept or reject it on Online orders.',
                'action_url'    => 'online_orders?order=' . (int)$o['order_id'],
                'severity'      => 'high',
                'dedupe_suffix' => 'once',
            ]);
        } catch (Throwable $e) {
            error_log('rnNotifyOnlineOrder: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rnNotifyKitchenReady')) {
    /** The kitchen marked a ticket ready → the waiter who took the order (record owner) and the rules' audience. */
    function rnNotifyKitchenReady(PDO $pdo, int $ticketId): void
    {
        if ($ticketId <= 0 || !rnModuleOn($pdo)) return;
        try {
            $st = $pdo->prepare("SELECT kt.ticket_id, kt.warehouse_id, kt.table_id, kt.order_label, rt.table_number,
                                        COALESCE(h.user_id, s.user_id) AS waiter_id,
                                        (SELECT GROUP_CONCAT(CONCAT(CAST(kti.quantity + 0 AS CHAR), ' × ', p.product_name) SEPARATOR ', ')
                                           FROM kitchen_ticket_items kti JOIN products p ON p.product_id = kti.product_id WHERE kti.ticket_id = kt.ticket_id) AS items
                                   FROM kitchen_tickets kt
                              LEFT JOIN restaurant_tables rt ON rt.table_id = kt.table_id
                              LEFT JOIN pos_held_sales h ON h.hold_id = kt.hold_id
                              LEFT JOIN pos_sales s ON s.sale_id = kt.sale_id
                                  WHERE kt.ticket_id = ?");
            $st->execute([$ticketId]);
            $k = $st->fetch(PDO::FETCH_ASSOC);
            if (!$k) return;
            $label = $k['table_number'] !== null ? 'Table ' . $k['table_number'] : ($k['order_label'] ?: 'Order #' . $k['ticket_id']);
            $items = mb_substr((string)($k['items'] ?? ''), 0, 160);
            // each reader gets it in their own language ("Meza T1 iko tayari…") — core/notify.php notifText
            $L = $k['table_number'] !== null ? ['%1$s %2$s', [['Table'], (string)$k['table_number']]] : ['%s', [$label]];
            rnDispatch($pdo, 'restaurant.kitchen_ready', [
                'entity_type'    => 'kitchen_ticket', 'entity_id' => (int)$k['ticket_id'],
                'warehouse_id'   => (int)$k['warehouse_id'],
                'owner_user_ids' => $k['waiter_id'] ? [(int)$k['waiter_id']] : [],
                'title'          => "Ready to serve: $label",
                'message'        => "$label is ready" . ($items !== '' ? ": $items" : '') . '.',
                'i18n'           => ['title' => ['Ready to serve: %s', [$L]],
                                     'message' => $items !== '' ? ['%1$s is ready: %2$s.', [$L, $items]] : ['%s is ready.', [$L]]],
                'action_url'     => $k['table_id'] ? 'tables-board?table=' . (int)$k['table_id'] : 'kitchen-dashboard?ticket=' . (int)$k['ticket_id'],
                'severity'       => 'medium',
                'dedupe_suffix'  => 'once',
            ]);
        } catch (Throwable $e) {
            error_log('rnNotifyKitchenReady: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rnNotifyReservationNew')) {
    function rnNotifyReservationNew(PDO $pdo, int $reservationId): void
    {
        if ($reservationId <= 0 || !rnModuleOn($pdo)) return;
        try {
            $st = $pdo->prepare("SELECT r.id, r.warehouse_id, r.customer_name, r.party_size, r.reservation_time, t.table_number
                                   FROM restaurant_reservations r LEFT JOIN restaurant_tables t ON t.table_id = r.table_id WHERE r.id = ?");
            $st->execute([$reservationId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return;
            $when = date('d M H:i', strtotime((string)$r['reservation_time']));
            rnDispatch($pdo, 'restaurant.reservation_new', [
                'entity_type'   => 'reservation', 'entity_id' => (int)$r['id'],
                'warehouse_id'  => (int)$r['warehouse_id'],
                'title'         => 'New reservation: ' . $r['customer_name'],
                'message'       => "{$r['customer_name']} · {$r['party_size']} people · $when" . ($r['table_number'] !== null ? ' · Table ' . $r['table_number'] : '') . '.',
                'action_url'    => 'reservations?date=' . date('Y-m-d', strtotime((string)$r['reservation_time'])) . '&id=' . (int)$r['id'],
                'severity'      => 'medium',
                'dedupe_suffix' => 'once',
            ]);
        } catch (Throwable $e) {
            error_log('rnNotifyReservationNew: ' . $e->getMessage());
        }
    }
}

// ─── Frequent (every ~2 minutes while anyone uses the system, or server cron) ─

if (!function_exists('rnRunFrequentChecks')) {
    /** @return array<string,int> notifications created/queued per check */
    function rnRunFrequentChecks(PDO $pdo): array
    {
        $sum = ['online_order_waiting' => 0, 'kitchen_waiting' => 0, 'reservation_upcoming' => 0, 'table_open_long' => 0];
        if (!rnModuleOn($pdo)) return $sum;
        $count = function (array $r) { return !empty($r['dispatched']) ? (int)$r['created'] + (int)$r['emailed'] : 0; };

        // Online orders nobody accepted.
        try {
            if ($pdo->query("SHOW TABLES LIKE 'restaurant_online_orders'")->fetch()) {
                $rows = $pdo->query("SELECT o.order_id, o.order_code, o.warehouse_id, o.subtotal, TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS mins, t.table_number
                                       FROM restaurant_online_orders o LEFT JOIN restaurant_tables t ON t.table_id = o.table_id
                                      WHERE o.status = 'pending' AND o.order_type IN ('table','pickup')
                                        AND o.created_at <= NOW() - INTERVAL " . RN_ORDER_WAIT_MIN . " MINUTE
                                        AND o.created_at >= NOW() - INTERVAL 12 HOUR LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $o) {
                    $sum['online_order_waiting'] += $count(rnDispatch($pdo, 'restaurant.online_order_waiting', [
                        'entity_type' => 'online_order', 'entity_id' => (int)$o['order_id'], 'warehouse_id' => (int)$o['warehouse_id'],
                        'title'       => "Online order {$o['order_code']} still waiting",
                        'message'     => "{$o['order_code']}" . ($o['table_number'] !== null ? ' · Table ' . $o['table_number'] : ' · Pickup') . " has waited {$o['mins']} minutes for staff to accept it.",
                        'action_url'  => 'online_orders?order=' . (int)$o['order_id'],
                        'severity'    => 'high', 'dedupe_suffix' => 'once',
                    ]));
                }
            }
        } catch (Throwable $e) { error_log('rn online_order_waiting: ' . $e->getMessage()); }

        // Pickup orders accepted but not paid / collected (closes itself on its sale at the till).
        $sum['online_order_uncollected'] = 0;
        try {
            if ($pdo->query("SHOW COLUMNS FROM restaurant_online_orders LIKE 'sale_id'")->fetch()) {
                $rows = $pdo->query("SELECT o.order_id, o.order_code, o.warehouse_id, o.customer_name, TIMESTAMPDIFF(HOUR, o.accepted_at, NOW()) AS hrs
                                       FROM restaurant_online_orders o
                                      WHERE o.order_type = 'pickup' AND o.status IN ('accepted','ready')
                                        AND o.accepted_at <= NOW() - INTERVAL " . RN_UNCOLLECTED_HOURS . " HOUR LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $o) {
                    $sum['online_order_uncollected'] += $count(rnDispatch($pdo, 'restaurant.online_order_uncollected', [
                        'entity_type' => 'online_order', 'entity_id' => (int)$o['order_id'], 'warehouse_id' => (int)$o['warehouse_id'],
                        'title'       => "Online order {$o['order_code']} not collected",
                        'message'     => "{$o['order_code']} · Pickup" . ($o['customer_name'] ? ' · ' . $o['customer_name'] : '') . " was accepted {$o['hrs']} hours ago and is not paid yet. "
                                       . 'Complete it at the till, or cancel it as not collected.',
                        'action_url'  => 'online_orders?order=' . (int)$o['order_id'],
                        'severity'    => 'medium', 'dedupe_suffix' => 'once',
                    ]));
                }
            }
        } catch (Throwable $e) { error_log('rn online_order_uncollected: ' . $e->getMessage()); }

        // Kitchen orders waiting long.
        try {
            $rows = $pdo->query("SELECT kt.ticket_id, kt.warehouse_id, kt.order_label, rt.table_number, TIMESTAMPDIFF(MINUTE, kt.created_at, NOW()) AS mins
                                   FROM kitchen_tickets kt LEFT JOIN restaurant_tables rt ON rt.table_id = kt.table_id
                                  WHERE kt.status IN ('queued','preparing') AND kt.created_at <= NOW() - INTERVAL " . RN_KITCHEN_WAIT_MIN . " MINUTE
                                    AND kt.created_at >= NOW() - INTERVAL 12 HOUR LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $k) {
                $label = $k['table_number'] !== null ? 'Table ' . $k['table_number'] : ($k['order_label'] ?: 'Order #' . $k['ticket_id']);
                $sum['kitchen_waiting'] += $count(rnDispatch($pdo, 'restaurant.kitchen_waiting', [
                    'entity_type' => 'kitchen_ticket', 'entity_id' => (int)$k['ticket_id'], 'warehouse_id' => (int)$k['warehouse_id'],
                    'title'       => "Kitchen: $label waiting {$k['mins']} min",
                    'message'     => "The kitchen order for $label has waited {$k['mins']} minutes.",
                    'action_url'  => 'kitchen-dashboard?ticket=' . (int)$k['ticket_id'],
                    'severity'    => 'high', 'dedupe_suffix' => 'once',
                ]));
            }
        } catch (Throwable $e) { error_log('rn kitchen_waiting: ' . $e->getMessage()); }

        // Reservations starting within the hour (the existing daily reminder stays as it is).
        try {
            $rows = $pdo->query("SELECT r.id, r.warehouse_id, r.customer_name, r.party_size, r.reservation_time, t.table_number
                                   FROM restaurant_reservations r LEFT JOIN restaurant_tables t ON t.table_id = r.table_id
                                  WHERE r.status = 'booked' AND r.reservation_time BETWEEN NOW() AND NOW() + INTERVAL " . RN_RESERVATION_AHEAD . " MINUTE
                                  LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $sum['reservation_upcoming'] += $count(rnDispatch($pdo, 'restaurant.reservation_upcoming', [
                    'entity_type' => 'reservation', 'entity_id' => (int)$r['id'], 'warehouse_id' => (int)$r['warehouse_id'],
                    'title'       => 'Reservation at ' . date('H:i', strtotime((string)$r['reservation_time'])) . ': ' . $r['customer_name'],
                    'message'     => "{$r['customer_name']} · {$r['party_size']} people · " . date('H:i', strtotime((string)$r['reservation_time']))
                                   . ($r['table_number'] !== null ? ' · Table ' . $r['table_number'] : '') . ' — within the hour.',
                    'action_url'  => 'reservations?date=' . date('Y-m-d', strtotime((string)$r['reservation_time'])) . '&id=' . (int)$r['id'],
                    'severity'    => 'medium', 'dedupe_suffix' => 'hour',
                ]));
            }
        } catch (Throwable $e) { error_log('rn reservation_upcoming: ' . $e->getMessage()); }

        // Table bills open for a long time: since the first save after the table's last paid bill.
        try {
            $rows = $pdo->query("
                SELECT h.table_id, h.warehouse_id, t.table_number,
                       (SELECT MIN(h2.held_at) FROM pos_held_sales h2
                         WHERE h2.table_id = h.table_id
                           AND h2.held_at > COALESCE((SELECT MAX(s.sale_date) FROM pos_sales s WHERE s.table_id = h.table_id), '1970-01-01')) AS open_since,
                       h.total_amount
                  FROM pos_held_sales h JOIN restaurant_tables t ON t.table_id = h.table_id
                 WHERE h.status = 'held' AND h.table_id IS NOT NULL
              GROUP BY h.table_id, h.warehouse_id, t.table_number, h.total_amount
                 LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (!$r['open_since'] || strtotime((string)$r['open_since']) > time() - RN_TABLE_OPEN_HOURS * 3600) continue;
                $hours = (int)floor((time() - strtotime((string)$r['open_since'])) / 3600);
                $sum['table_open_long'] += $count(rnDispatch($pdo, 'restaurant.table_open_long', [
                    'entity_type' => 'table', 'entity_id' => (int)$r['table_id'], 'warehouse_id' => (int)$r['warehouse_id'],
                    'title'       => "Table {$r['table_number']} open $hours h",
                    'message'     => "Table {$r['table_number']}'s bill (" . rnMoney((float)$r['total_amount']) . ") has been open for $hours hours. Has the customer paid?",
                    'action_url'  => 'tables-board?table=' . (int)$r['table_id'],
                    'severity'    => 'medium',
                    'dedupe_suffix' => date('YmdHi', strtotime((string)$r['open_since'])),   // once per open bill
                ]));
            }
        } catch (Throwable $e) { error_log('rn table_open_long: ' . $e->getMessage()); }

        return $sum;
    }
}

// ─── Daily ──────────────────────────────────────────────────────────────────

if (!function_exists('rnRunDailyChecks')) {
    function rnRunDailyChecks(PDO $pdo, ?string $today = null): array
    {
        $sum = ['stock_low' => 0, 'supplier_payment_due' => 0, 'daily_summary' => 0, 'dishes_no_cost' => 0];
        if (!rnModuleOn($pdo)) return $sum;
        $ids = rnShopIds($pdo);
        if (!$ids) return $sum;
        $in = implode(',', $ids);
        $today = $today ?? date('Y-m-d');
        $count = function (array $r) { return !empty($r['dispatched']) ? (int)$r['created'] + (int)$r['emailed'] : 0; };

        // Drinks / ingredients low or out — the restaurant dashboard's own rule, per shop and item.
        try {
            $rows = $pdo->query("SELECT ps.warehouse_id, p.product_id, p.product_name, p.unit, COALESCE(SUM(ps.stock_quantity), 0) AS stock,
                                        GREATEST(COALESCE(p.min_stock_level, 0), 5) AS lim
                                   FROM products p JOIN product_stocks ps ON ps.product_id = p.product_id AND ps.warehouse_id IN ($in)
                                  WHERE p.status = 'active' AND p.is_service = 0
                                    AND (p.is_menu_item = 1
                                         OR p.product_id IN (SELECT ac.component_product_id FROM product_assembly_components ac
                                                               JOIN products d ON d.product_id = ac.parent_product_id
                                                              WHERE d.is_menu_item = 1 AND d.is_service = 1 AND d.is_combo = 1))
                               GROUP BY ps.warehouse_id, p.product_id, p.product_name, p.unit, p.min_stock_level
                                 HAVING stock <= lim
                               ORDER BY stock ASC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $p) {
                $out = (float)$p['stock'] <= 0;
                $qty = rtrim(rtrim(number_format((float)$p['stock'], 3, '.', ''), '0'), '.');
                $sum['stock_low'] += $count(rnDispatch($pdo, 'restaurant.stock_low', [
                    'entity_type' => 'product', 'entity_id' => (int)$p['product_id'], 'warehouse_id' => (int)$p['warehouse_id'],
                    'title'       => ($out ? 'Out of stock: ' : 'Running low: ') . $p['product_name'],
                    'message'     => $out ? "{$p['product_name']} is out of stock. Buy more in Purchases."
                                          : "{$p['product_name']}: only $qty " . ($p['unit'] ?: 'left') . ' left. Buy more in Purchases.',
                    'action_url'  => 'purchases?item=' . (int)$p['product_id'] . '&shop=' . (int)$p['warehouse_id'],
                    'severity'    => $out ? 'high' : 'medium',
                    'dedupe_suffix' => $today . '|w' . (int)$p['warehouse_id'],
                ]));
            }
        } catch (Throwable $e) { error_log('rn stock_low: ' . $e->getMessage()); }

        // Supplier bills from Purchases: due tomorrow, today, or overdue (once a day each).
        try {
            require_once __DIR__ . '/restaurant_supplier_credit.php';
            foreach (restaurantSupplierDebts($pdo, $ids) as $b) {
                if (empty($b['due_date']) || (float)$b['owed'] <= 0) continue;
                $days = (int)floor((strtotime((string)$b['due_date']) - strtotime($today)) / 86400);
                if ($days > 1) continue;
                $when = $days < 0 ? 'was due ' . date('d M', strtotime((string)$b['due_date'])) . ' (overdue)' : ($days === 0 ? 'is due today' : 'is due tomorrow');
                $wh = (int)$pdo->query("SELECT warehouse_id FROM supplier_invoices WHERE id = " . (int)$b['id'])->fetchColumn();
                $sum['supplier_payment_due'] += $count(rnDispatch($pdo, 'restaurant.supplier_payment_due', [
                    'entity_type' => 'supplier_invoice', 'entity_id' => (int)$b['id'], 'warehouse_id' => $wh,
                    'title'       => 'Pay ' . $b['supplier_name'] . ': ' . rnMoney((float)$b['owed']),
                    'message'     => rnMoney((float)$b['owed']) . " to {$b['supplier_name']}" . ($b['what'] !== '' ? " for {$b['what']}" : '') . " $when.",
                    'action_url'  => 'purchases?bill=' . (int)$b['id'],
                    'severity'    => $days < 0 ? 'high' : 'medium',
                    'dedupe_suffix' => $today,
                ]));
            }
        } catch (Throwable $e) { error_log('rn supplier_payment_due: ' . $e->getMessage()); }

        // Yesterday's summary — money from the one ledger (same function as the dashboard), orders from the tills.
        try {
            require_once __DIR__ . '/restaurant_insights.php';
            $y = date('Y-m-d', strtotime('-1 day', strtotime($today)));
            $r = ['from' => $y, 'to' => $y, 'prev_from' => date('Y-m-d', strtotime('-8 day', strtotime($today))), 'prev_to' => date('Y-m-d', strtotime('-8 day', strtotime($today)))];
            $story = rdPeriodStory($pdo, ' AND je.warehouse_id IN (' . $in . ')', $ids, $r);
            if ($story['orders'] > 0 || $story['cur']['sales'] > 0) {
                $sum['daily_summary'] += $count(rnDispatch($pdo, 'restaurant.daily_summary', [
                    'entity_type' => 'restaurant_day', 'entity_id' => (int)str_replace('-', '', $y),
                    'title'       => 'Yesterday: sales ' . rnMoney($story['cur']['sales']),
                    'message'     => date('D d M', strtotime($y)) . ': sales ' . rnMoney($story['cur']['sales']) . ' · ' . $story['orders'] . ' orders · profit '
                                   . rnMoney($story['cur']['profit']) . ($story['orders'] > 0 ? ' · average bill ' . rnMoney($story['avg_bill']) : '') . '.',
                    'action_url'  => 'dashboard',
                    'severity'    => 'low',
                    'dedupe_suffix' => $y,
                ]));
            }
        } catch (Throwable $e) { error_log('rn daily_summary: ' . $e->getMessage()); }

        // Dishes sold this month with no cost (no ingredients, no other costs) — once a week. The old per-plate
        // guess (purchase_price) no longer counts as a cost (restaurant costing plan): same rule as the Menu.
        try {
            $rows = $pdo->query("SELECT DISTINCT p.product_id, p.product_name
                                   FROM pos_sale_items psi JOIN pos_sales ps ON ps.sale_id = psi.sale_id JOIN products p ON p.product_id = psi.product_id
                                  WHERE ps.sale_status IN ('completed','partially_refunded') AND ps.is_return_sale = 0
                                    AND ps.sale_date >= " . $pdo->quote(date('Y-m-01', strtotime($today))) . " AND ps.warehouse_id IN ($in)
                                    AND p.is_menu_item = 1 AND p.is_service = 1
                                    AND NOT " . restaurantDishHasCostSql($pdo, 'p') . "
                                  LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $names = implode(', ', array_slice(array_column($rows, 'product_name'), 0, 4)) . (count($rows) > 4 ? '…' : '');
                $sum['dishes_no_cost'] += $count(rnDispatch($pdo, 'restaurant.dishes_no_cost', [
                    'entity_type' => 'menu', 'entity_id' => 0,
                    'title'       => count($rows) . ' dish(es) have no cost',
                    'message'     => "Sold this month with no cost, so profit may look too high: $names. Add a recipe on the Menu.",
                    'action_url'  => 'menu?nocost=1',
                    'severity'    => 'low',
                    'dedupe_suffix' => date('o-\WW', strtotime($today)),
                ]));
            }
        } catch (Throwable $e) { error_log('rn dishes_no_cost: ' . $e->getMessage()); }

        return $sum;
    }
}

if (!function_exists('rnSafeActionPath')) {
    /**
     * The in-app path a notification may send someone to: a relative path inside this
     * system only (no scheme, no //host, no backslash, no control characters). '' = not safe.
     */
    function rnSafeActionPath($url): string
    {
        $u = trim((string)$url);
        if ($u === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $u)) return '';
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $u) || str_starts_with($u, '//')) return '';
        $u = ltrim($u, '/');
        if (!preg_match('#^[A-Za-z0-9_\-./]+(\?[A-Za-z0-9_\-.=&%+]*)?(\#[A-Za-z0-9_\-]*)?$#', $u) || str_contains($u, '..')) return '';
        return $u;
    }
}
