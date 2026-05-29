<?php
/**
 * 2026_05_29_purchase_returns_vat_columns.php
 * --------------------------------------------
 * Adds VAT columns to purchase_returns header table.
 * purchase_return_items already has tax_rate, tax_amount, line_total.
 *
 * purchase_returns:
 *   + total_tax   DECIMAL(10,2) NOT NULL DEFAULT 0.00
 *   + grand_total DECIMAL(10,2) NOT NULL DEFAULT 0.00
 *
 * All additions guarded by SHOW COLUMNS — safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: purchase_returns VAT columns...\n";

try {
    if (!$pdo->query("SHOW COLUMNS FROM `purchase_returns` LIKE 'total_tax'")->fetch()) {
        $pdo->exec("ALTER TABLE `purchase_returns` ADD COLUMN `total_tax` DECIMAL(10,2) NOT NULL DEFAULT '0.00' AFTER `total_amount`");
        echo "  + added purchase_returns.total_tax\n";
    } else {
        echo "  · purchase_returns.total_tax already exists, skipping.\n";
    }

    if (!$pdo->query("SHOW COLUMNS FROM `purchase_returns` LIKE 'grand_total'")->fetch()) {
        $pdo->exec("ALTER TABLE `purchase_returns` ADD COLUMN `grand_total` DECIMAL(10,2) NOT NULL DEFAULT '0.00' AFTER `total_tax`");
        echo "  + added purchase_returns.grand_total\n";
    } else {
        echo "  · purchase_returns.grand_total already exists, skipping.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
