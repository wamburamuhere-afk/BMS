<?php
require_once __DIR__ . '/../roots.php';
$stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
