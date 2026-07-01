<?php
/**
 * 2026_06_24_payroll_void_status.php
 * ------------------------------------
 * Add 'voided' status + audit columns to the payroll table.
 * Replaces hard-delete with a soft void: the row stays for audit/statutory
 * history; the GL reversal still fires; the record is excluded from all
 * remittance aggregates and duplicate-processing checks.
 *
 * Additive & idempotent. No DDL transactions.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Payroll void status...\n";

try {
    // ── 1. Expand payment_status ENUM to include 'voided' ───────────────────
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status
                ENUM('pending','paid','cancelled','approved','processing',
                     'rejected','unprocessed','partial','voided')
                DEFAULT 'pending'");
    echo "  + payroll.payment_status ENUM expanded with 'voided'.\n";

    // ── 2. Expand status ENUM to include 'voided' ────────────────────────────
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN status
                ENUM('pending','paid','cancelled','approved','processing',
                     'rejected','unprocessed','partial','voided')
                DEFAULT 'pending'");
    echo "  + payroll.status ENUM expanded with 'voided'.\n";

    // ── 3. Audit columns ─────────────────────────────────────────────────────
    $addCol = function (string $col, string $ddl) use ($pdo) {
        if (!$pdo->query("SHOW COLUMNS FROM payroll LIKE " . $pdo->quote($col))->fetch()) {
            $pdo->exec("ALTER TABLE payroll ADD COLUMN $ddl");
            echo "  + payroll.{$col} added.\n";
        } else {
            echo "  · payroll.{$col} already present.\n";
        }
    };

    $addCol('voided_by',    'voided_by   INT          NULL DEFAULT NULL');
    $addCol('voided_at',    'voided_at   DATETIME     NULL DEFAULT NULL');
    $addCol('void_reason',  'void_reason TEXT         NULL DEFAULT NULL');

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
