<?php
require_once 'roots.php';
global $pdo;

try {
    // Make payment_method optional in transactions table
    $sql = "ALTER TABLE transactions MODIFY COLUMN payment_method VARCHAR(100) NULL";
    $pdo->exec($sql);
    echo "Column 'payment_method' updated to NULL in 'transactions' table.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
