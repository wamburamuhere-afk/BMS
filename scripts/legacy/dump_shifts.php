<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT * FROM cash_register_shifts ORDER BY start_time DESC LIMIT 10");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($results, JSON_PRETTY_PRINT);
?>
