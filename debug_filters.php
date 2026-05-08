<?php
include 'includes/config.php';
$stmt = $pdo->query("SELECT movement_type, warehouse_id, COUNT(*) as count FROM stock_movements GROUP BY movement_type, warehouse_id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
