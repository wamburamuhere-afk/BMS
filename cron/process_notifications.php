<?php
/**
 * cron/process_notifications.php
 * ---------------------------------------------------------------------------
 * Phase 4 — email delivery worker. Sends queued notification emails from
 * notification_outbox via core/mailer.php (retry/backoff, logged).
 *
 * Runs two ways (both safe — idempotent, fail-silent):
 *   - Server cron:   php cron/process_notifications.php   (recommended, every 1-5 min)
 *   - Opportunistic: included (throttled) from header.php (wired in Phase 6)
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/notify.php';
global $pdo;

try {
    if (function_exists('save_setting')) {
        save_setting('notif_outbox_last_run', date('Y-m-d H:i:s'));
    }
    $res = processNotificationOutbox($pdo, 50);

    if (php_sapi_name() === 'cli') {
        echo "Notification outbox: processed {$res['processed']}, sent {$res['sent']}, "
           . "retry {$res['retry']}, failed {$res['failed']}\n";
    }
} catch (Throwable $e) {
    error_log('process_notifications.php error: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'Notification worker FAILED: ' . $e->getMessage() . "\n";
        exit(1);
    }
}
