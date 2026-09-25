<?php
/**
 * core/superadmin_notifications.php
 *
 * Helpers for the superadmin notification bell:
 *   insertSaNotification()         — create one notification (deduplicated per tenant/type/day)
 *   countUnreadSaNotifications()   — badge count for the bell
 *   getRecentSaNotifications()     — dropdown list (latest N, unread first)
 *   markAllSaNotificationsRead()   — called when the bell dropdown is opened
 */

require_once __DIR__ . '/control_db.php';

if (!function_exists('insertSaNotification')) {
    /**
     * Insert one superadmin notification for a tenant expiry event.
     * Silently skips if an identical (tenant_id, type) row already exists today,
     * so the bootstrap gate and the cron batch never double-up.
     */
    function insertSaNotification(int $tenantId, string $type, string $tenantName, string $subdomain): void
    {
        if (!in_array($type, ['trial_expired', 'subscription_expired'], true)) return;
        try {
            $ctrl = getControlPdo();
            // Deduplicate: one notification per (tenant_id, type) per calendar day
            $dup = $ctrl->prepare("
                SELECT 1 FROM superadmin_notifications
                 WHERE tenant_id = ? AND type = ? AND DATE(created_at) = CURDATE()
                 LIMIT 1
            ");
            $dup->execute([$tenantId, $type]);
            if ($dup->fetchColumn()) return;

            $typeLabels = [
                'trial_expired'        => 'Free trial expired',
                'subscription_expired' => 'Subscription expired',
            ];
            $label = $typeLabels[$type];
            $title = $label . ' — ' . $tenantName;
            $body  = $tenantName . ' (' . $subdomain . ') — ' . strtolower($label) . ' today.';

            $ctrl->prepare("
                INSERT INTO superadmin_notifications (type, title, body, tenant_id, tenant_name, subdomain)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$type, $title, $body, $tenantId, $tenantName, $subdomain]);
        } catch (Throwable $e) {
            error_log('insertSaNotification: ' . $e->getMessage());
        }
    }
}

if (!function_exists('countUnreadSaNotifications')) {
    /** Returns the unread notification count for the bell badge. */
    function countUnreadSaNotifications(): int
    {
        try {
            return (int) getControlPdo()
                ->query("SELECT COUNT(*) FROM superadmin_notifications WHERE is_read = 0")
                ->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('getRecentSaNotifications')) {
    /**
     * Returns the most recent N notifications (unread first, then newest).
     * Used to populate the bell dropdown.
     */
    function getRecentSaNotifications(int $limit = 15): array
    {
        try {
            return getControlPdo()->query("
                SELECT id, type, title, body, tenant_id, tenant_name, subdomain, is_read, created_at
                  FROM superadmin_notifications
                 ORDER BY is_read ASC, created_at DESC
                 LIMIT {$limit}
            ")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('markAllSaNotificationsRead')) {
    /** Marks all unread notifications as read. */
    function markAllSaNotificationsRead(): void
    {
        try {
            getControlPdo()->exec("UPDATE superadmin_notifications SET is_read = 1 WHERE is_read = 0");
        } catch (Throwable $e) {
            error_log('markAllSaNotificationsRead: ' . $e->getMessage());
        }
    }
}
