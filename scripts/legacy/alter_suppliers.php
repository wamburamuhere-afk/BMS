<?php
require_once 'roots.php';
try {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN credit_limit DECIMAL(15,2) DEFAULT 0.00 AFTER payment_terms");
    echo "Column credit_limit added to suppliers successfully.\n";
} catch (PDOException $e) {
    echo "Error adding column: " . $e->getMessage() . "\n";
}
?>
