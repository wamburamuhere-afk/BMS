<?php
/**
 * migrations/2026_09_08_pos_price_groups_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_08_pos_price_groups.php (Phase 14 —
 * pos_upgrade_plan.md §8) onto the LEGACY / non-tenant database.
 *
 * core/tenant_migration_runner.php only ever applies migrations/tenant/*.php
 * to databases registered as rows in the `tenants` control table. A host
 * running in single-tenant mode (no control database wired up, e.g.
 * bms.bjptechnologies.co.tz) or serving its own bare-domain legacy database
 * alongside real tenants (e.g. demo.bjptechnologies.co.tz's own default
 * database) has no row there and was never reached by that migration — this
 * is why price_groups went missing in production (Sentry:
 * demo.bjptechnologies.co.tz/pos/price-groups, DB bejundas_main) even though
 * every registered tenant already has the table. Every genuinely tenant-only
 * migration written from now on needs a decision on whether the legacy
 * database also needs it — this file exists because the answer here is yes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS price groups on the legacy database (Phase 14)...\n";

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

    $col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'default_price_group_id'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM customers LIKE 'credit_limit'")->fetch();
        $position = $anchor ? " AFTER credit_limit" : "";
        $pdo->exec("ALTER TABLE customers ADD COLUMN default_price_group_id INT NULL$position");
        echo "  + customers.default_price_group_id added" . ($position ? "" : " (anchor column 'credit_limit' not found — appended at end instead)") . ".\n";
    } else {
        echo "  · customers.default_price_group_id already present.\n";
    }

    $pdo->exec("INSERT IGNORE INTO price_groups (name, is_default, status) VALUES ('Retail', 1, 'active')");
    $pdo->exec("INSERT IGNORE INTO price_groups (name, is_default, status) VALUES ('Wholesale', 0, 'active')");
    echo "  + 'Retail' (default) and 'Wholesale' price groups seeded.\n";

    $wholesaleGroupId = (int)$pdo->query("SELECT price_group_id FROM price_groups WHERE name = 'Wholesale' LIMIT 1")->fetchColumn();
    if ($wholesaleGroupId) {
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

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
