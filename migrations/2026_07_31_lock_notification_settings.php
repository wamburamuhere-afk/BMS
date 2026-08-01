<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: lock Notification Settings to admin-only...\n";

/**
 * Follow-up to migrations/2026_07_30_lock_notification_rules.php: Notification
 * Settings (channels, email/SMS templates, alert rules) is now also strictly
 * admin-only by request — folded into notification_rules.php (Settings >
 * Admin > Notification Rules) as a collapsible panel. The standalone
 * notification_settings.php page still works directly for admins, but is no
 * longer delegable to any non-admin role via Roles & Permissions.
 *
 * Hides its permission row from the Roles & Permissions management screen
 * (already filters on `WHERE COALESCE(is_hidden, 0) = 0`).
 *
 * Purely additive/idempotent — only flips is_hidden, no rows added/removed.
 */
try {
    $update = $pdo->prepare("UPDATE permissions SET is_hidden = 1 WHERE page_key = ? AND COALESCE(is_hidden, 0) = 0");
    $update->execute(['notification_settings']);
    echo $update->rowCount() > 0
        ? "  + 'notification_settings' hidden from Roles & Permissions.\n"
        : "  · 'notification_settings' already hidden (or missing) — no change.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
