<?php
/**
 * migrations/tenant/2026_09_08_pos_price_groups.php
 *
 * Phase 14 (pos_upgrade_plan.md §8) — selling price tiers (Retail/Wholesale/
 * Custom). Builds on the existing products.selling_price / wholesale_price
 * columns rather than replacing them: a price group is a named, SPARSE list
 * of per-product overrides — any product without a row in
 * product_price_group_prices for a given group simply falls back to
 * products.selling_price. Two groups are seeded from data that already
 * exists so nothing is thrown away: "Retail" (is_default, needs zero
 * override rows since its fallback IS selling_price) and "Wholesale"
 * (pre-populated from products.wholesale_price wherever that column is a
 * real, different price).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS price groups (Phase 14)...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `price_groups` (
            `price_group_id` INT NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`price_group_id`),
            UNIQUE KEY `uq_price_group_name` (`name`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table price_groups ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_price_group_prices` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `price_group_id` INT NOT NULL,
            `price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_product_price_group` (`product_id`, `price_group_id`),
            KEY `idx_price_group` (`price_group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table product_price_group_prices ready.\n";

    // customers.default_price_group_id — auto-applies a customer's tier at POS.
    $col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'default_price_group_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN default_price_group_id INT NULL AFTER credit_limit");
        echo "  + customers.default_price_group_id added.\n";
    } else {
        echo "  · customers.default_price_group_id already present.\n";
    }

    // Seed the two default groups (idempotent — INSERT IGNORE on the unique name).
    $pdo->exec("INSERT IGNORE INTO price_groups (name, is_default, status) VALUES ('Retail', 1, 'active')");
    $pdo->exec("INSERT IGNORE INTO price_groups (name, is_default, status) VALUES ('Wholesale', 0, 'active')");
    echo "  + 'Retail' (default) and 'Wholesale' price groups seeded.\n";

    $wholesaleGroupId = (int)$pdo->query("SELECT price_group_id FROM price_groups WHERE name = 'Wholesale' LIMIT 1")->fetchColumn();
    if ($wholesaleGroupId) {
        // Sparse population: only where wholesale_price is a real, different price.
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO product_price_group_prices (product_id, price_group_id, price)
            SELECT product_id, ?, wholesale_price
            FROM products
            WHERE wholesale_price > 0
              AND ABS(wholesale_price - selling_price) > 0.001
        ");
        $stmt->execute([$wholesaleGroupId]);
        echo "  + Wholesale group pre-populated from products.wholesale_price ({$stmt->rowCount()} row(s)).\n";
    }

    // permission page_key — reuses the existing 'pos_advanced' gate (Phase 13),
    // no new permission row needed: price_groups.php checks canView/canEdit('pos_advanced')
    // directly, exactly like the Registers/Loyalty sections already do.

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
