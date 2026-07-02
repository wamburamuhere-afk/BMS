<?php
require_once 'roots.php';

global $pdo;

try {
    // Check if sales_orders table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'sales_orders'");
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "sales_orders table EXISTS\n";
        
        // Check column name
        $stmt = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE '%order_id%'");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nColumns with 'order_id' in sales_orders:\n";
        foreach ($columns as $col) {
            echo "  - " . $col['Field'] . "\n";
        }
    } else {
        echo "sales_orders table DOES NOT EXIST\n";
    }
    
    // Check invoice_items table
    $stmt = $pdo->query("SHOW TABLES LIKE 'invoice_items'");
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "\ninvoice_items table EXISTS\n";
    } else {
        echo "\ninvoice_items table DOES NOT EXIST\n";
    }
    
    // Try to run the actual query from get_invoices.php
    echo "\n\nTesting actual query from API:\n";
    $query = "
        SELECT 
            i.*,
            c.customer_name,
            c.company_name,
            c.email as customer_email,
            c.phone as customer_phone,
            so.order_number,
            u1.username as created_by_name,
            u2.username as updated_by_name,
            COUNT(ii.invoice_item_id) as total_items,
            (i.grand_total - i.paid_amount) as balance_due,
            CASE 
                WHEN i.status = 'cancelled' THEN 'cancelled'
                WHEN i.status = 'paid' THEN 'paid'
                WHEN i.status = 'partial' THEN 'partial'
                WHEN i.status = 'overdue' AND i.due_date < CURDATE() THEN 'overdue'
                WHEN i.status = 'sent' THEN 'sent'
                WHEN i.status = 'pending' THEN 'pending'
                ELSE 'draft'
            END as display_status
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
        LEFT JOIN invoice_items ii ON i.invoice_id = ii.invoice_id
        LEFT JOIN users u1 ON i.created_by = u1.user_id
        LEFT JOIN users u2 ON i.updated_by = u2.user_id
        WHERE 1=1
        GROUP BY i.invoice_id 
        ORDER BY i.invoice_date DESC, i.created_at DESC
        LIMIT 5
    ";
    
    $stmt = $pdo->query($query);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Query returned " . count($results) . " rows\n";
    if (count($results) > 0) {
        echo "\nFirst invoice:\n";
        print_r($results[0]);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "SQL State: " . $e->getCode() . "\n";
}
