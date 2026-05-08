<?php
require_once __DIR__ . '/roots.php';
global $pdo;

$stmt = $pdo->query("DESCRIBE tenders");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($columns, JSON_PRETTY_PRINT);
