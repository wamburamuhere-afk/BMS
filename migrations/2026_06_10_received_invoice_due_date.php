<?php
/**
 * Add payment_terms + due_date to supplier_invoices.
 * Idempotent: skips columns that already exist.
 */
require_once __DIR__ . '/../roots.php';

if (!$pdo->query("SHOW TABLES LIKE 'supplier_invoices'")->fetchColumn()) {
    echo "  · supplier_invoices table does not exist on this database — skipped.\n";
    echo "Migration complete.\n";
    exit(0);
}

$cols = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'payment_terms'")->fetchColumn();
if (!$cols) {
    $pdo->exec("ALTER TABLE supplier_invoices
        ADD COLUMN payment_terms VARCHAR(20) NULL DEFAULT NULL AFTER date_recorded,
        ADD COLUMN due_date      DATE        NULL DEFAULT NULL AFTER payment_terms");
    echo "OK: supplier_invoices — payment_terms + due_date added\n";
} else {
    echo "SKIP: columns already exist\n";
}
