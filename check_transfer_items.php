<?php
require_once 'includes/config.php';
$stmt = $pdo->query("DESCRIBE stock_transfer_items");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
