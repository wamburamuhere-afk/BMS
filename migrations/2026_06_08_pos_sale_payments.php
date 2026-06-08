<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: create pos_sale_payments (POS credit / partial-payment ledger)...\n";

try {
    // One row per payment received against a POS sale. The sum of a sale's
    // payments determines amount_paid / balance_due and the pos_sales.payment_status
    // (pending → partial → paid). Lets a credit sale be settled over time.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pos_sale_payments (
            payment_id     INT NOT NULL AUTO_INCREMENT,
            sale_id        INT NOT NULL,
            amount         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            payment_method ENUM('cash','card','mobile_money','bank_transfer','voucher','loyalty_points') NOT NULL DEFAULT 'cash',
            reference      VARCHAR(100) NULL,
            notes          VARCHAR(255) NULL,
            received_by    INT NULL,
            created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (payment_id),
            KEY idx_pos_pay_sale (sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
