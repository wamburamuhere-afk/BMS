<?php
require_once 'roots.php';
$stmt = $pdo->query("SELECT * FROM document_categories");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($categories, JSON_PRETTY_PRINT);
?>
