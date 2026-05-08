<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE suppliers");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($columns, JSON_PRETTY_PRINT);
?>
