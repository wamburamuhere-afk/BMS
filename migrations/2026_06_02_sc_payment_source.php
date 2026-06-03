<?php
/**
 * 2026_06_02_sc_payment_source.php
 * --------------------------------
 * Adds sc_payments.paid_from_account_id + transaction_id for the "Paid From"
 * source account and the consolidated-outflow ledger link. Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: sc_payments payment source...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'sc_payments'")->fetch()) {
        echo "  ! sc_payments not found — skipping.\n";
        exit(0);
    }
    foreach ([
        'paid_from_account_id' => "INT NULL AFTER payment_method",
        'transaction_id'       => "INT NULL AFTER reference_number",
    ] as $col => $def) {
        if ($pdo->query("SHOW COLUMNS FROM sc_payments LIKE '{$col}'")->fetch()) {
            echo "  · {$col} already exists, skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE sc_payments ADD COLUMN {$col} {$def}");
            echo "  + added sc_payments.{$col}.\n";
        }
    }
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
