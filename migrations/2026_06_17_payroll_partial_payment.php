<?php
/**
 * Migration: 2026_06_17_payroll_partial_payment.php
 * --------------------------------------------------
 * Adds partial-payment tracking to the payroll table:
 *   1. amount_paid  — cumulative amount disbursed to date for this payroll record
 *   2. Expands payment_status ENUM to include 'partial'
 *
 * Both changes are idempotent and safe to re-run.
 */

require_once __DIR__ . '/../roots.php';

try {
    $cols = array_column(
        $pdo->query("SHOW COLUMNS FROM payroll")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );

    if (!in_array('amount_paid', $cols)) {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER net_salary");
        echo "added payroll.amount_paid\n";
    } else {
        echo "payroll.amount_paid already exists - skipped\n";
    }

    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status
        ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial')
        DEFAULT 'pending'");
    echo "payment_status ENUM expanded\n";

    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN status
        ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial')
        DEFAULT 'pending'");
    echo "status ENUM expanded\n";

    echo "Migration 2026_06_17_payroll_partial_payment completed.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
