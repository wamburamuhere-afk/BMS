<?php
require_once 'roots.php';
global $pdo;

try {
    // Make category_id and payment_method optional in expenses table
    // Or set defaults if appropriate.
    $sql1 = "ALTER TABLE expenses MODIFY COLUMN category_id INT NULL DEFAULT 0";
    $pdo->exec($sql1);
    
    $sql2 = "ALTER TABLE expenses MODIFY COLUMN payment_method ENUM('cash','bank_transfer','mobile_money','cheque','card') NULL DEFAULT 'cash'";
    $pdo->exec($sql2);

    echo "Expenses schema updated: category_id and payment_method are now optional.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
