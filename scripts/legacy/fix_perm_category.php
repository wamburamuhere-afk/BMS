<?php
require_once 'c:/wamp64/www/bms/roots.php';
global $pdo;
try {
    // 1. Update module_name for primary 'customers' permission so it appears in the right tab
    $stmt = $pdo->prepare("UPDATE permissions SET module_name = 'Customers' WHERE page_key = 'customers' AND module_name = 'Core'");
    $stmt->execute();
    echo "Updated 'customers' permission category. ";

    // 2. Also ensure 'edit_customer' and 'customer_details' are in 'Customers' module (they likely already are)
    
    echo "Done.";
} catch (Exception $e) {
    echo $e->getMessage();
}
