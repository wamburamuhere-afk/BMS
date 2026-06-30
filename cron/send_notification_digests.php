<?php
/**
 * cron/send_notification_digests.php
 * ---------------------------------------------------------------------------
 * Phase 9 — AI daily digest. Queues ONE summary email per user who has unread
 * engine notifications (AI-summarized when configured, deterministic otherwise).
 * Opt-in via notif_digest_enabled; gated by master switch + global email toggle.
 *
 * Runs at most once per day:
 *   - Server cron:   php cron/send_notification_digests.php
 *   - Opportunistic: included (throttled once/day) from header.php
 * Self-contained + fail-silent.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/notify.php';
global $pdo;

try {
    if (function_exists('save_setting')) {
        save_setting('notif_digest_last_run', date('Y-m-d'));
    }
    $res = sendNotificationDigests($pdo);
    if (php_sapi_name() === 'cli') {
        echo "Notification digests: queued {$res['queued']} of {$res['users']} user(s) with pending items.\n";
    }
} catch (Throwable $e) {
    error_log('send_notification_digests.php error: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'Digest run FAILED: ' . $e->getMessage() . "\n";
        exit(1);
    }
}
