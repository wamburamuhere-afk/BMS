<?php
/**
 * 2026_06_02_payroll_payment_source.php
 * -------------------------------------
 * Adds payroll.paid_from_account_id + payment_transaction_id so paying a
 * payroll records the Paid-From source account and posts the consolidated
 * outflow (Dr Accounts Payable, Cr Paid-From). Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: payroll payment source...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'payroll'")->fetch()) {
        echo "  ! payroll not found — skipping.\n";
        exit(0);
    }
    foreach ([
        'paid_from_account_id'   => "INT NULL AFTER payment_method",
        'payment_transaction_id' => "INT NULL AFTER payment_date",
    ] as $col => $def) {
        if ($pdo->query("SHOW COLUMNS FROM payroll LIKE '{$col}'")->fetch()) {
            echo "  · {$col} already exists, skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE payroll ADD COLUMN {$col} {$def}");
            echo "  + added payroll.{$col}.\n";
        }
    }
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
