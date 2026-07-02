<?php
require_once 'roots.php';
global $pdo;

$sql = "CREATE TABLE IF NOT EXISTS petty_cash_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_date DATE NOT NULL,
    type ENUM('deposit', 'expense') NOT NULL,
    category_id INT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description TEXT,
    reference_number VARCHAR(50),
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

try {
    $pdo->exec($sql);
    echo "Table created successfully";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
