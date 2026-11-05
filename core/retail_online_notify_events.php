<?php
/**
 * core/retail_online_notify_events.php — the online shop notification events and their
 * registration, with NO other dependency. The tenant migration reads it, and a tenant migration must
 * never load roots.php (core/notify.php does, which would reconnect $pdo to the main database).
 */

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
