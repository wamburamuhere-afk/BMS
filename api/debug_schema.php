<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';

try {
    $stmt = $pdo->query("DESCRIBE purchase_receipts");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Columns in purchase_receipts:\n";
    print_r($columns);
    
    if (in_array('delivery_note', $columns)) {
        echo "\nColumn 'delivery_note' EXISTS.\n";
    } else {
        echo "\nColumn 'delivery_note' MISSING.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
