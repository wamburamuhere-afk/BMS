<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: lock Company Profile to admin-only...\n";

/**
 * Follow-up to migrations/2026_07_29_lock_login_history_and_zoom.php: Company
 * Profile is now also strictly admin-only by request (moved inside Settings >
 * Admin alongside Users/Roles & Permissions/Payments/Backup/Login History/
 * Zoom Integration — reaching that page already requires isAdmin(), so
 * Company Profile is no longer reachable or delegable for any non-admin
 * role). Company Profile remains the single source of truth for company
 * identity data (logo, TIN/VRN, addresses, currency) — nothing about its
 * fields or stored settings changes here, only who can reach the page.
 *
 * Hides its permission row from the Roles & Permissions management screen
 * (already filters on `WHERE COALESCE(is_hidden, 0) = 0`).
 *
 * Purely additive/idempotent — only flips is_hidden, no rows added/removed.
 */
try {
    $update = $pdo->prepare("UPDATE permissions SET is_hidden = 1 WHERE page_key = ? AND COALESCE(is_hidden, 0) = 0");
    $update->execute(['company_profile']);
    echo $update->rowCount() > 0
        ? "  + 'company_profile' hidden from Roles & Permissions.\n"
        : "  · 'company_profile' already hidden (or missing) — no change.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
