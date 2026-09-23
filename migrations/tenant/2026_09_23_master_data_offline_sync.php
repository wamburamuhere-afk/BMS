<?php
/**
 * migrations/tenant/2026_09_23_master_data_offline_sync.php
 *
 * Phase 2 of the offline-first sync layer for the Flutter / duka360 app.
 *
 * Adds client_uuid idempotency keys to the five master-data tables so that a
 * Flutter app can safely retry customer/supplier/product/expense/warehouse
 * creation over a flaky connection without creating duplicate records.
 *
 * Tables changed:
 *   customers   — client_uuid (idempotency)
 *   suppliers   — client_uuid (idempotency)
 *   products    — client_uuid (idempotency)
 *   expenses    — client_uuid (idempotency)
 *   warehouses  — client_uuid (idempotency)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: master-data offline-sync columns...\n";

$tables = [
    'customers'  => 'ux_customers_client_uuid',
    'suppliers'  => 'ux_suppliers_client_uuid',
    'products'   => 'ux_products_client_uuid',
    'expenses'   => 'ux_expenses_client_uuid',
    'warehouses' => 'ux_warehouses_client_uuid',
];

try {
    foreach ($tables as $table => $indexName) {
        if (!(bool)$pdo->query("SHOW TABLES LIKE '$table'")->fetch()) {
            echo "  · $table table does not exist — skipping.\n";
            continue;
        }

        if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE 'client_uuid'")->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN client_uuid VARCHAR(36) NULL");
            echo "  + $table.client_uuid added.\n";
        } else {
            echo "  · $table.client_uuid already exists.\n";
        }

        $uxExists = $pdo->query(
            "SELECT 1 FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name   = '$table'
                AND index_name   = '$indexName'
              LIMIT 1"
        )->fetch();
        if (!$uxExists) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` (client_uuid)");
                echo "  + $indexName unique index added.\n";
            } catch (PDOException $idxE) {
                echo "  ! Could not add $indexName: " . $idxE->getMessage() . " (skipped)\n";
            }
        } else {
            echo "  · $indexName already exists.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
