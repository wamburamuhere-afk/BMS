<?php
require_once __DIR__ . '/roots.php';
global $pdo;

$stmt = $pdo->query("DESCRIBE documents");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($res, JSON_PRETTY_PRINT);
