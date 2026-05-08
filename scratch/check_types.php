<?php
require_once __DIR__ . '/../roots.php';
$stmt = $pdo->query("SELECT * FROM leave_types");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Leave Types: " . implode(", ", array_column($rows, 'type_name'));
