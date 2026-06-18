<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: voucher_payments table + partially_paid status...\n";

try {
    // 1. Add partially_paid to payment_vouchers status ENUM
    $col = $pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (strpos($col['Type'], 'partially_paid') !== false) {
        echo "ENUM already contains 'partially_paid' — skipping ALTER.\n";
    } else {
        $pdo->exec("
            ALTER TABLE payment_vouchers
            MODIFY COLUMN status
                ENUM('pending','reviewed','approved','partially_paid','paid','cancelled','draft')
                NOT NULL DEFAULT 'pending'
        ");
        echo "Added 'partially_paid' to payment_vouchers.status ENUM.\n";
    }

    // 2. Create voucher_payments table
    $tables = $pdo->query("SHOW TABLES LIKE 'voucher_payments'")->fetchAll();
    if (!empty($tables)) {
        echo "Table voucher_payments already exists — skipping CREATE.\n";
    } else {
        $pdo->exec("
            CREATE TABLE voucher_payments (
                id                   INT AUTO_INCREMENT PRIMARY KEY,
                voucher_id           INT NOT NULL,
                amount               DECIMAL(15,2) NOT NULL,
                paid_from_account_id INT NOT NULL,
                payment_date         DATE NOT NULL,
                payment_method       ENUM('cash','cheque','bank_transfer','mobile_money') NOT NULL DEFAULT 'cash',
                reference_number     VARCHAR(100) NULL,
                gl_transaction_id    INT NULL,
                attachment           VARCHAR(255) NULL,
                created_by           INT NOT NULL,
                created_at           DATETIME NOT NULL DEFAULT NOW(),
                INDEX idx_voucher_id (voucher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Created table voucher_payments.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
