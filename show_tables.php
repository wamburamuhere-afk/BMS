<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SHOW TABLES");
$results = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($results, JSON_PRETTY_PRINT);
?>
