<?php
/**
 * 2026_06_09_payment_allocations.php
 * ----------------------------------
 * Plan A (Payment Allocation) — foundation.
 *
 * Lets ONE customer receipt be applied across MANY outstanding invoices. Purely
 * additive: the existing single-invoice record_payment.php keeps working unchanged
 * (it simply writes no allocation rows). The new Receive Payment screen writes a
 * payments row PLUS one payment_allocations row per invoice it settles.
 *
 *  1. CREATE payment_allocations — the receipt → invoice split.
 *  2. ADD payments.received_into_account_id — the cash/bank account the receipt
 *     was deposited into (so the receipt can appear on the Bank Statement). Nullable
 *     so existing rows and the legacy single-invoice flow are unaffected.
 *
 * Idempotent + additive — safe to re-run. No DDL inside a transaction.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: payment_allocations (table + received_into column)...\n";

try {
    // ── 1. payment_allocations ────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_allocations (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            payment_id       INT NOT NULL,
            payment_kind     ENUM('customer','supplier') NOT NULL DEFAULT 'customer',
            target_type      ENUM('invoice','bill','credit_note','debit_note') NOT NULL DEFAULT 'invoice',
            target_id        INT NOT NULL,
            allocated_amount DECIMAL(15,2) NOT NULL,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pa_payment (payment_id),
            KEY idx_pa_target (target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + payment_allocations table ready.\n";

    // ── 2. payments.received_into_account_id ──────────────────────────────
    $has = $pdo->query("SHOW COLUMNS FROM payments LIKE 'received_into_account_id'")->fetch();
    if (!$has) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN received_into_account_id INT NULL AFTER payment_method");
        echo "  + payments.received_into_account_id added.\n";
    } else {
        echo "  = payments.received_into_account_id already exists.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
