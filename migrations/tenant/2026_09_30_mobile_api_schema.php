<?php
/**
 * Migration: 2026_09_30_mobile_api_schema
 *
 * Adds columns that the mobile API endpoints expect but that may be absent on tenant
 * databases provisioned before these features were added:
 *   - client_uuid (offline idempotency key) on customers, suppliers, products, expenses, warehouses
 *   - brands table (if not present) and brand_id, tax_rate_id, min_selling_price, discount_rate on products
 *   - contact_person, city, supplier_type, notes, updated_at on suppliers
 *
 * All ALTER TABLE statements are guarded — safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: mobile API schema columns...\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // -------------------------------------------------------------------------
    // Helper: add a column only when it is absent
    // -------------------------------------------------------------------------
    $addColIfMissing = function (string $table, string $column, string $definition) use ($pdo): void {
        $r = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($r && $r->fetch()) {
            echo "  · $table.$column already exists.\n";
            return;
        }
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "  + $table.$column added.\n";
    };

    // Helper: create a table if it does not exist
    $createTableIfMissing = function (string $table, string $ddl) use ($pdo): void {
        $r = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($r && $r->fetch()) {
            echo "  · table $table already exists.\n";
            return;
        }
        $pdo->exec($ddl);
        echo "  + table $table created.\n";
    };

    // =========================================================================
    // 1. client_uuid — offline idempotency key on 5 tables
    // =========================================================================
    foreach ([
        ['customers',  'customer_id'],
        ['suppliers',  'supplier_id'],
        ['products',   'product_id'],
        ['expenses',   'expense_id'],
        ['warehouses', 'warehouse_id'],
    ] as [$table, $idCol]) {
        $r = $pdo->query("SHOW TABLES LIKE '$table'");
        if (!($r && $r->fetch())) {
            echo "  · table $table not present — skipping.\n";
            continue;
        }
        $addColIfMissing($table, 'client_uuid', "VARCHAR(36) NULL DEFAULT NULL AFTER `$idCol`");
        // Unique index (nullable — MySQL allows multiple NULLs in a unique index)
        $idxCheck = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = 'uq_{$table}_client_uuid'");
        if (!($idxCheck && $idxCheck->fetch())) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `uq_{$table}_client_uuid` (`client_uuid`)");
                echo "  + uq_{$table}_client_uuid index added.\n";
            } catch (PDOException $e) {
                // Duplicate values already exist — add as plain index instead
                $pdo->exec("ALTER TABLE `$table` ADD INDEX `idx_{$table}_client_uuid` (`client_uuid`)");
                echo "  + idx_{$table}_client_uuid (plain) index added — duplicates existed.\n";
            }
        } else {
            echo "  · uq_{$table}_client_uuid index already exists.\n";
        }
    }

    // =========================================================================
    // 2. suppliers — optional columns the list/get/create endpoints use
    // =========================================================================
    $addColIfMissing('suppliers', 'contact_person', "VARCHAR(191) NULL DEFAULT NULL AFTER `supplier_name`");
    $addColIfMissing('suppliers', 'city',           "VARCHAR(100) NULL DEFAULT NULL AFTER `address`");
    $addColIfMissing('suppliers', 'supplier_type',  "VARCHAR(50)  NULL DEFAULT NULL AFTER `city`");
    $addColIfMissing('suppliers', 'notes',          "TEXT         NULL DEFAULT NULL");
    $addColIfMissing('suppliers', 'updated_at',     "TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

    // =========================================================================
    // 3. brands table + product columns used by products/get.php
    // =========================================================================
    $createTableIfMissing('brands', "
        CREATE TABLE `brands` (
            `brand_id`   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `brand_name` VARCHAR(191)     NOT NULL,
            `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`brand_id`),
            UNIQUE KEY `uq_brands_name` (`brand_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $addColIfMissing('products', 'brand_id',         "INT UNSIGNED NULL DEFAULT NULL AFTER `category_id`");
    $addColIfMissing('products', 'tax_rate_id',       "INT UNSIGNED NULL DEFAULT NULL AFTER `brand_id`");
    $addColIfMissing('products', 'min_selling_price', "DECIMAL(15,4) NOT NULL DEFAULT 0 AFTER `selling_price`");
    $addColIfMissing('products', 'discount_rate',     "DECIMAL(5,2)  NOT NULL DEFAULT 0 AFTER `min_selling_price`");

    // tax_rates table (referenced by LEFT JOIN in products/get.php)
    $createTableIfMissing('tax_rates', "
        CREATE TABLE `tax_rates` (
            `rate_id`    INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `rate_name`  VARCHAR(100)     NOT NULL,
            `rate`       DECIMAL(5,2)     NOT NULL DEFAULT 0,
            `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`rate_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
