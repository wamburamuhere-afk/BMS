<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE project_milestones");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($columns, JSON_PRETTY_PRINT);
