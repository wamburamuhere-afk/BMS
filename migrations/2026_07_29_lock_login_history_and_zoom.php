<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: lock Login History + Zoom Integration to admin-only...\n";

/**
 * Follow-up to migrations/2026_07_29_lock_sensitive_settings.php: Login
 * History and Zoom Integration are now also strictly admin-only by request
 * (moved inside Settings > Admin alongside Users/Roles & Permissions/
 * Payments/Backup — reaching that page already requires isAdmin(), so
 * these two are no longer reachable or delegable for any non-admin role).
 *
 * Hides their permission rows from the Roles & Permissions management
 * screen (already filters on `WHERE COALESCE(is_hidden, 0) = 0`).
 *
 * Purely additive/idempotent — only flips is_hidden, no rows added/removed.
 */
try {
    $lockedPageKeys = ['login_history', 'zoom_settings'];

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
