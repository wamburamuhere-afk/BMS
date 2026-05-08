<?php
require_once __DIR__ . '/roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE project_milestones");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($res, JSON_PRETTY_PRINT);
