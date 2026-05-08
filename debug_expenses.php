<?php
require_once __DIR__ . '/roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE expenses");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/expenses_schema.json', json_encode($columns, JSON_PRETTY_PRINT));
?>
