<?php
require_once 'roots.php';
global $pdo;

try {
    echo "Updating status ENUM...\n";
    $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft','pending','approved','rejected','ordered','received','partially_received','completed','cancelled') DEFAULT 'pending'");
    echo "ENUM updated successfully.\n";
    
    // Now re-run the status repair logic
    echo "Re-running repairs...\n";
    $pos = $pdo->query("SELECT purchase_order_id FROM purchase_orders")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pos as $po) {
        $id = $po['purchase_order_id'];
        $stmt = $pdo->prepare("
            SELECT 
                SUM(poi.quantity) as total_ordered,
                SUM(IFNULL(pri.received_qty, 0)) as total_received
            FROM purchase_order_items poi
            LEFT JOIN (
                SELECT purchase_order_item_id, SUM(quantity_received) as received_qty
                FROM receipt_items
                GROUP BY purchase_order_item_id
            ) pri ON poi.item_id = pri.purchase_order_item_id
            WHERE poi.purchase_order_id = ?
        ");
        $stmt->execute([$id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $ordered = floatval($stats['total_ordered'] ?? 0);
        $received = floatval($stats['total_received'] ?? 0);
        
        if ($ordered > 0) {
            if ($received >= $ordered) $newStatus = 'completed';
            elseif ($received > 0) $newStatus = 'partially_received';
            else $newStatus = 'ordered'; 
            
            $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE purchase_order_id = ?")->execute([$newStatus, $id]);
            echo "PO #$id: Updated to $newStatus\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
