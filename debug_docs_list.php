<?php
require_once __DIR__ . '/roots.php';
global $pdo;

$stmt = $pdo->query("SELECT id, document_name, project_id, access_level FROM documents ORDER BY id DESC LIMIT 10");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($res, JSON_PRETTY_PRINT);
