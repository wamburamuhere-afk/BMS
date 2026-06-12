<?php
/**
 * 2026_06_09_po_supplier_quote_ref.php
 * Adds supplier_quote_ref to purchase_orders so the supplier's own quote
 * reference number can be recorded on the PO for traceability.
 * Idempotent: skips if the column already exists.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: purchase_orders.supplier_quote_ref...\n";

try {
    $exists = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'supplier_quote_ref'")->fetch();
    if ($exists) {
        echo "  · column supplier_quote_ref already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN supplier_quote_ref VARCHAR(100) NULL AFTER proforma_invoice_ref");
        echo "  + added column purchase_orders.supplier_quote_ref.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
