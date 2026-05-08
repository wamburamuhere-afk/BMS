<?php
require_once 'includes/config.php';

try {
    echo "Checking stock_transfers table...\n";
    $stmt = $pdo->query("DESCRIBE stock_transfers");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // If transfer_no exists but transfer_number doesn't, rename it
    if (in_array('transfer_no', $columns) && !in_array('transfer_number', $columns)) {
        echo "Renaming transfer_no to transfer_number...\n";
        $pdo->exec("ALTER TABLE stock_transfers CHANGE transfer_no transfer_number varchar(50) NOT NULL");
        echo "Done!\n";
    } elseif (!in_array('transfer_number', $columns)) {
        echo "Adding transfer_number column...\n";
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN transfer_number varchar(50) NOT NULL AFTER transfer_id");
        $pdo->exec("ALTER TABLE stock_transfers ADD UNIQUE KEY (transfer_number)");
        echo "Done!\n";
    } else {
        echo "transfer_number column already exists.\n";
    }
    
    echo "Database sync completed!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
