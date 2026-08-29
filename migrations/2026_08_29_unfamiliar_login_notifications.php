<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Unfamiliar-login admin notifications...\n";

/**
 * Registers 'unfamiliar_login_detected' in the existing Smart Notification
 * Engine (core/notify.php) — no new delivery mechanism, reuses the same
 * event -> RBAC recipients -> in-app + email outbox bus every other alert in
 * BMS already goes through.
 *
 * page_key = 'login_history': that permission is admin-only and hidden from
 * Roles & Permissions (migrations/2026_07_29_lock_login_history_and_zoom.php),
 * so usersWithPermission() resolves this to admins only — matching "admin
 * determined by role as normal as usual", nothing new invented.
 *
 * A security alert like this must not be silently swallowed by the general
 * "enable email notifications" toggle most other notification types respect
 * (resolveRecipients()'s no-rule fallback) — confirmed with the user this
 * should always email, so an explicit notification_rules row forces
 * channel_email=1 regardless of that global setting.
 *
 * Also seeds the default policy setting this event's dispatch logic reads
 * (core/session_tracker.php): 'notify' = email admins only (the required
 * default — never automatic action against the user); 'auto_logout' = also
 * force-end that session immediately. Never seeds 'auto_logout' by default.
 */
try {
    $seed = $pdo->prepare("
        INSERT IGNORE INTO notification_events
            (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $seed->execute([
        'unfamiliar_login_detected',
        'Unfamiliar login detected',
        'A user signed in from a country + device combination never seen for their account before',
        'Security', 'login_history', 'view', 'high', 0,
    ]);
    echo "  + notification_events seeded (" . $seed->rowCount() . " new).\n";

    $ruleExists = $pdo->prepare("SELECT id FROM notification_rules WHERE event_key = ? AND target_type = 'permission' LIMIT 1");
    $ruleExists->execute(['unfamiliar_login_detected']);
    if ($ruleExists->fetch()) {
        echo "  · notification_rules row already exists — skipped.\n";
    } else {
        $rule = $pdo->prepare("
            INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_by, created_at)
            VALUES (?, 'permission', 0, 1, 1, 0, 1, NULL, NOW())
        ");
        $rule->execute(['unfamiliar_login_detected']);
        echo "  + notification_rules row added (forces email, independent of the general email toggle).\n";
    }

    if (function_exists('get_setting') && get_setting('unfamiliar_login_policy', '') === '') {
        save_setting('unfamiliar_login_policy', 'notify');
        echo "  + unfamiliar_login_policy default seeded ('notify' — admin manual action, never automatic).\n";
    } else {
        echo "  · unfamiliar_login_policy already set — left untouched.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
