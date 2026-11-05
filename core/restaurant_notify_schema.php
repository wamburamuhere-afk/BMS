<?php
/**
 * core/restaurant_notify_schema.php — registers the restaurant notification events
 * and their default routing. One body for the tenant migration and its legacy twin.
 *
 *  - Events (module "Restaurant", page_key restaurant_pos, warehouse-scope aware) —
 *    they then appear on Settings → Notification rules under "Restaurant".
 *  - The existing restaurant.reservation_upcoming moves to the "Restaurant" group.
 *  - Default rule for every restaurant event: All admins — in-app + email
 *    (owner: "make sure admin receive all of these email also"). Other staff are
 *    added per event on notification_rules.php.
 *  - Existing events that had NO rule yet (reservation_upcoming, pos_credit.due_soon,
 *    pos_credit.overdue): the admins rule is added AND a "everyone with access — in-app"
 *    rule, so staff who got them before keep getting them. An event an admin already
 *    configured is never touched.
 * Idempotent.
 */
// Only the event list: restaurant_notify.php loads notify.php → roots.php, which a tenant migration
// must never load (it reconnects $pdo to the main database).
require_once __DIR__ . '/restaurant_notify_events.php';

if (!function_exists('restaurantNotifyEnsureSchema')) {
    function restaurantNotifyEnsureSchema(PDO $pdo, callable $log): bool
    {
        if (!$pdo->query("SHOW TABLES LIKE 'notification_events'")->fetch()
            || !$pdo->query("SHOW TABLES LIKE 'notification_rules'")->fetch()) {
            $log('  ~ notification engine tables absent — nothing to do.');
            return false;
        }
        $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
        $insert = $pdo->prepare("INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
                                 VALUES (?, ?, ?, 'Restaurant', 'restaurant_pos', 'view', ?, 1, 1, NOW())");
        $existing = [];
        foreach (rnEvents() as $key => [$title, $desc, $sev]) {
            $exists->execute([$key]);
            if ($exists->fetchColumn()) {
                $existing[] = $key;
                $pdo->prepare("UPDATE notification_events SET module = 'Restaurant' WHERE event_key = ? AND module <> 'Restaurant'")->execute([$key]);
                $log("  ~ event $key already present (group: Restaurant).");
                continue;
            }
            $insert->execute([$key, $title, $desc, $sev]);
            $log("  + event $key registered.");
        }

        $hasRules = $pdo->prepare("SELECT COUNT(*) FROM notification_rules WHERE event_key = ?");
        $addRule  = $pdo->prepare("INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_at, updated_at)
                                   VALUES (?, ?, NULL, ?, 1, 'immediate', 1, NOW(), NOW())");
        $emailDefault = (string)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_email_notifications'")->fetchColumn() ?: '0') === '1' ? 1 : 0;
        $keys = array_merge(array_keys(rnEvents()), ['pos_credit.due_soon', 'pos_credit.overdue']);
        foreach ($keys as $key) {
            $exists->execute([$key]);
            if (!$exists->fetchColumn()) continue;
            $hasRules->execute([$key]);
            if ((int)$hasRules->fetchColumn() > 0) { $log("  ~ $key already has rules — left as the admin set them."); continue; }
            $addRule->execute([$key, 'admins', 1]);
            $log("  + rule $key: All admins — in-app + email.");
            $wasLive = in_array($key, $existing, true) || str_starts_with($key, 'pos_credit.');
            if ($wasLive) {
                // Keep what staff had before rules existed: in-app for everyone with access (+ email as the global setting said).
                $addRule->execute([$key, 'permission', $emailDefault]);
                $log("  + rule $key: everyone with access — in-app (unchanged for staff).");
            }
        }
        return true;
    }
}
