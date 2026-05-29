<?php
/**
 * 2026_05_29_sales_returns_vat_columns.php
 * -----------------------------------------
 * Adds VAT columns to sales_returns and sales_return_items so the
 * per-item VAT (BMS standard: 0% or 18%) can be stored and displayed.
 *
 * sales_returns:
 *   + total_tax  DECIMAL(15,2) NOT NULL DEFAULT 0.00
 *
 * sales_return_items:
 *   + tax_rate   DECIMAL(5,2)  NOT NULL DEFAULT 0.00
 *   + tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00
 *
 * All additions are guarded by SHOW COLUMNS — safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: sales_returns VAT columns...\n";

try {
    // ── sales_returns.total_tax ───────────────────────────────────────────────
    if (!$pdo->query("SHOW COLUMNS FROM `sales_returns` LIKE 'total_tax'")->fetch()) {
        $pdo->exec("ALTER TABLE `sales_returns` ADD COLUMN `total_tax` DECIMAL(15,2) NOT NULL DEFAULT '0.00' AFTER `total_amount`");
        echo "  + added sales_returns.total_tax\n";
    } else {
        echo "  · sales_returns.total_tax already exists, skipping.\n";
    }

    // ── sales_return_items.tax_rate ───────────────────────────────────────────
    if (!$pdo->query("SHOW COLUMNS FROM `sales_return_items` LIKE 'tax_rate'")->fetch()) {
        $pdo->exec("ALTER TABLE `sales_return_items` ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT '0.00' AFTER `unit_price`");
        echo "  + added sales_return_items.tax_rate\n";
    } else {
        echo "  · sales_return_items.tax_rate already exists, skipping.\n";
    }

    // ── sales_return_items.tax_amount ─────────────────────────────────────────
    if (!$pdo->query("SHOW COLUMNS FROM `sales_return_items` LIKE 'tax_amount'")->fetch()) {
        $pdo->exec("ALTER TABLE `sales_return_items` ADD COLUMN `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT '0.00' AFTER `tax_rate`");
        echo "  + added sales_return_items.tax_amount\n";
    } else {
        echo "  · sales_return_items.tax_amount already exists, skipping.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
