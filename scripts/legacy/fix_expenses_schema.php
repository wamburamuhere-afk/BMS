<?php
require_once 'roots.php';
global $pdo;

try {
    $sql = "ALTER TABLE expenses ADD COLUMN transaction_id INT NULL AFTER created_by";
    $pdo->exec($sql);
    echo "Column 'transaction_id' added to 'expenses' table successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
