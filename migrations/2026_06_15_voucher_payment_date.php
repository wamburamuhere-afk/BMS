<?php
/**
 * 2026_06_15_voucher_payment_date.php
 * -----------------------------------
 * Payment Vouchers — add a real payment_date for the "Pay" step.
 *
 * A payment voucher is authorised (approved) and later PAID. The pay step now opens a
 * proper form (Paid From bank account, payment date, method, reference, attachment),
 * modelled on WorkDo's VendorPayment form (payment_date + bank_account_id +
 * reference_number + amount + notes). Until now there was no column to store WHEN the
 * voucher was actually paid (only vouch_date, the voucher's own date), so the GL
 * posting used vouch_date. This adds `payment_date` so the cash-out is dated when it
 * truly happened. Additive + idempotent: only adds the column if missing.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: payment_vouchers.payment_date...\n";

try {
    $exists = $pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE 'payment_date'")->fetch();
    if ($exists) {
        echo "  ~ payment_date already present — nothing to do.\n";
        echo "Migration complete (no-op).\n";
        return;
    }
    // Place it next to the existing paid_from_account_id for tidiness.
    $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN payment_date DATE NULL AFTER paid_from_account_id");
    echo "  + added payment_vouchers.payment_date (DATE NULL)\n";
    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "  ! migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
