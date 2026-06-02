<?php
/**
 * 2026_06_02_supplier_invoice_items.php
 * -------------------------------------
 * Adds line-items support to received (supplier) invoices, mirroring the
 * customer-invoice items model so amount/VAT are computed from the lines the
 * same way invoice_create.php does.
 *
 *   1. supplier_invoice_items — one row per Product/Item line.
 *   2. supplier_invoices.warehouse_id — the warehouse the goods relate to.
 *
 * Idempotent: table created IF NOT EXISTS; column added only if missing.
 * Non-destructive — existing supplier_invoices rows (single amount, no items)
 * keep working; the UI falls back to the stored amount when no items exist.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: supplier_invoice_items + warehouse_id...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'supplier_invoices'")->fetch()) {
        echo "  ! supplier_invoices table not found — skipping.\n";
        exit(0);
    }

    // 1. Line-items table (mirrors invoice_items conventions).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS supplier_invoice_items (
            item_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id     INT NOT NULL,
            product_id     INT NULL,
            item_name      VARCHAR(255) NOT NULL,
            description    VARCHAR(255) NULL,
            quantity       DECIMAL(15,2) NOT NULL DEFAULT 0,
            unit           VARCHAR(50) NULL,
            unit_price     DECIMAL(15,2) NOT NULL DEFAULT 0,
            tax_rate       DECIMAL(5,2)  NOT NULL DEFAULT 0,
            tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
            line_total     DECIMAL(15,2) NOT NULL DEFAULT 0,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id),
            KEY idx_sii_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table supplier_invoice_items ready.\n";

    // 2. warehouse_id on supplier_invoices.
    $exists = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'warehouse_id'")->fetch();
    if ($exists) {
        echo "  · column warehouse_id already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE supplier_invoices ADD COLUMN warehouse_id INT NULL AFTER project_id");
        echo "  + added column supplier_invoices.warehouse_id.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
