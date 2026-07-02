<?php
require_once 'includes/config.php';
$stmt = $pdo->query("DESCRIBE stock_transfers");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
