<?php
require_once 'roots.php';
global $pdo;

$sql = "CREATE TABLE IF NOT EXISTS payment_vouchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    voucher_number VARCHAR(50) NOT NULL UNIQUE,
    vouch_date DATE NOT NULL,
    payee_name VARCHAR(100) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    amount_in_words TEXT,
    description TEXT,
    payment_method ENUM('cash', 'cheque', 'bank_transfer', 'mobile_money') DEFAULT 'cash',
    reference_number VARCHAR(50),
    expense_category_id INT NULL,
    prepared_by INT NOT NULL,
    approved_by INT NULL,
    status ENUM('draft', 'approved', 'paid', 'cancelled') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

try {
    $pdo->exec($sql);
    echo "Payment Vouchers Table created successfully";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
