<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: lock sensitive Settings pages to admin-only...\n";

/**
 * Five pages are now strictly admin-only by design (hard isAdmin() check in
 * code, not delegable no matter what's granted): system_settings.php (the
 * "Admin" page — General/Email/SMS/Collections/Security policy), users.php,
 * user_roles.php (the escalation-risk pair — a non-admin editor here could
 * grant their own role admin-equivalent power), backup_restore.php (full
 * restore/download risk), and payment_settings.php (bank/gateway fraud
 * risk).
 *
 * This migration hides their permission rows from the Roles & Permissions
 * management screen (user_roles.php already filters on
 * `WHERE COALESCE(is_hidden, 0) = 0`), so an admin can't even be offered a
 * checkbox that would silently do nothing once granted.
 *
 * Purely additive/idempotent — only flips is_hidden, no rows added/removed.
 */
try {
    $lockedPageKeys = ['system_settings', 'users', 'user_roles', 'backup_restore', 'payment_settings'];

    $update = $pdo->prepare("UPDATE permissions SET is_hidden = 1 WHERE page_key = ? AND COALESCE(is_hidden, 0) = 0");
    foreach ($lockedPageKeys as $key) {
        $update->execute([$key]);
        echo $update->rowCount() > 0
            ? "  + '$key' hidden from Roles & Permissions.\n"
            : "  · '$key' already hidden (or missing) — no change.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
