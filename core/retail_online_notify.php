<?php
/**
 * core/retail_online_notify.php — retail online shop notifications (the bell + email),
 * through the ONE notification engine (core/notify.php → dispatchEvent): RBAC on `pos`
 * ∩ the shop's warehouse scope − mutes, then the rules on Settings → Notification rules.
 * The migration seeds each event with "All admins — in-app + email"; other staff are added
 * per event on notification_rules.php (owner's decision 3).
 *
 *   Instant   retail.online_order_new          a buyer sent an order from the online shop
 *   Frequent  retail.online_order_waiting      not accepted after RSN_WAIT_MIN minutes
 *   (≈2 min)  retail.online_order_uncollected  accepted but not paid/collected after RSN_UNCOLLECTED_HOURS
 *
 * Each fires once per order (dedupe) and opens that order. Fail-silent: never breaks an order.
 */

require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/pos_nav.php';

const RSN_WAIT_MIN          = 5;
const RSN_UNCOLLECTED_HOURS = 3;

if (!function_exists('rsnEvents')) {
    /** key => [title, description, severity] — the events the migration registers (module "Online shop"). */
    function rsnEvents(): array
    {
        return [
            'retail.online_order_new'         => ['New online shop order', 'A buyer sent an order from the online shop link.', 'high'],
            'retail.online_order_waiting'     => ['Online shop order waiting', 'An online shop order has not been accepted yet.', 'high'],
            'retail.online_order_uncollected' => ['Online shop order not collected', 'An accepted online shop order has not been paid or collected yet.', 'medium'],
        ];
    }
}

if (!function_exists('rsnModuleOn')) {
    /** Smart POS + online shop schema + notification engine present — WITHOUT a logged-in user. */
    function rsnModuleOn(PDO $pdo): bool
    {
        static $on = null;
        if ($on !== null) return $on;
        try {
            require_once __DIR__ . '/retail_online.php';
            $on = rsModuleOnForPublic($pdo) && (bool)$pdo->query("SHOW TABLES LIKE 'notification_events'")->fetch();
        } catch (Throwable $e) {
            $on = false;
        }
        return $on;
    }
}

if (!function_exists('rsnMoney')) {
    function rsnMoney(float $v): string
    {
        $sym = function_exists('bmsCurrencySymbol') ? trim(bmsCurrencySymbol()) : 'TSh';
        return $sym . ' ' . number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2);
    }
}

if (!function_exists('rsnDispatch')) {
    /** dispatchEvent with the online-shop defaults; never throws. */
    function rsnDispatch(PDO $pdo, string $key, array $ctx): array
    {
        try {
            $ctx['category'] = $ctx['category'] ?? 'Online shop';
            return dispatchEvent($pdo, $key, $ctx);
        } catch (Throwable $e) {
            error_log("rsnDispatch $key: " . $e->getMessage());
            return ['dispatched' => false, 'created' => 0, 'emailed' => 0];
        }
    }
}

if (!function_exists('rsnOrderLabel')) {
    function rsnOrderLabel(array $o): string
    {
        $how = $o['order_type'] === 'shop_delivery' ? 'Delivery' : 'Pickup';
        return $how . ($o['customer_name'] ? ' · ' . $o['customer_name'] : '') . ($o['customer_phone'] ? ' · ' . $o['customer_phone'] : '');
    }
}

