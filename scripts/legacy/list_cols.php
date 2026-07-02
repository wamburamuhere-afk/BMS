<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE projects");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo implode(", ", $columns);
?>
