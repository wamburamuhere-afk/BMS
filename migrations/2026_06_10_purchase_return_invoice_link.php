<?php
/**
 * Migration: link purchase returns to supplier invoices + available-qty guard
 * Adds supplier_invoice_id to purchase_returns
 * Adds original_invoice_item_id to purchase_return_items
 * Both nullable — existing returns remain valid.
 */
require_once __DIR__ . '/../roots.php';

try {
    // purchase_returns.supplier_invoice_id
    $col = $pdo->query("SHOW COLUMNS FROM purchase_returns LIKE 'supplier_invoice_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE purchase_returns ADD COLUMN supplier_invoice_id INT NULL DEFAULT NULL AFTER receipt_id");
        echo "Added purchase_returns.supplier_invoice_id\n";
    } else {
        echo "purchase_returns.supplier_invoice_id already exists — skipped\n";
    }

    // purchase_return_items.original_invoice_item_id
    $col2 = $pdo->query("SHOW COLUMNS FROM purchase_return_items LIKE 'original_invoice_item_id'")->fetch();
    if (!$col2) {
        $pdo->exec("ALTER TABLE purchase_return_items ADD COLUMN original_invoice_item_id INT NULL DEFAULT NULL AFTER purchase_return_id");
        echo "Added purchase_return_items.original_invoice_item_id\n";
    } else {
        echo "purchase_return_items.original_invoice_item_id already exists — skipped\n";
    }

    echo "Migration complete.\n";
    exit(0);
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
