<?php
/**
 * migrations/2026_09_08_pos_product_batches_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_08_pos_product_batches.php (Phase 17 —
 * pos_upgrade_plan.md §8) onto the LEGACY / non-tenant database. See
 * 2026_09_08_pos_price_groups_legacy_db.php for why this exists —
 * core/tenant_migration_runner.php never reaches a host's own legacy
 * database, only rows registered in the `tenants` control table. This is the
 * table behind the first live incident (Sentry: demo.bjptechnologies.co.tz/
 * dashboard, DB bejundas_main).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS product batches on the legacy database (Phase 17)...\n";

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
