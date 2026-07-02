<?php
require_once 'roots.php';
global $pdo;

try {
    // 1. Update transactions table to allow 'expense' type and make loan_id optional
    $sql1 = "ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM('disbursement','repayment','fee','interest','expense','general') NOT NULL DEFAULT 'general'";
    $pdo->exec($sql1);
    
    $sql2 = "ALTER TABLE transactions MODIFY COLUMN loan_id INT NULL";
    $pdo->exec($sql2);
    
    // Also make account IDs optional in the header since they are in books_transactions
    $sql3 = "ALTER TABLE transactions MODIFY COLUMN account_id INT NULL DEFAULT 0";
    $pdo->exec($sql3);
    $sql4 = "ALTER TABLE transactions MODIFY COLUMN contra_account_id INT NULL DEFAULT 0";
    $pdo->exec($sql4);
    $sql5 = "ALTER TABLE transactions MODIFY COLUMN disbursement_account_id INT NULL DEFAULT 0";
    $pdo->exec($sql5);

    echo "Schema 'transactions' updated successfully.\n";
} catch (PDOException $e) {
    echo "Error updating schema: " . $e->getMessage() . "\n";
}
?>
