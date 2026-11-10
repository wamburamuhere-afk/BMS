<?php
/**
 * core/restaurant_alerts_schema.php — schema for the restaurant alerts pass (2026-11-10). Dependency-free:
 * used by migrations/tenant/2026_11_10_restaurant_alerts.php and its legacy twin. Idempotent.
 *
 *  - notifications.entity_type / entity_id: which record an alert is about, so once one person acts on it
 *    (the cashier confirms an order, the storekeeper gives out stock, the waiter receives the food) every
 *    other copy of that alert is marked read (notifResolve() in core/notify.php).
 *  - "Ready to serve" (restaurant.kitchen_ready) goes to the waiter who took the order: a record-owner
 *    in-app rule, added only where the event has none (an admin's own rules are never touched).
 */

if (!function_exists('rasHasColumn')) {
    function rasHasColumn(PDO $pdo, string $table, string $col): bool
    {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$col]);
        return (bool)$st->fetch();
    }
}

if (!function_exists('rasEnsureNotificationEntity')) {
    function rasEnsureNotificationEntity(PDO $pdo, callable $log): void
    {
        if (!$pdo->query("SHOW TABLES LIKE 'notifications'")->fetch()) { $log('  ~ notifications table absent — skipped.'); return; }
        if (!rasHasColumn($pdo, 'notifications', 'entity_type')) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN entity_type VARCHAR(50) NULL DEFAULT NULL AFTER event_key");
            $log('  + notifications.entity_type added.');
        }
        if (!rasHasColumn($pdo, 'notifications', 'entity_id')) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN entity_id INT NULL DEFAULT NULL AFTER entity_type");
            $log('  + notifications.entity_id added.');
        }
        $idx = $pdo->query("SHOW INDEX FROM notifications WHERE Key_name = 'idx_notif_event_entity'")->fetch();
        if (!$idx) {
            $pdo->exec("ALTER TABLE notifications ADD INDEX idx_notif_event_entity (event_key, entity_type, entity_id)");
            $log('  + index idx_notif_event_entity added.');
        }
    }
}

if (!function_exists('rasEnsureWaiterReadyRule')) {
    function rasEnsureWaiterReadyRule(PDO $pdo, callable $log): void
    {
        if (!$pdo->query("SHOW TABLES LIKE 'notification_rules'")->fetch() || !$pdo->query("SHOW TABLES LIKE 'notification_events'")->fetch()) return;
        $ev = $pdo->query("SELECT 1 FROM notification_events WHERE event_key = 'restaurant.kitchen_ready'")->fetchColumn();
        if (!$ev) { $log('  ~ restaurant.kitchen_ready not registered here — skipped.'); return; }
        $has = $pdo->query("SELECT 1 FROM notification_rules WHERE event_key = 'restaurant.kitchen_ready' AND target_type = 'record_owner'")->fetchColumn();
        if ($has) { $log('  ~ "Ready to serve" already goes to the waiter.'); return; }
        $pdo->exec("INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_at, updated_at)
                    VALUES ('restaurant.kitchen_ready', 'record_owner', NULL, 0, 1, 'immediate', 1, NOW(), NOW())");
        $log('  + "Ready to serve" now also goes to the waiter who took the order (in-app).');
    }
}

if (!function_exists('restaurantAlertsEnsureSchema')) {
    function restaurantAlertsEnsureSchema(PDO $pdo, callable $log): void
    {
        rasEnsureNotificationEntity($pdo, $log);
        rasEnsureWaiterReadyRule($pdo, $log);
    }
}
