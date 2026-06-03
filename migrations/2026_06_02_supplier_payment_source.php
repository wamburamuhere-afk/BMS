<?php
/**
 * 2026_06_02_supplier_payment_source.php
 * --------------------------------------
 * Adds supplier_payments.paid_from_account_id — the cash/bank account the
 * payment was made from. Used to post the consolidated outflow
 * (Dr Accounts Payable, Cr Paid-From) and to show the source on the
 * Consolidated Expenses report. Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: supplier_payments.paid_from_account_id...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'supplier_payments'")->fetch()) {
        echo "  ! supplier_payments not found — skipping.\n";
        exit(0);
    }
    if ($pdo->query("SHOW COLUMNS FROM supplier_payments LIKE 'paid_from_account_id'")->fetch()) {
        echo "  · column already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN paid_from_account_id INT NULL AFTER payment_method");
        echo "  + added supplier_payments.paid_from_account_id.\n";
    }
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
