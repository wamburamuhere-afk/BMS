<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: lock Project Assignments to admin-only...\n";

/**
 * Follow-up to migrations/2026_07_30_lock_notification_rules.php: Project
 * Assignments is now also strictly admin-only by request (moved inside
 * Settings > Admin — reaching that page already requires isAdmin(), so
 * Project Assignments is no longer reachable or delegable for any
 * non-admin role). Same page, same fields — only who can reach it changed.
 *
 * Note: AI Assistant (page_key 'ai_assistant') got the same hard isAdmin()
 * gate on its config page in this same change, but its permission row is
 * deliberately NOT hidden here — that key also gates the separate "Ask BMS
 * AI" chat feature, which should stay grantable to non-admins independent
 * of the (now admin-only) config page.
 *
 * Hides the 'user_projects' permission row from the Roles & Permissions
 * management screen (already filters on `WHERE COALESCE(is_hidden, 0) = 0`).
 *
 * Purely additive/idempotent — only flips is_hidden, no rows added/removed.
 */
try {
    $update = $pdo->prepare("UPDATE permissions SET is_hidden = 1 WHERE page_key = ? AND COALESCE(is_hidden, 0) = 0");
    $update->execute(['user_projects']);
    echo $update->rowCount() > 0
        ? "  + 'user_projects' hidden from Roles & Permissions.\n"
        : "  · 'user_projects' already hidden (or missing) — no change.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
