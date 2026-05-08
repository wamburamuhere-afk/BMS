<?php
require_once 'roots.php';
try {
    // Update from PO
    $pdo->exec("
        UPDATE purchase_returns pr
        JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        SET pr.project_id = po.project_id
        WHERE pr.project_id IS NULL OR pr.project_id = 0
    ");
    
    // Update from Supplier if still null
    $pdo->exec("
        UPDATE purchase_returns pr
        JOIN suppliers s ON pr.supplier_id = s.supplier_id
        SET pr.project_id = s.project_id
        WHERE pr.project_id IS NULL OR pr.project_id = 0
    ");
    
    echo "Existing returns updated with project_id successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
