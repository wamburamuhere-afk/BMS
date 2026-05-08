<?php
require_once __DIR__ . '/../roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE brands");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Columns in brands table: " . implode(", ", $columns);
?>
