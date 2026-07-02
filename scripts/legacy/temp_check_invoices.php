<?php
require_once 'roots.php';

global $pdo;

try {
    // Check total invoices
    $stmt = $pdo->query('SELECT COUNT(*) as total FROM invoices');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total invoices in database: " . $result['total'] . "\n\n";
    
    // Check sample invoices
    $stmt = $pdo->query('SELECT invoice_id, invoice_number, customer_id, status, invoice_date, grand_total FROM invoices ORDER BY created_at DESC LIMIT 10');
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Sample invoices:\n";
    foreach ($invoices as $inv) {
        echo "ID: {$inv['invoice_id']}, Number: {$inv['invoice_number']}, Customer: {$inv['customer_id']}, Status: {$inv['status']}, Date: {$inv['invoice_date']}, Total: {$inv['grand_total']}\n";
    }
    
    // Check if customers table has data
    $stmt = $pdo->query('SELECT COUNT(*) as total FROM customers');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\nTotal customers: " . $result['total'] . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
