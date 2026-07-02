<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE document_categories");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($columns, JSON_PRETTY_PRINT);
?>
