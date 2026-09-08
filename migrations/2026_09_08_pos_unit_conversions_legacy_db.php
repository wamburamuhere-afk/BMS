<?php
/**
 * migrations/2026_09_08_pos_unit_conversions_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_08_pos_unit_conversions.php (Phase 15 —
 * pos_upgrade_plan.md §8) onto the LEGACY / non-tenant database. See
 * 2026_09_08_pos_price_groups_legacy_db.php for why this exists, and
 * 2026_09_08_pos_network_printer_legacy_db.php for why the table-missing
 * degrade-instead-of-fail approach is used for the pos_sale_items columns.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS unit conversions on the legacy database (Phase 15)...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_unit_conversions` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `unit_label` VARCHAR(50) NOT NULL,
            `base_unit_multiplier` DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
            `unit_price_override` DECIMAL(15,2) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_product_unit_label` (`product_id`, `unit_label`),
            KEY `idx_product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table product_unit_conversions ready.\n";

    $table = $pdo->query("SHOW TABLES LIKE 'pos_sale_items'")->fetch();
    if (!$table) {
        echo "  · pos_sale_items does not exist on this database — skipping its columns (not this migration's job to create it).\n";
        echo "Migration complete.\n";
        exit(0);
    }

    foreach ([
        'sold_unit_label'    => "VARCHAR(50) DEFAULT NULL",
        'sold_unit_quantity' => "DECIMAL(10,3) DEFAULT NULL",
    ] as $col => $def) {
        $exists = $pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE " . $pdo->quote($col))->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE pos_sale_items ADD COLUMN `$col` $def");
            echo "  + pos_sale_items.$col added.\n";
        } else {
            echo "  · pos_sale_items.$col already present.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
