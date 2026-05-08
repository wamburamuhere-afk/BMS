<?php
require_once 'includes/config.php';

try {
    echo "Syncing stock_transfer_items table...\n";
    $stmt = $pdo->query("DESCRIBE stock_transfer_items");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('received_quantity', $columns)) {
        echo "Adding received_quantity to stock_transfer_items...\n";
        $pdo->exec("ALTER TABLE stock_transfer_items ADD COLUMN received_quantity decimal(15,3) DEFAULT '0.000' AFTER quantity");
        echo "Done!\n";
    }
    
    // Also update quantity to 15,3 for precision if needed
    echo "Updating quantity precision...\n";
    $pdo->exec("ALTER TABLE stock_transfer_items MODIFY COLUMN quantity decimal(15,3) NOT NULL");
    echo "Done!\n";

    echo "Sync completed!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
