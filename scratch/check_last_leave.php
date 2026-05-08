<?php
require_once __DIR__ . '/../roots.php';
$stmt = $pdo->query("SELECT * FROM leaves ORDER BY leave_id DESC LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($row);
