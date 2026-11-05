<?php
/**
 * core/restaurant_notify_events.php — the list of restaurant notification events, with NO other
 * dependency. Tenant migrations read it, and a tenant migration must never load roots.php
 * (core/tenant_migration_bootstrap.php): core/restaurant_notify.php loads core/notify.php, which
 * loads roots.php and silently reconnects $pdo to the main database.
 */

if (!function_exists('rnEvents')) {
    /** key => [title, description, severity] — the events the migration registers (module "Restaurant"). */
    function rnEvents(): array
    {
        return [
            'restaurant.online_order_new'      => ['New online order', 'A customer sent an order from the online menu or a table QR code.', 'high'],
            'restaurant.online_order_waiting'  => ['Online order waiting', 'An online order has not been accepted yet.', 'high'],
            'restaurant.online_order_uncollected' => ['Online order not collected', 'An accepted pickup order has not been paid or collected yet.', 'medium'],
            'restaurant.kitchen_ready'         => ['Kitchen order ready', 'The kitchen marked an order ready to serve.', 'medium'],
            'restaurant.kitchen_waiting'       => ['Kitchen order waiting long', 'A kitchen order has been waiting a long time.', 'high'],
            'restaurant.reservation_new'       => ['New reservation', 'A table reservation was booked.', 'medium'],
            'restaurant.reservation_upcoming'  => ['Reservation upcoming', 'A restaurant table reservation is coming up soon', 'medium'],
            'restaurant.stock_low'             => ['Running low on stock', 'A drink or ingredient is low or out of stock.', 'medium'],
            'restaurant.supplier_payment_due'  => ['Supplier payment due', 'A supplier bill from Purchases is due or overdue.', 'high'],
            'restaurant.table_open_long'       => ['Table bill open long', 'A table bill has been open for a long time.', 'medium'],
            'restaurant.daily_summary'         => ['Restaurant daily summary', "Yesterday's sales, orders and profit.", 'low'],
            'restaurant.dishes_no_cost'        => ['Dishes with no cost', 'Dishes were sold with no cost, so profit may look too high.', 'low'],
        ];
    }
}

if (!function_exists('rnRegisterMissingEvents')) {
    /**
     * Register every restaurant event this database does not have yet, each with the default rule
     * "All admins — in-app + email". An event that already exists is never touched (nor its rules,
     * so an admin's choices stay). Repairs tenants where the 2026-11-03 migration wrote its events
     * to the main database instead. Idempotent.
     */
    function rnRegisterMissingEvents(PDO $pdo, callable $log): int
    {
        if (!$pdo->query("SHOW TABLES LIKE 'notification_events'")->fetch() || !$pdo->query("SHOW TABLES LIKE 'notification_rules'")->fetch()) return 0;
        $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
        $insert = $pdo->prepare("INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
                                 VALUES (?, ?, ?, 'Restaurant', 'restaurant_pos', 'view', ?, 1, 1, NOW())");
        $rule   = $pdo->prepare("INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_at, updated_at)
                                 VALUES (?, 'admins', NULL, 1, 1, 'immediate', 1, NOW(), NOW())");
        $added = 0;
        foreach (rnEvents() as $key => [$title, $desc, $sev]) {
            $exists->execute([$key]);
            if ($exists->fetchColumn()) continue;
            $insert->execute([$key, $title, $desc, $sev]);
            $rule->execute([$key]);
            $added++;
            $log("  + event $key registered with rule: All admins — in-app + email.");
        }
        if (!$added) $log('  ~ all restaurant events already registered.');
        return $added;
    }
}
