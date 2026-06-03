<?php
/**
 * 2026_06_02_received_invoice_payment_source.php
 * ----------------------------------------------
 * Adds the Paid-From source + ledger link to received-invoice payments:
 *   supplier_invoices.payment_account_id     — cash/bank account paid from
 *   supplier_invoices.payment_transaction_id — consolidated ledger entry id
 * Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: received_invoice payment source...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'supplier_invoices'")->fetch()) {
        echo "  ! supplier_invoices not found — skipping.\n";
        exit(0);
    }
    foreach ([
        'payment_account_id'     => "INT NULL AFTER payment_method",
        'payment_transaction_id' => "INT NULL AFTER payment_ref",
    ] as $col => $def) {
        if ($pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE '{$col}'")->fetch()) {
            echo "  · {$col} already exists, skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE supplier_invoices ADD COLUMN {$col} {$def}");
            echo "  + added supplier_invoices.{$col}.\n";
        }
    }
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
