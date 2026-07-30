<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: lock Notification Rules to admin-only...\n";

/**
 * Follow-up to migrations/2026_07_30_lock_company_profile.php: Notification
 * Rules is now also strictly admin-only by request (moved inside Settings >
 * Admin alongside Users/Roles & Permissions/Payments/Backup/Login History/
 * Zoom Integration/Company Profile — reaching that page already requires
 * isAdmin(), so Notification Rules is no longer reachable or delegable for
 * any non-admin role). Same page, same fields — only who can reach it
 * changed.
 *
 * Hides its permission row from the Roles & Permissions management screen
 * (already filters on `WHERE COALESCE(is_hidden, 0) = 0`).
 *
 * Purely additive/idempotent — only flips is_hidden, no rows added/removed.
 */
try {
    $update = $pdo->prepare("UPDATE permissions SET is_hidden = 1 WHERE page_key = ? AND COALESCE(is_hidden, 0) = 0");
    $update->execute(['notification_rules']);
    echo $update->rowCount() > 0
        ? "  + 'notification_rules' hidden from Roles & Permissions.\n"
        : "  · 'notification_rules' already hidden (or missing) — no change.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
