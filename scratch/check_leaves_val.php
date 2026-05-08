<?php
require_once __DIR__ . '/../roots.php';
$stmt = $pdo->query("SELECT leave_type FROM leaves LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Leaves in DB: " . implode(", ", array_column($rows, 'leave_type'));
