<?php
require_once __DIR__ . '/roots.php';
global $pdo;
$stmt = $pdo->prepare("SELECT * FROM permissions WHERE page_key LIKE '%grn%'");
$stmt->execute();
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
