<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Concurrent-login admin notification...\n";

/**
 * Registers 'concurrent_login_detected' — fires when an account logs in while
 * a PREVIOUS session for that same account is still open (second device,
 * second browser, or someone else with the same password).
 *
 * Deliberate design change (2026-08-29, user's own decision after review):
 * this used to auto-close the earlier session ("superseded"). It no longer
 * does — the user has made explicit that ONLY two things may ever
 * automatically end a session: the person clicking Logout, and the 30-minute
 * idle timeout (expireIdleSessions()). Everything else, including this, is
 * now purely a SIGNAL: email admins, leave every session exactly as it was,
 * and let an admin decide — from Login History, seeing both rows genuinely
 * "Active" side by side — whether to End Session on one of them.
 *
 * Same reusable pattern as unfamiliar_login_detected
 * (migrations/2026_08_29_unfamiliar_login_notifications.php): page_key =
 * 'login_history' resolves recipients to admins only (that permission is
 * admin-only and hidden from Roles & Permissions), and a forced
 * notification_rules row makes the email non-optional, independent of the
 * general "enable email notifications" toggle — the same reasoning already
 * applied to the unfamiliar-login alert.
 */
try {
    $seed = $pdo->prepare("
        INSERT IGNORE INTO notification_events
            (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $seed->execute([
        'concurrent_login_detected',
        'Concurrent login detected',
        'An account signed in again while a previous session for that account was still open',
        'Security', 'login_history', 'view', 'high', 0,
    ]);
    echo "  + notification_events seeded (" . $seed->rowCount() . " new).\n";

    $ruleExists = $pdo->prepare("SELECT id FROM notification_rules WHERE event_key = ? AND target_type = 'permission' LIMIT 1");
    $ruleExists->execute(['concurrent_login_detected']);
    if ($ruleExists->fetch()) {
        echo "  · notification_rules row already exists — skipped.\n";
    } else {
        $rule = $pdo->prepare("
            INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_by, created_at)
            VALUES (?, 'permission', 0, 1, 1, 0, 1, NULL, NOW())
        ");
        $rule->execute(['concurrent_login_detected']);
        echo "  + notification_rules row added (forces email, independent of the general email toggle).\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
