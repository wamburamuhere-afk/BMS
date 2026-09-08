<?php
/**
 * migrations/tenant/2026_09_08_pos_product_batches.php
 *
 * Phase 17 (pos_upgrade_plan.md §8) — batch/lot number + expiry tracking,
 * end-to-end (GRN -> stock -> POS -> alerts). Turns the pre-existing but
 * dead-end `receipt_items.batch_number`/`.expiry_date` data (captured at GRN
 * since day one, never used again) into a real, decrementing stock ledger.
 *
 * Three new tables:
 *   - product_batches: the live batch stock ledger, one row per GRN line
 *     that actually specified a batch_number or expiry_date (sparse — a
 *     plain non-batch-tracked product gets zero rows and behaves exactly as
 *     it did before this phase).
 *   - pos_sale_item_batches: links a POS sale line to the batch(es) it drew
 *     from (FEFO — First-Expired-First-Out), mirroring how create_return.php
 *     already links a return back to its original sale item.
 *   - product_batch_expiry_reminders: milestone-dedupe table, exact copy of
 *     the proven document_expiry_reminders shape/pattern
 *     (cron/check_document_expiry.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS product batches (Phase 17)...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_batches` (
            `batch_id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `warehouse_id` INT NOT NULL,
            `batch_number` VARCHAR(100) DEFAULT NULL,
            `expiry_date` DATE DEFAULT NULL,
            `quantity_received` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            `quantity_remaining` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            `unit_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `receipt_id` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`batch_id`),
            KEY `idx_product_warehouse` (`product_id`, `warehouse_id`),
            KEY `idx_expiry_date` (`expiry_date`),
            KEY `idx_quantity_remaining` (`quantity_remaining`),
            KEY `idx_receipt_id` (`receipt_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table product_batches ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pos_sale_item_batches` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `sale_item_id` INT NOT NULL,
            `batch_id` INT NOT NULL,
            `quantity` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_sale_item_id` (`sale_item_id`),
            KEY `idx_batch_id` (`batch_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table pos_sale_item_batches ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_batch_expiry_reminders` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `batch_id` INT NOT NULL,
            `milestone` INT NOT NULL,
            `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_batch_milestone` (`batch_id`, `milestone`),
            KEY `idx_batch_id` (`batch_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "  + table product_batch_expiry_reminders ready.\n";

    // notification_events row — reuses the existing engine (core/notify.php),
    // scope-aware on warehouse so a scoped user is only alerted about
    // expiring stock in their own warehouse (Phase 6's ACL, not bypassed;
    // resolveRecipients() gained a warehouse_id branch alongside its existing
    // project_id one — see core/notify.php and core/warehouse_scope.php's
    // new warehouseIdsForUser()).
    $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
    $exists->execute(['product.batch_expiring']);
    if (!$exists->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
            VALUES ('product.batch_expiring', 'Product batch expiring', 'A tracked product batch/lot is approaching its expiry date', 'Inventory', 'products', 'view', 'high', 1, 1, NOW())
        ")->execute();
        echo "  + notification_events row 'product.batch_expiring' seeded.\n";
    } else {
        echo "  · notification_events row 'product.batch_expiring' already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
