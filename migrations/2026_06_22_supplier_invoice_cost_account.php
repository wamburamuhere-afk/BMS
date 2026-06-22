<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add cost_account_id to supplier_invoices...\n";

try {
    // Idempotent: only add the column if it isn't already there.
    $col = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'cost_account_id'")->fetchColumn();
    if (!$col) {
        // Nullable on purpose: existing rows + Bills that don't pick an account
        // fall back to the canonical resolver (inventoryAccountId) at posting time,
        // so this is zero-regression. Holds the GL account the goods/cost should be
        // debited to (an Expense, COGS, or Asset/Inventory leaf account).
        $pdo->exec("ALTER TABLE supplier_invoices
                    ADD COLUMN cost_account_id INT NULL DEFAULT NULL AFTER amount");
        echo "OK: cost_account_id column added to supplier_invoices\n";
    } else {
        echo "SKIP: cost_account_id already exists\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
