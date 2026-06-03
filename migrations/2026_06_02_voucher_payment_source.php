<?php
/**
 * 2026_06_02_voucher_payment_source.php
 * -------------------------------------
 * Adds payment_vouchers.paid_from_account_id + transaction_id so paying a
 * voucher records the Paid-From source account and posts the consolidated
 * outflow (Dr Accounts Payable, Cr Paid-From). Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: voucher payment source...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'payment_vouchers'")->fetch()) {
        echo "  ! payment_vouchers not found — skipping.\n";
        exit(0);
    }
    foreach ([
        'paid_from_account_id' => "INT NULL AFTER payment_method",
        'transaction_id'       => "INT NULL AFTER reference_number",
    ] as $col => $def) {
        if ($pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE '{$col}'")->fetch()) {
            echo "  · {$col} already exists, skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN {$col} {$def}");
            echo "  + added payment_vouchers.{$col}.\n";
        }
    }
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