if (!function_exists('rsnNotifyNewOrder')) {
    function rsnNotifyNewOrder(PDO $pdo, int $orderId): void
    {
        if ($orderId <= 0 || !rsnModuleOn($pdo)) return;
        try {
            $st = $pdo->prepare("SELECT o.order_id, o.order_code, o.order_type, o.subtotal, o.warehouse_id, o.customer_name, o.customer_phone, w.warehouse_name
                                   FROM restaurant_online_orders o JOIN warehouses w ON w.warehouse_id = o.warehouse_id
                                  WHERE o.order_id = ? AND o.order_type IN ('shop_pickup','shop_delivery')");
            $st->execute([$orderId]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o) return;
            rsnDispatch($pdo, 'retail.online_order_new', [
                'entity_type'   => 'online_order', 'entity_id' => (int)$o['order_id'],
                'warehouse_id'  => (int)$o['warehouse_id'],
                'title'         => 'New online shop order ' . $o['order_code'],
                'message'       => "{$o['order_code']} · {$o['warehouse_name']} · " . rsnOrderLabel($o) . ' · ' . rsnMoney((float)$o['subtotal'])
                                 . '. Accept or reject it on Online orders.',
                'action_url'    => 'shop_online_orders?order=' . (int)$o['order_id'],
                'severity'      => 'high',
                'dedupe_suffix' => 'once',
            ]);
        } catch (Throwable $e) {
            error_log('rsnNotifyNewOrder: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rsnRunFrequentChecks')) {
    /** Waiting too long + accepted but not collected. @return array<string,int> */
    function rsnRunFrequentChecks(PDO $pdo): array
    {
        $sum = ['online_order_waiting' => 0, 'online_order_uncollected' => 0, 'expired' => 0];
        if (!rsnModuleOn($pdo)) return $sum;
        $count = fn(array $r) => !empty($r['dispatched']) ? (int)$r['created'] + (int)$r['emailed'] : 0;
        try {
            $sum['expired'] = rsExpireWaiting($pdo);
            $rows = $pdo->query("SELECT o.order_id, o.order_code, o.order_type, o.warehouse_id, o.customer_name, o.customer_phone,
                                        TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS mins
                                   FROM restaurant_online_orders o
                                  WHERE o.order_type IN ('shop_pickup','shop_delivery') AND o.status = 'pending'
                                    AND o.created_at <= NOW() - INTERVAL " . RSN_WAIT_MIN . " MINUTE LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $o) {
                $sum['online_order_waiting'] += $count(rsnDispatch($pdo, 'retail.online_order_waiting', [
                    'entity_type' => 'online_order', 'entity_id' => (int)$o['order_id'], 'warehouse_id' => (int)$o['warehouse_id'],
                    'title'       => "Online shop order {$o['order_code']} still waiting",
                    'message'     => "{$o['order_code']} · " . rsnOrderLabel($o) . " has waited {$o['mins']} minutes for staff to accept it.",
                    'action_url'  => 'shop_online_orders?order=' . (int)$o['order_id'],
                    'severity'    => 'high', 'dedupe_suffix' => 'once',
                ]));
            }
            $rows = $pdo->query("SELECT o.order_id, o.order_code, o.order_type, o.warehouse_id, o.customer_name, o.customer_phone,
                                        TIMESTAMPDIFF(HOUR, o.accepted_at, NOW()) AS hrs
                                   FROM restaurant_online_orders o
                                  WHERE o.order_type IN ('shop_pickup','shop_delivery') AND o.status IN ('accepted','ready')
                                    AND o.accepted_at <= NOW() - INTERVAL " . RSN_UNCOLLECTED_HOURS . " HOUR LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $o) {
                $sum['online_order_uncollected'] += $count(rsnDispatch($pdo, 'retail.online_order_uncollected', [
                    'entity_type' => 'online_order', 'entity_id' => (int)$o['order_id'], 'warehouse_id' => (int)$o['warehouse_id'],
                    'title'       => "Online shop order {$o['order_code']} not collected",
                    'message'     => "{$o['order_code']} · " . rsnOrderLabel($o) . " was accepted {$o['hrs']} hours ago and is not paid yet. "
                                   . 'Complete it at the till, or cancel it as not collected.',
                    'action_url'  => 'shop_online_orders?order=' . (int)$o['order_id'],
                    'severity'    => 'medium', 'dedupe_suffix' => 'once',
                ]));
            }
        } catch (Throwable $e) {
            error_log('rsnRunFrequentChecks: ' . $e->getMessage());
        }
        return $sum;
    }
}

if (!function_exists('rsnEnsureSchema')) {
    /** Register the events (module "Online shop", page_key pos) + default rule "All admins — in-app + email". Idempotent. */
    function rsnEnsureSchema(PDO $pdo, callable $log): bool
    {
        if (!$pdo->query("SHOW TABLES LIKE 'notification_events'")->fetch()
            || !$pdo->query("SHOW TABLES LIKE 'notification_rules'")->fetch()) {
            $log('  ~ notification engine tables absent — nothing to do.');
            return false;
        }
        $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
        $insert = $pdo->prepare("INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
                                 VALUES (?, ?, ?, 'Online shop', 'pos', 'view', ?, 1, 1, NOW())");
        $hasRules = $pdo->prepare("SELECT COUNT(*) FROM notification_rules WHERE event_key = ?");
        $addRule  = $pdo->prepare("INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_at, updated_at)
                                   VALUES (?, 'admins', NULL, 1, 1, 'immediate', 1, NOW(), NOW())");
        foreach (rsnEvents() as $key => [$title, $desc, $sev]) {
            $exists->execute([$key]);
            if ($exists->fetchColumn()) { $log("  ~ event $key already present."); }
            else { $insert->execute([$key, $title, $desc, $sev]); $log("  + event $key registered."); }
            $hasRules->execute([$key]);
            if ((int)$hasRules->fetchColumn() > 0) { $log("  ~ $key already has rules — left as the admin set them."); continue; }
            $addRule->execute([$key]);
            $log("  + rule $key: All admins — in-app + email.");
        }
        return true;
    }
}
