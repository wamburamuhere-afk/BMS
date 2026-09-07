<?php
/**
 * migrations/tenant/2026_09_07_pos_loyalty_program.php
 *
 * pos_sales.loyalty_points_earned/loyalty_points_redeemed have existed since
 * day one (pos_upgrade_plan.md's original schema audit) but nothing ever wrote
 * to them — there was no customer balance to earn into or redeem from. Adds:
 *   - customers.loyalty_points_balance — a fast denormalised read of the
 *     current balance (mirrors products.stock_quantity as a cache).
 *   - customer_loyalty_transactions — the ledger of truth (mirrors
 *     stock_movements): every earn/redeem/reversal is an auditable row, not
 *     just a mutated number, so a balance can always be reconciled/explained.
 *
 * Per-tenant (customers/pos_sales are tenant-scoped tables), so this runs
 * through migrations/tenant/ + core/tenant_migration_runner.php against every
 * existing tenant database, and every new tenant gets it via
 * schema/tenant_schema_template.sql (updated alongside this file).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS Loyalty Program (Phase 11, pos_upgrade_plan.md §7)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'loyalty_points_balance'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN loyalty_points_balance INT NOT NULL DEFAULT 0 AFTER current_balance");
        echo "  + customers.loyalty_points_balance added.\n";
    } else {
        echo "  . customers.loyalty_points_balance already present.\n";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_loyalty_transactions (
            loyalty_txn_id INT NOT NULL AUTO_INCREMENT,
            customer_id INT NOT NULL,
            sale_id INT DEFAULT NULL,
            txn_type ENUM('earn','redeem','earn_reversal','redeem_reversal') NOT NULL,
            points INT NOT NULL COMMENT 'always positive; txn_type gives direction',
            balance_after INT NOT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (loyalty_txn_id),
            KEY idx_customer_id (customer_id),
            KEY idx_sale_id (sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + customer_loyalty_transactions table ready.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
