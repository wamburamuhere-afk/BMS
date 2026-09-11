<?php
/**
 * migrations/tenant/2026_09_11_product_expiry_notifications.php
 *
 * Closes a gap found while explaining Phase 17 (pos_upgrade_plan.md §8) to the
 * product owner: the milestone-based expiry cron
 * (cron/run_notification_checks.php) only ever scanned `product_batches` —
 * a product tracked at batch level. A product that only carries the older,
 * simpler `products.expiry_date` (no batch tracking turned on) showed up on
 * the dashboard's "expiring" widget when someone happened to look at it, but
 * never triggered an automatic in-app/email notification.
 *
 * This migration adds one small, additive table so the cron can also alert
 * on plain (non-batch-tracked) products, reusing the existing
 * `product.batch_expiring` notification_events row — zero new settings UI,
 * any tenant's existing recipient/email configuration for that event
 * automatically covers this case too.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: plain-product expiry notifications...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_expiry_reminders` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `warehouse_id` INT NOT NULL,
            `milestone` INT NOT NULL,
            `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_product_warehouse_milestone` (`product_id`, `warehouse_id`, `milestone`),
            KEY `idx_product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "  + table product_expiry_reminders ready.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
