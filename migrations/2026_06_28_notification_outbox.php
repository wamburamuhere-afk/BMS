<?php
/**
 * 2026_06_28_notification_outbox.php
 * ---------------------------------------------------------------------------
 * Phase 4 of the Smart Notification Engine — the email delivery queue.
 *
 * dispatchEvent() enqueues an email row per recipient (when email is enabled);
 * the worker cron/process_notifications.php sends them via core/mailer.php with
 * retry/backoff and writes results to notification_log. Queuing keeps SMTP
 * latency off the web request.
 *
 * Additive & idempotent. No DDL transactions.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: notification outbox (email queue)...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_outbox (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            event_key         VARCHAR(80)  NULL,
            recipient_user_id INT          NULL,
            to_email          VARCHAR(150) NOT NULL,
            channel           VARCHAR(20)  NOT NULL DEFAULT 'email',
            subject           VARCHAR(255) NOT NULL,
            body              MEDIUMTEXT    NULL,
            status            VARCHAR(20)  NOT NULL DEFAULT 'queued',  -- queued|sent|failed
            attempts          INT          NOT NULL DEFAULT 0,
            max_attempts      INT          NOT NULL DEFAULT 5,
            dedupe_key        VARCHAR(191) NULL,
            entity_type       VARCHAR(50)  NULL,
            entity_id         INT          NULL,
            scheduled_for     DATETIME     NULL,   -- NULL = send asap; future = delayed/digest
            last_error        VARCHAR(255) NULL,
            created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at           DATETIME     NULL,
            UNIQUE KEY uq_outbox_dedupe (dedupe_key),
            KEY idx_outbox_status (status, scheduled_for),
            KEY idx_outbox_user (recipient_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table notification_outbox ensured.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
