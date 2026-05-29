<?php
/**
 * 2026_05_29_receipt_items_vat_columns.php
 * ------------------------------------------
 * Adds VAT columns to receipt_items so GRN lines can carry tax_rate and
 * tax_amount (BMS standard: 0% or 18%).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: receipt_items VAT columns...\n";

try {
    if (!$pdo->query("SHOW COLUMNS FROM `receipt_items` LIKE 'tax_rate'")->fetch()) {
        $pdo->exec("ALTER TABLE `receipt_items` ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT '0.00' AFTER `unit_price`");
        echo "  + added receipt_items.tax_rate\n";
    } else {
        echo "  · receipt_items.tax_rate already exists, skipping.\n";
    }

    if (!$pdo->query("SHOW COLUMNS FROM `receipt_items` LIKE 'tax_amount'")->fetch()) {
        $pdo->exec("ALTER TABLE `receipt_items` ADD COLUMN `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT '0.00' AFTER `tax_rate`");
        echo "  + added receipt_items.tax_amount\n";
    } else {
        echo "  · receipt_items.tax_amount already exists, skipping.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
