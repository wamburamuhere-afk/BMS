<?php
/**
 * 2026_07_01_lpo_invoice_warehouse.php
 * -------------------------------------
 * Customer LPO and Invoice creation had no warehouse field at all, so their
 * inventory-product stock figures were never scoped to a specific warehouse
 * (fell back to products.current_stock, a non-warehouse-specific total).
 * Adds warehouse_id (nullable int, mirrors sales_orders.warehouse_id) to
 * both tables so the create/edit forms can offer a project-filtered
 * warehouse picker, same as Sales Order / Quotation / PO / GRN / DN.
 *
 * Additive & idempotent. No DDL transactions.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add warehouse_id to customer_lpos + invoices...\n";

try {
    if (!$pdo->query("SHOW COLUMNS FROM customer_lpos LIKE 'warehouse_id'")->fetch()) {
        $pdo->exec("ALTER TABLE customer_lpos ADD COLUMN warehouse_id INT DEFAULT NULL AFTER project_id");
        echo "  + customer_lpos.warehouse_id added.\n";
    } else {
        echo "  · customer_lpos.warehouse_id already present.\n";
    }

    if (!$pdo->query("SHOW COLUMNS FROM invoices LIKE 'warehouse_id'")->fetch()) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN warehouse_id INT DEFAULT NULL AFTER project_id");
        echo "  + invoices.warehouse_id added.\n";
    } else {
        echo "  · invoices.warehouse_id already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
