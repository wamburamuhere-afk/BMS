<?php
/**
 * core/online_shop_schema.php — schema for the retail online shop (Smart POS) and the
 * stronger online-order record shared with the restaurant (retail_online_shop_plan.md).
 * One body for the tenant migration and its legacy-database twin. Purely additive and
 * idempotent; every switch defaults to OFF, so no shop changes until its owner acts.
 */
require_once __DIR__ . '/restaurant_online_schema.php';

if (!function_exists('onlineShopEnsureSchema')) {
    /**
     * @param callable(string):void $log
     * @return bool true when everything is in place
     */
    function onlineShopEnsureSchema(PDO $pdo, callable $log): bool
    {
        // The order table lives with the restaurant online menu; make sure it exists first.
        restaurantOnlineEnsureSchema($pdo, $log);
        if (!$pdo->query("SHOW TABLES LIKE 'restaurant_online_orders'")->fetch()) {
            $log('  ~ restaurant_online_orders absent (restaurant module schema missing) — nothing to do.');
            return false;
        }

        $hasCol = fn(string $t, string $c): bool => (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch();
        $addCol = function (string $table, string $col, string $ddl) use ($pdo, $log, $hasCol): void {
            if ($hasCol($table, $col)) { $log("  ~ $table.$col already exists."); return; }
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
            $log("  + $table.$col added.");
        };
        $addIdx = function (string $table, string $name, string $ddl) use ($pdo, $log): void {
            if ($pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$name'")->fetch()) { $log("  ~ index $table.$name already exists."); return; }
            $pdo->exec("ALTER TABLE `$table` ADD $ddl");
            $log("  + index $table.$name added.");
        };

        // ── Shop switches (retail online shop) ──
        $addCol('warehouses', 'online_shop_token',     "`online_shop_token` CHAR(32) NULL DEFAULT NULL");
        $addCol('warehouses', 'online_shop_enabled',   "`online_shop_enabled` TINYINT(1) NOT NULL DEFAULT 0");
        $addCol('warehouses', 'online_shop_ordering',  "`online_shop_ordering` TINYINT(1) NOT NULL DEFAULT 0");
        $addCol('warehouses', 'online_shop_pickup',    "`online_shop_pickup` TINYINT(1) NOT NULL DEFAULT 1");
        $addCol('warehouses', 'online_shop_delivery',  "`online_shop_delivery` TINYINT(1) NOT NULL DEFAULT 0");
        $addCol('warehouses', 'online_shop_wholesale', "`online_shop_wholesale` TINYINT(1) NOT NULL DEFAULT 0");
        $addCol('warehouses', 'online_show_payment',   "`online_show_payment` TINYINT(1) NOT NULL DEFAULT 0");
        $addIdx('warehouses', 'uq_wh_online_shop_token', "UNIQUE INDEX `uq_wh_online_shop_token` (`online_shop_token`)");

        // ── Links already posted with the old "Generate Link" keep working: a shop that had a live
        //    catalog link gets the new online shop switched on in VIEW-ONLY mode (ordering stays off),
        //    and shop_catalog.php sends the old link on to it. Only shops never moved before. ──
        if ($hasCol('warehouses', 'public_catalog_token_hash')) {
            $old = $pdo->query("SELECT warehouse_id FROM warehouses WHERE public_catalog_token_hash IS NOT NULL AND online_shop_token IS NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($old as $w) {
                for ($i = 0; $i < 3; $i++) {
                    try {
                        $pdo->prepare("UPDATE warehouses SET online_shop_token = ?, online_shop_enabled = 1 WHERE warehouse_id = ? AND online_shop_token IS NULL")
                            ->execute([bin2hex(random_bytes(16)), (int)$w]);
                        break;
                    } catch (PDOException $e) {
                        if ($e->getCode() !== '23000') throw $e;
                    }
                }
            }
            $log($old ? '  + ' . count($old) . ' shop(s) with an old catalog link moved to the online shop (view only).' : '  ~ no old catalog links to move.');
        }

        // ── Product: hide from the online shop ──
        $addCol('products', 'online_hidden', "`online_hidden` TINYINT(1) NOT NULL DEFAULT 0");

        // ── Order record ──
        $t = $pdo->query("SHOW COLUMNS FROM restaurant_online_orders LIKE 'order_type'")->fetch(PDO::FETCH_ASSOC);
        if ($t && strpos((string)$t['Type'], 'shop_pickup') === false) {
            $pdo->exec("ALTER TABLE restaurant_online_orders MODIFY COLUMN `order_type` ENUM('table','pickup','shop_pickup','shop_delivery') NOT NULL DEFAULT 'table'");
            $log('  + restaurant_online_orders.order_type now allows shop_pickup / shop_delivery.');
        } else {
            $log('  ~ restaurant_online_orders.order_type already allows shop orders.');
        }
        $addCol('restaurant_online_orders', 'price_type',       "`price_type` ENUM('retail','wholesale') NOT NULL DEFAULT 'retail'");
        $addCol('restaurant_online_orders', 'delivery_address', "`delivery_address` VARCHAR(200) NULL DEFAULT NULL");
        $addCol('restaurant_online_orders', 'customer_id',      "`customer_id` INT NULL DEFAULT NULL");
        $addCol('restaurant_online_orders', 'sale_id',          "`sale_id` INT NULL DEFAULT NULL");
        $addCol('restaurant_online_orders', 'cancelled_by',     "`cancelled_by` VARCHAR(10) NULL DEFAULT NULL");
        $addCol('restaurant_online_orders', 'completed_at',     "`completed_at` DATETIME NULL DEFAULT NULL");
        $addIdx('restaurant_online_orders', 'idx_roo_sale', "INDEX `idx_roo_sale` (`sale_id`)");
        $addIdx('restaurant_online_orders', 'idx_roo_type_status', "INDEX `idx_roo_type_status` (`order_type`, `status`, `created_at`)");

        // ── Held order ↔ online order (lets any staff of that shop load it, not only who accepted) ──
        $addCol('pos_held_sales', 'online_order_id', "`online_order_id` INT NULL DEFAULT NULL");
        $addIdx('pos_held_sales', 'idx_phs_online_order', "INDEX `idx_phs_online_order` (`online_order_id`)");

        // ── Alerts: register the online-shop events + default "All admins" rule, and the restaurant's
        //    new "not collected" event (the restaurant body only adds what is missing) ──
        require_once __DIR__ . '/retail_online_notify.php';
        rsnEnsureSchema($pdo, $log);
        if ($pdo->query("SHOW TABLES LIKE 'notification_events'")->fetch() && $pdo->query("SHOW TABLES LIKE 'notification_rules'")->fetch()) {
            $key = 'restaurant.online_order_uncollected';
            $ex = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
            $ex->execute([$key]);
            if (!$ex->fetchColumn()) {
                $pdo->prepare("INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
                               VALUES (?, 'Online order not collected', 'An accepted pickup order has not been paid or collected yet.', 'Restaurant', 'restaurant_pos', 'view', 'medium', 1, 1, NOW())")
                    ->execute([$key]);
                $pdo->prepare("INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_at, updated_at)
                               VALUES (?, 'admins', NULL, 1, 1, 'immediate', 1, NOW(), NOW())")->execute([$key]);
                $log("  + event $key registered with rule: All admins — in-app + email.");
            } else {
                $log("  ~ event $key already present.");
            }
        }

        return true;
    }
}
