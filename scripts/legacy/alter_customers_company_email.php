<?php
require_once 'roots.php';
try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN company_email VARCHAR(255) AFTER email");
    echo "Column company_email added to customers successfully.\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') {
        echo "Column company_email already exists.\n";
    } else {
        echo "Error adding column: " . $e->getMessage() . "\n";
    }
}
?>
